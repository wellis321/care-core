<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Clock;
use App\Core\Database as DB;

/**
 * The safety net. Events and transactions keep the data consistent, but a nightly check proves it: anything that still
 * disagrees (for example a change made directly in the database, or imported) is reported, and can be put right.
 */
final class Reconcile
{
    /**
     * @return list<array{code:string,severity:string,person_id:int,person_uuid:string,person:string,detail:string,fixable:bool}>
     */
    public static function check(int $orgId, ?string $on = null): array
    {
        $on ??= Clock::today();
        $issues = [];
        $push = static function (string $code, string $sev, array $row, string $detail, bool $fixable) use (&$issues): void {
            $issues[] = ['code' => $code, 'severity' => $sev, 'person_id' => (int) $row['pid'], 'person_uuid' => $row['puuid'], 'person' => $row['first_name'] . ' ' . $row['last_name'], 'detail' => $detail, 'fixable' => $fixable];
        };

        // 1. Team places still running for someone who is no longer staff or supported.
        foreach (DB::all(
            "SELECT DISTINCT p.id AS pid, p.uuid AS puuid, p.first_name, p.last_name, t.name AS team
             FROM team_assignments a JOIN persons p ON p.id = a.person_id JOIN teams t ON t.id = a.team_id
             WHERE a.organisation_id = ? AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?)
               AND NOT EXISTS (SELECT 1 FROM employments e WHERE e.person_id = p.id AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?))
               AND NOT EXISTS (SELECT 1 FROM support_episodes s WHERE s.person_id = p.id AND s.start_date <= ? AND (s.end_date IS NULL OR s.end_date >= ?))",
            [$orgId, $on, $on, $on, $on, $on, $on]
        ) as $r) {
            $push('team_for_leaver', 'high', $r, 'Still in ' . $r['team'] . ' although they no longer work or receive support here.', true);
        }

        // 2. A login that still works for someone who has left.
        foreach (DB::all(
            "SELECT p.id AS pid, p.uuid AS puuid, p.first_name, p.last_name, u.email, MAX(e.end_date) AS last_end
             FROM users u JOIN persons p ON p.id = u.person_id JOIN employments e ON e.person_id = p.id
             WHERE u.organisation_id = ? AND u.status <> 'disabled' AND (u.access_ends_on IS NULL OR u.access_ends_on > ?)
             GROUP BY u.id, p.id, p.uuid, p.first_name, p.last_name, u.email
             HAVING SUM(e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?)) = 0 AND MAX(e.end_date) < ?",
            [$orgId, $on, $on, $on, $on]
        ) as $r) {
            $push('login_for_leaver', 'high', $r, 'The login ' . $r['email'] . ' still works, but they left on ' . date('j M Y', strtotime((string) $r['last_end'])) . '.', true);
        }

        // 3. Key worker links held by someone who is no longer staff.
        foreach (DB::all(
            "SELECT s.id AS pid, s.uuid AS puuid, s.first_name, s.last_name, f.first_name AS ff, f.last_name AS fl
             FROM key_worker_assignments k JOIN persons s ON s.id = k.supported_person_id JOIN persons f ON f.id = k.staff_person_id
             WHERE k.organisation_id = ? AND k.valid_from <= ? AND (k.valid_to IS NULL OR k.valid_to >= ?)
               AND NOT EXISTS (SELECT 1 FROM employments e WHERE e.person_id = f.id AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?))",
            [$orgId, $on, $on, $on, $on]
        ) as $r) {
            $push('keyworker_left', 'high', $r, 'Their key worker, ' . $r['ff'] . ' ' . $r['fl'] . ', is no longer staff.', true);
        }

        // 4. Key worker links for someone who is no longer receiving support.
        foreach (DB::all(
            "SELECT s.id AS pid, s.uuid AS puuid, s.first_name, s.last_name
             FROM key_worker_assignments k JOIN persons s ON s.id = k.supported_person_id
             WHERE k.organisation_id = ? AND k.valid_from <= ? AND (k.valid_to IS NULL OR k.valid_to >= ?)
               AND NOT EXISTS (SELECT 1 FROM support_episodes e WHERE e.person_id = s.id AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?))",
            [$orgId, $on, $on, $on, $on]
        ) as $r) {
            $push('keyworker_unsupported', 'medium', $r, 'Has a key worker but is no longer receiving support.', true);
        }

        // 5. Someone supported with no key worker.
        foreach (DB::all(
            "SELECT p.id AS pid, p.uuid AS puuid, p.first_name, p.last_name FROM support_episodes s JOIN persons p ON p.id = s.person_id
             WHERE s.organisation_id = ? AND s.start_date <= ? AND (s.end_date IS NULL OR s.end_date >= ?)
               AND NOT EXISTS (SELECT 1 FROM key_worker_assignments k WHERE k.supported_person_id = p.id AND k.valid_from <= ? AND (k.valid_to IS NULL OR k.valid_to >= ?))",
            [$orgId, $on, $on, $on, $on]
        ) as $r) {
            $push('no_keyworker', 'medium', $r, 'Has no key worker.', false);
        }

        // 6. Current staff in no team.
        foreach (DB::all(
            "SELECT p.id AS pid, p.uuid AS puuid, p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id
             WHERE e.organisation_id = ? AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?)
               AND NOT EXISTS (SELECT 1 FROM team_assignments a WHERE a.person_id = p.id AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?))",
            [$orgId, $on, $on, $on, $on]
        ) as $r) {
            $push('staff_no_team', 'low', $r, 'Works here but is not in any team.', false);
        }

        return $issues;
    }

    /**
     * Put right everything that can safely be put right: end the places, links and logins that should have ended.
     *
     * @return array{fixed:int}
     */
    public static function fix(int $orgId, Actor $actor, ?string $on = null): array
    {
        $on ??= Clock::today();
        $fixed = 0;
        DB::transaction(function () use ($orgId, $actor, $on, &$fixed) {
            foreach (self::check($orgId, $on) as $i) {
                if (!$i['fixable']) {
                    continue;
                }
                $pid = $i['person_id'];
                if ($i['code'] === 'team_for_leaver') {
                    $end = self::lastActiveDay($pid, $on);
                    $n = DB::run('UPDATE team_assignments SET valid_to = GREATEST(valid_from, ?), is_primary = 0 WHERE person_id = ? AND valid_to IS NULL', [$end, $pid])->rowCount();
                    Audit::record($orgId, $actor, 'reconcile.team_ended', 'person', $i['person_uuid'], 'Ended ' . $n . ' team place(s) for ' . $i['person'], ['to' => $end]);
                    $fixed += $n;
                } elseif ($i['code'] === 'login_for_leaver') {
                    $end = self::lastActiveDay($pid, $on);
                    $n = DB::run('UPDATE users SET access_ends_on = ? WHERE person_id = ? AND (access_ends_on IS NULL OR access_ends_on > ?)', [$end, $pid, $end])->rowCount();
                    Audit::record($orgId, $actor, 'reconcile.access_ended', 'person', $i['person_uuid'], 'Ended access for ' . $i['person'], ['to' => $end]);
                    $fixed += $n;
                } elseif ($i['code'] === 'keyworker_left') {
                    // End each link on the staff member's own last day.
                    $n = DB::run(
                        "UPDATE key_worker_assignments k
                         SET k.valid_to = GREATEST(k.valid_from, COALESCE((SELECT MAX(e.end_date) FROM employments e WHERE e.person_id = k.staff_person_id), ?))
                         WHERE k.supported_person_id = ? AND k.valid_to IS NULL
                           AND NOT EXISTS (SELECT 1 FROM employments e WHERE e.person_id = k.staff_person_id AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?))",
                        [$on, $pid, $on, $on]
                    )->rowCount();
                    Audit::record($orgId, $actor, 'reconcile.keyworker_ended', 'person', $i['person_uuid'], 'Ended ' . $n . ' key worker link(s) for ' . $i['person']);
                    $fixed += $n;
                } elseif ($i['code'] === 'keyworker_unsupported') {
                    $end = (string) (DB::value('SELECT MAX(end_date) FROM support_episodes WHERE person_id = ?', [$pid]) ?: $on);
                    $n = DB::run('UPDATE key_worker_assignments SET valid_to = GREATEST(valid_from, ?) WHERE supported_person_id = ? AND valid_to IS NULL', [$end, $pid])->rowCount();
                    Audit::record($orgId, $actor, 'reconcile.keyworker_ended', 'person', $i['person_uuid'], 'Ended key worker link(s) for ' . $i['person'] . ' (no longer supported)');
                    $fixed += $n;
                }
            }
        });
        return ['fixed' => $fixed];
    }

    private static function lastActiveDay(int $personId, string $fallback): string
    {
        $end = DB::value('SELECT MAX(end_date) FROM employments WHERE person_id = ?', [$personId]);
        $ep = DB::value('SELECT MAX(end_date) FROM support_episodes WHERE person_id = ?', [$personId]);
        return (string) (max((string) $end, (string) $ep) ?: $fallback);
    }
}
