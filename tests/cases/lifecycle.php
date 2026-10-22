<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Modules\Care\CareRepo;
use App\Modules\Identity\UserRepo;
use App\Modules\People\PersonRepo;
use App\Modules\Staff\Lifecycle;
use App\Modules\Teams\AssignmentRepo;

// ── Joiner ──────────────────────────────────────────────────────────────────

test('joiner: creates the person, employment, team place, checklist, audit row and event together', function () {
    $org = Fx::org();
    $team = Fx::team($org, 'Day Team');
    $r = Fx::staff($org, 'Ava', 'Joiner', ['team_id' => $team, 'team_role' => 'Care worker', 'create_login' => true, 'login_role' => 'staff']);
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 1);
    eq(Fx::count('employments', 'person_id = ?', [$r['person_id']]), 1);
    eq(Fx::count('team_assignments', 'person_id = ? AND team_id = ? AND valid_to IS NULL AND is_primary = 1', [$r['person_id'], $team]), 1);
    eq(Fx::count('tasks', "person_id = ? AND kind = 'joiner'", [$r['person_id']]), 7, 'onboarding checklist');
    ok($r['invite_token'] !== null, 'an invitation token is returned once');
    eq(Fx::count('users', "person_id = ? AND status = 'invited'", [$r['person_id']]), 1);
    eq(Fx::count('audit_log', "organisation_id = ? AND action = 'employment.started'", [$org]), 1);
    eq(Fx::count('outbox_events', "organisation_id = ? AND type = 'employment.started'", [$org]), 1);
});

test('joiner: events carry identifiers and dates only, never names', function () {
    $org = Fx::org();
    Fx::staff($org, 'Zelda', 'Privacy');
    foreach (DB::all('SELECT payload FROM outbox_events WHERE organisation_id = ?', [$org]) as $e) {
        ok(stripos($e['payload'], 'Zelda') === false && stripos($e['payload'], 'Privacy') === false, 'payload leaks a name: ' . $e['payload']);
    }
});

test('joiner: is all or nothing (a failure part-way leaves nothing behind)', function () {
    $org = Fx::org();
    Fx::staff($org, 'First', 'Person', ['email' => 'taken@example.test', 'create_login' => true]);
    $before = [Fx::count('persons'), Fx::count('employments'), Fx::count('audit_log'), Fx::count('outbox_events')];
    // Same email as an existing LOGIN in another organisation: the login step fails after the person and employment were written.
    $org2 = Fx::org();
    throws(fn() => Lifecycle::join($org2, ['first_name' => 'Second', 'last_name' => 'Person', 'email' => 'taken@example.test', 'job_title' => 'Cook', 'start_date' => '2025-03-03', 'create_login' => true], Fx::actor()), 'already a login');
    eq([Fx::count('persons'), Fx::count('employments'), Fx::count('audit_log'), Fx::count('outbox_events')], $before, 'nothing was written');
});

test('joiner: refuses a duplicate email, a bad date, bad hours and a reused employee reference', function () {
    $org = Fx::org();
    Fx::staff($org, 'Dup', 'One', ['email' => 'dup@example.test', 'employee_ref' => 'E1']);
    throws(fn() => Fx::staff($org, 'Dup', 'Two', ['email' => 'dup@example.test']), 'already exists');
    throws(fn() => Fx::staff($org, 'Bad', 'Date', ['start_date' => '2025-13-45']), 'valid date');
    throws(fn() => Fx::staff($org, 'Bad', 'Hours', ['hours_per_week' => '999']), 'hours');
    throws(fn() => Fx::staff($org, 'Ref', 'Reuse', ['employee_ref' => 'E1']), 'reference');
    throws(fn() => Fx::staff($org, 'No', 'Title', ['job_title' => '']), 'Job title');
});

test('joiner: a rehire is a NEW employment for the same person; earlier dates are untouched', function () {
    $org = Fx::org();
    $a = Fx::staff($org, 'Rae', 'Rehire', ['start_date' => '2024-01-08']);
    Lifecycle::leave($org, $a['employment_id'], '2024-09-30', 'resigned', Fx::actor());
    $b = Lifecycle::join($org, ['person_uuid' => $a['person_uuid'], 'job_title' => 'Senior Support Worker', 'start_date' => '2025-02-03'], Fx::actor());
    ok($b['rehire'], 'flagged as a rehire');
    eq($b['person_id'], $a['person_id'], 'same person');
    $stints = Lifecycle::employments($a['person_id']);
    eq(count($stints), 2);
    eq([$stints[1]['start_date'], $stints[1]['end_date']], ['2024-01-08', '2024-09-30'], 'first stint untouched');
    eq($stints[0]['end_date'], null);
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 1, 'no second person');
});

test('joiner: cannot start before the previous employment ended, or while already employed', function () {
    $org = Fx::org();
    $a = Fx::staff($org, 'Ova', 'Lap', ['start_date' => '2024-01-08']);
    throws(fn() => Lifecycle::join($org, ['person_uuid' => $a['person_uuid'], 'job_title' => 'X', 'start_date' => '2024-06-01'], Fx::actor()), 'already employed');
    Lifecycle::leave($org, $a['employment_id'], '2024-09-30', '', Fx::actor());
    throws(fn() => Lifecycle::join($org, ['person_uuid' => $a['person_uuid'], 'job_title' => 'X', 'start_date' => '2024-09-30'], Fx::actor()), 'already employed');   // the last day counts as employed
    Lifecycle::join($org, ['person_uuid' => $a['person_uuid'], 'job_title' => 'X', 'start_date' => '2024-10-01'], Fx::actor());   // the next day is fine
});

// ── Mover ───────────────────────────────────────────────────────────────────

test('mover: changes are recorded with their dates, and the old team stint is kept', function () {
    $org = Fx::org();
    $day = Fx::team($org, 'Day'); $night = Fx::team($org, 'Night');
    $s = Fx::staff($org, 'Mo', 'Mover', ['team_id' => $day, 'start_date' => '2025-01-06']);
    Clock::freeze('2025-06-10');
    Lifecycle::move($org, $s['employment_id'], ['job_title' => 'Team Leader', 'hours_per_week' => '37.5', 'new_team_id' => $night, 'new_team_role' => 'Lead'], '2025-06-02', Fx::actor());
    $emp = Lifecycle::currentEmployment($s['person_id'], '2025-06-10');
    eq($emp['job_title'], 'Team Leader');
    eq(Fx::count('employment_changes', 'employment_id = ?', [$s['employment_id']]), 3, 'job title, hours and team');
    $stints = AssignmentRepo::forPerson($s['person_id']);
    eq(count($stints), 2);
    $dayStint = array_values(array_filter($stints, fn($a) => (int) $a['team_id'] === $day))[0];
    eq([$dayStint['valid_from'], $dayStint['valid_to']], ['2025-01-06', '2025-06-01'], 'day team stint ends the day before the move');
    $nightStint = array_values(array_filter($stints, fn($a) => (int) $a['team_id'] === $night))[0];
    eq([$nightStint['valid_from'], $nightStint['valid_to']], ['2025-06-02', null]);
    eq(Fx::count('outbox_events', "organisation_id = ? AND type = 'employment.changed'", [$org]), 1);
});

test('mover: refuses future dates, no-op changes, and employments that are not active', function () {
    $org = Fx::org();
    $s = Fx::staff($org, 'No', 'Op');
    Clock::freeze('2025-06-10');
    throws(fn() => Lifecycle::move($org, $s['employment_id'], ['job_title' => 'X'], '2025-07-01', Fx::actor()), 'today or earlier');
    throws(fn() => Lifecycle::move($org, $s['employment_id'], ['job_title' => 'Support Worker'], '2025-06-01', Fx::actor()), 'Nothing has changed');
    throws(fn() => Lifecycle::move($org, $s['employment_id'], ['job_title' => 'X'], '2024-01-01', Fx::actor()), 'not active');
    throws(fn() => Lifecycle::move($org, $s['employment_id'], ['job_title' => 'X', 'line_manager_person_id' => $s['person_id']], '2025-06-01', Fx::actor()), 'own line manager');
});

// ── Leaver ──────────────────────────────────────────────────────────────────

test('leaver: ends the employment, team places, key worker links and login, and creates the offboarding work, in one go', function () {
    $org = Fx::org();
    $team = Fx::team($org, 'Care Home');
    $boss = Fx::staff($org, 'Bea', 'Boss');
    $leaver = Fx::staff($org, 'Lee', 'Leaver', ['team_id' => $team, 'create_login' => true, 'line_manager_person_id' => null]);
    $report = Fx::staff($org, 'Rob', 'Report', ['line_manager_person_id' => $leaver['person_id']]);
    $client = Fx::supported($org, 'Cleo', 'Client');
    CareRepo::assignKeyWorker($org, $client['id'], $leaver['person_id'], 'Key worker', '2025-03-01', Fx::actor());
    $inv = DB::one('SELECT id FROM users WHERE person_id = ?', [$leaver['person_id']]);
    DB::update('users', ['status' => 'active'], ['id' => $inv['id']]);

    $res = Lifecycle::leave($org, $leaver['employment_id'], '2025-09-30', 'resigned', Fx::actor());
    eq($res, ['teams' => 1, 'keyworkers' => 1, 'reports' => 1, 'logins' => 1, 'tasks' => 5]);

    $emp = DB::one('SELECT * FROM employments WHERE id = ?', [$leaver['employment_id']]);
    eq([$emp['end_date'], $emp['leaving_reason']], ['2025-09-30', 'resigned']);
    eq(Fx::count('team_assignments', 'person_id = ? AND valid_to = ?', [$leaver['person_id'], '2025-09-30']), 1);
    eq(Fx::count('key_worker_assignments', 'staff_person_id = ? AND valid_to = ?', [$leaver['person_id'], '2025-09-30']), 1);
    eq(Fx::count('tasks', "kind = 'reassign' AND organisation_id = ?", [$org]), 2, 'a new key worker for the client and a new line manager for the report');
    eq(Fx::count('tasks', "kind = 'leaver' AND person_id = ?", [$leaver['person_id']]), 5);
    eq(DB::value('SELECT access_ends_on FROM users WHERE person_id = ?', [$leaver['person_id']]), '2025-09-30');
    eq(Fx::count('outbox_events', "organisation_id = ? AND type = 'employment.ended'", [$org]), 1);
    eq(Fx::count('outbox_events', "organisation_id = ? AND type = 'team_assignment.ended'", [$org]), 1);
    eq(Fx::count('outbox_events', "organisation_id = ? AND type = 'keyworker.ended'", [$org]), 1);
    ok(Fx::count('persons', 'id = ?', [$leaver['person_id']]) === 1, 'the person is never deleted');
});

test('leaver: the preview matches what actually happens', function () {
    $org = Fx::org();
    $team = Fx::team($org, 'Hub');
    $l = Fx::staff($org, 'Pre', 'View', ['team_id' => $team, 'create_login' => true]);
    $c = Fx::supported($org, 'Cy', 'Client');
    CareRepo::assignKeyWorker($org, $c['id'], $l['person_id'], null, '2025-03-01', Fx::actor());
    $p = Lifecycle::previewLeave($org, $l['employment_id'], '2025-08-01');
    eq(count($p['teams']), 1); eq(count($p['keyworkers']), 1); eq(count($p['logins']), 1); eq($p['tasks'], 5);
    $before = Fx::count('team_assignments', 'valid_to IS NOT NULL');
    eq(Fx::count('team_assignments', 'valid_to IS NOT NULL'), $before, 'previewing changes nothing');
    $r = Lifecycle::leave($org, $l['employment_id'], '2025-08-01', '', Fx::actor());
    eq([count($p['teams']), count($p['keyworkers']), count($p['logins'])], [$r['teams'], $r['keyworkers'], $r['logins']]);
});

test('leaver: access lasts through the last day and stops after it, with no scheduled job', function () {
    $org = Fx::org();
    $l = Fx::staff($org, 'Day', 'Count', ['create_login' => true]);
    DB::update('users', ['status' => 'active'], ['person_id' => $l['person_id']]);
    Lifecycle::leave($org, $l['employment_id'], '2025-09-30', '', Fx::actor());
    $u = DB::one('SELECT * FROM users WHERE person_id = ?', [$l['person_id']]);
    ok(UserRepo::canSignIn($u, '2025-09-30'), 'still works on the last day');
    ok(!UserRepo::canSignIn($u, '2025-10-01'), 'stops the day after');
});

test('leaver: a future last day keeps everything working until then', function () {
    $org = Fx::org();
    $team = Fx::team($org, 'T');
    $l = Fx::staff($org, 'Notice', 'Period', ['team_id' => $team]);
    Clock::freeze('2025-09-01');
    Lifecycle::leave($org, $l['employment_id'], '2025-09-30', '', Fx::actor());
    ok(Lifecycle::currentEmployment($l['person_id'], '2025-09-15') !== null, 'still employed in the notice period');
    ok(Lifecycle::currentEmployment($l['person_id'], '2025-10-01') === null, 'not employed after');
    eq(count(AssignmentRepo::members($team, '2025-09-15')), 1, 'still in the team during notice');
    eq(count(AssignmentRepo::members($team, '2025-10-01')), 0, 'gone after the last day');
});

test('leaver: cannot leave twice, or before they started', function () {
    $org = Fx::org();
    $l = Fx::staff($org, 'Twice', 'Left', ['start_date' => '2025-03-03']);
    throws(fn() => Lifecycle::leave($org, $l['employment_id'], '2025-01-01', '', Fx::actor()), 'before they started');
    Lifecycle::leave($org, $l['employment_id'], '2025-09-30', '', Fx::actor());
    throws(fn() => Lifecycle::leave($org, $l['employment_id'], '2025-10-15', '', Fx::actor()), 'already ended');
});

test('leaver: a returning leaver gets their login back', function () {
    $org = Fx::org();
    $a = Fx::staff($org, 'Back', 'Again', ['create_login' => true, 'start_date' => '2024-01-08']);
    DB::update('users', ['status' => 'active'], ['person_id' => $a['person_id']]);
    Lifecycle::leave($org, $a['employment_id'], '2024-06-30', '', Fx::actor());
    ok(DB::value('SELECT access_ends_on FROM users WHERE person_id = ?', [$a['person_id']]) === '2024-06-30');
    Lifecycle::join($org, ['person_uuid' => $a['person_uuid'], 'job_title' => 'Worker', 'start_date' => '2025-03-03', 'create_login' => true], Fx::actor());
    ok(DB::value('SELECT access_ends_on FROM users WHERE person_id = ?', [$a['person_id']]) === null);
});

// ── Isolation between organisations ─────────────────────────────────────────

test('tenancy: one organisation can never see or change another organisation\'s people', function () {
    $a = Fx::org(); $b = Fx::org();
    $pa = Fx::staff($a, 'Alpha', 'One');
    $pb = Fx::staff($b, 'Beta', 'Two');
    ok(PersonRepo::byId($a, $pb['person_id']) === null, 'cannot read');
    ok(PersonRepo::byUuid($a, $pb['person_uuid']) === null, 'cannot read by uuid');
    throws(fn() => Lifecycle::join($a, ['person_uuid' => $pb['person_uuid'], 'job_title' => 'X', 'start_date' => '2025-05-05'], Fx::actor()), 'not found');
    throws(fn() => Lifecycle::leave($a, $pb['employment_id'], '2025-09-30', '', Fx::actor()), 'not found');
    throws(fn() => AssignmentRepo::assign($a, Fx::team($a, 'Mine'), $pb['person_id'], null, false, '2025-05-05', Fx::actor()), 'not found');
    eq(count(PersonRepo::search($a)), 1);
});
