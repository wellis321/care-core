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
use App\Modules\Staff\Checklists;
use App\Modules\Staff\Lifecycle;
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

// History is complete: only recent and upcoming work is still open.
DB::run("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP() WHERE status = 'open' AND due_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)");

echo 'Seeded 1 organisation, ' . DB::value('SELECT COUNT(DISTINCT person_id) FROM employments') . ' staff records, ' . DB::value('SELECT COUNT(*) FROM support_episodes') . " support episodes, 3 logins.\n";
echo "Sign in as admin@demo.lochview.test, manager@demo.lochview.test or staff@demo.lochview.test (password " . DEMO_PASSWORD . ").\n";
