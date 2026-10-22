<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;

/**
 * Everything a staff record holds beyond the basics: the personal profile (address, emergency contact, NI, bank,
 * photo, signature) and the terms of an employment (TUPE, working time, visa, apprenticeship, leave).
 *
 * One registry says which fields exist, how each is checked, which are encrypted, and which a member of staff may
 * change themselves (and whether their line manager must approve it).
 */
final class StaffRecord
{
    /** Personal fields. table = persons | person_profiles. approval: null = managers only, true = staff may ask, false = staff may change. */
    public const FIELDS = [
        'first_name'              => ['table' => 'persons',         'label' => 'First name',                'type' => 'text', 'self' => 'approval'],
        'last_name'               => ['table' => 'persons',         'label' => 'Last name',                 'type' => 'text', 'self' => 'approval'],
        'date_of_birth'           => ['table' => 'persons',         'label' => 'Date of birth',             'type' => 'date', 'self' => 'approval'],
        'phone'                   => ['table' => 'persons',         'label' => 'Phone number',              'type' => 'text', 'self' => 'direct'],
        'address_line1'           => ['table' => 'person_profiles', 'label' => 'Address line 1',            'type' => 'text', 'self' => 'approval'],
        'address_line2'           => ['table' => 'person_profiles', 'label' => 'Address line 2',            'type' => 'text', 'self' => 'approval'],
        'address_city'            => ['table' => 'person_profiles', 'label' => 'Town or city',              'type' => 'text', 'self' => 'approval'],
        'address_county'          => ['table' => 'person_profiles', 'label' => 'County',                    'type' => 'text', 'self' => 'approval'],
        'address_postcode'        => ['table' => 'person_profiles', 'label' => 'Postcode',                  'type' => 'text', 'self' => 'approval'],
        'address_country'         => ['table' => 'person_profiles', 'label' => 'Country',                   'type' => 'text', 'self' => 'approval'],
        'emergency_contact_name'  => ['table' => 'person_profiles', 'label' => 'Emergency contact name',  'type' => 'text', 'self' => 'direct'],
        'emergency_contact_phone' => ['table' => 'person_profiles', 'label' => 'Emergency contact phone', 'type' => 'text', 'self' => 'direct'],
        'ni_number'               => ['table' => 'person_profiles', 'label' => 'National Insurance number', 'type' => 'text', 'self' => 'approval', 'secret' => true],
        'bank_sort_code'          => ['table' => 'person_profiles', 'label' => 'Bank sort code',            'type' => 'text', 'self' => 'approval', 'secret' => true],
        'bank_account_number'     => ['table' => 'person_profiles', 'label' => 'Bank account number',       'type' => 'text', 'self' => 'approval', 'secret' => true],
        'bank_account_name'       => ['table' => 'person_profiles', 'label' => 'Bank account name',         'type' => 'text', 'self' => 'approval', 'secret' => true],
    ];

    /** Employment sections: name => [label, fields]. A field is type bool | date | num | text | longtext | url. */
    public const SECTIONS = [
        'terms' => ['Job terms', [
            'place_of_work' => ['text', 'Place of work'], 'is_bank_staff' => ['bool', 'Bank or casual staff'],
            'external_job_description_url' => ['url', 'Job description link'], 'external_job_description_ref' => ['text', 'Job description reference'],
            'notes' => ['longtext', 'Notes'],
        ]],
        'tupe' => ['TUPE transfer', [
            'is_tupe' => ['bool', 'Transferred under TUPE'], 'tupe_transfer_date' => ['date', 'Transfer date'],
            'tupe_previous_organisation' => ['text', 'Previous organisation'], 'tupe_previous_employer_ref' => ['text', 'Previous employer reference'],
            'tupe_contract_type' => ['text', 'Contract type under TUPE'], 'tupe_hours_per_week' => ['num', 'Hours per week under TUPE'],
            'tupe_salary' => ['num', 'Salary under TUPE (£)'], 'tupe_notes' => ['longtext', 'TUPE notes'],
        ]],
        'wtd' => ['Working time', [
            'wtd_agreed' => ['bool', 'Working Time Directive agreed'], 'wtd_agreement_date' => ['date', 'Agreement date'], 'wtd_agreement_version' => ['text', 'Agreement version'],
            'wtd_opt_out' => ['bool', 'Opted out of the 48-hour week'], 'wtd_opt_out_date' => ['date', 'Opt-out signed'], 'wtd_opt_out_expiry_date' => ['date', 'Opt-out expires'],
            'wtd_notes' => ['longtext', 'Working time notes'],
        ]],
        'apprentice' => ['Apprenticeship', [
            'is_apprentice' => ['bool', 'Is an apprentice'], 'apprenticeship_start_date' => ['date', 'Starts'], 'apprenticeship_end_date' => ['date', 'Expected end'],
            'apprenticeship_level' => ['text', 'Level'], 'apprenticeship_provider' => ['text', 'Training provider'],
        ]],
        'visa' => ['Right to work and visa', [
            'has_visa' => ['bool', 'Needs a visa'], 'visa_type' => ['text', 'Visa type'], 'visa_number' => ['text', 'Visa reference'],
            'visa_issue_date' => ['date', 'Issued'], 'visa_expiry_date' => ['date', 'Expires'], 'visa_sponsor' => ['text', 'Sponsor'],
        ]],
        'leave' => ['Leave', [
            'leave_year_start_date' => ['date', 'Leave year starts'], 'leave_year_end_date' => ['date', 'Leave year ends'],
            'annual_leave_allocation' => ['num', 'Annual leave (days)'], 'annual_leave_used' => ['num', 'Used (days)'], 'annual_leave_carry_over' => ['num', 'Carried over (days)'],
            'time_in_lieu_hours' => ['num', 'Time in lieu earned (hours)'], 'time_in_lieu_used' => ['num', 'Time in lieu used (hours)'],
            'lying_time_hours' => ['num', 'Lying time earned (hours)'], 'lying_time_used' => ['num', 'Lying time used (hours)'],
        ]],
    ];

    /** Columns that cannot be empty: a blank box means zero. */
    private const ZERO_DEFAULT = ['annual_leave_used', 'annual_leave_carry_over', 'time_in_lieu_hours', 'time_in_lieu_used', 'lying_time_hours', 'lying_time_used'];

    /** @return array<string,mixed> the profile with secrets decrypted (empty strings for anything not set) */
    public static function profile(int $personId): array
    {
        $row = DB::one('SELECT * FROM person_profiles WHERE person_id = ?', [$personId]) ?? [];
        $out = [];
        foreach (self::FIELDS as $f => $m) {
            if ($m['table'] === 'person_profiles') {
                $v = $row[$f] ?? null;
                $out[$f] = !empty($m['secret']) ? Crypto::decrypt($v) : $v;
            }
        }
        return $out + ['photo_path' => $row['photo_path'] ?? null, 'signature_path' => $row['signature_path'] ?? null, 'signature_method' => $row['signature_method'] ?? null, 'signature_at' => $row['signature_at'] ?? null];
    }

    /** @return array<string,mixed> */
    public static function details(int $employmentId): array
    {
        return DB::one('SELECT * FROM employment_details WHERE employment_id = ?', [$employmentId]) ?? [];
    }

    /** Check and tidy values for any subset of the personal fields. @param array<string,mixed> $in @return array{0:array<string,?string>,1:array<string,string>} clean values, errors by field */
    public static function cleanProfile(array $in): array
    {
        $clean = [];
        $errors = [];
        foreach (array_intersect_key($in, self::FIELDS) as $f => $raw) {
            $v = is_string($raw) ? trim($raw) : '';
            [$value, $err] = self::cleanOne($f, $v);
            if ($err) {
                $errors[$f] = $err;
            } else {
                $clean[$f] = $value;
            }
        }
        return [$clean, $errors];
    }

    /** @return array{0:?string,1:?string} */
    private static function cleanOne(string $f, string $v): array
    {
        $label = self::FIELDS[$f]['label'];
        if ($v === '') {
            return in_array($f, ['first_name', 'last_name'], true) ? [null, "$label is required."] : [null, null];
        }
        if (mb_strlen($v) > 190) {
            return [null, "$label is too long."];
        }
        switch ($f) {
            case 'date_of_birth':
                if (!Clock::valid($v)) {
                    return [null, 'Enter the date of birth as a real date.'];
                }
                return ($v > Clock::today() || $v < '1900-01-01') ? [null, 'That date of birth does not look right.'] : [$v, null];
            case 'phone':
            case 'emergency_contact_phone':
                return preg_match('/^[0-9+()\s-]{7,25}$/', $v) ? [$v, null] : [null, "$label can only have numbers, spaces, + ( ) and -."];
            case 'address_postcode':
                $pc = strtoupper(preg_replace('/\s+/', '', $v));
                return preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $pc) ? [substr($pc, 0, -3) . ' ' . substr($pc, -3), null] : [null, 'That postcode does not look right.'];
            case 'ni_number':
                $ni = strtoupper(preg_replace('/\s+/', '', $v));
                $badPrefix = in_array(substr($ni, 0, 2), ['BG', 'GB', 'NK', 'KN', 'TN', 'NT', 'ZZ'], true);
                return (preg_match('/^[A-CEGHJ-PR-TW-Z][A-CEGHJ-NPR-TW-Z]\d{6}[A-D]$/', $ni) && !$badPrefix) ? [$ni, null] : [null, 'That National Insurance number does not look right. It is two letters, six numbers and a letter, such as QQ123456C.'];
            case 'bank_sort_code':
                $d = preg_replace('/[\s-]/', '', $v);
                return preg_match('/^\d{6}$/', (string) $d) ? [substr($d, 0, 2) . '-' . substr($d, 2, 2) . '-' . substr($d, 4, 2), null] : [null, 'A sort code is six numbers, such as 12-34-56.'];
            case 'bank_account_number':
                $d = preg_replace('/\s/', '', $v);
                return preg_match('/^\d{8}$/', (string) $d) ? [$d, null] : [null, 'A bank account number is eight numbers.'];
            default:
                return [$v, null];
        }
    }

    /**
     * Write personal fields straight to the live record. Used for managers, and for the fields staff may change without approval,
     * and when a manager approves a request. Secrets are encrypted. The audit row names the fields, never the values.
     *
     * @param array<string,?string> $clean already checked by cleanProfile()
     */
    public static function applyProfile(int $orgId, int $personId, array $clean, Actor $actor, string $how = 'updated'): void
    {
        if (!$clean) {
            return;
        }
        DB::transaction(function () use ($orgId, $personId, $clean, $actor, $how) {
            $person = DB::one('SELECT uuid FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId]);
            if (!$person) {
                throw new DomainException('That person could not be found.');
            }
            $own = [];
            $prof = [];
            foreach ($clean as $f => $v) {
                $m = self::FIELDS[$f];
                if ($m['table'] === 'persons') {
                    $own[$f] = $v;
                } else {
                    $prof[$f] = !empty($m['secret']) ? Crypto::encrypt($v) : $v;
                }
            }
            if ($own) {
                DB::update('persons', $own, ['id' => $personId]);
            }
            if ($prof) {
                self::upsertProfile($orgId, $personId, $prof);
            }
            Audit::record($orgId, $actor, 'person.profile_' . $how, 'person', $person['uuid'], 'Staff record ' . (['updated_by_self' => 'changed by the person', 'approved' => 'change approved', 'imported' => 'imported', 'updated' => 'updated'][$how] ?? $how) . ': ' . implode(', ', array_map(static fn($f) => self::FIELDS[$f]['label'], array_keys($clean))), ['fields' => array_keys($clean)]);
            Events::emit($orgId, 'person.updated', $person['uuid'], ['fields' => array_keys($clean)]);
        });
    }

    /** @param array<string,mixed> $cols */
    public static function upsertProfile(int $orgId, int $personId, array $cols): void
    {
        if (!DB::value('SELECT 1 FROM person_profiles WHERE person_id = ?', [$personId])) {
            DB::insert('person_profiles', ['person_id' => $personId, 'organisation_id' => $orgId]);
        }
        DB::update('person_profiles', $cols, ['person_id' => $personId]);
    }

    /** Check and tidy one section of employment details. @param array<string,mixed> $in @return array{0:array<string,mixed>,1:array<string,string>} */
    public static function cleanSection(string $section, array $in): array
    {
        if (!isset(self::SECTIONS[$section])) {
            throw new DomainException('Unknown section.');
        }
        $clean = [];
        $errors = [];
        foreach (self::SECTIONS[$section][1] as $f => [$type, $label]) {
            $raw = isset($in[$f]) && is_string($in[$f]) ? trim($in[$f]) : '';
            switch ($type) {
                case 'bool':
                    $clean[$f] = $raw === '1' || $raw === 'on' ? 1 : 0;   // a form always posts the whole section, so an unticked box means no
                    break;
                case 'date':
                    if ($raw !== '' && !Clock::valid($raw)) {
                        $errors[$f] = "$label is not a real date.";
                    }
                    $clean[$f] = $raw === '' ? null : $raw;
                    break;
                case 'num':
                    if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 99999999)) {
                        $errors[$f] = "$label must be a number, zero or more.";
                    }
                    $clean[$f] = $raw === '' ? (in_array($f, self::ZERO_DEFAULT, true) ? 0 : null) : $raw;
                    break;
                case 'url':
                    if ($raw !== '' && !preg_match('#^https?://[^\s]+$#i', $raw)) {
                        $errors[$f] = "$label must start with http:// or https://.";
                    }
                    $clean[$f] = $raw === '' ? null : $raw;
                    break;
                default:
                    if (mb_strlen($raw) > ($type === 'longtext' ? 5000 : 190)) {
                        $errors[$f] = "$label is too long.";
                    }
                    $clean[$f] = $raw === '' ? null : $raw;
            }
        }
        if ($section === 'visa' && !empty($clean['has_visa']) && empty($clean['visa_expiry_date'])) {
            $errors['visa_expiry_date'] = 'Enter when the visa expires.';
        }
        if ($section === 'apprentice' && !empty($clean['apprenticeship_start_date']) && !empty($clean['apprenticeship_end_date']) && $clean['apprenticeship_end_date'] < $clean['apprenticeship_start_date']) {
            $errors['apprenticeship_end_date'] = 'The apprenticeship cannot end before it starts.';
        }
        if ($section === 'leave' && !empty($clean['leave_year_start_date']) && !empty($clean['leave_year_end_date']) && $clean['leave_year_end_date'] < $clean['leave_year_start_date']) {
            $errors['leave_year_end_date'] = 'The leave year cannot end before it starts.';
        }
        if ($section === 'wtd' && !empty($clean['wtd_opt_out_expiry_date']) && !empty($clean['wtd_opt_out_date']) && $clean['wtd_opt_out_expiry_date'] < $clean['wtd_opt_out_date']) {
            $errors['wtd_opt_out_expiry_date'] = 'The opt-out cannot expire before it was signed.';
        }
        return [$clean, $errors];
    }

    /** Save one section for an employment. Only that section's columns are touched. @param array<string,mixed> $clean */
    public static function saveSection(int $orgId, array $employment, string $section, array $clean, Actor $actor): void
    {
        DB::transaction(function () use ($orgId, $employment, $section, $clean, $actor) {
            if (!DB::value('SELECT 1 FROM employment_details WHERE employment_id = ?', [$employment['id']])) {
                DB::insert('employment_details', ['employment_id' => $employment['id'], 'organisation_id' => $orgId]);
            }
            DB::update('employment_details', $clean, ['employment_id' => $employment['id']]);
            $person = DB::one('SELECT uuid FROM persons WHERE id = ?', [$employment['person_id']]);
            Audit::record($orgId, $actor, 'employment.details_updated', 'person', $person['uuid'] ?? null, 'Updated ' . str_ireplace('tupe', 'TUPE', strtolower(self::SECTIONS[$section][0])) . ' for ' . $employment['job_title'], ['section' => $section]);
            Events::emit($orgId, 'employment.changed', $employment['uuid'], ['section' => $section]);
        });
    }

    /** Mask a secret for display: the last four characters only. */
    public static function mask(?string $v): string
    {
        $v = (string) $v;
        return $v === '' ? '' : str_repeat('•', max(0, strlen($v) - 4)) . substr($v, -4);
    }
}
