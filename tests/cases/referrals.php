<?php
declare(strict_types=1);

use App\Core\Database as DB;
use App\Core\DomainException;
use App\Modules\Referrals\ReferralRepo;

function newReferral(int $org, array $over = []): array
{
    return ReferralRepo::create($org, $over + ['source' => 'council', 'person_name' => 'Morag Fraser', 'reason' => 'Needs support at home after a fall', 'plan_types' => ['home', 'dementia'], 'skills' => ['PEG feeding', 'Makaton', 'peg feeding']], Fx::actor());
}

function refuses(callable $f, string $contains): void
{
    try {
        $f();
    } catch (DomainException $e) {
        ok(str_contains($e->getMessage(), $contains), 'wrong message: ' . $e->getMessage());
        return;
    }
    throw new AssertionError("expected a refusal containing: $contains");
}

test('referrals: recording one gives it a reference, keeps plan types and de-duplicated skills, and raises an event with identifiers only', function () {
    $org = Fx::org();
    $r = newReferral($org);
    eq($r['reference'], 'REF-0001');
    eq(newReferral($org)['reference'], 'REF-0002');
    $row = ReferralRepo::byId($org, $r['id']);
    eq($row['status'], 'received');
    eq(ReferralRepo::planTypes($row['plan_types']), ['dementia', 'home']);
    eq(ReferralRepo::skills($r['id']), ['PEG feeding', 'Makaton']);
    $ev = DB::one("SELECT payload FROM outbox_events WHERE type = 'referral.received' ORDER BY id DESC LIMIT 1");
    ok($ev !== null, 'an event was raised');
    ok(!str_contains((string) $ev['payload'], 'Morag') && !str_contains((string) $ev['payload'], 'fall'), 'the event carries no name and no health detail');
});

test('referrals: the basics are required and a future received date is refused', function () {
    $org = Fx::org();
    refuses(fn() => ReferralRepo::create($org, ['source' => 'nope', 'person_name' => 'Ann Lee', 'reason' => 'Because'], Fx::actor()), 'came from');
    refuses(fn() => ReferralRepo::create($org, ['source' => 'nhs', 'person_name' => 'A', 'reason' => 'Because of a fall'], Fx::actor()), 'name');
    refuses(fn() => ReferralRepo::create($org, ['source' => 'nhs', 'person_name' => 'Ann Lee', 'reason' => 'no'], Fx::actor()), 'why');
    refuses(fn() => ReferralRepo::create($org, ['source' => 'nhs', 'person_name' => 'Ann Lee', 'reason' => 'After a fall', 'received_on' => '2999-01-01'], Fx::actor()), 'future');
});

test('referrals: received → assessing → accepted; declining or withdrawing needs a reason; a closed referral stays closed', function () {
    $org = Fx::org();
    $r = newReferral($org);
    ReferralRepo::setStatus($org, $r['id'], 'assessing', null, Fx::actor());
    refuses(fn() => ReferralRepo::setStatus($org, $r['id'], 'declined', '', Fx::actor()), 'why');
    ReferralRepo::setStatus($org, $r['id'], 'accepted', null, Fx::actor());
    refuses(fn() => ReferralRepo::setStatus($org, $r['id'], 'assessing', null, Fx::actor()), 'already been accepted');
    refuses(fn() => ReferralRepo::update($org, $r['id'], ['reason' => 'Changed my mind about this'], Fx::actor()), 'cannot be changed');
    ReferralRepo::setStatus($org, $r['id'], 'withdrawn', 'The family chose another service', Fx::actor());
    refuses(fn() => ReferralRepo::setStatus($org, $r['id'], 'accepted', null, Fx::actor()), 'closed');
    eq(ReferralRepo::byId($org, $r['id'])['decision_reason'], 'The family chose another service');
    $ev = DB::one("SELECT payload FROM outbox_events WHERE type = 'referral.status_changed' ORDER BY id DESC LIMIT 1");
    ok(str_contains((string) $ev['payload'], 'withdrawn') && !str_contains((string) $ev['payload'], 'family'), 'the status event says what happened, not why');
});

test('referrals: an accepted referral links to an existing person once, or starts a person from its name', function () {
    $org = Fx::org();
    $r = newReferral($org);
    refuses(fn() => ReferralRepo::createPersonAndLink($org, $r['id'], Fx::actor()), 'accepted');
    ReferralRepo::setStatus($org, $r['id'], 'accepted', null, Fx::actor());
    $pat = Fx::supported($org, 'Morag', 'Fraser');
    ReferralRepo::linkPerson($org, $r['id'], $pat['id'], Fx::actor());
    eq((int) ReferralRepo::byId($org, $r['id'])['person_id'], (int) $pat['id']);
    refuses(fn() => ReferralRepo::linkPerson($org, $r['id'], $pat['id'], Fx::actor()), 'already linked');
    eq(ReferralRepo::forPerson($org, $pat['id'])['reference'], $r['reference']);

    $r2 = newReferral($org, ['person_name' => 'Iain MacLeod']);
    ReferralRepo::setStatus($org, $r2['id'], 'accepted', null, Fx::actor());
    $person = ReferralRepo::createPersonAndLink($org, $r2['id'], Fx::actor());
    $p = DB::one('SELECT first_name, last_name FROM persons WHERE id = ?', [$person['id']]);
    eq([$p['first_name'], $p['last_name']], ['Iain', 'MacLeod']);
    refuses(fn() => ReferralRepo::linkPerson($org, newReferral($org)['id'], $pat['id'], Fx::actor()), 'accepted');
});

test('referrals: one organisation never sees another’s', function () {
    $a = Fx::org();
    $b = Fx::org();
    $r = newReferral($a);
    ok(ReferralRepo::byId($b, $r['id']) === null, 'another organisation cannot load it by id');
    ok(ReferralRepo::byUuid($b, $r['uuid']) === null, 'nor by uuid');
    eq(count(ReferralRepo::all($b)), 0);
    refuses(fn() => ReferralRepo::setStatus($b, $r['id'], 'accepted', null, Fx::actor()), 'not found');
});

test('referrals API: record, read, assess, accept and link by scope; no scope, no access', function () {
    $org = Fx::org();
    $key = apiKey($org, ['referrals.read', 'referrals.write', 'people.read']);
    $created = apiOk(api('POST', '/referrals', $key['token'], ['source' => 'nhs', 'person_name' => 'Elspeth Grant', 'reason' => 'Discharge from hospital', 'plan_types' => ['home'], 'skills' => ['Stoma care']]), 201)['data'];
    eq($created['status'], 'received');
    eq($created['skills'], ['Stoma care']);
    eq(apiOk(api('GET', '/referrals/' . $created['id'], $key['token']))['data']['person_name'], 'Elspeth Grant');
    eq(count(apiOk(api('GET', '/referrals', $key['token'], null, ['status' => 'received']))['data']), 1);
    $bad = api('POST', '/referrals/' . $created['id'] . '/status', $key['token'], ['status' => 'declined']);
    eq($bad[0], 422);
    apiOk(api('POST', '/referrals/' . $created['id'] . '/status', $key['token'], ['status' => 'accepted']));
    $linked = apiOk(api('POST', '/referrals/' . $created['id'] . '/link', $key['token'], ['create_person' => true]))['data'];
    ok($linked['person_id'] !== null, 'linked to a person');
    $readOnly = apiKey($org, ['referrals.read']);
    eq(api('POST', '/referrals', $readOnly['token'], ['source' => 'nhs', 'person_name' => 'Ann Lee', 'reason' => 'After a fall'])[0], 403);
    $none = apiKey($org, ['people.read']);
    eq(api('GET', '/referrals', $none['token'])[0], 403);
});
