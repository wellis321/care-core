<?php
declare(strict_types=1);

use App\Core\Database as DB;
use App\Modules\Recruitment\ApplicationRepo;
use App\Modules\Recruitment\RoleRepo;
use App\Modules\Recruitment\VacancyRepo;

/** A role with two checks (one required), a team and a line manager, and an open vacancy for it. @return array<string,mixed> */
function recruitmentSetup(int $org, int $posts = 1): array
{
    $team = Fx::team($org, 'Hamilton Support');
    $mgr = Fx::staff($org, 'Mary', 'Manager');
    $role = RoleRepo::create($org, ['title' => 'Support Worker', 'code' => 'SW', 'description' => 'Supports people at home.', 'default_team_id' => $team, 'hours_per_week' => 37.5], Fx::actor());
    RoleRepo::addRequirement($org, $role['id'], 'PVG scheme membership', null, true, Fx::actor());
    RoleRepo::addRequirement($org, $role['id'], 'Driving licence', 'Only if the post needs it', false, Fx::actor());
    $vac = VacancyRepo::create($org, ['role_id' => $role['id'], 'posts' => $posts, 'line_manager_person_id' => $mgr['person_id']], Fx::actor());
    VacancyRepo::open($org, $vac['id'], Fx::actor());
    return ['team' => $team, 'mgr' => $mgr, 'role' => $role, 'vac' => $vac];
}

function candidate(int $org, int $vacancyId, string $first = 'Cat', string $last = 'Candidate', array $more = []): array
{
    return ApplicationRepo::create($org, $vacancyId, ['first_name' => $first, 'last_name' => $last, 'email' => strtolower($first . $last) . '@example.test', 'date_of_birth' => '1995-04-05'] + $more, Fx::actor());
}

/** Take an application as far as an accepted offer. */
function toAcceptedOffer(int $org, int $appId, string $start = '2025-03-03'): void
{
    ApplicationRepo::setStage($org, $appId, 'shortlisted', [], Fx::actor());
    ApplicationRepo::setStage($org, $appId, 'interview', ['interview_date' => '2025-02-10'], Fx::actor());
    ApplicationRepo::setStage($org, $appId, 'offer', ['proposed_start_date' => $start], Fx::actor());
    ApplicationRepo::acceptOffer($org, $appId, '2025-02-20', Fx::actor());
}

test('recruitment: a role holds its reporting line, default team and the checks needed before starting, and loops are refused', function () {
    $org = Fx::org();
    $team = Fx::team($org, 'North');
    $head = RoleRepo::create($org, ['title' => 'Service Manager'], Fx::actor());
    $lead = RoleRepo::create($org, ['title' => 'Team Leader', 'reports_to_role_id' => $head['id'], 'default_team_id' => $team, 'code' => 'TL'], Fx::actor());
    $role = RoleRepo::byId($org, $lead['id']);
    eq([(int) $role['reports_to_role_id'], (int) $role['default_team_id'], $role['code']], [$head['id'], $team, 'TL']);
    throws(fn() => RoleRepo::update($org, $head['id'], ['reports_to_role_id' => $lead['id']], Fx::actor()), 'loop');
    throws(fn() => RoleRepo::update($org, $head['id'], ['reports_to_role_id' => $head['id']], Fx::actor()), 'loop');
    throws(fn() => RoleRepo::create($org, ['title' => 'Another', 'code' => 'TL'], Fx::actor()), 'code');
    throws(fn() => RoleRepo::create($org, ['title' => ''], Fx::actor()), 'Title');
    throws(fn() => RoleRepo::create($org, ['title' => 'X', 'contract_type' => 'nonsense'], Fx::actor()), 'contract');
    // another organisation's team cannot be used
    $other = Fx::team(Fx::org(), 'Not mine');
    throws(fn() => RoleRepo::update($org, $head['id'], ['default_team_id' => $other], Fx::actor()), 'team');
    RoleRepo::addRequirement($org, $lead['id'], 'Right to work', null, true, Fx::actor());
    eq(count(RoleRepo::requirements($lead['id'])), 1);
    RoleRepo::setActive($org, $lead['id'], false, Fx::actor());
    throws(fn() => VacancyRepo::create($org, ['role_id' => $lead['id']], Fx::actor()), 'retired');
});

test('recruitment: a vacancy starts from its role, gets a reference, and only an open one takes candidates', function () {
    $org = Fx::org();
    $s = recruitmentSetup($org, 2);
    $v = VacancyRepo::byId($org, $s['vac']['id']);
    eq([$v['reference'], $v['title'], (int) $v['team_id'], (int) $v['posts'], (float) $v['hours_per_week'], $v['status']], ['VAC-0001', 'Support Worker', $s['team'], 2, 37.5, 'open']);
    $second = VacancyRepo::create($org, ['role_id' => $s['role']['id']], Fx::actor());
    eq($second['reference'], 'VAC-0002');
    throws(fn() => candidate($org, $second['id']), 'open vacancy');   // still a draft
    throws(fn() => VacancyRepo::create($org, ['role_id' => $s['role']['id'], 'posts' => 0], Fx::actor()), 'posts');
    $a = candidate($org, $s['vac']['id']);
    eq(count(ApplicationRepo::checks($a['id'])), 2, 'the role’s checks are copied to the application');
    throws(fn() => candidate($org, $s['vac']['id']), 'already applied');
});

test('recruitment: stages follow the pipeline, an offer needs a start date, and hired is only reached by hiring', function () {
    $org = Fx::org();
    $s = recruitmentSetup($org);
    $a = candidate($org, $s['vac']['id']);
    throws(fn() => ApplicationRepo::setStage($org, $a['id'], 'offer', ['proposed_start_date' => '2025-03-03'], Fx::actor()), 'straight to');
    ApplicationRepo::setStage($org, $a['id'], 'shortlisted', [], Fx::actor());
    throws(fn() => ApplicationRepo::setStage($org, $a['id'], 'offer', [], Fx::actor()), 'Proposed start date');
    throws(fn() => ApplicationRepo::setStage($org, $a['id'], 'hired', [], Fx::actor()), 'Hire');
    throws(fn() => ApplicationRepo::setStage($org, $a['id'], 'shortlisted', [], Fx::actor()), 'already');
    throws(fn() => ApplicationRepo::acceptOffer($org, $a['id'], null, Fx::actor()), 'no offer');
    ApplicationRepo::setStage($org, $a['id'], 'rejected', ['reason' => 'Not enough experience'], Fx::actor());
    eq(ApplicationRepo::byId($org, $a['id'])['reason'], 'Not enough experience');
    ApplicationRepo::setStage($org, $a['id'], 'applied', [], Fx::actor());   // reopened
    eq(ApplicationRepo::byId($org, $a['id'])['reason'], null);
});

test('recruitment: hiring needs an accepted offer and the required checks, then starts them through the joiner process', function () {
    $org = Fx::org();
    $s = recruitmentSetup($org);
    $a = candidate($org, $s['vac']['id'], 'Hana', 'Hired', ['phone' => '07700 900222']);
    throws(fn() => ApplicationRepo::hire($org, $a['id'], [], Fx::actor()), 'offer');
    ApplicationRepo::setStage($org, $a['id'], 'shortlisted', [], Fx::actor());
    ApplicationRepo::setStage($org, $a['id'], 'offer', ['proposed_start_date' => '2025-03-03'], Fx::actor());
    throws(fn() => ApplicationRepo::hire($org, $a['id'], [], Fx::actor()), 'accepted the offer');
    ApplicationRepo::acceptOffer($org, $a['id'], '2025-02-20', Fx::actor());
    $outstanding = ApplicationRepo::outstanding($a['id']);
    eq($outstanding, ['PVG scheme membership'], 'only the required check stands in the way');
    throws(fn() => ApplicationRepo::hire($org, $a['id'], [], Fx::actor()), 'PVG scheme membership');
    $check = DB::one('SELECT id FROM application_checks WHERE application_id = ? AND is_required = 1', [$a['id']]);
    ApplicationRepo::setCheck($org, $a['id'], (int) $check['id'], '2025-02-25', 'Certificate seen', Fx::actor());
    eq(ApplicationRepo::outstanding($a['id']), []);

    $before = (int) DB::value('SELECT COUNT(*) FROM outbox_events WHERE organisation_id = ?', [$org]);
    $r = ApplicationRepo::hire($org, $a['id'], ['employee_ref' => 'E100'], Fx::actor());
    eq($r['filled'], true, 'one post, one hire: the vacancy is filled');
    $person = DB::one('SELECT * FROM persons WHERE uuid = ?', [$r['person_uuid']]);
    eq([$person['first_name'], $person['phone'], $person['date_of_birth']], ['Hana', '07700 900222', '1995-04-05'], 'their details become the person');
    $emp = DB::one('SELECT * FROM employments WHERE uuid = ?', [$r['employment_uuid']]);
    eq([$emp['job_title'], $emp['start_date'], $emp['employee_ref'], (int) $emp['line_manager_person_id'], (float) $emp['hours_per_week']], ['Support Worker', '2025-03-03', 'E100', $s['mgr']['person_id'], 37.5], 'the role, the manager and the hours come from the vacancy');
    ok((bool) DB::value('SELECT 1 FROM team_assignments WHERE person_id = ? AND team_id = ?', [$person['id'], $s['team']]), 'placed in the vacancy’s team');
    ok($r['tasks'] > 0, 'the onboarding checklist was created');
    $types = array_column(DB::all('SELECT type FROM outbox_events WHERE organisation_id = ? AND id > (SELECT COALESCE(MAX(id), 0) - 20 FROM outbox_events)', [$org]), 'type');
    foreach (['employment.started', 'application.hired', 'vacancy.filled'] as $t) {
        ok(in_array($t, $types, true), "event $t");
    }
    $app = ApplicationRepo::byId($org, $a['id']);
    eq([$app['stage'], (int) $app['person_id']], ['hired', (int) $person['id']]);
    eq(VacancyRepo::byId($org, $s['vac']['id'])['status'], 'filled');
    throws(fn() => ApplicationRepo::hire($org, $a['id'], [], Fx::actor()), 'already been hired');
    throws(fn() => ApplicationRepo::update($org, $a['id'], ['phone' => '1'], Fx::actor()), 'People now');
});

test('recruitment: several posts fill one by one, an existing person is matched rather than duplicated, and a filled vacancy takes no more hires', function () {
    $org = Fx::org();
    $s = recruitmentSetup($org, 2);
    $skip = fn(array $a) => DB::update('application_checks', ['done_on' => '2025-02-25'], ['application_id' => $a['id']]);
    // a returning person: someone who worked here before, applying with the same email
    $old = Fx::staff($org, 'Ruth', 'Returner', ['start_date' => '2020-01-06']);
    \App\Modules\Staff\Lifecycle::leave($org, $old['employment_id'], '2024-12-31', 'resigned', Fx::actor());
    $email = DB::value('SELECT email FROM persons WHERE id = ?', [$old['person_id']]);
    $r1 = ApplicationRepo::create($org, $s['vac']['id'], ['first_name' => 'Ruth', 'last_name' => 'Returner', 'email' => $email], Fx::actor());
    $skip($r1);
    toAcceptedOffer($org, $r1['id']);
    $hire1 = ApplicationRepo::hire($org, $r1['id'], [], Fx::actor());
    eq($hire1['person_uuid'], $old['person_uuid'], 'matched by email, not duplicated');
    eq($hire1['rehire'], true);
    eq(VacancyRepo::byId($org, $s['vac']['id'])['status'], 'open', 'one of two posts filled');
    $a2 = candidate($org, $s['vac']['id'], 'Dan', 'Second');
    $skip($a2);
    toAcceptedOffer($org, $a2['id']);
    $a3 = candidate($org, $s['vac']['id'], 'Eve', 'Third');
    $skip($a3);
    toAcceptedOffer($org, $a3['id']);
    ApplicationRepo::hire($org, $a2['id'], [], Fx::actor());
    eq(VacancyRepo::byId($org, $s['vac']['id'])['status'], 'filled');
    throws(fn() => ApplicationRepo::hire($org, $a3['id'], [], Fx::actor()), 'filled');
});

test('recruitment: closing a vacancy can let the remaining candidates go, and an unsuccessful candidate’s details can be removed', function () {
    $org = Fx::org();
    $s = recruitmentSetup($org);
    $a = candidate($org, $s['vac']['id'], 'Una', 'Unlucky', ['phone' => '07700 900333', 'notes' => 'Strong interview']);
    $b = candidate($org, $s['vac']['id'], 'Ben', 'Bystander');
    eq(VacancyRepo::close($org, $s['vac']['id'], true, Fx::actor()), 2);
    eq([ApplicationRepo::byId($org, $a['id'])['stage'], ApplicationRepo::byId($org, $a['id'])['reason']], ['rejected', 'The vacancy closed']);
    throws(fn() => ApplicationRepo::removeDetails($org, $a['id'] + 999, Fx::actor()), 'not found');
    ApplicationRepo::removeDetails($org, $a['id'], Fx::actor());
    $gone = ApplicationRepo::byId($org, $a['id']);
    eq([$gone['first_name'], $gone['phone'], $gone['notes'], $gone['email']], ['Removed', null, null, null]);
    throws(fn() => VacancyRepo::open($org, VacancyRepo::byId($org, $s['vac']['id'])['id'], Fx::actor()) ?: throw new App\Core\DomainException('x'), 'x');   // reopening is allowed
    eq(VacancyRepo::byId($org, $s['vac']['id'])['status'], 'open');
});

test('recruitment: each organisation sees only its own roles, vacancies and candidates', function () {
    $a = Fx::org();
    $b = Fx::org();
    $s = recruitmentSetup($a);
    $cand = candidate($a, $s['vac']['id']);
    eq(RoleRepo::byId($b, $s['role']['id']), null);
    eq(VacancyRepo::byId($b, $s['vac']['id']), null);
    eq(ApplicationRepo::byId($b, $cand['id']), null);
    eq(count(VacancyRepo::all($b)), 0);
    throws(fn() => VacancyRepo::create($b, ['role_id' => $s['role']['id']], Fx::actor()), 'role');
    throws(fn() => ApplicationRepo::create($b, $s['vac']['id'], ['first_name' => 'X', 'last_name' => 'Y'], Fx::actor()), 'not found');
});
