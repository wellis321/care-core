<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;

/**
 * Registrations, supervisions, appraisals and learning: the records an inspector asks about. One definition per kind
 * drives the forms, the checks and the storage, so there is one place to change.
 */
final class Compliance
{
    /** field => [type, label, required]  (type: text | longtext | date | int | bool | enum:a,b,c | person) */
    public const KINDS = [
        'registration' => ['table' => 'registrations', 'title' => 'Registrations', 'date' => 'expiry_date', 'summary' => 'registration_type', 'fields' => [
            'registration_type' => ['text', 'Registration', true], 'registration_body' => ['text', 'Body (for example SSSC)', false], 'registration_number' => ['text', 'Number', false],
            'issue_date' => ['date', 'Issued', false], 'expiry_date' => ['date', 'Expires', true], 'is_required_for_role' => ['bool', 'Needed for the role', false], 'notes' => ['longtext', 'Notes', false],
        ]],
        'supervision' => ['table' => 'supervisions', 'title' => 'Supervisions', 'date' => 'next_due_date', 'summary' => 'supervision_type', 'fields' => [
            'supervision_date' => ['date', 'Date held', true], 'supervisor_person_id' => ['person', 'Supervisor', false],
            'supervision_type' => ['enum:individual,group,peer,other', 'Type', true], 'duration_minutes' => ['int', 'Minutes', false],
            'outcome' => ['longtext', 'Outcome', false], 'next_due_date' => ['date', 'Next due', false], 'notes' => ['longtext', 'Notes', false],
        ]],
        'appraisal' => ['table' => 'appraisals', 'title' => 'Appraisals', 'date' => 'next_due_date', 'summary' => 'appraisal_type', 'fields' => [
            'appraisal_date' => ['date', 'Date', true], 'appraiser_person_id' => ['person', 'Appraiser', false],
            'appraisal_type' => ['enum:annual,probationary,interim,return_to_work,other', 'Type', true],
            'outcome' => ['enum:pending,outstanding,exceeds_expectations,meets_expectations,requires_improvement,unsatisfactory,not_completed', 'Outcome', true],
            'next_due_date' => ['date', 'Next due', false], 'notes' => ['longtext', 'Notes', false],
        ]],
        'learning' => ['table' => 'learning_records', 'title' => 'Learning and qualifications', 'date' => 'expiry_date', 'summary' => 'title', 'fields' => [
            'title' => ['text', 'Title', true], 'record_type' => ['enum:training,qualification,course,certification,induction,other', 'Kind', true], 'provider' => ['text', 'Provider', false],
            'qualification_level' => ['text', 'Level', false], 'completion_date' => ['date', 'Completed', false], 'expiry_date' => ['date', 'Expires', false],
            'certificate_number' => ['text', 'Certificate number', false], 'status' => ['enum:completed,in_progress,pending,expired', 'Status', true],
            'is_mandatory' => ['bool', 'Mandatory training', false], 'notes' => ['longtext', 'Notes', false],
        ]],
    ];

    /** @return list<array<string,mixed>> */
    public static function list(int $orgId, int $personId, string $kind): array
    {
        $k = self::kind($kind);
        $order = $kind === 'registration' ? 'expiry_date' : ($kind === 'learning' ? 'COALESCE(completion_date, expiry_date)' : ($kind === 'supervision' ? 'supervision_date' : 'appraisal_date'));
        $extra = isset($k['fields']['supervisor_person_id']) ? ', (SELECT CONCAT(s.first_name, " ", s.last_name) FROM persons s WHERE s.id = t.supervisor_person_id) AS other_name'
            : (isset($k['fields']['appraiser_person_id']) ? ', (SELECT CONCAT(s.first_name, " ", s.last_name) FROM persons s WHERE s.id = t.appraiser_person_id) AS other_name' : '');
        return DB::all("SELECT t.*$extra FROM {$k['table']} t WHERE t.organisation_id = ? AND t.person_id = ? ORDER BY $order DESC, t.id DESC", [$orgId, $personId]);
    }

    /** @param array<string,mixed> $in @return array{0:array<string,mixed>,1:list<string>} */
    public static function clean(int $orgId, string $kind, array $in): array
    {
        $clean = [];
        $errors = [];
        foreach (self::kind($kind)['fields'] as $f => [$type, $label, $required]) {
            $raw = isset($in[$f]) && is_string($in[$f]) ? trim($in[$f]) : '';
            if ($type === 'bool') {
                $clean[$f] = $raw === '1' || $raw === 'on' ? 1 : 0;
                continue;
            }
            if ($raw === '') {
                if ($required) {
                    $errors[] = "$label is required.";
                }
                $clean[$f] = null;
                continue;
            }
            if ($type === 'date') {
                Clock::valid($raw) ? $clean[$f] = $raw : $errors[] = "$label is not a real date.";
            } elseif ($type === 'int') {
                ctype_digit($raw) && (int) $raw <= 1440 ? $clean[$f] = (int) $raw : $errors[] = "$label must be a whole number of minutes (up to 1440).";
            } elseif ($type === 'person') {
                $id = DB::value('SELECT id FROM persons WHERE organisation_id = ? AND uuid = ?', [$orgId, $raw]);
                $id ? $clean[$f] = (int) $id : $errors[] = "Choose $label from the list.";
            } elseif (str_starts_with($type, 'enum:')) {
                in_array($raw, explode(',', substr($type, 5)), true) ? $clean[$f] = $raw : $errors[] = "Choose $label from the list.";
            } else {
                mb_strlen($raw) <= ($type === 'longtext' ? 5000 : 190) ? $clean[$f] = $raw : $errors[] = "$label is too long.";
            }
        }
        if ($kind === 'registration' && !empty($clean['issue_date']) && !empty($clean['expiry_date']) && $clean['expiry_date'] < $clean['issue_date']) {
            $errors[] = 'A registration cannot expire before it was issued.';
        }
        if ($kind === 'learning' && !empty($clean['completion_date']) && !empty($clean['expiry_date']) && $clean['expiry_date'] < $clean['completion_date']) {
            $errors[] = 'Something cannot expire before it was completed.';
        }
        if (in_array($kind, ['supervision', 'appraisal'], true)) {
            $d = $clean[$kind === 'supervision' ? 'supervision_date' : 'appraisal_date'] ?? null;
            if ($d && !empty($clean['next_due_date']) && $clean['next_due_date'] < $d) {
                $errors[] = 'The next one cannot be due before this one.';
            }
        }
        return [$clean, $errors];
    }

    /** @param array<string,mixed> $clean */
    public static function add(int $orgId, array $person, string $kind, array $clean, Actor $actor): string
    {
        $k = self::kind($kind);
        return DB::transaction(function () use ($orgId, $person, $kind, $k, $clean, $actor) {
            $uuid = Uuid::v4();
            DB::insert($k['table'], ['uuid' => $uuid, 'organisation_id' => $orgId, 'person_id' => $person['id']] + $clean);
            Audit::record($orgId, $actor, "$kind.added", 'person', $person['uuid'], 'Added ' . $kind . ': ' . ($clean[$k['summary']] ?? ''), ['record' => $uuid]);
            Events::emit($orgId, "$kind.recorded", $person['uuid'], ['record' => $uuid, 'date' => $clean[$k['date']] ?? null]);
            return $uuid;
        });
    }

    public static function remove(int $orgId, array $person, string $kind, string $recordUuid, Actor $actor): void
    {
        $k = self::kind($kind);
        DB::transaction(function () use ($orgId, $person, $kind, $k, $recordUuid, $actor) {
            $n = DB::run("DELETE FROM {$k['table']} WHERE organisation_id = ? AND person_id = ? AND uuid = ?", [$orgId, $person['id'], $recordUuid])->rowCount();
            if (!$n) {
                throw new DomainException('That record could not be found.');
            }
            Audit::record($orgId, $actor, "$kind.removed", 'person', $person['uuid'], 'Removed ' . $kind . ' record', ['record' => $recordUuid]);
        });
    }

    /** Things due or overdue in the next N days across the organisation. @return list<array<string,mixed>> */
    public static function dueSoon(int $orgId, int $days = 60): array
    {
        $today = Clock::today();
        $until = Clock::addDays($today, $days);
        $out = [];
        $q = [
            ['registration', 'registrations', 'expiry_date', 'registration_type', 'Registration expires'],
            ['learning', 'learning_records', 'expiry_date', 'title', 'Training expires'],
            ['supervision', 'supervisions', 'next_due_date', 'supervision_type', 'Supervision due'],
            ['appraisal', 'appraisals', 'next_due_date', 'appraisal_type', 'Appraisal due'],
        ];
        foreach ($q as [$kind, $table, $col, $name, $what]) {
            // Only people who are currently employed, and only the most recent record of each kind needs attention for supervisions and appraisals.
            $latest = in_array($kind, ['supervision', 'appraisal'], true) ? " AND t.id = (SELECT MAX(t2.id) FROM $table t2 WHERE t2.person_id = t.person_id AND t2.$col IS NOT NULL)" : '';
            foreach (DB::all(
                "SELECT p.uuid AS person_uuid, p.first_name, p.last_name, t.$col AS due, t.$name AS label FROM $table t JOIN persons p ON p.id = t.person_id
                 WHERE t.organisation_id = ? AND t.$col IS NOT NULL AND t.$col <= ?$latest
                   AND EXISTS (SELECT 1 FROM employments e WHERE e.person_id = p.id AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?))",
                [$orgId, $until, $today, $today]
            ) as $r) {
                $out[] = $r + ['what' => $what, 'kind' => $kind, 'overdue' => $r['due'] < $today];
            }
        }
        usort($out, static fn($a, $b) => [$a['due'], $a['last_name']] <=> [$b['due'], $b['last_name']]);
        return $out;
    }

    /** @return array<string,mixed> */
    private static function kind(string $kind): array
    {
        return self::KINDS[$kind] ?? throw new DomainException('Unknown kind of record.');
    }
}
