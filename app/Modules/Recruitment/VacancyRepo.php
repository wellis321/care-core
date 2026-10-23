<?php
declare(strict_types=1);

namespace App\Modules\Recruitment;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;
use App\Modules\Staff\Lifecycle;

/**
 * A vacancy: a number of posts to fill in a job role, in a team, reporting to a line manager. It is draft until it is
 * opened, open while candidates can apply, filled when enough people have been hired, or closed without filling.
 */
final class VacancyRepo
{
    /** @param array<string,mixed> $d role_id (internal) plus optional overrides @return list<string> */
    public static function problems(array $d): array
    {
        $posts = $d['posts'] ?? 1;
        return Check::all([
            Check::maxLen($d['title'] ?? null, 150, 'Title'),
            (is_numeric($posts) && (int) $posts >= 1 && (int) $posts <= 500 && (string) (int) $posts === (string) $posts) ? null : 'The number of posts must be a whole number from 1 to 500.',
            Check::oneOf(($d['contract_type'] ?? '') ?: 'permanent', Lifecycle::CONTRACTS, 'a contract type'),
            Check::date($d['closing_date'] ?? null, 'Closing date'),
            Check::maxLen($d['notes'] ?? null, 5000, 'Notes'),
            (($d['hours_per_week'] ?? '') === '' || $d['hours_per_week'] === null || (is_numeric($d['hours_per_week']) && (float) $d['hours_per_week'] > 0 && (float) $d['hours_per_week'] <= 168)) ? null : 'Hours a week must be a number between 0 and 168.',
        ]);
    }

    /**
     * Start a vacancy from a role: the role's title, team, contract and hours are the defaults.
     *
     * @param array<string,mixed> $d role_id; title, team_id, line_manager_person_id, posts, contract_type, hours_per_week, closing_date, notes
     * @return array{id:int,uuid:string,reference:string}
     */
    public static function create(int $orgId, array $d, Actor $actor): array
    {
        $role = RoleRepo::byId($orgId, (int) ($d['role_id'] ?? 0)) ?? throw new DomainException('Choose a role for the vacancy.');
        if (!(int) $role['active']) {
            throw new DomainException('That role has been retired.');
        }
        $d += ['title' => $role['title'], 'contract_type' => $role['contract_type'], 'hours_per_week' => $role['hours_per_week']];
        $d['title'] = trim((string) $d['title']) !== '' ? $d['title'] : $role['title'];
        if (($d['team_id'] ?? '') === '' || !array_key_exists('team_id', $d)) {
            $d['team_id'] = $role['default_team_id'];
        }
        if ($p = self::problems($d)) {
            throw DomainException::of($p);
        }
        $teamId = self::team($orgId, $d['team_id'] ?? null);
        $managerId = self::manager($orgId, $d['line_manager_person_id'] ?? null);

        return DB::transaction(function () use ($orgId, $d, $actor, $role, $teamId, $managerId) {
            $uuid = Uuid::v4();
            $n = 1 + (int) DB::value("SELECT COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) FROM vacancies WHERE organisation_id = ? AND reference LIKE 'VAC-%'", [$orgId]);
            $ref = sprintf('VAC-%04d', $n);
            $id = DB::insert('vacancies', [
                'uuid' => $uuid, 'organisation_id' => $orgId, 'reference' => $ref, 'role_id' => $role['id'], 'title' => trim($d['title']),
                'team_id' => $teamId, 'line_manager_person_id' => $managerId, 'posts' => (int) ($d['posts'] ?? 1), 'contract_type' => $d['contract_type'],
                'hours_per_week' => ($d['hours_per_week'] ?? '') !== '' && $d['hours_per_week'] !== null ? (float) $d['hours_per_week'] : null,
                'closing_date' => ($d['closing_date'] ?? '') ?: null, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
            ]);
            Audit::record($orgId, $actor, 'vacancy.created', 'vacancy', $uuid, "Started vacancy $ref: " . trim($d['title']));
            Events::emit($orgId, 'vacancy.created', $uuid, ['reference' => $ref, 'role' => $role['uuid']]);
            return ['id' => $id, 'uuid' => $uuid, 'reference' => $ref];
        });
    }

    /** @param array<string,mixed> $d only the keys present are changed */
    public static function update(int $orgId, int $vacancyId, array $d, Actor $actor): void
    {
        $v = self::byId($orgId, $vacancyId) ?? throw new DomainException('Vacancy not found.');
        if (in_array($v['status'], ['filled', 'closed'], true)) {
            throw new DomainException('A ' . $v['status'] . ' vacancy cannot be changed. Reopen it first.');
        }
        $merged = array_intersect_key($d, array_flip(['title', 'team_id', 'line_manager_person_id', 'posts', 'contract_type', 'hours_per_week', 'closing_date', 'notes'])) + array_intersect_key($v, array_flip(['title', 'team_id', 'line_manager_person_id', 'posts', 'contract_type', 'hours_per_week', 'closing_date', 'notes']));
        if ($p = self::problems($merged)) {
            throw DomainException::of($p);
        }
        $set = [];
        foreach ($d as $k => $val) {
            switch ($k) {
                case 'title':
                    $set['title'] = trim((string) $val) !== '' ? trim((string) $val) : $v['title'];
                    break;
                case 'team_id':
                    $set['team_id'] = self::team($orgId, $val);
                    break;
                case 'line_manager_person_id':
                    $set['line_manager_person_id'] = self::manager($orgId, $val);
                    break;
                case 'posts':
                    if ((int) $val < self::hired((int) $v['id'])) {
                        throw new DomainException('Fewer posts than people already hired.');
                    }
                    $set['posts'] = (int) $val;
                    break;
                case 'contract_type':
                    $set['contract_type'] = $val ?: 'permanent';
                    break;
                case 'hours_per_week':
                    $set['hours_per_week'] = ($val === null || $val === '') ? null : (float) $val;
                    break;
                case 'closing_date':
                    $set['closing_date'] = $val ?: null;
                    break;
                case 'notes':
                    $set['notes'] = trim((string) $val) ?: null;
                    break;
            }
        }
        if ($set) {
            DB::update('vacancies', $set, ['id' => $vacancyId]);
            Audit::record($orgId, $actor, 'vacancy.updated', 'vacancy', $v['uuid'], 'Updated vacancy ' . $v['reference']);
        }
    }

    public static function open(int $orgId, int $vacancyId, Actor $actor): void
    {
        $v = self::byId($orgId, $vacancyId) ?? throw new DomainException('Vacancy not found.');
        if ($v['status'] === 'open') {
            return;
        }
        if ($v['status'] === 'filled') {
            throw new DomainException('All the posts are filled. Add a post to reopen it.');
        }
        DB::transaction(function () use ($orgId, $v, $actor) {
            DB::update('vacancies', ['status' => 'open', 'opened_on' => $v['opened_on'] ?: Clock::today(), 'closed_on' => null], ['id' => $v['id']]);
            Audit::record($orgId, $actor, 'vacancy.opened', 'vacancy', $v['uuid'], 'Opened vacancy ' . $v['reference'] . ': ' . $v['title']);
            Events::emit($orgId, 'vacancy.opened', $v['uuid'], ['reference' => $v['reference']]);
        });
    }

    /** Close without filling. Optionally let the remaining candidates know their application is not going ahead. */
    public static function close(int $orgId, int $vacancyId, bool $rejectRemaining, Actor $actor): int
    {
        $v = self::byId($orgId, $vacancyId) ?? throw new DomainException('Vacancy not found.');
        if (in_array($v['status'], ['closed', 'filled'], true)) {
            return 0;
        }
        return DB::transaction(function () use ($orgId, $v, $rejectRemaining, $actor) {
            DB::update('vacancies', ['status' => 'closed', 'closed_on' => Clock::today()], ['id' => $v['id']]);
            $n = $rejectRemaining ? ApplicationRepo::closeRemaining($orgId, (int) $v['id'], 'The vacancy closed', $actor) : 0;
            Audit::record($orgId, $actor, 'vacancy.closed', 'vacancy', $v['uuid'], 'Closed vacancy ' . $v['reference'] . ($n ? " and closed $n open application" . ($n === 1 ? '' : 's') : ''));
            Events::emit($orgId, 'vacancy.closed', $v['uuid'], ['reference' => $v['reference']]);
            return $n;
        });
    }

    /** Called after a hire: the vacancy is filled once enough people have been hired. Returns true if it just became filled. */
    public static function checkFilled(int $orgId, int $vacancyId, Actor $actor): bool
    {
        $v = self::byId($orgId, $vacancyId);
        if (!$v || $v['status'] === 'filled' || self::hired($vacancyId) < (int) $v['posts']) {
            return false;
        }
        DB::update('vacancies', ['status' => 'filled', 'closed_on' => Clock::today()], ['id' => $vacancyId]);
        Audit::record($orgId, $actor, 'vacancy.filled', 'vacancy', $v['uuid'], 'Vacancy ' . $v['reference'] . ' is filled');
        Events::emit($orgId, 'vacancy.filled', $v['uuid'], ['reference' => $v['reference']]);
        return true;
    }

    public static function hired(int $vacancyId): int
    {
        return (int) DB::value("SELECT COUNT(*) FROM applications WHERE vacancy_id = ? AND stage = 'hired'", [$vacancyId]);
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public static function byId(int $orgId, int $id): ?array
    {
        return DB::one('SELECT * FROM vacancies WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
    }

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one('SELECT * FROM vacancies WHERE uuid = ? AND organisation_id = ?', [$uuid, $orgId]) : null;
    }

    /** With the names behind the ids and how many candidates are where. @return list<array<string,mixed>> */
    public static function all(int $orgId, ?string $status = null): array
    {
        $sql = "SELECT v.*, r.title AS role_title, r.uuid AS role_uuid, t.name AS team_name, t.uuid AS team_uuid,
                       CONCAT(m.first_name, ' ', m.last_name) AS manager_name, m.uuid AS manager_uuid,
                       (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id) AS applications,
                       (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id AND a.stage IN ('applied','shortlisted','interview','offer')) AS in_progress,
                       (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id AND a.stage = 'hired') AS hired
                FROM vacancies v JOIN job_roles r ON r.id = v.role_id LEFT JOIN teams t ON t.id = v.team_id LEFT JOIN persons m ON m.id = v.line_manager_person_id
                WHERE v.organisation_id = ?";
        $params = [$orgId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND v.status = ?';
            $params[] = $status;
        }
        return DB::all($sql . " ORDER BY FIELD(v.status, 'open', 'draft', 'filled', 'closed'), v.id DESC", $params);
    }

    private static function team(int $orgId, mixed $t): ?int
    {
        if ($t === null || $t === '') {
            return null;
        }
        if (!DB::value('SELECT 1 FROM teams WHERE id = ? AND organisation_id = ?', [(int) $t, $orgId])) {
            throw new DomainException('Choose a team from the list.');
        }
        return (int) $t;
    }

    private static function manager(int $orgId, mixed $m): ?int
    {
        if ($m === null || $m === '') {
            return null;
        }
        if (!DB::value('SELECT 1 FROM persons WHERE id = ? AND organisation_id = ?', [(int) $m, $orgId])) {
            throw new DomainException('Choose a line manager from the list.');
        }
        return (int) $m;
    }
}
