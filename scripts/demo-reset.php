<?php
declare(strict_types=1);

/**
 * Rebuild the public demo: drop every table, recreate the schema and seed a fictional care organisation, using the same
 * services the application uses (so the demo also proves they work). Run nightly:  php scripts/demo-reset.php
 *
 * Refuses unless DEMO_MODE=true AND the database name contains "demo".
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Actor;
use App\Core\Config;
use App\Core\Database as DB;
use App\Core\Uuid;
use App\Modules\Care\CareRepo;
use App\Modules\Identity\UserRepo;
use App\Modules\People\PersonRepo;
use App\Modules\Recruitment\ApplicationRepo;
use App\Modules\Recruitment\RoleRepo;
use App\Modules\Recruitment\VacancyRepo;
use App\Modules\Staff\Checklists;
use App\Modules\Staff\Compliance;
use App\Modules\Staff\Lifecycle;
use App\Modules\Staff\ProfileChanges;
use App\Modules\Staff\StaffRecord;
use App\Modules\Teams\AssignmentRepo;
use App\Modules\Teams\TeamRepo;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!Config::demo() || stripos((string) Config::get('DB_NAME'), 'demo') === false) {
    fwrite(STDERR, "Refusing to reset: DEMO_MODE must be true and the database name must contain 'demo' (database: " . Config::get('DB_NAME') . ").\n");
    exit(1);
}

DB::pdo()->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
DB::run('INSERT INTO schema_migrations (version) VALUES (?)', ['0001_initial']);
foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $f) {
    DB::run('INSERT INTO schema_migrations (version) VALUES (?)', [basename($f, '.sql')]);
}
echo "Schema rebuilt.\n";

const DEMO_PASSWORD = 'DemoPass2026!';
$actor = Actor::system('demo');
$day = static fn(int $offset): string => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));

$org = DB::insert('organisations', ['uuid' => Uuid::v4(), 'name' => 'Loch View Care (Demo)', 'slug' => 'loch-view-demo']);
Checklists::seedDefaults($org);

// ── Teams ───────────────────────────────────────────────────────────────────
$team = static fn(string $name, ?int $parent = null, string $kind = 'care', bool $staffOnly = false): int => TeamRepo::create($org, ['name' => $name, 'parent_team_id' => $parent, 'kind' => $kind, 'staff_only' => $staffOnly], $actor)['id'];
$home    = $team('Loch View Care Home');
$dayT    = $team('Day Team', $home);
$nightT  = $team('Night Team', $home);
$cah     = $team('Care at Home');
$invT    = $team('Inverness Visits', $cah);
$lochT   = $team('Lochaber Visits', $cah);
$activT  = $team('Activities and Wellbeing', null, 'support');
$hrT     = $team('HR', null, 'hr', true);
$leadT   = $team('Senior Leadership', null, 'management', true);

// ── Staff: [first, last, email local, job title, start offset (days), team, role in team, line manager index] ──
$staffSpec = [
    ['Fiona',    'Maclean',  'admin',     'Registered Manager',     -2900, $leadT,  'Registered Manager', null],
    ['Isla',     'Grant',    'manager',   'Deputy Manager',         -2200, $leadT,  'Deputy Manager',     0],
    ['Callum',   'Reid',     'staff',     'Support Worker',         -1500, $dayT,   'Team Leader',        1],
    ['Rory',     'Fraser',   'rory.fraser',     'Support Worker',   -900,  $dayT,   'Care Worker',        2],
    ['Morven',   'Stewart',  'morven.stewart',  'Support Worker',   -420,  $dayT,   'Care Worker',        2],
    ['Ewan',     'Mackay',   'ewan.mackay',     'Night Support Worker', -1100, $nightT, 'Night Lead',     1],
    ['Kirsty',   'Munro',    'kirsty.munro',    'Team Leader, Care at Home', -1800, $invT, 'Team Leader', 1],
    ['Andrew',   'Sinclair', 'andrew.sinclair', 'Care at Home Worker', -650, $invT, 'Care Worker',        6],
    ['Catriona', 'Rose',     'catriona.rose',   'Care at Home Worker', -300, $lochT, 'Care Worker',       6],
    ['Hamish',   'Cameron',  'hamish.cameron',  'Support Worker',   -150,  $nightT, 'Care Worker',        5],
    ['Eilidh',   'Ross',     'eilidh.ross',     'HR and Payroll Officer', -2500, $hrT, 'Officer',         0],
    ['Struan',   'Gunn',     'struan.gunn',     'Activities Coordinator', -800, $activT, 'Coordinator',   1],
];
$staff = [];
foreach ($staffSpec as $i => [$first, $last, $local, $title, $start, $teamId, $role, $mgr]) {
    $staff[$i] = Lifecycle::join($org, [
        'first_name' => $first, 'last_name' => $last, 'email' => $local . '@demo.lochview.test', 'phone' => sprintf('07700 900%03d', 100 + $i),
        'job_title' => $title, 'start_date' => $day($start), 'employee_ref' => sprintf('LVC%03d', $i + 1), 'hours_per_week' => [37.5, 37.5, 37.5, 30, 30, 36, 37.5, 28, 24, 30, 25, 20][$i],
        'line_manager_person_id' => $mgr !== null ? $staff[$mgr]['person_id'] : null, 'team_id' => $teamId, 'team_role' => $role,
    ], $actor);
}
// Logins for the three demo roles (the other staff have none, as in many real services).
foreach ([[0, 'admin', 'admin'], [1, 'manager', 'manager'], [2, 'staff', 'staff']] as [$i, $local, $role]) {
    UserRepo::create($org, $staff[$i]['person_id'], $local . '@demo.lochview.test', $role, $actor, false, DEMO_PASSWORD);
}
// Onboarding for people who joined long ago is finished.
DB::run("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP() WHERE organisation_id = ? AND kind = 'joiner'", [$org]);

// ── A move: Callum was promoted to team leader eight months ago ─────────────
Lifecycle::move($org, $staff[2]['employment_id'], ['job_title' => 'Senior Support Worker'], $day(-240), $actor);

// ── A rehire: Rae left two and a half years ago and came back ───────────────
$rae = Lifecycle::join($org, ['first_name' => 'Rae', 'last_name' => 'Duncan', 'email' => 'rae.duncan@demo.lochview.test', 'job_title' => 'Support Worker', 'start_date' => $day(-1300), 'employee_ref' => 'LVC013', 'team_id' => $dayT, 'team_role' => 'Care Worker', 'line_manager_person_id' => $staff[2]['person_id']], $actor);
Lifecycle::leave($org, $rae['employment_id'], $day(-900), 'Moved to another service', $actor);
Lifecycle::join($org, ['person_uuid' => $rae['person_uuid'], 'job_title' => 'Senior Support Worker', 'start_date' => $day(-200), 'employee_ref' => 'LVC019', 'team_id' => $nightT, 'team_role' => 'Care Worker', 'line_manager_person_id' => $staff[5]['person_id']], $actor);

// ── A leaver three months ago, a leaver next month, and a starter next week ─
$morag = Lifecycle::join($org, ['first_name' => 'Morag', 'last_name' => 'Dunbar', 'email' => 'morag.dunbar@demo.lochview.test', 'job_title' => 'Cook', 'start_date' => $day(-1600), 'employee_ref' => 'LVC014', 'team_id' => $home, 'team_role' => 'Cook'], $actor);
Lifecycle::leave($org, $morag['employment_id'], $day(-95), 'Retired', $actor);
DB::run("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP() WHERE person_id = ?", [$morag['person_id']]);

$leaver = Lifecycle::join($org, ['first_name' => 'Fergus', 'last_name' => 'Baird', 'email' => 'fergus.baird@demo.lochview.test', 'job_title' => 'Support Worker', 'start_date' => $day(-500), 'employee_ref' => 'LVC015', 'team_id' => $dayT, 'team_role' => 'Care Worker', 'line_manager_person_id' => $staff[2]['person_id']], $actor);
DB::run("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP() WHERE person_id = ? AND kind = 'joiner'", [$leaver['person_id']]);

$starter = Lifecycle::join($org, ['first_name' => 'Nina', 'last_name' => 'Walker', 'email' => 'nina.walker@demo.lochview.test', 'job_title' => 'Support Worker', 'start_date' => $day(9), 'employee_ref' => 'LVC016', 'team_id' => $dayT, 'team_role' => 'Care Worker', 'line_manager_person_id' => $staff[2]['person_id'], 'hours_per_week' => 30], $actor);

// ── People we support ───────────────────────────────────────────────────────
$people = [
    ['Euan',    'Campbell', '1988-02-11', $day(-800),  'HC-DEMO-001', $dayT,   [['Mobility', 'Uses a wheelchair outdoors.', 'medium'], ['Communication', 'Prefers short written prompts.', 'low']]],
    ['Isobel',  'Murray',   '1941-09-03', $day(-1300), 'HC-DEMO-002', $dayT,   [['Dementia', 'Memory support and a familiar routine.', 'high'], ['Nutrition', 'Needs help at mealtimes.', 'medium']]],
    ['Ross',    'Hendry',   '1975-06-21', $day(-400),  'HC-DEMO-003', $invT,   [['Medication', 'Prompting for twice-daily medication.', 'medium']]],
    ['Aileen',  'Duncan',   '1952-12-30', $day(-2100), 'HC-DEMO-004', $home,   [['Falls risk', 'Falls review every six months.', 'high']]],
    ['Calum',   'Ritchie',  '1998-04-08', $day(-150),  'HC-DEMO-005', $nightT, [['Sleep', 'Settles best with a night check at 2am.', 'low']]],
    ['Nan',     'Sutherland', '1936-01-17', $day(-1100), 'HC-DEMO-006', $lochT, [['Frailty', 'Daily personal care visits.', 'high']]],
    ['Findlay', 'Bain',     '1990-07-25', $day(-60),   'HC-DEMO-007', $invT,   [['Autism', 'Quiet environment, predictable visits.', 'medium']]],
    ['Mairi',   'Kerr',     '1961-03-14', $day(-700),  'HC-DEMO-008', $home,   []],
];
$supported = [];
foreach ($people as $i => [$first, $last, $dob, $since, $laRef, $teamId, $needs]) {
    $pr = PersonRepo::create($org, ['first_name' => $first, 'last_name' => $last, 'date_of_birth' => $dob, 'phone' => sprintf('01463 000%03d', 100 + $i)], $actor);
    CareRepo::startSupport($org, $pr['id'], ['start_date' => $since, 'reference' => sprintf('P%03d', $i + 1), 'local_authority_ref' => $laRef], $actor);
    AssignmentRepo::assign($org, $teamId, $pr['id'], 'Service user', false, $since, $actor);
    foreach ($needs as [$cat, $desc, $sev]) {
        CareRepo::addNeed($org, $pr['id'], ['category' => $cat, 'description' => $desc, 'severity' => $sev, 'review_date' => $day(40 + $i * 17)], $actor);
    }
    CareRepo::addContact($org, $pr['id'], ['contact_type' => 'next_of_kin', 'label' => 'Family', 'value' => sprintf('07700 900%03d', 200 + $i), 'is_primary' => 1], $actor);
    CareRepo::addContact($org, $pr['id'], ['contact_type' => 'gp', 'label' => 'Sample Medical Practice', 'value' => '01463 000000'], $actor);
    $supported[$i] = $pr;
}
// Key workers for everyone we support.
$kw = [0 => 3, 1 => 2, 2 => 7, 3 => 4, 4 => 9, 5 => 8, 6 => 7];
foreach ($kw as $si => $stI) {
    CareRepo::assignKeyWorker($org, $supported[$si]['id'], $staff[$stI]['person_id'], 'Key worker', max($people[$si][3], $day(-90)), $actor);
}
// Fergus is a key worker too, and leaves next month: his leaving shows the "find a new key worker" task being created.
CareRepo::assignKeyWorker($org, $supported[7]['id'], $leaver['person_id'], 'Key worker', max($people[7][3], $day(-90)), $actor);
Lifecycle::leave($org, $leaver['employment_id'], $day(21), 'Resigned', $actor);

// A person whose support ended some time ago: their key worker link and team place ended with it.
$moved = PersonRepo::create($org, ['first_name' => 'Douglas', 'last_name' => 'Fyfe', 'date_of_birth' => '1949-05-02'], $actor);
CareRepo::startSupport($org, $moved['id'], ['start_date' => $day(-1000), 'reference' => 'P009'], $actor);
AssignmentRepo::assign($org, $home, $moved['id'], 'Service user', false, $day(-1000), $actor);
CareRepo::assignKeyWorker($org, $moved['id'], $staff[3]['person_id'], 'Key worker', $day(-900), $actor);
CareRepo::endSupport($org, $moved['id'], $day(-120), $actor);


// ── Recruitment: roles, two vacancies, and candidates at different stages ──
$mgrRole  = RoleRepo::create($org, ['title' => 'Registered Manager', 'code' => 'RM', 'description' => 'Responsible for the service and its registration with the Care Inspectorate.', 'default_team_id' => $leadT, 'hours_per_week' => 37.5, 'grade' => 'Band 7'], $actor);
$swRole   = RoleRepo::create($org, ['title' => 'Support Worker', 'code' => 'SW', 'description' => 'Supports people to live well day to day, following their care plans.', 'person_specification' => 'Kind, reliable, willing to learn. SVQ 2 or willingness to work towards it.', 'reports_to_role_id' => $mgrRole['id'], 'default_team_id' => $dayT, 'hours_per_week' => 30, 'grade' => 'Band 3'], $actor);
$nightRole = RoleRepo::create($org, ['title' => 'Night Support Worker', 'code' => 'NSW', 'description' => 'Overnight support and checks.', 'reports_to_role_id' => $mgrRole['id'], 'default_team_id' => $nightT, 'hours_per_week' => 36, 'grade' => 'Band 3'], $actor);
foreach ([$swRole['id'], $nightRole['id']] as $roleId) {
    RoleRepo::addRequirement($org, $roleId, 'PVG scheme membership', 'Enhanced disclosure for working with adults.', true, $actor);
    RoleRepo::addRequirement($org, $roleId, 'Two references', null, true, $actor);
    RoleRepo::addRequirement($org, $roleId, 'Right to work', null, true, $actor);
    RoleRepo::addRequirement($org, $roleId, 'SSSC registration', 'Needed within six months of starting.', false, $actor);
}
$vacSw = VacancyRepo::create($org, ['role_id' => $swRole['id'], 'posts' => 2, 'line_manager_person_id' => $staff[2]['person_id'], 'closing_date' => $day(14)], $actor);
VacancyRepo::open($org, $vacSw['id'], $actor);
$vacNight = VacancyRepo::create($org, ['role_id' => $nightRole['id'], 'posts' => 1, 'line_manager_person_id' => $staff[5]['person_id']], $actor);   // left as a draft
$cands = [];
foreach ([['Lewis', 'Gordon', 'lewis.gordon', 'Applied through the careers page'], ['Amara', 'Okafor', 'amara.okafor', 'Recommended by a colleague'], ['Jamie', 'Brodie', 'jamie.brodie', 'Job centre'], ['Kerry', 'Lennox', 'kerry.lennox', 'Applied through the careers page']] as [$f, $l, $em, $src]) {
    $cands[$l] = ApplicationRepo::create($org, $vacSw['id'], ['first_name' => $f, 'last_name' => $l, 'email' => $em . '@example.test', 'source' => $src, 'date_of_birth' => '199' . random_int(0, 9) . '-0' . random_int(1, 9) . '-1' . random_int(0, 9)], $actor);
}
ApplicationRepo::setStage($org, $cands['Okafor']['id'], 'shortlisted', [], $actor);
ApplicationRepo::setStage($org, $cands['Brodie']['id'], 'shortlisted', [], $actor);
ApplicationRepo::setStage($org, $cands['Brodie']['id'], 'interview', ['interview_date' => $day(3)], $actor);
ApplicationRepo::setStage($org, $cands['Lennox']['id'], 'shortlisted', [], $actor);
ApplicationRepo::setStage($org, $cands['Lennox']['id'], 'interview', ['interview_date' => $day(-6)], $actor);
ApplicationRepo::setStage($org, $cands['Lennox']['id'], 'offer', ['proposed_start_date' => $day(30), 'offer_date' => $day(-2)], $actor);
ApplicationRepo::acceptOffer($org, $cands['Lennox']['id'], $day(-1), $actor);
foreach (DB::all('SELECT id FROM application_checks WHERE application_id = ? AND title IN (\'PVG scheme membership\', \'Right to work\')', [$cands['Lennox']['id']]) as $c) {
    ApplicationRepo::setCheck($org, $cands['Lennox']['id'], (int) $c['id'], $day(-1), null, $actor);
}
ApplicationRepo::create($org, $vacSw['id'], ['first_name' => 'Pat', 'last_name' => 'Henderson', 'email' => 'pat.henderson@example.test'], $actor);
ApplicationRepo::setStage($org, (int) DB::value('SELECT id FROM applications WHERE last_name = ?', ['Henderson']), 'rejected', ['reason' => 'Not able to work the required shifts'], $actor);

// ── Staff records: profile, terms, registrations, supervisions, appraisals, learning ──
$streets = ['Ness Walk', 'Church Street', 'Academy Street', 'High Street', 'Lochaber Road', 'Glen Road', 'Harbour Road', 'Station Road'];
foreach ($staff as $i => $s) {
    [$clean] = StaffRecord::cleanProfile([
        'address_line1' => (10 + $i) . ' ' . $streets[$i % 8], 'address_city' => 'Inverness', 'address_county' => 'Highland', 'address_postcode' => 'IV' . (1 + $i % 3) . ' ' . (1 + $i % 9) . 'AA', 'address_country' => 'United Kingdom',
        'emergency_contact_name' => 'Family contact', 'emergency_contact_phone' => sprintf('07700 900%03d', 300 + $i),
        'date_of_birth' => date('Y-m-d', strtotime('-' . (24 + ($i * 3) % 30) . ' years -' . (($i * 37) % 300) . ' days')),
        'ni_number' => 'JA' . sprintf('%06d', $i) . 'A', 'bank_sort_code' => '00-00-00', 'bank_account_number' => '00000000', 'bank_account_name' => $staffSpec[$i][0] . ' ' . $staffSpec[$i][1],
    ]);
    StaffRecord::applyProfile($org, $s['person_id'], $clean, $actor);
    $emp = DB::one('SELECT * FROM employments WHERE id = ?', [$s['employment_id']]);
    $terms = ['place_of_work' => $i % 2 ? 'Loch View Care Home' : 'Care at Home base'];
    [$c] = StaffRecord::cleanSection('terms', $terms + ['is_bank_staff' => $i === 9 ? '1' : '']);
    StaffRecord::saveSection($org, $emp, 'terms', $c, $actor);
    [$c] = StaffRecord::cleanSection('wtd', ['wtd_agreed' => '1', 'wtd_agreement_date' => $day(-300), 'wtd_agreement_version' => '2025.1', 'wtd_opt_out' => $i === 4 ? '1' : '', 'wtd_opt_out_date' => $i === 4 ? $day(-200) : '', 'wtd_opt_out_expiry_date' => $i === 4 ? $day(165) : '']);
    StaffRecord::saveSection($org, $emp, 'wtd', $c, $actor);
    [$c] = StaffRecord::cleanSection('leave', ['leave_year_start_date' => date('Y') . '-04-01', 'leave_year_end_date' => (date('Y') + 1) . '-03-31', 'annual_leave_allocation' => '33', 'annual_leave_used' => (string) (6 + $i % 9), 'annual_leave_carry_over' => '2']);
    StaffRecord::saveSection($org, $emp, 'leave', $c, $actor);
    // Registrations: most are comfortably in date, a few are due soon, one has lapsed.
    $expiry = [$day(410), $day(380), $day(25), $day(540), $day(-12), $day(300), $day(48), $day(700), $day(260), $day(500), $day(35), $day(610)][$i % 12];
    if ($i >= 1) {
        [$c] = Compliance::clean($org, 'registration', ['registration_type' => $i < 2 ? 'SSSC registered manager' : 'SSSC support worker', 'registration_body' => 'Scottish Social Services Council', 'registration_number' => sprintf('SSSC-DEMO-%04d', 2000 + $i), 'issue_date' => $day(-900), 'expiry_date' => $expiry, 'is_required_for_role' => '1']);
        Compliance::add($org, ['id' => $s['person_id'], 'uuid' => $s['person_uuid']], 'registration', $c, $actor);
    }
    $mgr = $staffSpec[$i][7];
    $sup = $mgr !== null ? $staff[$mgr]['person_uuid'] : '';
    [$c] = Compliance::clean($org, 'supervision', ['supervision_date' => $day(-20 - $i * 4), 'supervisor_person_id' => $sup, 'supervision_type' => 'individual', 'duration_minutes' => '60', 'outcome' => 'Wellbeing good. Training plan agreed.', 'next_due_date' => $day(10 + $i * 5 - 20)]);
    Compliance::add($org, ['id' => $s['person_id'], 'uuid' => $s['person_uuid']], 'supervision', $c, $actor);
    [$c] = Compliance::clean($org, 'appraisal', ['appraisal_date' => $day(-200), 'appraiser_person_id' => $sup, 'appraisal_type' => 'annual', 'outcome' => 'meets_expectations', 'next_due_date' => $day(165 + $i * 7)]);
    Compliance::add($org, ['id' => $s['person_id'], 'uuid' => $s['person_uuid']], 'appraisal', $c, $actor);
    foreach ([['Moving and handling', $day(-100), $day(265 - $i * 20), 1], ['Safeguarding adults', $day(-60), $day(305), 1], ['Medication awareness', $day(-400), $day($i % 3 === 0 ? 20 : 330), 1]] as [$t, $done, $exp, $m]) {
        [$c] = Compliance::clean($org, 'learning', ['title' => $t, 'record_type' => 'training', 'provider' => 'Highland Care Training (demo)', 'completion_date' => $done, 'expiry_date' => $exp, 'status' => 'completed', 'is_mandatory' => '1']);
        Compliance::add($org, ['id' => $s['person_id'], 'uuid' => $s['person_uuid']], 'learning', $c, $actor);
    }
}
// One TUPE transfer and one visa, so those sections show real content.
foreach ([[6, 'tupe', ['is_tupe' => '1', 'tupe_transfer_date' => $day(-700), 'tupe_previous_organisation' => 'Glen Care Services (demo)', 'tupe_previous_employer_ref' => 'GCS-114', 'tupe_contract_type' => 'permanent', 'tupe_hours_per_week' => '37.5', 'tupe_notes' => 'Terms protected under TUPE.']],
          [8, 'visa', ['has_visa' => '1', 'visa_type' => 'Health and Care Worker', 'visa_number' => 'DEMO-VISA-0008', 'visa_issue_date' => $day(-600), 'visa_expiry_date' => $day(75), 'visa_sponsor' => 'Loch View Care (Demo)']]] as [$i, $sec, $vals]) {
    [$c] = StaffRecord::cleanSection($sec, $vals);
    StaffRecord::saveSection($org, DB::one('SELECT * FROM employments WHERE id = ?', [$staff[$i]['employment_id']]), $sec, $c, $actor);
}
// A request from Callum (a "staff" login) waiting for his line manager Isla ("manager" login): shows the approval flow.
$callumUser = DB::one('SELECT id FROM users WHERE email = ?', ['staff@demo.lochview.test']);
if ($callumUser) {
    [$c] = StaffRecord::cleanProfile(['address_line1' => '4 Millburn Road', 'address_postcode' => 'IV2 3PS', 'phone' => '07700 900555']);
    ProfileChanges::submit($org, (int) $staff[2]['person_id'], (int) $callumUser['id'], $c, Actor::system('demo'));
}

// History is complete: only recent and upcoming work is still open.
DB::run("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP() WHERE status = 'open' AND due_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)");

// The owner's own administrator login survives the nightly reset. The server's .env holds OWNER_EMAIL and
// OWNER_PASSWORD_HASH (a hash made on the owner's own computer: the password itself never appears anywhere here).
$ownerEmail = strtolower(trim((string) Config::get('OWNER_EMAIL', '')));
$ownerHash = trim((string) Config::get('OWNER_PASSWORD_HASH', ''));
if ($ownerEmail !== '' || $ownerHash !== '') {
    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || (password_get_info($ownerHash)['algo'] ?? null) === null) {
        fwrite(STDERR, "OWNER_EMAIL or OWNER_PASSWORD_HASH is not valid, so the owner login was not created.\n");
    } else {
        DB::insert('users', ['uuid' => Uuid::v4(), 'organisation_id' => $org, 'person_id' => null, 'email' => $ownerEmail, 'role' => 'admin', 'status' => 'active', 'password_hash' => $ownerHash]);
        echo "Kept the owner's administrator login: $ownerEmail\n";
    }
}
echo 'Seeded 1 organisation, ' . DB::value('SELECT COUNT(DISTINCT person_id) FROM employments') . ' staff records, ' . DB::value('SELECT COUNT(*) FROM support_episodes') . " support episodes, 3 logins.\n";
echo "Sign in as admin@demo.lochview.test, manager@demo.lochview.test or staff@demo.lochview.test (password " . DEMO_PASSWORD . ").\n";
