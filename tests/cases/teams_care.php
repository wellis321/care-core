<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Database as DB;
use App\Modules\Care\CareRepo;
use App\Modules\Staff\Lifecycle;
use App\Modules\Teams\AssignmentRepo;
use App\Modules\Teams\TeamRepo;

// ── Teams ───────────────────────────────────────────────────────────────────

test('teams: a team cannot sit under itself or one of its own sub-teams, and loops cannot hang the tree', function () {
    $org = Fx::org();
    $a = Fx::team($org, 'Region'); $b = Fx::team($org, 'Area', $a); $c = Fx::team($org, 'Unit', $b);
    throws(fn() => TeamRepo::update($org, $a, ['name' => 'Region', 'parent_team_id' => $c], Fx::actor()), 'sub-teams');
    throws(fn() => TeamRepo::update($org, $a, ['name' => 'Region', 'parent_team_id' => $a], Fx::actor()), 'itself');
    DB::update('teams', ['parent_team_id' => $c], ['id' => $a]);   // force a loop directly in the database
    eq(count(TeamRepo::descendantIds($a)), 2, 'finishes, and does not list itself');
    ok(count(TeamRepo::path($a)) <= 3, 'path finishes');
    ok(is_array(TeamRepo::tree($org)), 'tree finishes');
});

test('teams: a parent from another organisation is refused; a team with sub-teams cannot be closed', function () {
    $org = Fx::org(); $other = Fx::org();
    $foreign = Fx::team($other, 'Theirs');
    throws(fn() => TeamRepo::create($org, ['name' => 'Mine', 'parent_team_id' => $foreign], Fx::actor()), 'parent team');
    $p = Fx::team($org, 'Parent'); Fx::team($org, 'Child', $p);
    throws(fn() => TeamRepo::deactivate($org, $p, Fx::actor()), 'sub-teams');
});

test('teams: closing a team ends everyone\'s place in it and keeps the history', function () {
    $org = Fx::org();
    $t = Fx::team($org, 'Closing');
    $s = Fx::staff($org, 'Cl', 'Ose', ['team_id' => $t]);
    Clock::freeze('2025-08-01');
    TeamRepo::deactivate($org, $t, Fx::actor());
    eq(DB::value('SELECT valid_to FROM team_assignments WHERE person_id = ?', [$s['person_id']]), '2025-08-01');
    eq(count(AssignmentRepo::past($t, '2025-08-02')), 1);
});

test('assignments: leaving and rejoining keeps every stint; a new stint cannot start before the last one ended', function () {
    $org = Fx::org();
    $t = Fx::team($org, 'Hist');
    $s = Fx::staff($org, 'Re', 'Join');
    AssignmentRepo::assign($org, $t, $s['person_id'], 'Worker', false, '2025-01-10', Fx::actor());
    AssignmentRepo::end($org, $t, $s['person_id'], '2025-03-31', Fx::actor());
    throws(fn() => AssignmentRepo::assign($org, $t, $s['person_id'], null, false, '2025-02-01', Fx::actor()), 'cannot be earlier');
    AssignmentRepo::assign($org, $t, $s['person_id'], 'Lead', false, '2025-06-01', Fx::actor());
    $rows = DB::all('SELECT valid_from, valid_to FROM team_assignments WHERE team_id = ? AND person_id = ? ORDER BY valid_from', [$t, $s['person_id']]);
    eq($rows, [['valid_from' => '2025-01-10', 'valid_to' => '2025-03-31'], ['valid_from' => '2025-06-01', 'valid_to' => null]]);
    AssignmentRepo::assign($org, $t, $s['person_id'], 'Lead 2', false, '2025-07-01', Fx::actor());
    eq(Fx::count('team_assignments', 'team_id = ? AND person_id = ?', [$t, $s['person_id']]), 2, 're-adding an active member updates them, no duplicate');
    throws(fn() => AssignmentRepo::end($org, $t, $s['person_id'], '2025-05-01', Fx::actor()), 'before they joined');
});

test('assignments: a staff member has one primary team; staff-only teams refuse people we support', function () {
    $org = Fx::org();
    $a = Fx::team($org, 'A'); $b = Fx::team($org, 'B'); $hr = Fx::team($org, 'HR', null, true);
    $s = Fx::staff($org, 'Prim', 'Ary');
    AssignmentRepo::assign($org, $a, $s['person_id'], null, true, '2025-01-10', Fx::actor());
    AssignmentRepo::assign($org, $b, $s['person_id'], null, true, '2025-01-10', Fx::actor());
    eq(Fx::count('team_assignments', 'person_id = ? AND is_primary = 1 AND valid_to IS NULL', [$s['person_id']]), 1);
    $c = Fx::supported($org, 'Only', 'Client');
    throws(fn() => AssignmentRepo::assign($org, $hr, $c['id'], null, false, '2025-03-01', Fx::actor()), 'staff-only');
    AssignmentRepo::assign($org, $hr, $s['person_id'], null, false, '2025-01-10', Fx::actor());   // staff are welcome
    eq(Fx::count('team_assignments', 'team_id = ? AND valid_to IS NULL', [$hr]), 1);
});

// ── Care ────────────────────────────────────────────────────────────────────

test('care: key workers must be current staff and the person must be receiving support', function () {
    $org = Fx::org();
    $staff = Fx::staff($org, 'Key', 'Worker', ['start_date' => '2025-01-06']);
    $c = Fx::supported($org, 'Sup', 'Ported', '2025-02-03');
    $nobody = App\Modules\People\PersonRepo::create($org, ['first_name' => 'Not', 'last_name' => 'Supported'], Fx::actor());
    throws(fn() => CareRepo::assignKeyWorker($org, $nobody['id'], $staff['person_id'], null, '2025-03-01', Fx::actor()), 'receiving support');
    throws(fn() => CareRepo::assignKeyWorker($org, $c['id'], $nobody['id'], null, '2025-03-01', Fx::actor()), 'current staff');
    throws(fn() => CareRepo::assignKeyWorker($org, $c['id'], $c['id'], null, '2025-03-01', Fx::actor()), 'own key worker');
    CareRepo::assignKeyWorker($org, $c['id'], $staff['person_id'], 'Key', '2025-03-01', Fx::actor());
    throws(fn() => CareRepo::assignKeyWorker($org, $c['id'], $staff['person_id'], 'Key', '2025-03-02', Fx::actor()), 'already a key worker');
});

test('care: ending support ends the person\'s key worker links and team places together', function () {
    $org = Fx::org();
    $t = Fx::team($org, 'Home');
    $staff = Fx::staff($org, 'Kw', 'One');
    $c = Fx::supported($org, 'Moves', 'On', '2025-02-03');
    CareRepo::assignKeyWorker($org, $c['id'], $staff['person_id'], null, '2025-03-01', Fx::actor());
    AssignmentRepo::assign($org, $t, $c['id'], 'Service user', false, '2025-02-03', Fx::actor());
    $r = CareRepo::endSupport($org, $c['id'], '2025-09-30', Fx::actor());
    eq($r, ['keyworkers' => 1, 'teams' => 1]);
    throws(fn() => CareRepo::endSupport($org, $c['id'], '2025-10-01', Fx::actor()), 'not receiving');
    CareRepo::startSupport($org, $c['id'], ['start_date' => '2026-01-05'], Fx::actor());   // support can resume: a new episode
    eq(count(CareRepo::episodes($c['id'])), 2);
});

test('care: NHS numbers, contacts and care needs are validated', function () {
    $org = Fx::org();
    $p = App\Modules\People\PersonRepo::create($org, ['first_name' => 'Val', 'last_name' => 'Id'], Fx::actor());
    throws(fn() => CareRepo::startSupport($org, $p['id'], ['start_date' => '2025-01-06', 'nhs_number' => '123'], Fx::actor()), 'NHS number');
    CareRepo::startSupport($org, $p['id'], ['start_date' => '2025-01-06', 'nhs_number' => '943 476 5919'], Fx::actor());
    throws(fn() => CareRepo::addContact($org, $p['id'], ['contact_type' => 'email', 'value' => 'nope'], Fx::actor()), 'email');
    throws(fn() => CareRepo::addContact($org, $p['id'], ['contact_type' => 'bogus', 'value' => 'x'], Fx::actor()), 'contact type');
    throws(fn() => CareRepo::addNeed($org, $p['id'], ['category' => '', 'description' => 'x'], Fx::actor()), 'Category');
    throws(fn() => CareRepo::addNeed($org, $p['id'], ['category' => 'Mobility', 'description' => 'x', 'review_date' => '2026-02-31'], Fx::actor()), 'review date');
    CareRepo::addContact($org, $p['id'], ['contact_type' => 'phone', 'value' => '07700 900123'], Fx::actor());
    CareRepo::addNeed($org, $p['id'], ['category' => 'Mobility', 'description' => 'Walking frame', 'severity' => 'medium'], Fx::actor());
    eq(count(CareRepo::contacts($p['id'])), 1); eq(count(CareRepo::needs($p['id'])), 1);
});
