<?php
declare(strict_types=1);

namespace App\Modules\Import\Exporters;

use PDO;

/**
 * Reads the older Team Service database and describes it as an import bundle: the team hierarchy (team types become the
 * team's kind, "staff only" types become staff-only teams) and every membership stint with its dates and role. Members
 * point at the other services' people: "staff:<id>" and "people:<id>".
 */
final class TeamService
{
    /** @param array{org:int} $o @return array<string,mixed> */
    public static function export(PDO $db, array $o): array
    {
        $org = (int) $o['org'];
        $teams = [];
        $q = $db->prepare('SELECT t.*, ty.name AS type_name, ty.is_staff_only FROM teams t LEFT JOIN team_types ty ON ty.id = t.team_type_id WHERE t.organisation_id = ? ORDER BY t.id');
        $q->execute([$org]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $teams[] = ['ref' => 'team:' . (int) $t['id'], 'name' => $t['name'], 'parent_ref' => !empty($t['parent_team_id']) ? 'team:' . (int) $t['parent_team_id'] : null, 'kind' => $t['type_name'] ? strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', trim((string) $t['type_name'])) ?? 'care') : 'care', 'staff_only' => (bool) ($t['is_staff_only'] ?? 0), 'active' => (bool) $t['is_active']];
        }
        $roles = [];
        try {
            $r = $db->prepare('SELECT id, name FROM team_roles WHERE organisation_id = ?');
            $r->execute([$org]);
            foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $roles[(int) $x['id']] = $x['name'];
            }
        } catch (\PDOException) {
        }
        $places = [];
        $skipped = 0;
        $q = $db->prepare('SELECT * FROM team_members WHERE organisation_id = ? ORDER BY id');
        $q->execute([$org]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $prefix = ['staff' => 'staff', 'person' => 'people'][$m['member_type']] ?? null;
            if ($prefix === null) {
                $skipped++;   // 'user' members are logins, not people
                continue;
            }
            $places[] = [
                'ref' => 'teammember:' . (int) $m['id'], 'team_ref' => 'team:' . (int) $m['team_id'], 'person_ref' => $prefix . ':' . (int) $m['external_id'],
                'role' => !empty($m['team_role_id']) ? ($roles[(int) $m['team_role_id']] ?? null) : null, 'primary' => (bool) $m['is_primary_team'],
                'start_date' => self::d($m['joined_at']) ?? self::d($m['created_at']), 'end_date' => self::d($m['left_at']),
            ];
        }
        $notes = $skipped ? ["$skipped team memberships for logins (not people) were not exported."] : [];
        return ['format' => 'carecore-import', 'version' => 1, 'source' => 'Team Service export ' . gmdate('Y-m-d'), 'teams' => $teams, 'people' => [], 'team_places' => $places, 'key_workers' => [], '_notes' => $notes];
    }

    private static function d(mixed $v): ?string
    {
        $s = $v === null ? '' : substr((string) $v, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && $s !== '0000-00-00' ? $s : null;
    }
}
