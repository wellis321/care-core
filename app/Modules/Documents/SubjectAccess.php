<?php
declare(strict_types=1);

namespace App\Modules\Documents;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Modules\Leave\LeaveRepo;
use App\Modules\Staff\Checklists;
use App\Modules\Staff\Compliance;
use App\Modules\Staff\StaffRecord;

/**
 * Everything Care Core holds about one member of staff, as a single file, for a subject access request. It lists the
 * documents by name and type (the files themselves are downloaded one by one from the staff record). It says what is
 * NOT included, so nobody assumes it is the whole of what the organisation holds elsewhere.
 */
final class SubjectAccess
{
    /** @return array<string,mixed> */
    public static function build(int $orgId, int $personId, Actor $actor): array
    {
        $person = DB::one('SELECT * FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId]) ?? throw new DomainException('Person not found.');
        $employments = DB::all('SELECT * FROM employments WHERE person_id = ? ORDER BY start_date', [$personId]);
        $out = [
            'generated_on' => Clock::today(),
            'about' => $person['first_name'] . ' ' . $person['last_name'],
            'person' => self::plain($person),
            'profile' => array_filter(StaffRecord::profile($personId), static fn($v, $k) => !in_array($k, ['photo_path', 'signature_path'], true) && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH),
            'employments' => array_map(static fn($e) => self::plain($e) + ['details' => self::plain(StaffRecord::details((int) $e['id'])), 'changes' => array_map([self::class, 'plain'], DB::all('SELECT * FROM employment_changes WHERE employment_id = ? ORDER BY effective_date', [$e['id']]))], $employments),
            'team_places' => array_map([self::class, 'plain'], DB::all('SELECT t.name AS team, a.role, a.valid_from, a.valid_to FROM team_assignments a JOIN teams t ON t.id = a.team_id WHERE a.person_id = ? ORDER BY a.valid_from', [$personId])),
            'records' => [],
            'leave_and_absence' => array_map([self::class, 'plain'], DB::all('SELECT kind, starts_on, ends_on, start_half, end_half, days, status, reason, decision_note, rtw_done_on FROM absences WHERE person_id = ? ORDER BY starts_on', [$personId])),
            'leave_allowances' => array_map([self::class, 'plain'], DB::all('SELECT year_start, days, note FROM leave_allowances WHERE person_id = ? ORDER BY year_start', [$personId])),
            'tasks' => array_map(static fn($t) => ['title' => $t['title'], 'status' => $t['status'], 'due' => $t['due_date']], Checklists::forPerson($personId)),
            'documents' => array_map(static fn($d) => ['title' => $d['title'], 'category' => $d['category'], 'file' => $d['original_name'], 'size_bytes' => (int) $d['size_bytes'], 'expires_on' => $d['expires_on'], 'added' => $d['created_at']], DocumentRepo::forPerson($personId)),
            'activity_log' => array_map([self::class, 'plain'], DB::all('SELECT created_at, action, summary FROM audit_log WHERE organisation_id = ? AND entity_uuid = ? ORDER BY id', [$orgId, $person['uuid']])),
            'not_included' => [
                'The document files themselves: download them from the staff record.',
                'Anything held outside Care Core (payroll, email, paper files, other products).',
                'Information about other people that appears in your records, which may need to be left out before sharing.',
            ],
        ];
        foreach (array_keys(Compliance::KINDS) as $k) {
            $out['records'][$k] = array_map([self::class, 'plain'], Compliance::list($orgId, $personId, $k));
        }
        Audit::record($orgId, $actor, 'person.subject_access', 'person', $person['uuid'], 'Subject access export made for ' . $person['first_name'] . ' ' . $person['last_name']);
        return $out;
    }

    /** Drop internal keys and nulls, so the file is about the person, not our tables. @param array<string,mixed> $row @return array<string,mixed> */
    private static function plain(array $row): array
    {
        $drop = ['id', 'organisation_id', 'person_id', 'employment_id', 'uuid', 'created_at', 'updated_at', 'line_manager_person_id', 'supervisor_person_id', 'appraiser_person_id', 'added_by_user_id'];
        $out = [];
        foreach ($row as $k => $v) {
            if (!in_array($k, $drop, true) && $v !== null && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
