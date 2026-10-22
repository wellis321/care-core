<?php
declare(strict_types=1);

namespace App\Modules\Import\Exporters;

use PDO;

/**
 * Reads the older Staff Service (people-management-service) database, read only, and describes it as an import bundle.
 * Staff become people with an employment; their profile, TUPE, working time, visa, apprenticeship and leave details,
 * registrations, supervisions, appraisals, learning and role history come with them. Refs are "staff:<people.id>".
 */
final class StaffService
{
    /** @param array{org:int,include_supported?:bool} $o @return array<string,mixed> */
    public static function export(PDO $db, array $o): array
    {
        $org = (int) $o['org'];
        $warn = [];
        $people = [];
        $q = $db->prepare("SELECT p.*, sp.* , p.id AS pid, p.created_at AS p_created, p.updated_at AS p_updated FROM people p LEFT JOIN staff_profiles sp ON sp.person_id = p.id WHERE p.organisation_id = ? ORDER BY p.id");
        $q->execute([$org]);
        $skippedSupported = 0;
        $assumedEnd = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (($r['person_type'] ?? 'staff') !== 'staff') {
                $skippedSupported++;
                continue;
            }
            $id = (int) $r['pid'];
            $ref = 'staff:' . $id;
            $start = self::d($r['employment_start_date'] ?? null);
            if ($start === null) {
                $start = self::d($r['p_created']);
            }
            $end = self::d($r['employment_end_date'] ?? null);
            if ($end === null && isset($r['is_active']) && !(int) $r['is_active']) {
                $end = self::d($r['p_updated']) ?? $start;
                $assumedEnd++;
            }
            $emp = [
                'ref' => 'staffemp:' . $id, 'employee_ref' => $r['employee_reference'], 'job_title' => $r['job_title'], 'contract_type' => $r['contract_type'] ?: 'permanent',
                'hours_per_week' => $r['contracted_hours'], 'start_date' => $start, 'end_date' => $end,
                'line_manager_ref' => !empty($r['line_manager_id']) ? 'staff:' . (int) $r['line_manager_id'] : null,
                'details' => [
                    'terms' => ['place_of_work' => $r['place_of_work'] ?? null, 'is_bank_staff' => (bool) ($r['is_bank_staff'] ?? 0), 'external_job_description_url' => self::url($r['external_job_description_url'] ?? null), 'external_job_description_ref' => $r['external_job_description_ref'] ?? null, 'notes' => $r['notes'] ?? null],
                    'tupe' => self::pick($r, ['is_tupe', 'tupe_transfer_date', 'tupe_previous_organisation', 'tupe_previous_employer_ref', 'tupe_contract_type', 'tupe_hours_per_week', 'tupe_salary', 'tupe_notes'], ['is_tupe']),
                    'wtd' => self::pick($r, ['wtd_agreed', 'wtd_agreement_date', 'wtd_agreement_version', 'wtd_opt_out', 'wtd_opt_out_date', 'wtd_opt_out_expiry_date', 'wtd_notes'], ['wtd_agreed', 'wtd_opt_out']),
                    'apprentice' => self::pick($r, ['is_apprentice', 'apprenticeship_start_date', 'apprenticeship_end_date', 'apprenticeship_level', 'apprenticeship_provider'], ['is_apprentice']),
                    'visa' => self::pick($r, ['has_visa', 'visa_type', 'visa_number', 'visa_issue_date', 'visa_expiry_date', 'visa_sponsor'], ['has_visa']),
                    'leave' => self::pick($r, ['leave_year_start_date', 'leave_year_end_date', 'annual_leave_allocation', 'annual_leave_used', 'annual_leave_carry_over', 'time_in_lieu_hours', 'time_in_lieu_used', 'lying_time_hours', 'lying_time_used'], []),
                ],
                'changes' => [],
            ];
            if (empty($r['employment_start_date'])) {
                $warn[] = "staff:$id had no employment start date; their record's creation date ($start) was used.";
            }
            $people[$id] = [
                'ref' => $ref, 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'email' => $r['email'], 'phone' => $r['phone'], 'date_of_birth' => self::d($r['date_of_birth']),
                'profile' => array_filter([
                    'address_line1' => $r['address_line1'] ?? null, 'address_line2' => $r['address_line2'] ?? null, 'address_city' => $r['address_city'] ?? null, 'address_county' => $r['address_county'] ?? null,
                    'address_postcode' => $r['address_postcode'] ?? null, 'address_country' => $r['address_country'] ?? null, 'emergency_contact_name' => $r['emergency_contact_name'] ?? null, 'emergency_contact_phone' => $r['emergency_contact_phone'] ?? null,
                    'ni_number' => $r['ni_number'] ?? null, 'bank_sort_code' => $r['bank_sort_code'] ?? null, 'bank_account_number' => $r['bank_account_number'] ?? null, 'bank_account_name' => $r['bank_account_name'] ?? null,
                ], static fn($v) => $v !== null && $v !== ''),
                'photo_file' => ($r['photo_approval_status'] ?? 'approved') === 'approved' ? ($r['photo_path'] ?? null) : null,
                'signature_file' => $r['signature_path'] ?? null, 'signature_method' => ($r['signature_method'] ?? '') === 'digital' ? 'draw' : 'upload',
                'employments' => [$emp], 'registrations' => [], 'supervisions' => [], 'appraisals' => [], 'learning' => [],
            ];
        }
        $ids = array_keys($people);
        if ($ids) {
            foreach (self::rows($db, 'staff_registrations', $org) as $r) {
                if (isset($people[(int) $r['person_id']])) {
                    $people[(int) $r['person_id']]['registrations'][] = ['registration_type' => $r['registration_type'], 'registration_body' => $r['registration_body'], 'registration_number' => $r['registration_number'], 'issue_date' => self::d($r['issue_date']), 'expiry_date' => self::d($r['expiry_date']), 'is_required_for_role' => (bool) $r['is_required_for_role'], 'notes' => $r['notes']];
                }
            }
            foreach (self::rows($db, 'staff_supervisions', $org) as $r) {
                if (isset($people[(int) $r['person_id']])) {
                    $people[(int) $r['person_id']]['supervisions'][] = ['supervision_date' => self::d($r['supervision_date']), 'supervisor_ref' => !empty($r['supervisor_person_id']) ? 'staff:' . (int) $r['supervisor_person_id'] : null, 'supervision_type' => $r['supervision_type'], 'duration_minutes' => $r['duration_minutes'] !== null ? (int) $r['duration_minutes'] : null, 'outcome' => $r['outcome'], 'next_due_date' => self::d($r['next_due_date']), 'notes' => $r['notes']];
                }
            }
            foreach (self::rows($db, 'staff_appraisals', $org) as $r) {
                if (isset($people[(int) $r['person_id']])) {
                    $people[(int) $r['person_id']]['appraisals'][] = ['appraisal_date' => self::d($r['appraisal_date']), 'appraiser_ref' => !empty($r['appraiser_person_id']) ? 'staff:' . (int) $r['appraiser_person_id'] : null, 'appraisal_type' => $r['appraisal_type'], 'outcome' => $r['outcome'], 'next_due_date' => self::d($r['next_due_date']), 'notes' => $r['notes']];
                }
            }
            foreach (self::rows($db, 'staff_learning_records', $org) as $r) {
                if (isset($people[(int) $r['person_id']])) {
                    $type = in_array($r['record_type'], ['training', 'qualification', 'course', 'certification', 'induction'], true) ? $r['record_type'] : 'other';
                    $status = in_array($r['status'], ['completed', 'in_progress', 'pending', 'expired'], true) ? $r['status'] : 'completed';
                    $people[(int) $r['person_id']]['learning'][] = ['title' => $r['title'], 'record_type' => $type, 'provider' => $r['provider'], 'qualification_level' => $r['qualification_level'], 'completion_date' => self::d($r['completion_date']), 'expiry_date' => self::d($r['expiry_date']), 'certificate_number' => $r['certificate_number'], 'status' => $status, 'is_mandatory' => (bool) $r['is_mandatory'], 'notes' => $r['notes']];
                }
            }
            // Role history becomes the history of changes to the employment.
            $hist = [];
            foreach (self::rows($db, 'staff_role_history', $org, 'start_date, id') as $r) {
                $hist[(int) $r['person_id']][] = $r;
            }
            foreach ($hist as $pid => $rows) {
                if (!isset($people[$pid]) || count($rows) < 2) {
                    continue;
                }
                $changes = [];
                for ($i = 1; $i < count($rows); $i++) {
                    $a = $rows[$i - 1];
                    $b = $rows[$i];
                    $eff = self::d($b['start_date']);
                    if ($eff === null) {
                        continue;
                    }
                    foreach (['job_title' => 'job_title', 'hours_per_week' => 'hours_per_week', 'contract_type' => 'contract_type'] as $col => $field) {
                        if (($a[$col] ?? null) != ($b[$col] ?? null) && ($b[$col] ?? '') !== '') {
                            $changes[] = ['effective_date' => $eff, 'field' => $field, 'from' => $a[$col], 'to' => $b[$col]];
                        }
                    }
                }
                $people[$pid]['employments'][0]['changes'] = $changes;
            }
        }
        $bundle = ['format' => 'carecore-import', 'version' => 1, 'source' => 'Staff Service export ' . gmdate('Y-m-d'), 'teams' => [], 'people' => array_values($people), 'team_places' => [], 'key_workers' => []];
        if ($assumedEnd) {
            $warn[] = "$assumedEnd staff are marked inactive with no end date; the date their record was last changed was used as their last day. Check these.";
        }
        if ($skippedSupported) {
            $warn[] = "$skippedSupported people we support held in the Staff Service were not exported: import them from the People Service export instead.";
        }
        $bundle['_notes'] = $warn;
        return $bundle;
    }

    /** @param array<string,mixed> $r @param list<string> $cols @param list<string> $bools @return array<string,mixed> */
    private static function pick(array $r, array $cols, array $bools): array
    {
        $out = [];
        foreach ($cols as $c) {
            $v = $r[$c] ?? null;
            if (in_array($c, $bools, true)) {
                $out[$c] = (bool) (int) $v;
            } elseif (str_contains($c, 'date')) {
                $out[$c] = self::d($v);
            } else {
                $out[$c] = $v === '' ? null : $v;
            }
        }
        return $out;
    }

    private static function d(mixed $v): ?string
    {
        $s = $v === null ? '' : substr((string) $v, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && $s !== '0000-00-00' ? $s : null;
    }

    private static function url(mixed $v): ?string
    {
        return is_string($v) && preg_match('#^https?://\S+$#i', $v) ? $v : null;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(PDO $db, string $table, int $org, string $order = 'id'): array
    {
        try {
            $q = $db->prepare("SELECT * FROM `$table` WHERE organisation_id = ? ORDER BY $order");
            $q->execute([$org]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];   // an older installation without that table
        }
    }
}
