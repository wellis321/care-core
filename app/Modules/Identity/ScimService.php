<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Actor;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Uuid;
use App\Modules\People\PersonRepo;
use App\Modules\Staff\Lifecycle;

/**
 * A SCIM 2.0 (RFC 7644) Users endpoint, so an identity provider such as Microsoft Entra can PUSH joiners and leavers.
 *
 * A SCIM user maps to a PERSON (its id is the person's uuid). "active" means the person is currently employed:
 *   active=false   -> the leaver process runs (employment ends today; team places, key worker links and the login end)
 *   active=true    -> a returner is rehired (a NEW employment)
 *   POST           -> the joiner process runs (or a rehire, when the person already exists)
 * The provider may also update the name and job title (a title change is recorded as a mover change). Everything else
 * about the person (teams, line manager, hours, records) is owned here and ignored if sent.
 */
final class ScimService
{
    private const USER_SCHEMA  = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const LIST_SCHEMA  = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    private const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';
    private const PATCH_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    /**
     * @param array<string,string> $query
     * @return array{0:int,1:array<string,mixed>|null} status and JSON body
     */
    public static function handle(int $orgId, string $method, string $path, array $query, ?array $body): array
    {
        $actor = Actor::system('scim');
        try {
            $path = '/' . trim($path, '/');
            if ($path === '/ServiceProviderConfig' && $method === 'GET') {
                return [200, [
                    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
                    'patch' => ['supported' => true], 'bulk' => ['supported' => false], 'filter' => ['supported' => true, 'maxResults' => 100],
                    'changePassword' => ['supported' => false], 'sort' => ['supported' => false], 'etag' => ['supported' => false],
                    'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer token', 'description' => 'A long-lived bearer token created in Care Core']],
                ]];
            }
            if ($path === '/Users') {
                return match ($method) {
                    'GET'  => self::listUsers($orgId, $query),
                    'POST' => self::createUser($orgId, $body ?? [], $actor),
                    default => self::error(405, 'Method not allowed'),
                };
            }
            if (preg_match('#^/Users/([0-9a-fA-F-]{36})$#', $path, $m)) {
                $person = PersonRepo::byUuid($orgId, strtolower($m[1]));
                if (!$person) {
                    return self::error(404, 'User not found');
                }
                return match ($method) {
                    'GET'    => [200, self::resource($person)],
                    'PATCH'  => self::patchUser($orgId, $person, $body ?? [], $actor),
                    'PUT'    => self::replaceUser($orgId, $person, $body ?? [], $actor),
                    'DELETE' => self::deprovision($orgId, $person, $actor, true),
                    default  => self::error(405, 'Method not allowed'),
                };
            }
            return self::error(404, 'Not found');
        } catch (DomainException $e) {
            return self::error(400, $e->getMessage(), 'invalidValue');
        }
    }

    // ── Operations ──────────────────────────────────────────────────────────

    /** @param array<string,string> $query */
    private static function listUsers(int $orgId, array $query): array
    {
        $sql = 'SELECT p.* FROM persons p WHERE p.organisation_id = ?';
        $params = [$orgId];
        if (!empty($query['filter'])) {
            if (!preg_match('/^userName\s+eq\s+"([^"]+)"$/i', trim($query['filter']), $m)) {
                return self::error(400, 'Only the filter userName eq "..." is supported', 'invalidFilter');
            }
            $sql .= ' AND p.email = ?';
            $params[] = strtolower($m[1]);
        }
        $start = max(1, (int) ($query['startIndex'] ?? 1));
        $count = min(100, max(0, (int) ($query['count'] ?? 100)));
        $total = (int) DB::value('SELECT COUNT(*) FROM (' . $sql . ') x', $params);
        $rows = DB::all($sql . ' ORDER BY p.id LIMIT ' . $count . ' OFFSET ' . ($start - 1), $params);
        return [200, [
            'schemas' => [self::LIST_SCHEMA], 'totalResults' => $total, 'startIndex' => $start, 'itemsPerPage' => count($rows),
            'Resources' => array_map([self::class, 'resource'], $rows),
        ]];
    }

    /** @param array<string,mixed> $b */
    private static function createUser(int $orgId, array $b, Actor $actor): array
    {
        $email = strtolower(trim((string) ($b['userName'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::error(400, 'userName must be an email address', 'invalidValue');
        }
        $existing = DB::one('SELECT * FROM persons WHERE organisation_id = ? AND email = ?', [$orgId, $email]);
        if ($existing && Lifecycle::currentEmployment((int) $existing['id'])) {
            return self::error(409, 'A user with that userName already exists', 'uniqueness');
        }
        $active = self::truthy($b['active'] ?? true);
        $data = [
            'first_name' => (string) ($b['name']['givenName'] ?? ''), 'last_name' => (string) ($b['name']['familyName'] ?? ''),
            'email' => $email, 'job_title' => trim((string) ($b['title'] ?? '')) ?: 'Employee', 'start_date' => Clock::today(),
            'employee_ref' => ($b['externalId'] ?? '') !== '' ? (string) $b['externalId'] : null, 'create_login' => true, 'login_role' => 'staff',
        ];
        if ($existing) {
            $data = ['person_uuid' => $existing['uuid']] + $data;   // returner: a new employment, same person
        }
        $r = Lifecycle::join($orgId, $data, $actor);
        $person = PersonRepo::byId($orgId, $r['person_id']);
        if (!$active) {
            Lifecycle::leave($orgId, $r['employment_id'], Clock::today(), 'created inactive by identity provider', $actor);
            $person = PersonRepo::byId($orgId, $r['person_id']);
        }
        return [201, self::resource($person)];
    }

    /** @param array<string,mixed> $person @param array<string,mixed> $b */
    private static function patchUser(int $orgId, array $person, array $b, Actor $actor): array
    {
        foreach ($b['Operations'] ?? [] as $op) {
            $verb = strtolower((string) ($op['op'] ?? ''));
            if (!in_array($verb, ['replace', 'add'], true)) {
                return self::error(400, 'Only add and replace operations are supported', 'invalidSyntax');
            }
            $path = (string) ($op['path'] ?? '');
            $value = $op['value'] ?? null;
            $changes = $path === '' && is_array($value) ? $value : [$path => $value];   // Entra sometimes sends a whole object with no path
            foreach ($changes as $k => $v) {
                $err = self::applyAttribute($orgId, $person, (string) $k, $v, $actor);
                if ($err) {
                    return $err;
                }
                $person = PersonRepo::byId($orgId, (int) $person['id']) ?? $person;
            }
        }
        return [200, self::resource(PersonRepo::byId($orgId, (int) $person['id']))];
    }

    /** @param array<string,mixed> $person @param array<string,mixed> $b */
    private static function replaceUser(int $orgId, array $person, array $b, Actor $actor): array
    {
        foreach (['active' => $b['active'] ?? null, 'title' => $b['title'] ?? null, 'name.givenName' => $b['name']['givenName'] ?? null, 'name.familyName' => $b['name']['familyName'] ?? null] as $k => $v) {
            if ($v !== null) {
                if ($err = self::applyAttribute($orgId, $person, $k, $v, $actor)) {
                    return $err;
                }
                $person = PersonRepo::byId($orgId, (int) $person['id']) ?? $person;
            }
        }
        return [200, self::resource(PersonRepo::byId($orgId, (int) $person['id']))];
    }

    /** @param array<string,mixed> $person @return array{0:int,1:array<string,mixed>|null}|null an error, or null when applied */
    private static function applyAttribute(int $orgId, array $person, string $attr, mixed $value, Actor $actor): ?array
    {
        $attr = trim($attr);
        switch ($attr) {
            case 'active':
                if (self::truthy($value)) {
                    return self::reprovision($orgId, $person, $actor);
                }
                self::deprovision($orgId, $person, $actor, false);
                return null;
            case 'title':
                $emp = Lifecycle::currentEmployment((int) $person['id']);
                if ($emp && trim((string) $value) !== '' && trim((string) $value) !== $emp['job_title']) {
                    Lifecycle::move($orgId, (int) $emp['id'], ['job_title' => (string) $value], Clock::today() < $emp['start_date'] ? $emp['start_date'] : Clock::today(), $actor);
                }
                return null;
            case 'name.givenName':
            case 'name.familyName':
                $d = ['first_name' => $person['first_name'], 'last_name' => $person['last_name'], 'preferred_name' => $person['preferred_name'], 'date_of_birth' => $person['date_of_birth'], 'email' => $person['email'], 'phone' => $person['phone'], 'notes' => $person['notes']];
                $d[$attr === 'name.givenName' ? 'first_name' : 'last_name'] = (string) $value;
                PersonRepo::update($orgId, (int) $person['id'], $d, $actor);
                return null;
            case 'userName':
                return self::error(400, 'userName cannot be changed here', 'mutability');
            default:
                return null;   // attributes we do not own are ignored, as SCIM allows
        }
    }

    /** @param array<string,mixed> $person */
    private static function deprovision(int $orgId, array $person, Actor $actor, bool $isDelete): array
    {
        $emp = Lifecycle::currentEmployment((int) $person['id']);
        if (!$emp) {
            // Already not employed. A SCIM client may repeat itself, so this is not an error.
            return $isDelete ? [204, null] : [200, self::resource($person)];
        }
        // Deprovisioning takes effect NOW: the last day is yesterday, so access has stopped as of today.
        $last = date('Y-m-d', strtotime(Clock::today() . ' -1 day'));
        if ($last < $emp['start_date']) {
            $last = $emp['start_date'];
        }
        Lifecycle::leave($orgId, (int) $emp['id'], $last, 'deprovisioned by identity provider', $actor);
        // The identity provider has removed them: sign-in stops now, whatever their last day was.
        foreach (DB::all("SELECT id, uuid, email FROM users WHERE person_id = ? AND status <> 'disabled'", [$person['id']]) as $u) {
            DB::update('users', ['status' => 'disabled'], ['id' => $u['id']]);
            \App\Core\Audit::record($orgId, $actor, 'user.disabled', 'user', $u['uuid'], 'Disabled the login for ' . $u['email'] . ' (identity provider)');
        }
        return $isDelete ? [204, null] : [200, self::resource(PersonRepo::byId($orgId, (int) $person['id']))];
    }

    /** @param array<string,mixed> $person */
    private static function reprovision(int $orgId, array $person, Actor $actor): ?array
    {
        if (Lifecycle::currentEmployment((int) $person['id']) && !DB::value("SELECT 1 FROM users WHERE person_id = ? AND status = 'disabled' LIMIT 1", [$person['id']])) {
            return null;
        }
        DB::run("UPDATE users SET status = 'active', access_ends_on = NULL WHERE person_id = ? AND status = 'disabled'", [$person['id']]);
        if (Lifecycle::currentEmployment((int) $person['id'])) {
            return null;
        }
        Lifecycle::join($orgId, ['person_uuid' => $person['uuid'], 'job_title' => (string) (DB::value('SELECT job_title FROM employments WHERE person_id = ? ORDER BY start_date DESC LIMIT 1', [$person['id']]) ?: 'Employee'), 'start_date' => Clock::today(), 'create_login' => true], $actor);
        return null;
    }

    // ── Representation ──────────────────────────────────────────────────────

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function resource(array $p): array
    {
        $emp = Lifecycle::currentEmployment((int) $p['id']);
        $last = DB::one('SELECT job_title, employee_ref FROM employments WHERE person_id = ? ORDER BY start_date DESC LIMIT 1', [$p['id']]);
        return [
            'schemas' => [self::USER_SCHEMA], 'id' => $p['uuid'], 'externalId' => $last['employee_ref'] ?? null,
            'userName' => $p['email'], 'name' => ['givenName' => $p['first_name'], 'familyName' => $p['last_name'], 'formatted' => PersonRepo::displayName($p)],
            'displayName' => PersonRepo::displayName($p), 'title' => $emp['job_title'] ?? ($last['job_title'] ?? null),
            'emails' => $p['email'] ? [['value' => $p['email'], 'type' => 'work', 'primary' => true]] : [],
            'active' => $emp !== null && !DB::value("SELECT 1 FROM users WHERE person_id = ? AND status = 'disabled' LIMIT 1", [$p['id']]),
            'meta' => ['resourceType' => 'User', 'created' => str_replace(' ', 'T', (string) $p['created_at']) . 'Z', 'lastModified' => str_replace(' ', 'T', (string) $p['updated_at']) . 'Z'],
        ];
    }

    private static function truthy(mixed $v): bool
    {
        if (is_string($v)) {
            return in_array(strtolower($v), ['true', '1'], true);   // Entra sends "True" / "False" as strings
        }
        return (bool) $v;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private static function error(int $status, string $detail, ?string $type = null): array
    {
        return [$status, ['schemas' => [self::ERROR_SCHEMA], 'status' => (string) $status, 'detail' => $detail] + ($type ? ['scimType' => $type] : [])];
    }
}
