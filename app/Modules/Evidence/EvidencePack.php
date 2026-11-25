<?php
declare(strict_types=1);

namespace App\Modules\Evidence;

use App\Core\Clock;
use App\Core\Database as DB;

/**
 * The staff records an inspector or a commissioner asks to see, as held on a given date.
 *
 * It reports what is RECORDED: registrations and their expiry dates, the latest supervision and appraisal and when the
 * next is recorded as due, and mandatory training with its expiry. It never rates, scores or says anyone is compliant:
 * the standard to meet (how often supervision should happen, what training is required) is the organisation's own, so the
 * only "gaps" listed are dates that were recorded and have passed, or a record marked expired. Nothing is invented.
 */
final class EvidencePack
{
    /** Registrations expiring within this many days are flagged as "expires soon" (the same window as the due-soon list). */
    public const SOON_DAYS = 60;

    /**
     * @return array{as_at:string,team:?array,staff:list<array<string,mixed>>,gaps:list<array<string,string>>,summary:array<string,int>}
     */
    public static function build(int $orgId, ?int $teamId, string $asAt): array
    {
        $team = $teamId ? DB::one('SELECT id, uuid, name FROM teams WHERE id = ? AND organisation_id = ?', [$teamId, $orgId]) : null;
        $rows = DB::all(
            "SELECT p.id, p.uuid, p.first_name, p.last_name, e.job_title, e.contract_type, e.start_date
             FROM persons p JOIN employments e ON e.person_id = p.id AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?)
             WHERE p.organisation_id = ?" . ($team ? ' AND EXISTS (SELECT 1 FROM team_assignments ta WHERE ta.person_id = p.id AND ta.team_id = ? AND ta.valid_from <= ? AND (ta.valid_to IS NULL OR ta.valid_to >= ?))' : '') . ' ORDER BY p.last_name, p.first_name',
            $team ? [$asAt, $asAt, $orgId, $team['id'], $asAt, $asAt] : [$asAt, $asAt, $orgId]
        );
        $soon = Clock::addDays($asAt, self::SOON_DAYS);
        $year = Clock::addDays($asAt, -365);
        $staff = []; $gaps = [];
        $sum = ['staff' => 0, 'reg_in_date' => 0, 'reg_expired' => 0, 'reg_none' => 0, 'train_current' => 0, 'train_expired' => 0, 'sup_overdue' => 0, 'apr_overdue' => 0];
        foreach ($rows as $p) {
            $pid = (int) $p['id']; $name = $p['first_name'] . ' ' . $p['last_name'];
            $teams = array_column(DB::all('SELECT t.name FROM team_assignments ta JOIN teams t ON t.id = ta.team_id WHERE ta.person_id = ? AND t.organisation_id = ? AND ta.valid_from <= ? AND (ta.valid_to IS NULL OR ta.valid_to >= ?) ORDER BY t.name', [$pid, $orgId, $asAt, $asAt]), 'name');
            // registrations: the latest of each type
            $regs = [];
            foreach (DB::all('SELECT * FROM registrations WHERE person_id = ? AND (issue_date IS NULL OR issue_date <= ?) ORDER BY expiry_date DESC, id DESC', [$pid, $asAt]) as $r) {
                $k = strtolower($r['registration_type']);
                if (isset($regs[$k])) continue;
                $r['state'] = $r['expiry_date'] < $asAt ? 'expired' : ($r['expiry_date'] <= $soon ? 'soon' : 'in_date');
                $regs[$k] = $r;
            }
            // supervision and appraisal: the latest on or before the date
            $sup = DB::one('SELECT supervision_date, supervision_type, next_due_date FROM supervisions WHERE person_id = ? AND supervision_date <= ? ORDER BY supervision_date DESC, id DESC LIMIT 1', [$pid, $asAt]);
            $supCount = (int) DB::value('SELECT COUNT(*) FROM supervisions WHERE person_id = ? AND supervision_date > ? AND supervision_date <= ?', [$pid, $year, $asAt]);
            $apr = DB::one('SELECT appraisal_date, appraisal_type, next_due_date FROM appraisals WHERE person_id = ? AND appraisal_date <= ? ORDER BY appraisal_date DESC, id DESC LIMIT 1', [$pid, $asAt]);
            // mandatory training: the latest record of each title
            $train = [];
            foreach (DB::all('SELECT * FROM learning_records WHERE person_id = ? AND is_mandatory = 1 AND (completion_date IS NULL OR completion_date <= ?) ORDER BY COALESCE(completion_date, created_at) DESC, id DESC', [$pid, $asAt]) as $l) {
                $k = strtolower($l['title']);
                if (isset($train[$k])) continue;
                $l['state'] = $l['status'] === 'completed' || $l['status'] === 'expired'
                    ? (($l['status'] === 'expired' || ($l['expiry_date'] !== null && $l['expiry_date'] < $asAt)) ? 'expired' : 'current')
                    : 'not_done';
                $train[$k] = $l;
            }
            $supDue = $sup && $sup['next_due_date'] !== null && $sup['next_due_date'] < $asAt;
            $aprDue = $apr && $apr['next_due_date'] !== null && $apr['next_due_date'] < $asAt;
            $sum['staff']++;
            if (!$regs) $sum['reg_none']++;
            foreach ($regs as $r) {
                if ($r['state'] === 'expired') { $sum['reg_expired']++; $gaps[] = ['person' => $name, 'uuid' => $p['uuid'], 'what' => $r['registration_type'] . ' registration expired', 'date' => $r['expiry_date']]; } else { $sum['reg_in_date']++; }
            }
            foreach ($train as $t) {
                if ($t['state'] === 'current') { $sum['train_current']++; } elseif ($t['state'] === 'expired') { $sum['train_expired']++; $gaps[] = ['person' => $name, 'uuid' => $p['uuid'], 'what' => $t['title'] . ' (mandatory) expired', 'date' => (string) $t['expiry_date']]; }
            }
            if ($supDue) { $sum['sup_overdue']++; $gaps[] = ['person' => $name, 'uuid' => $p['uuid'], 'what' => 'Supervision recorded as due and not since recorded', 'date' => $sup['next_due_date']]; }
            if ($aprDue) { $sum['apr_overdue']++; $gaps[] = ['person' => $name, 'uuid' => $p['uuid'], 'what' => 'Appraisal recorded as due and not since recorded', 'date' => $apr['next_due_date']]; }
            $staff[] = $p + ['teams' => $teams, 'regs' => array_values($regs), 'sup' => $sup ?: null, 'sup_count' => $supCount, 'sup_overdue' => $supDue, 'apr' => $apr ?: null, 'apr_overdue' => $aprDue, 'train' => array_values($train)];
        }
        usort($gaps, static fn($a, $b) => [$a['date'], $a['person']] <=> [$b['date'], $b['person']]);
        return ['as_at' => $asAt, 'team' => $team ?: null, 'staff' => $staff, 'gaps' => $gaps, 'summary' => $sum];
    }

    /** One row per person for a spreadsheet. @return list<list<string>> */
    public static function csv(array $pack): array
    {
        $out = [['Name', 'Job title', 'Teams', 'Started', 'Registrations (type, number, expires, state)', 'Last supervision', 'Supervisions in the 12 months', 'Next supervision recorded as due', 'Last appraisal', 'Next appraisal recorded as due', 'Mandatory training (title, expires, state)']];
        $states = ['in_date' => 'in date', 'soon' => 'expires soon', 'expired' => 'EXPIRED', 'current' => 'current', 'not_done' => 'not completed'];
        foreach ($pack['staff'] as $s) {
            $out[] = [
                $s['first_name'] . ' ' . $s['last_name'], $s['job_title'], implode('; ', $s['teams']), $s['start_date'],
                implode(' | ', array_map(static fn($r) => $r['registration_type'] . ', ' . ($r['registration_number'] ?: 'no number') . ', ' . $r['expiry_date'] . ', ' . $states[$r['state']], $s['regs'])) ?: 'None recorded',
                $s['sup']['supervision_date'] ?? 'None recorded', (string) $s['sup_count'], $s['sup']['next_due_date'] ?? '',
                $s['apr']['appraisal_date'] ?? 'None recorded', $s['apr']['next_due_date'] ?? '',
                implode(' | ', array_map(static fn($t) => $t['title'] . ', ' . ($t['expiry_date'] ?: 'no expiry') . ', ' . $states[$t['state']], $s['train'])) ?: 'None recorded',
            ];
        }
        return $out;
    }
}
