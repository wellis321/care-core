<?php
declare(strict_types=1);

use App\Core\Config;

/**
 * Builds three throwaway databases shaped like the older Staff, People and Team services and fills them with fictional,
 * deliberately imperfect data: the kind real systems collect (a bad postcode, a missing start date, a leaver who was never
 * marked as having left, a duplicate employee reference). The exporters and the importer are tested against it.
 */
final class OldSystems
{
    public const STAFF = 'cc_old_staff';
    public const PEOPLE = 'cc_old_people';
    public const TEAM = 'cc_old_team';

    public static function server(): ?PDO
    {
        try {
            return new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'), Config::get('DB_PORT', '3306')), (string) Config::get('DB_USER', 'root'), (string) Config::get('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{staff:PDO,people:PDO,team:PDO}|null null when the database server will not let us create databases */
    public static function build(): ?array
    {
        $srv = self::server();
        if (!$srv) {
            return null;
        }
        try {
            foreach (['staff' => self::STAFF, 'people' => self::PEOPLE, 'team' => self::TEAM] as $k => $db) {
                $srv->exec("DROP DATABASE IF EXISTS `$db`");
                $srv->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
                $srv->exec("USE `$db`");
                $srv->exec((string) file_get_contents(__DIR__ . '/old_' . $k . '.sql'));
            }
        } catch (Throwable) {
            return null;
        }
        $mk = static fn(string $db) => new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'), Config::get('DB_PORT', '3306'), $db), (string) Config::get('DB_USER', 'root'), (string) Config::get('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $d = ['staff' => $mk(self::STAFF), 'people' => $mk(self::PEOPLE), 'team' => $mk(self::TEAM)];
        self::seedStaff($d['staff']);
        self::seedPeople($d['people']);
        self::seedTeam($d['team']);
        return $d;
    }

    private static function ins(PDO $db, string $table, array $row): int
    {
        $db->prepare("INSERT INTO `$table` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        return (int) $db->lastInsertId();
    }

    private static function staff(PDO $db, array $p, array $sp): int
    {
        $id = self::ins($db, 'people', $p + ['organisation_id' => 1, 'person_type' => 'staff', 'is_active' => 1, 'created_at' => '2024-02-01 09:00:00', 'updated_at' => '2025-01-10 09:00:00']);
        self::ins($db, 'staff_profiles', ['person_id' => $id] + $sp);
        return $id;
    }

    private static function seedStaff(PDO $db): void
    {
        $f = self::staff($db, ['first_name' => 'Fiona', 'last_name' => 'Maclean', 'email' => 'fiona.maclean@lochview.test', 'phone' => '01463 000001', 'date_of_birth' => '1975-03-14', 'employee_reference' => 'E001'],
            ['job_title' => 'Registered Manager', 'employment_start_date' => '2018-04-02', 'contract_type' => 'permanent', 'contracted_hours' => 37.5, 'address_line1' => '1 Ness Walk', 'address_city' => 'Inverness', 'address_county' => 'Highland', 'address_postcode' => 'IV2 4SE', 'emergency_contact_name' => 'Ewan Maclean', 'emergency_contact_phone' => '07700 900111', 'ni_number' => 'JA000001A', 'bank_sort_code' => '00-00-00', 'bank_account_number' => '00000001', 'bank_account_name' => 'F Maclean', 'place_of_work' => 'Loch View Care Home', 'wtd_agreed' => 1, 'wtd_agreement_date' => '2018-04-02']);
        $i = self::staff($db, ['first_name' => 'Isla', 'last_name' => 'Grant', 'email' => 'isla.grant@lochview.test', 'employee_reference' => 'E002', 'date_of_birth' => '1982-11-02'],
            ['job_title' => 'Deputy Manager', 'employment_start_date' => '2020-09-14', 'line_manager_id' => $f, 'contract_type' => 'permanent', 'contracted_hours' => 37.5, 'is_tupe' => 1, 'tupe_transfer_date' => '2020-09-14', 'tupe_previous_organisation' => 'Glen Care Services', 'tupe_previous_employer_ref' => 'GCS-77', 'tupe_hours_per_week' => 37.5, 'tupe_salary' => 31200, 'tupe_notes' => 'Terms protected.', 'wtd_agreed' => 1, 'wtd_opt_out' => 1, 'wtd_opt_out_date' => '2021-01-04', 'wtd_opt_out_expiry_date' => '2026-01-04', 'annual_leave_allocation' => 33, 'annual_leave_used' => 12, 'leave_year_start_date' => '2025-04-01', 'leave_year_end_date' => '2026-03-31']);
        foreach ([['Support Worker', '2020-09-14', 30, 'permanent'], ['Senior Support Worker', '2022-02-01', 37.5, 'permanent'], ['Deputy Manager', '2024-06-03', 37.5, 'permanent']] as [$t, $s, $h, $c]) {
            self::ins($db, 'staff_role_history', ['person_id' => $i, 'organisation_id' => 1, 'job_title' => $t, 'start_date' => $s, 'hours_per_week' => $h, 'contract_type' => $c]);
        }
        $c = self::staff($db, ['first_name' => 'Callum', 'last_name' => 'Reid', 'email' => 'callum.reid@lochview.test', 'employee_reference' => 'E003', 'phone' => 'ring the office'],
            ['job_title' => 'Support Worker', 'employment_start_date' => '2022-09-13', 'line_manager_id' => $i, 'contracted_hours' => 30, 'address_line1' => '12 Academy Street', 'address_postcode' => 'NOTAPC', 'ni_number' => 'XX', 'bank_sort_code' => '12-34', 'is_apprentice' => 1, 'apprenticeship_start_date' => '2022-09-13', 'apprenticeship_end_date' => '2024-09-12', 'apprenticeship_level' => 'Level 3']);
        $m = self::staff($db, ['first_name' => 'Morven', 'last_name' => 'Stewart', 'email' => 'morven.stewart@lochview.test', 'employee_reference' => 'E004'],
            ['job_title' => 'Cook', 'employment_start_date' => '2023-02-06', 'line_manager_id' => $f, 'has_visa' => 1, 'visa_type' => 'Skilled Worker', 'visa_number' => 'DEMO-0001', 'visa_expiry_date' => '2027-02-05', 'is_bank_staff' => 1]);
        $left = self::staff($db, ['first_name' => 'Fergus', 'last_name' => 'Baird', 'email' => 'fergus.baird@lochview.test', 'employee_reference' => 'E005', 'is_active' => 0, 'updated_at' => '2024-11-30 16:00:00'],
            ['job_title' => 'Support Worker', 'employment_start_date' => '2021-05-10', 'line_manager_id' => $i, 'contracted_hours' => 37.5]);
        $nostart = self::staff($db, ['first_name' => 'Nina', 'last_name' => 'Walker', 'email' => 'not-an-email', 'created_at' => '2023-08-14 10:00:00'],
            ['job_title' => '', 'employment_start_date' => null, 'contracted_hours' => 999]);
        self::staff($db, ['first_name' => 'Isla', 'last_name' => 'Grant-Duplicate', 'email' => 'ISLA.GRANT@lochview.test', 'phone' => '01463 000099'], ['job_title' => 'Duplicate of Isla', 'employment_start_date' => '2025-01-01']);
        $dbl = self::ins($db, 'people', ['organisation_id' => 1, 'person_type' => 'person_we_support', 'first_name' => 'Supported', 'last_name' => 'InStaffService', 'is_active' => 1, 'created_at' => '2024-02-01 09:00:00', 'updated_at' => '2024-02-01 09:00:00']);
        // Compliance
        self::ins($db, 'staff_registrations', ['person_id' => $f, 'organisation_id' => 1, 'registration_type' => 'SSSC registered manager', 'registration_body' => 'Scottish Social Services Council', 'registration_number' => 'SSSC-1', 'issue_date' => '2019-01-01', 'expiry_date' => '2027-01-01', 'is_active' => 1, 'is_required_for_role' => 1]);
        self::ins($db, 'staff_registrations', ['person_id' => $c, 'organisation_id' => 1, 'registration_type' => 'SSSC support worker', 'expiry_date' => '2020-01-01', 'issue_date' => '2023-01-01', 'is_active' => 1, 'is_required_for_role' => 1]);
        self::ins($db, 'staff_supervisions', ['person_id' => $c, 'organisation_id' => 1, 'supervision_date' => '2025-09-10', 'supervisor_person_id' => $i, 'supervision_type' => 'individual', 'duration_minutes' => 60, 'outcome' => 'Good.', 'next_due_date' => '2025-12-10']);
        self::ins($db, 'staff_supervisions', ['person_id' => $c, 'organisation_id' => 1, 'supervision_date' => '2025-03-10', 'supervisor_person_id' => 99999, 'supervision_type' => 'group']);
        self::ins($db, 'staff_appraisals', ['person_id' => $i, 'organisation_id' => 1, 'appraisal_date' => '2025-01-20', 'appraiser_person_id' => $f, 'appraisal_type' => 'annual', 'outcome' => 'meets_expectations', 'next_due_date' => '2026-01-20']);
        self::ins($db, 'staff_learning_records', ['person_id' => $i, 'organisation_id' => 1, 'record_type' => 'training', 'title' => 'Moving and handling', 'completion_date' => '2025-02-01', 'expiry_date' => '2026-02-01', 'status' => 'completed', 'is_mandatory' => 1]);
        self::ins($db, 'staff_learning_records', ['person_id' => $i, 'organisation_id' => 1, 'record_type' => 'something_odd', 'title' => 'Mystery course', 'completion_date' => '2024-02-01', 'status' => 'completed', 'is_mandatory' => 0]);
    }

    private static function seedPeople(PDO $db): void
    {
        $p = static fn(array $r) => self::ins($db, 'people', $r + ['organisation_id' => 1, 'status' => 'active', 'created_at' => '2024-03-01 09:00:00', 'updated_at' => '2025-02-01 09:00:00']);
        $a = $p(['first_name' => 'Aileen', 'last_name' => 'Duncan', 'preferred_name' => 'Aily', 'date_of_birth' => '1952-12-30', 'gender' => 'Female', 'pronouns' => 'she/her', 'support_start' => '2019-03-04', 'nhs_number' => '943 476 5919', 'local_authority_ref' => 'HC-DEMO-004', 'address_line1' => '3 Church Street', 'city' => 'Inverness', 'postcode' => 'IV1 1EN', 'notes' => 'Likes the garden.']);
        $r = $p(['first_name' => 'Ross', 'last_name' => 'Hendry', 'date_of_birth' => '1975-06-21', 'status' => 'inactive', 'support_start' => '2021-04-12', 'support_end' => null, 'updated_at' => '2024-08-15 09:00:00', 'nhs_number' => '1234567890']);
        $n = $p(['first_name' => 'Nan', 'last_name' => 'Sutherland', 'date_of_birth' => '1936-01-17', 'support_start' => null, 'created_at' => '2023-05-02 09:00:00']);
        $ended = $p(['first_name' => 'Douglas', 'last_name' => 'Fyfe', 'date_of_birth' => '1949-05-02', 'status' => 'archived', 'support_start' => '2020-01-06', 'support_end' => '2024-02-29']);
        self::ins($db, 'care_needs', ['person_id' => $a, 'organisation_id' => 1, 'category' => 'Falls risk', 'description' => 'Falls review every six months.', 'severity' => 'high', 'review_date' => '2026-03-01']);
        self::ins($db, 'care_needs', ['person_id' => $a, 'organisation_id' => 1, 'category' => 'Falls risk', 'description' => 'Falls review every six months.', 'severity' => 'high']);
        self::ins($db, 'care_needs', ['person_id' => $a, 'organisation_id' => 1, 'category' => 'Medication', 'description' => 'Prompting twice daily.', 'severity' => 'medium']);
        self::ins($db, 'person_contacts', ['person_id' => $a, 'organisation_id' => 1, 'contact_type' => 'next_of_kin', 'label' => 'Daughter', 'value' => '07700 900201', 'is_primary' => 1, 'notes' => 'Evenings']);
        self::ins($db, 'person_contacts', ['person_id' => $a, 'organisation_id' => 1, 'contact_type' => 'gp', 'label' => 'Sample Medical Practice', 'value' => '01463 000000', 'is_primary' => 0]);
        self::ins($db, 'person_contacts', ['person_id' => $a, 'organisation_id' => 1, 'contact_type' => 'phone', 'value' => 'not a number', 'is_primary' => 0]);
        // key workers: Isla (current), Fergus (who left in 2024 and was never closed off), a ghost staff id
        self::ins($db, 'person_keyworkers', ['person_id' => $a, 'organisation_id' => 1, 'staff_id' => 2, 'display_name' => 'Isla Grant', 'role_label' => 'Key worker', 'assigned_at' => '2022-01-10 00:00:00', 'ended_at' => null]);
        self::ins($db, 'person_keyworkers', ['person_id' => $r, 'organisation_id' => 1, 'staff_id' => 5, 'display_name' => 'Fergus Baird', 'assigned_at' => '2021-06-01 00:00:00', 'ended_at' => null]);
        self::ins($db, 'person_keyworkers', ['person_id' => $n, 'organisation_id' => 1, 'staff_id' => 424242, 'display_name' => 'Gone Missing', 'assigned_at' => '2023-06-01 00:00:00']);
        self::ins($db, 'person_keyworkers', ['person_id' => $ended, 'organisation_id' => 1, 'staff_id' => 3, 'display_name' => 'Callum Reid', 'assigned_at' => '2022-10-01 00:00:00', 'ended_at' => null]);
    }

    private static function seedTeam(PDO $db): void
    {
        $care = self::ins($db, 'team_types', ['organisation_id' => 1, 'name' => 'Care Service', 'is_staff_only' => 0]);
        $hr = self::ins($db, 'team_types', ['organisation_id' => 1, 'name' => 'HR', 'is_staff_only' => 1]);
        $role = self::ins($db, 'team_roles', ['organisation_id' => 1, 'name' => 'Team Leader', 'applies_to' => 'staff']);
        $role2 = self::ins($db, 'team_roles', ['organisation_id' => 1, 'name' => 'Service user', 'applies_to' => 'person']);
        $home = self::ins($db, 'teams', ['organisation_id' => 1, 'name' => 'Loch View Care Home', 'team_type_id' => $care, 'is_active' => 1]);
        $day = self::ins($db, 'teams', ['organisation_id' => 1, 'name' => 'Day Team', 'parent_team_id' => $home, 'team_type_id' => $care, 'is_active' => 1]);
        $early = self::ins($db, 'teams', ['organisation_id' => 1, 'name' => 'Early Shift', 'parent_team_id' => $day, 'team_type_id' => $care, 'is_active' => 1]);
        $hrt = self::ins($db, 'teams', ['organisation_id' => 1, 'name' => 'HR', 'team_type_id' => $hr, 'is_active' => 1]);
        $old = self::ins($db, 'teams', ['organisation_id' => 1, 'name' => 'Old Unit', 'parent_team_id' => 98765, 'team_type_id' => $care, 'is_active' => 0]);
        $m = static fn(array $r) => self::ins($db, 'team_members', $r + ['organisation_id' => 1, 'created_at' => '2024-02-01 09:00:00', 'is_primary_team' => 0]);
        $m(['team_id' => $day, 'member_type' => 'staff', 'external_id' => 3, 'display_name' => 'Callum Reid', 'team_role_id' => $role, 'is_primary_team' => 1, 'joined_at' => '2022-09-13 00:00:00']);
        $m(['team_id' => $day, 'member_type' => 'staff', 'external_id' => 5, 'display_name' => 'Fergus Baird', 'joined_at' => '2021-05-10 00:00:00', 'left_at' => '2023-12-31 00:00:00', 'is_primary_team' => 1]);
        $m(['team_id' => $hrt, 'member_type' => 'staff', 'external_id' => 1, 'display_name' => 'Fiona Maclean', 'joined_at' => '2018-04-02 00:00:00', 'is_primary_team' => 1]);
        $m(['team_id' => $home, 'member_type' => 'person', 'external_id' => 1, 'display_name' => 'Aileen Duncan', 'team_role_id' => $role2, 'joined_at' => '2019-03-04 00:00:00']);
        $m(['team_id' => $hrt, 'member_type' => 'person', 'external_id' => 1, 'display_name' => 'Aileen Duncan', 'joined_at' => '2024-01-01 00:00:00']);
        $m(['team_id' => $home, 'member_type' => 'user', 'external_id' => 7, 'display_name' => 'A login', 'joined_at' => '2024-01-01 00:00:00']);
        $m(['team_id' => $early, 'member_type' => 'staff', 'external_id' => 999, 'display_name' => 'Nobody Known', 'joined_at' => '2024-01-01 00:00:00']);
        $m(['team_id' => $early, 'member_type' => 'staff', 'external_id' => 2, 'display_name' => 'Isla Grant', 'joined_at' => '2024-06-03 00:00:00', 'is_primary_team' => 1]);
    }
}
