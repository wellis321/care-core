<?php
declare(strict_types=1);

namespace App\Modules\Recruitment;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;
use App\Modules\Staff\Lifecycle;

/**
 * Job roles: the reference definition of a job. What it is, who it reports to, which team it normally sits in, and what
 * has to be checked before someone can start in it. A vacancy is a post to fill in a role; hiring someone gives them an
 * employment whose job title is the role's.
 */
final class RoleRepo
{
    /** @param array<string,mixed> $d @return list<string> */
    public static function problems(int $orgId, array $d, ?int $roleId = null): array
    {
        $code = self::nullable($d['code'] ?? null);
        $msgs = [
            Check::required($d['title'] ?? null, 'Title'),
            Check::maxLen($d['title'] ?? null, 150, 'Title'),
            Check::maxLen($d['code'] ?? null, 30, 'Code'),
            Check::maxLen($d['grade'] ?? null, 60, 'Grade'),
            Check::maxLen($d['description'] ?? null, 10000, 'Description'),
            Check::maxLen($d['person_specification'] ?? null, 10000, 'Person specification'),
            Check::oneOf(($d['contract_type'] ?? '') ?: 'permanent', Lifecycle::CONTRACTS, 'a contract type'),
            self::hours($d['hours_per_week'] ?? null),
        ];
        if ($code !== null && DB::value('SELECT 1 FROM job_roles WHERE organisation_id = ? AND code = ?' . ($roleId ? ' AND id <> ' . (int) $roleId : ''), [$orgId, $code])) {
            $msgs[] = 'Another role already uses that code.';
        }
        return Check::all($msgs);
    }

    /** @param array<string,mixed> $d @return array{id:int,uuid:string} */
    public static function create(int $orgId, array $d, Actor $actor): array
    {
        if ($p = self::problems($orgId, $d)) {
            throw DomainException::of($p);
        }
        return DB::transaction(function () use ($orgId, $d, $actor) {
            $uuid = Uuid::v4();
            $id = DB::insert('job_roles', ['uuid' => $uuid, 'organisation_id' => $orgId] + self::columns($orgId, $d));
            Audit::record($orgId, $actor, 'role.created', 'job_role', $uuid, 'Added the role ' . trim($d['title']));
            Events::emit($orgId, 'role.created', $uuid, ['title' => trim($d['title'])]);
            return ['id' => $id, 'uuid' => $uuid];
        });
    }

    /** @param array<string,mixed> $d */
    public static function update(int $orgId, int $roleId, array $d, Actor $actor): void
    {
        $role = self::byId($orgId, $roleId) ?? throw new DomainException('Role not found.');
        $d += ['title' => $role['title']];
        if ($p = self::problems($orgId, $d, $roleId)) {
            throw DomainException::of($p);
        }
        DB::transaction(function () use ($orgId, $roleId, $d, $actor, $role) {
            DB::update('job_roles', self::columns($orgId, $d, $role, $roleId), ['id' => $roleId, 'organisation_id' => $orgId]);
            Audit::record($orgId, $actor, 'role.updated', 'job_role', $role['uuid'], 'Updated the role ' . trim((string) ($d['title'] ?? $role['title'])));
            Events::emit($orgId, 'role.updated', $role['uuid'], []);
        });
    }

    /** A role that is no longer used. Existing vacancies and hires keep it. */
    public static function setActive(int $orgId, int $roleId, bool $active, Actor $actor): void
    {
        $role = self::byId($orgId, $roleId) ?? throw new DomainException('Role not found.');
        DB::update('job_roles', ['active' => $active ? 1 : 0], ['id' => $roleId]);
        Audit::record($orgId, $actor, $active ? 'role.reopened' : 'role.retired', 'job_role', $role['uuid'], ($active ? 'Brought back the role ' : 'Retired the role ') . $role['title']);
    }

    /** @param array<string,mixed> $d @param array<string,mixed>|null $current @return array<string,mixed> */
    private static function columns(int $orgId, array $d, ?array $current = null, ?int $selfId = null): array
    {
        $out = [];
        foreach (['title', 'code', 'description', 'person_specification', 'grade'] as $k) {
            if (array_key_exists($k, $d) || !$current) {
                $out[$k] = self::nullable($d[$k] ?? null);
            }
        }
        if (array_key_exists('contract_type', $d) || !$current) {
            $out['contract_type'] = ($d['contract_type'] ?? '') ?: 'permanent';
        }
        if (array_key_exists('hours_per_week', $d) || !$current) {
            $out['hours_per_week'] = ($d['hours_per_week'] ?? '') !== '' && $d['hours_per_week'] !== null ? (float) $d['hours_per_week'] : null;
        }
        if (array_key_exists('reports_to_role_id', $d) || !$current) {
            $r = $d['reports_to_role_id'] ?? null;
            $out['reports_to_role_id'] = ($r === null || $r === '') ? null : self::validRole($orgId, (int) $r, $selfId);
        }
        if (array_key_exists('default_team_id', $d) || !$current) {
            $t = $d['default_team_id'] ?? null;
            if ($t === null || $t === '') {
                $out['default_team_id'] = null;
            } else {
                if (!DB::value('SELECT 1 FROM teams WHERE id = ? AND organisation_id = ?', [(int) $t, $orgId])) {
                    throw new DomainException('Choose a team from the list.');
                }
                $out['default_team_id'] = (int) $t;
            }
        }
        if (isset($out['title'])) {
            $out['title'] = trim((string) $out['title']);
        }
        return $out;
    }

    /** The role this one reports to: it must exist, not be itself, and not make a loop. */
    private static function validRole(int $orgId, int $reportsTo, ?int $selfId): int
    {
        if (!DB::value('SELECT 1 FROM job_roles WHERE id = ? AND organisation_id = ?', [$reportsTo, $orgId])) {
            throw new DomainException('Choose the role it reports to from the list.');
        }
        if ($selfId !== null) {
            $seen = [];
            for ($cur = $reportsTo; $cur; $cur = (int) DB::value('SELECT reports_to_role_id FROM job_roles WHERE id = ?', [$cur])) {
                if ($cur === $selfId) {
                    throw new DomainException('That would make the reporting line a loop.');
                }
                if (isset($seen[$cur])) {
                    break;
                }
                $seen[$cur] = true;
            }
        }
        return $reportsTo;
    }

    // ── Requirements ────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public static function requirements(int $roleId): array
    {
        return DB::all('SELECT * FROM job_role_requirements WHERE role_id = ? ORDER BY sort_order, id', [$roleId]);
    }

    public static function addRequirement(int $orgId, int $roleId, string $title, ?string $description, bool $required, Actor $actor): void
    {
        $role = self::byId($orgId, $roleId) ?? throw new DomainException('Role not found.');
        if ($p = Check::all([Check::required($title, 'What has to be checked'), Check::maxLen($title, 150, 'What has to be checked'), Check::maxLen($description, 500, 'Description')])) {
            throw DomainException::of($p);
        }
        $order = 1 + (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) FROM job_role_requirements WHERE role_id = ?', [$roleId]);
        DB::insert('job_role_requirements', ['role_id' => $roleId, 'title' => trim($title), 'description' => self::nullable($description), 'is_required' => $required ? 1 : 0, 'sort_order' => $order]);
        Audit::record($orgId, $actor, 'role.updated', 'job_role', $role['uuid'], 'Added a check to ' . $role['title'] . ': ' . trim($title));
    }

    public static function removeRequirement(int $orgId, int $roleId, int $requirementId, Actor $actor): void
    {
        $role = self::byId($orgId, $roleId) ?? throw new DomainException('Role not found.');
        $req = DB::one('SELECT * FROM job_role_requirements WHERE id = ? AND role_id = ?', [$requirementId, $roleId]);
        if ($req) {
            DB::run('DELETE FROM job_role_requirements WHERE id = ?', [$requirementId]);
            Audit::record($orgId, $actor, 'role.updated', 'job_role', $role['uuid'], 'Removed a check from ' . $role['title'] . ': ' . $req['title']);
        }
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public static function byId(int $orgId, int $id): ?array
    {
        return DB::one('SELECT * FROM job_roles WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
    }

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one('SELECT * FROM job_roles WHERE uuid = ? AND organisation_id = ?', [$uuid, $orgId]) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function all(int $orgId, bool $activeOnly = false): array
    {
        return DB::all(
            'SELECT r.*, rr.title AS reports_to_title, rr.uuid AS reports_to_uuid, t.name AS team_name, t.uuid AS team_uuid,
                    (SELECT COUNT(*) FROM vacancies v WHERE v.role_id = r.id AND v.status IN (\'draft\', \'open\')) AS live_vacancies
             FROM job_roles r LEFT JOIN job_roles rr ON rr.id = r.reports_to_role_id LEFT JOIN teams t ON t.id = r.default_team_id
             WHERE r.organisation_id = ?' . ($activeOnly ? ' AND r.active = 1' : '') . ' ORDER BY r.active DESC, r.title',
            [$orgId]
        );
    }

    private static function nullable(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private static function hours(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) && (float) $v > 0 && (float) $v <= 168 ? null : 'Hours a week must be a number between 0 and 168.';
    }
}
