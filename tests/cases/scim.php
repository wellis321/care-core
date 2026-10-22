<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Database as DB;
use App\Modules\Identity\ScimService as Scim;
use App\Modules\Staff\Lifecycle;
use App\Modules\Teams\AssignmentRepo;

test('scim: a pushed user becomes a joiner, with a person, employment, login and onboarding checklist', function () {
    $org = Fx::org();
    [$status, $u] = Scim::handle($org, 'POST', '/Users', [], ['userName' => 'New.Starter@Example.test', 'name' => ['givenName' => 'New', 'familyName' => 'Starter'], 'title' => 'Cook', 'externalId' => 'E-100', 'active' => true]);
    eq($status, 201);
    eq([$u['userName'], $u['active'], $u['title'], $u['externalId']], ['new.starter@example.test', true, 'Cook', 'E-100']);
    eq(Fx::count('tasks', "organisation_id = ? AND kind = 'joiner'", [$org]), 7);
    eq(Fx::count('users', 'organisation_id = ?', [$org]), 1);
    eq(Fx::count('outbox_events', "organisation_id = ? AND type = 'employment.started'", [$org]), 1);
    [$s2] = Scim::handle($org, 'POST', '/Users', [], ['userName' => 'new.starter@example.test', 'name' => ['givenName' => 'New', 'familyName' => 'Starter']]);
    eq($s2, 409, 'the same user cannot be created twice');
    [$s3] = Scim::handle($org, 'POST', '/Users', [], ['userName' => 'not-an-email']);
    eq($s3, 400);
});

test('scim: "active: False" (Entra sends a string) runs the full leaver process', function () {
    $org = Fx::org();
    $team = Fx::team($org, 'Care');
    Clock::freeze('2025-06-02');
    [, $u] = Scim::handle($org, 'POST', '/Users', [], ['userName' => 'leaving@example.test', 'name' => ['givenName' => 'Leaving', 'familyName' => 'Soon']]);
    $person = DB::one('SELECT id FROM persons WHERE uuid = ?', [$u['id']]);
    AssignmentRepo::assign($org, $team, (int) $person['id'], null, true, '2025-06-02', Fx::actor());
    Clock::freeze('2025-06-10');
    [$status, $after] = Scim::handle($org, 'PATCH', '/Users/' . $u['id'], [], [
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']],
    ]);
    eq($status, 200);
    eq($after['active'], false);
    eq(DB::value('SELECT end_date FROM employments WHERE person_id = ?', [$person['id']]), '2025-06-09', 'last day is yesterday: access stops now');
    eq(DB::value('SELECT valid_to FROM team_assignments WHERE person_id = ?', [$person['id']]), '2025-06-09', 'team place ended');
    eq(DB::value('SELECT access_ends_on FROM users WHERE person_id = ?', [$person['id']]), '2025-06-09', 'login no longer works today');
    eq(Fx::count('tasks', "person_id = ? AND kind = 'leaver'", [$person['id']]), 5, 'offboarding checklist created');
    eq(Fx::count('outbox_events', "organisation_id = ? AND type = 'employment.ended'", [$org]), 1);
    [$again] = Scim::handle($org, 'PATCH', '/Users/' . $u['id'], [], ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
    eq($again, 200, 'repeating the request is harmless');
    eq(Fx::count('outbox_events', "organisation_id = ? AND type = 'employment.ended'", [$org]), 1, 'and does not run the leaver process twice');
});

test('scim: re-enabling a leaver rehires them with a NEW employment', function () {
    $org = Fx::org();
    Clock::freeze('2025-06-02');
    [, $u] = Scim::handle($org, 'POST', '/Users', [], ['userName' => 'return@example.test', 'name' => ['givenName' => 'Ret', 'familyName' => 'Urn'], 'title' => 'Carer']);
    Clock::freeze('2025-06-10');
    Scim::handle($org, 'PATCH', '/Users/' . $u['id'], [], ['Operations' => [['op' => 'replace', 'value' => ['active' => false]]]]);   // Entra sometimes omits the path
    $person = DB::one('SELECT id FROM persons WHERE uuid = ?', [$u['id']]);
    Clock::freeze('2025-06-13');
    [, $back] = Scim::handle($org, 'PATCH', '/Users/' . $u['id'], [], ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => 'True']]]);
    eq($back['active'], true);
    eq(count(Lifecycle::employments((int) $person['id'])), 2, 'two stints');
    eq($back['title'], 'Carer', 'keeps their job title');
});

test('scim: list, filter, get, delete, and attributes the system owns are ignored or refused', function () {
    $org = Fx::org(); $other = Fx::org();
    Clock::freeze('2025-06-02');
    [, $a] = Scim::handle($org, 'POST', '/Users', [], ['userName' => 'a@example.test', 'name' => ['givenName' => 'A', 'familyName' => 'One']]);
    Scim::handle($org, 'POST', '/Users', [], ['userName' => 'b@example.test', 'name' => ['givenName' => 'B', 'familyName' => 'Two']]);
    Scim::handle($other, 'POST', '/Users', [], ['userName' => 'c@example.test', 'name' => ['givenName' => 'C', 'familyName' => 'Three']]);
    [, $list] = Scim::handle($org, 'GET', '/Users', [], null);
    eq($list['totalResults'], 2, 'only this organisation\'s people');
    [, $f] = Scim::handle($org, 'GET', '/Users', ['filter' => 'userName eq "b@example.test"'], null);
    eq($f['totalResults'], 1);
    [$bad] = Scim::handle($org, 'GET', '/Users', ['filter' => 'displayName co "x"'], null);
    eq($bad, 400);
    [$g] = Scim::handle($org, 'GET', '/Users/' . $a['id'], [], null);
    eq($g, 200);
    [$cross] = Scim::handle($other, 'GET', '/Users/' . $a['id'], [], null);
    eq($cross, 404, 'another organisation cannot read it');
    [$u] = Scim::handle($org, 'PATCH', '/Users/' . $a['id'], [], ['Operations' => [['op' => 'replace', 'path' => 'userName', 'value' => 'x@example.test']]]);
    eq($u, 400);
    [$ig] = Scim::handle($org, 'PATCH', '/Users/' . $a['id'], [], ['Operations' => [['op' => 'replace', 'path' => 'phoneNumbers', 'value' => []]]]);
    eq($ig, 200, 'attributes we do not own are ignored');
    Clock::freeze('2025-06-10');
    [$del] = Scim::handle($org, 'DELETE', '/Users/' . $a['id'], [], null);
    eq($del, 204);
    [, $after] = Scim::handle($org, 'GET', '/Users/' . $a['id'], [], null);
    eq($after['active'], false, 'DELETE deprovisions, the person record is kept');
});
