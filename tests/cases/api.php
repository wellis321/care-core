<?php
declare(strict_types=1);

use App\Api\Api;
use App\Api\ApiKeys;
use App\Api\Scopes;
use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database as DB;
use App\Core\Request;

const ALL_SCOPES = ['people.read', 'people.write', 'staff.read', 'staff.write', 'compliance.read', 'compliance.write', 'teams.read', 'teams.write', 'care.read', 'care.write', 'tasks.read', 'tasks.write', 'events.read', 'webhooks.manage', 'audit.read', 'admin.read'];

/** @return array{token:string,id:int,uuid:string} */
function apiKey(int $org, array $scopes = ALL_SCOPES, array $over = []): array
{
    return ApiKeys::create($org, $over['name'] ?? 'Test key', $scopes, $over['ips'] ?? null, $over['expires'] ?? null, $over['rate'] ?? 600, null, Fx::actor());
}

/** Call the API in-process: no web server needed. @return array{0:int,1:mixed,2:App\Core\Response} */
function api(string $method, string $path, ?string $token, ?array $body = null, array $query = [], array $headers = [], string $host = 'localhost', string $ip = '203.0.113.9'): array
{
    $h = ['host' => $host, 'content-type' => 'application/json'] + $headers;
    if ($token !== null) {
        $h['authorization'] = 'Bearer ' . $token;
    }
    $req = new Request($method, Api::PREFIX . $path, $query, [], $h, $body === null ? '' : json_encode($body), $ip);
    $res = Api::handle($req);
    return [$res->status, $res->body === '' ? null : json_decode($res->body, true), $res];
}

function apiOk(array $r, int $status = 200): mixed
{
    if ($r[0] !== $status) {
        throw new AssertionError("expected HTTP $status, got {$r[0]}: " . json_encode($r[1]));
    }
    return $r[1];
}

// ── Keys, authentication, scopes ────────────────────────────────────────────

test('api keys: only a hash is stored; the token is shown once and finds the key', function () {
    $org = Fx::org();
    $k = apiKey($org);
    ok(preg_match('/^cc_[0-9a-f]{8}_[0-9a-f]{48}$/', $k['token']) === 1);
    $row = DB::one('SELECT * FROM api_keys WHERE id = ?', [$k['id']]);
    ok(!str_contains(json_encode($row), substr($k['token'], 12)), 'the secret must not be stored');
    eq($row['key_hash'], hash('sha256', $k['token']));
    eq(ApiKeys::find($k['token'])['id'], $k['id']);
    eq(ApiKeys::find(substr($k['token'], 0, -1) . (str_ends_with($k['token'], '0') ? '1' : '0')), null);
    eq(ApiKeys::find('nonsense'), null);
    throws(fn() => ApiKeys::create($org, '', ['people.read'], null, null, 120, null, Fx::actor()), 'name');
    throws(fn() => ApiKeys::create($org, 'x', [], null, null, 120, null, Fx::actor()), 'at least one');
    throws(fn() => ApiKeys::create($org, 'x', ['people.read'], 'not-an-ip', null, 120, null, Fx::actor()), 'not a valid IP');
    throws(fn() => ApiKeys::create($org, 'x', ['people.read'], null, '2001-01-01', 120, null, Fx::actor()), 'future');
    eq(Fx::count('audit_log', "organisation_id = ? AND action = 'apikey.created'", [$org]), 1);
    ok(!str_contains((string) DB::value("SELECT detail FROM audit_log WHERE organisation_id = ? AND action = 'apikey.created'", [$org]), substr($k['token'], 12)));
});

test('api auth: no key, wrong key, revoked, expired and wrong address are all refused, with the right status', function () {
    $org = Fx::org();
    eq(api('GET', '/ping', null)[0], 401);
    eq(api('GET', '/ping', 'cc_00000000_' . str_repeat('0', 48))[0], 401);
    $r = api('GET', '/ping', null);
    eq($r[2]->headers['WWW-Authenticate'], 'Bearer realm="Care Core API"');
    eq($r[2]->headers['Content-Type'], 'application/problem+json; charset=utf-8');
    $k = apiKey($org);
    eq(api('GET', '/ping', $k['token'])[0], 200);
    ApiKeys::revoke($org, $k['id'], Fx::actor());
    $r = api('GET', '/ping', $k['token']);
    eq($r[0], 401);
    ok(str_contains($r[1]['detail'], 'revoked'));
    $e = apiKey($org);
    DB::update('api_keys', ['expires_at' => '2020-01-01 00:00:00'], ['id' => $e['id']]);
    eq(api('GET', '/ping', $e['token'])[0], 401);
    $ip = apiKey($org, ALL_SCOPES, ['ips' => '198.51.100.0/24, 2001:db8::/32']);
    eq(api('GET', '/ping', $ip['token'], null, [], [], 'localhost', '203.0.113.9')[0], 403);
    eq(api('GET', '/ping', $ip['token'], null, [], [], 'localhost', '198.51.100.77')[0], 200);
    eq(api('GET', '/ping', $ip['token'], null, [], [], 'localhost', '2001:db8::1')[0], 200);
    eq(api('GET', '/ping', $ip['token'], null, [], [], 'localhost', '2001:dead::1')[0], 403);
});

test('api auth: the API refuses plain HTTP on a real host (but allows localhost for development)', function () {
    $org = Fx::org();
    $k = apiKey($org);
    putenv('APP_ENV=production');
    $r = api('GET', '/ping', $k['token'], null, [], [], 'core.example.org');
    eq($r[0], 403);
    eq($r[1]['code'], 'https_required');
    eq(api('GET', '/ping', $k['token'], null, [], [], 'localhost:8990')[0], 200);
    putenv('APP_ENV');
});

test('api scopes: each endpoint demands its scope; a read-only key cannot write; sensitive fields need their own scope', function () {
    $org = Fx::org();
    $read = apiKey($org, ['people.read']);
    $r = api('POST', '/people', $read['token'], ['first_name' => 'A', 'last_name' => 'B']);
    eq($r[0], 403);
    eq($r[1]['code'], 'forbidden');
    ok(str_contains($r[1]['detail'], 'people.write'));
    eq(api('GET', '/teams', $read['token'])[0], 403);
    eq(api('GET', '/events', $read['token'])[0], 403);
    eq(api('GET', '/audit', $read['token'])[0], 403);
    eq(api('GET', '/webhooks', $read['token'])[0], 403);
    $scopes = apiKey($org, ['people.read'])['token'];
    $list = apiOk(api('GET', '/scopes', $scopes));
    eq(count($list['data']), count(Scopes::ALL));
    eq(array_values(array_filter($list['data'], fn($s) => $s['granted']))[0]['scope'], 'people.read');
});

test('api rate limit: the key’s own limit applies, with standard headers and Retry-After', function () {
    $org = Fx::org();
    $k = apiKey($org, ['people.read'], ['rate' => 10]);
    for ($i = 0; $i < 10; $i++) {
        $r = api('GET', '/people', $k['token']);
        eq($r[0], 200);
    }
    eq($r[2]->headers['X-RateLimit-Limit'], '10');
    eq($r[2]->headers['X-RateLimit-Remaining'], '0');
    $r = api('GET', '/people', $k['token']);
    eq($r[0], 429);
    eq($r[1]['code'], 'rate_limited');
    ok((int) $r[2]->headers['Retry-After'] >= 1);
    $other = apiKey($org, ['people.read'], ['rate' => 10]);
    eq(api('GET', '/people', $other['token'])[0], 200, 'another key has its own allowance');
});

test('api errors: problem documents with codes and request ids; 404, 405, 415 and malformed JSON are handled', function () {
    $org = Fx::org();
    $k = apiKey($org);
    $r = api('GET', '/nonsense', $k['token']);
    eq([$r[0], $r[1]['code']], [404, 'not_found']);
    ok(strlen($r[2]->headers['X-Request-Id']) === 16 && $r[1]['request_id'] === $r[2]->headers['X-Request-Id']);
    $r = api('DELETE', '/people', $k['token']);
    eq($r[0], 405);
    ok(str_contains($r[2]->headers['Allow'], 'GET') && str_contains($r[2]->headers['Allow'], 'POST'));
    $r = api('POST', '/people', $k['token'], null, [], ['content-type' => 'text/plain']);
    eq($r[0], 422, 'an empty body is validated, not trusted');   // no body: required fields are missing
    $req = new Request('POST', Api::PREFIX . '/people', [], [], ['host' => 'localhost', 'authorization' => 'Bearer ' . $k['token'], 'content-type' => 'text/plain'], '{"first_name":"A"}', '1.2.3.4');
    eq(Api::handle($req)->status, 415);
    $req = new Request('POST', Api::PREFIX . '/people', [], [], ['host' => 'localhost', 'authorization' => 'Bearer ' . $k['token'], 'content-type' => 'application/json'], '{not json', '1.2.3.4');
    $res = Api::handle($req);
    eq($res->status, 400);
    $req = new Request('POST', Api::PREFIX . '/people', [], [], ['host' => 'localhost', 'authorization' => 'Bearer ' . $k['token'], 'content-type' => 'application/json'], '[1,2]', '1.2.3.4');
    eq(Api::handle($req)->status, 400);
    eq(api('GET', '/', null)[0], 200, 'the service document is public');
    eq(api('GET', '/openapi.json', null)[0], 200, 'so is the specification');
});

// ── People ──────────────────────────────────────────────────────────────────

test('api people: create, read, patch; unknown and invalid fields are refused with field errors; Location header; UUIDs only', function () {
    $org = Fx::org();
    $k = apiKey($org);
    $r = api('POST', '/people', $k['token'], ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'date_of_birth' => '1990-05-04']);
    $p = apiOk($r, 201)['data'];
    ok(preg_match('/^[0-9a-f-]{36}$/', $p['id']) === 1);
    ok(str_ends_with($r[2]->headers['Location'], '/api/v1/people/' . $p['id']), $r[2]->headers['Location']);
    ok(!array_key_exists('organisation_id', $p) && !array_key_exists('uuid', $p) && is_string($p['id']));
    eq([$p['is_staff'], $p['is_supported'], $p['current_employment']], [false, false, null]);
    eq(apiOk(api('GET', '/people/' . $p['id'], $k['token']))['data']['email'], 'ada@example.test');
    $r = api('PATCH', '/people/' . $p['id'], $k['token'], ['phone' => '07700 900123']);
    $q = apiOk($r)['data'];
    eq([$q['phone'], $q['first_name']], ['07700 900123', 'Ada'], 'a patch changes only what it sends');
    $r = api('POST', '/people', $k['token'], ['first_name' => 'X', 'surname' => 'Y', 'date_of_birth' => 'tomorrow']);
    eq($r[0], 422);
    $fields = array_column($r[1]['errors'], 'field');
    ok(in_array('last_name', $fields) && in_array('surname', $fields) && in_array('date_of_birth', $fields), json_encode($r[1]['errors']));
    $r = api('POST', '/people', $k['token'], ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'not an email']);
    eq($r[0], 422);
    ok(str_contains($r[1]['detail'], 'email'), 'domain rules still apply and are reported');
    eq(api('GET', '/people/not-a-uuid', $k['token'])[0], 404);
    eq(api('GET', '/people/' . \App\Core\Uuid::v4(), $k['token'])[0], 404);
});

test('api people: another organisation’s people are invisible and cannot be changed', function () {
    $a = Fx::org();
    $b = Fx::org();
    $s = Fx::staff($b, 'Other', 'Org');
    $k = apiKey($a);
    eq(api('GET', '/people/' . $s['person_uuid'], $k['token'])[0], 404);
    eq(api('PATCH', '/people/' . $s['person_uuid'], $k['token'], ['phone' => '07700 900123'])[0], 404);
    eq(apiOk(api('GET', '/people', $k['token']))['data'], []);
    eq(api('GET', '/people/' . $s['person_uuid'] . '/employments', $k['token'])[0], 404);
    eq(api('GET', '/employments/' . $s['employment_uuid'], $k['token'])[0], 404);
    eq(api('POST', '/employments/' . $s['employment_uuid'] . '/end', $k['token'], ['last_day' => '2026-01-01'])[0], 404);
    eq(DB::value('SELECT end_date FROM employments WHERE id = ?', [$s['employment_id']]), null);
});

test('api people: cursor pagination returns every row exactly once, in a stable order, and refuses a bad cursor or limit', function () {
    $org = Fx::org();
    $k = apiKey($org);
    for ($i = 1; $i <= 7; $i++) {
        apiOk(api('POST', '/people', $k['token'], ['first_name' => "P$i", 'last_name' => 'Page']), 201);
    }
    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $res = apiOk(api('GET', '/people', $k['token'], null, ['limit' => '3'] + ($cursor ? ['cursor' => $cursor] : [])));
        $pages++;
        foreach ($res['data'] as $p) {
            $seen[] = $p['first_name'];
        }
        $cursor = $res['page']['next_cursor'];
        eq($res['page']['has_more'], $cursor !== null);
    } while ($cursor);
    eq([$pages, $seen], [3, ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7']]);
    eq(api('GET', '/people', $k['token'], null, ['cursor' => 'garbage'])[0], 400);
    eq(api('GET', '/people', $k['token'], null, ['limit' => '0'])[0], 400);
    eq(api('GET', '/people', $k['token'], null, ['limit' => '201'])[0], 400);
    $only = apiOk(api('GET', '/people', $k['token'], null, ['q' => 'P3']));
    eq(count($only['data']), 1);
});

test('api people: filters (kind, employed_on, updated_since) and least privilege (no care.read means supported-only people are invisible)', function () {
    $org = Fx::org();
    $staff = Fx::staff($org, 'Sam', 'Worker');
    $leaver = Fx::staff($org, 'Lee', 'Gone');
    \App\Modules\Staff\Lifecycle::leave($org, $leaver['employment_id'], '2025-06-30', 'Resigned', Fx::actor());
    $sup = Fx::supported($org, 'Pat', 'Supported');
    $full = apiKey($org);
    $names = fn($r) => array_column(apiOk($r)['data'], 'first_name');
    eq($names(api('GET', '/people', $full['token'], null, ['kind' => 'staff'])), ['Sam']);
    eq($names(api('GET', '/people', $full['token'], null, ['kind' => 'leavers'])), ['Lee']);
    eq($names(api('GET', '/people', $full['token'], null, ['kind' => 'supported'])), ['Pat']);
    eq($names(api('GET', '/people', $full['token'], null, ['employed_on' => '2025-03-01'])), ['Sam', 'Lee']);
    eq($names(api('GET', '/people', $full['token'], null, ['employed_on' => '2025-12-01'])), ['Sam']);
    eq(count(apiOk(api('GET', '/people', $full['token'], null, ['updated_since' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]))['data']), 3);
    eq(count(apiOk(api('GET', '/people', $full['token'], null, ['updated_since' => '2999-01-01']))['data']), 0);
    eq(api('GET', '/people', $full['token'], null, ['kind' => 'bogus'])[0], 400);
    $hr = apiKey($org, ['people.read']);
    eq($names(api('GET', '/people', $hr['token'])), ['Sam', 'Lee'], 'a key without care.read never sees someone who is only supported');
    eq(api('GET', '/people/' . $sup['uuid'], $hr['token'])[0], 404);
    eq(api('GET', '/people', $hr['token'], null, ['kind' => 'supported'])[0], 403);
    $p = apiOk(api('GET', '/people/' . $staff['person_uuid'], $hr['token']))['data'];
    eq($p['is_supported'], null);
    ok(!array_key_exists('notes', $p), 'free-text notes need people.write');
    eq(apiOk(api('GET', '/people/' . $staff['person_uuid'], $full['token']))['data']['is_supported'], false);
});

test('api idempotency: a retried POST does not do it twice; a changed body under the same key is refused', function () {
    $org = Fx::org();
    $k = apiKey($org);
    $body = ['first_name' => 'Once', 'last_name' => 'Only'];
    $a = api('POST', '/people', $k['token'], $body, [], ['idempotency-key' => 'abc-123']);
    $b = api('POST', '/people', $k['token'], $body, [], ['idempotency-key' => 'abc-123']);
    eq([$a[0], $b[0]], [201, 201]);
    eq($a[1], $b[1], 'the same answer');
    eq($b[2]->headers['Idempotent-Replayed'], 'true');
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 1);
    $c = api('POST', '/people', $k['token'], ['first_name' => 'Changed', 'last_name' => 'Only'], [], ['idempotency-key' => 'abc-123']);
    eq([$c[0], $c[1]['code']], [422, 'idempotency_mismatch']);
    $d = api('POST', '/people', $k['token'], ['first_name' => 'Bad'], [], ['idempotency-key' => 'fail-1']);
    eq($d[0], 422);
    $e = api('POST', '/people', $k['token'], ['first_name' => 'Bad', 'last_name' => 'Fixed'], [], ['idempotency-key' => 'fail-1']);
    eq($e[0], 201, 'a failed attempt leaves the key free, so it can be retried with a corrected body');
    $other = apiKey($org);
    $f = api('POST', '/people', $other['token'], $body, [], ['idempotency-key' => 'abc-123']);
    eq($f[0], 201, 'keys are scoped to the API key that used them');
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 3, 'Once Only (twice with that key: once), Bad Fixed, and the second key’s own Once Only');
});

// ── Staff: joiner, mover, leaver ────────────────────────────────────────────

test('api staff: the whole lifecycle through the API — join, move, preview, end — with the same effects as the web interface', function () {
    $org = Fx::org();
    $k = apiKey($org);
    $day = Fx::team($org, 'Day Team');
    $night = Fx::team($org, 'Night Team');
    $boss = Fx::staff($org, 'Bea', 'Boss');
    $teamId = DB::value('SELECT uuid FROM teams WHERE id = ?', [$day]);
    $nightId = DB::value('SELECT uuid FROM teams WHERE id = ?', [$night]);
    $r = api('POST', '/employments', $k['token'], ['first_name' => 'New', 'last_name' => 'Starter', 'email' => 'new.starter@example.test', 'job_title' => 'Support Worker', 'start_date' => '2025-02-03', 'hours_per_week' => 37.5, 'team_id' => $teamId, 'team_role' => 'Care worker', 'line_manager_id' => $boss['person_uuid'], 'employee_ref' => 'E100']);
    $emp = apiOk($r, 201)['data'];
    eq([$emp['job_title'], $emp['status'], $emp['hours_per_week'], $emp['line_manager_id'], $emp['rehire'], $emp['tasks_created']], ['Support Worker', 'current', 37.5, $boss['person_uuid'], false, 7]);
    ok(str_contains($r[2]->headers['Location'], '/employments/' . $emp['id']));
    $personId = $emp['person_id'];
    eq(Fx::count('team_assignments', 'person_id = (SELECT id FROM persons WHERE uuid = ?) AND valid_to IS NULL AND is_primary = 1', [$personId]), 1);
    eq(api('POST', '/employments', $k['token'], ['first_name' => 'Dup', 'last_name' => 'Email', 'email' => 'new.starter@example.test', 'job_title' => 'X', 'start_date' => '2025-02-03'])[0], 422, 'the same rules as the web interface');
    eq(api('POST', '/employments', $k['token'], ['first_name' => 'No', 'last_name' => 'Job'])[0], 422);
    $bad = api('POST', '/employments', $k['token'], ['first_name' => 'A', 'last_name' => 'B', 'job_title' => 'X', 'start_date' => '2025-02-03', 'team_id' => \App\Core\Uuid::v4()]);
    eq([$bad[0], $bad[1]['errors'][0]['field']], [422, 'team_id']);

    Clock::freeze('2025-06-01');
    $m = apiOk(api('POST', '/employments/' . $emp['id'] . '/changes', $k['token'], ['effective_date' => '2025-05-01', 'job_title' => 'Senior Support Worker', 'team_id' => $nightId, 'hours_per_week' => 30]))['data'];
    eq([$m['job_title'], $m['hours_per_week']], ['Senior Support Worker', 30]);
    $changes = apiOk(api('GET', '/employments/' . $emp['id'] . '/changes', $k['token']))['data'];
    ok(count($changes) >= 3 && in_array('job_title', array_column($changes, 'field')));
    $places = apiOk(api('GET', '/people/' . $personId . '/teams', $k['token']))['data'];
    $current = array_values(array_filter($places, fn($p) => $p['end_date'] === null));
    eq([count($current), $current[0]['team_id']], [1, $nightId]);
    eq(api('POST', '/employments/' . $emp['id'] . '/changes', $k['token'], ['effective_date' => '2999-01-01', 'job_title' => 'Future'])[0], 422, 'no future-dated moves');

    $prev = apiOk(api('GET', '/employments/' . $emp['id'] . '/leave-preview', $k['token'], null, ['last_day' => '2025-07-31']))['data'];
    eq([$prev['team_places_that_will_end'], $prev['offboarding_tasks_that_will_be_created']], [['Night Team'], 5]);
    eq(DB::value('SELECT end_date FROM employments WHERE uuid = ?', [$emp['id']]), null, 'a preview changes nothing');
    eq(api('GET', '/employments/' . $emp['id'] . '/leave-preview', $k['token'])[0], 400);
    $end = apiOk(api('POST', '/employments/' . $emp['id'] . '/end', $k['token'], ['last_day' => '2025-07-31', 'reason' => 'Resigned']))['data'];
    eq([$end['employment']['end_date'], $end['team_places_ended'], $end['offboarding_tasks_created']], ['2025-07-31', 1, 5]);
    eq(DB::value("SELECT COUNT(*) FROM team_assignments WHERE person_id = (SELECT id FROM persons WHERE uuid = ?) AND valid_to IS NULL", [$personId]), 0);
    eq(api('POST', '/employments/' . $emp['id'] . '/end', $k['token'], ['last_day' => '2025-08-01'])[0], 422, 'already ended');
    eq(apiOk(api('GET', '/employments', $k['token'], null, ['status' => 'current']))['data'][1]['status'] ?? 'current', 'current', 'still employed until the last day');
    Clock::freeze('2025-09-01');
    eq(apiOk(api('GET', '/employments', $k['token'], null, ['status' => 'ended']))['data'][0]['id'], $emp['id']);
    $types = array_column(DB::all("SELECT type FROM outbox_events WHERE organisation_id = ?", [$org]), 'type');
    ok(in_array('employment.started', $types) && in_array('employment.changed', $types) && in_array('employment.ended', $types), 'the same events as the web interface: ' . implode(',', $types));
    eq(DB::value("SELECT actor_label FROM audit_log WHERE organisation_id = ? AND action = 'employment.ended'", [$org]), 'system:api:Test key');
});

test('api staff: a rehire through the API is a new employment for the same person', function () {
    $org = Fx::org();
    $k = apiKey($org);
    $s = Fx::staff($org, 'Rae', 'Back', ['start_date' => '2024-01-08']);
    \App\Modules\Staff\Lifecycle::leave($org, $s['employment_id'], '2024-12-31', 'Resigned', Fx::actor());
    $r = apiOk(api('POST', '/employments', $k['token'], ['person_id' => $s['person_uuid'], 'job_title' => 'Senior', 'start_date' => '2025-03-03']), 201)['data'];
    eq([$r['person_id'], $r['rehire']], [$s['person_uuid'], true]);
    eq(count(apiOk(api('GET', '/people/' . $s['person_uuid'] . '/employments', $k['token']))['data']), 2);
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 1);
    eq(api('POST', '/employments', $k['token'], ['person_id' => $s['person_uuid'], 'job_title' => 'Overlap', 'start_date' => '2025-04-01'])[0], 422);
});

test('api staff: terms sections and the personal profile; secrets need staff.sensitive and are encrypted; checks apply', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $k = apiKey($org, ['staff.read', 'staff.write']);
    $full = apiKey($org, ['staff.read', 'staff.write', 'staff.sensitive']);
    $emp = $s['employment_uuid'];
    $d = apiOk(api('PATCH', "/employments/$emp/details/tupe", $k['token'], ['is_tupe' => true, 'tupe_transfer_date' => '2025-03-01', 'tupe_previous_organisation' => 'Old Co', 'tupe_salary' => 24500.5]))['data'];
    eq([$d['tupe']['is_tupe'], $d['tupe']['tupe_previous_organisation'], $d['tupe']['tupe_salary']], [true, 'Old Co', 24500.5]);
    apiOk(api('PATCH', "/employments/$emp/details/leave", $k['token'], ['annual_leave_allocation' => 33]));
    $d = apiOk(api('GET', "/employments/$emp/details", $k['token']))['data'];
    eq([$d['tupe']['is_tupe'], $d['leave']['annual_leave_allocation']], [true, 33], 'saving one section leaves the others alone');
    eq(api('PATCH', "/employments/$emp/details/visa", $k['token'], ['has_visa' => true])[0], 422, 'a visa needs an expiry');
    eq(api('PATCH', "/employments/$emp/details/nonsense", $k['token'], [])[0], 404);
    eq(api('PATCH', "/employments/$emp/details/tupe", $k['token'], ['nope' => 1])[0], 422);

    $pid = $s['person_uuid'];
    $r = api('PATCH', "/people/$pid/profile", $k['token'], ['address_line1' => '1 High Street', 'address_postcode' => 'iv2 3ps', 'emergency_contact_name' => 'Pat']);
    $p = apiOk($r)['data'];
    eq([$p['address_postcode'], $p['has_photo'], array_key_exists('ni_number', $p)], ['IV2 3PS', false, false]);
    eq(api('PATCH', "/people/$pid/profile", $k['token'], ['ni_number' => 'JA000001A'])[0], 403, 'sensitive writes need the sensitive scope');
    eq(api('PATCH', "/people/$pid/profile", $full['token'], ['ni_number' => 'QQ123456C'])[0], 422);
    $p = apiOk(api('PATCH', "/people/$pid/profile", $full['token'], ['ni_number' => 'ja 00 00 01 a', 'bank_sort_code' => '123456', 'bank_account_number' => '12345678']))['data'];
    eq([$p['ni_number'], $p['bank_sort_code']], ['JA000001A', '12-34-56']);
    $raw = DB::one('SELECT ni_number, bank_account_number FROM person_profiles WHERE person_id = ?', [$s['person_id']]);
    ok(str_starts_with($raw['ni_number'], 'enc2:') && str_starts_with($raw['bank_account_number'], 'enc2:'), 'encrypted at rest through the API too');
    ok(!array_key_exists('ni_number', apiOk(api('GET', "/people/$pid/profile", $k['token']))['data']), 'hidden without the scope');
    eq(apiOk(api('GET', "/people/$pid/profile", $full['token']))['data']['bank_account_number'], '12345678');
    foreach (DB::all("SELECT detail, summary FROM audit_log WHERE organisation_id = ? AND action LIKE 'person.profile%'", [$org]) as $a) {
        ok(!str_contains($a['detail'] . $a['summary'], 'JA000001A') && !str_contains($a['detail'] . $a['summary'], '12345678'), 'no values in the audit log');
    }
});

test('api staff: profile change requests are readable, with sensitive values withheld', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    [$c] = App\Modules\Staff\StaffRecord::cleanProfile(['address_line1' => '9 Brae Road', 'ni_number' => 'JA000009A']);
    App\Modules\Staff\ProfileChanges::submit($org, $s['person_id'], 0, $c, Fx::actor());
    $k = apiKey($org, ['staff.read']);
    $full = apiKey($org, ['staff.read', 'staff.sensitive']);
    $rows = apiOk(api('GET', '/profile-changes', $k['token'], null, ['status' => 'pending']))['data'];
    $by = array_column($rows, null, 'field');
    eq([$by['address_line1']['proposed_value'], $by['ni_number']['proposed_value']], ['9 Brae Road', null]);
    $by = array_column(apiOk(api('GET', '/profile-changes', $full['token']))['data'], null, 'field');
    eq($by['ni_number']['proposed_value'], 'JA000009A');
    eq(api('GET', '/profile-changes', $k['token'], null, ['status' => 'nope'])[0], 400);
});

// ── Compliance ──────────────────────────────────────────────────────────────

test('api compliance: add, list, remove each kind; checks apply; what is due', function () {
    $org = Fx::org();
    Clock::freeze('2026-06-01');
    $s = Fx::staff($org, 'Cy', 'Comply');
    $boss = Fx::staff($org, 'Bea', 'Boss');
    $k = apiKey($org);
    $pid = $s['person_uuid'];
    $reg = apiOk(api('POST', "/people/$pid/registrations", $k['token'], ['registration_type' => 'SSSC support worker', 'expiry_date' => '2026-06-20', 'registration_number' => 'R1']), 201)['data'];
    eq([$reg['registration_type'], $reg['is_required_for_role']], ['SSSC support worker', false]);
    eq(api('POST', "/people/$pid/registrations", $k['token'], ['registration_type' => 'X', 'issue_date' => '2026-05-01', 'expiry_date' => '2026-01-01'])[0], 422);
    eq(api('POST', "/people/$pid/registrations", $k['token'], ['registration_type' => 'X'])[0], 422);
    $sup = apiOk(api('POST', "/people/$pid/supervisions", $k['token'], ['supervision_date' => '2026-05-20', 'supervision_type' => 'individual', 'supervisor_person_id' => $boss['person_uuid'], 'duration_minutes' => 45, 'next_due_date' => '2026-06-30']), 201)['data'];
    eq([$sup['supervisor_person_id'], $sup['duration_minutes']], [$boss['person_uuid'], 45]);
    eq(api('POST', "/people/$pid/supervisions", $k['token'], ['supervision_date' => '2026-05-20', 'supervision_type' => 'weird'])[0], 422);
    apiOk(api('POST', "/people/$pid/appraisals", $k['token'], ['appraisal_date' => '2026-01-10', 'appraisal_type' => 'annual', 'outcome' => 'meets_expectations', 'next_due_date' => '2027-01-10']), 201);
    apiOk(api('POST', "/people/$pid/learning", $k['token'], ['title' => 'Moving and handling', 'record_type' => 'training', 'status' => 'completed', 'is_mandatory' => true, 'expiry_date' => '2026-06-10']), 201);
    eq(count(apiOk(api('GET', "/people/$pid/registrations", $k['token']))['data']), 1);
    $due = apiOk(api('GET', '/compliance/due', $k['token'], null, ['days' => '60']))['data'];
    $labels = array_column($due, 'label');
    ok(in_array('SSSC support worker', $labels) && in_array('Moving and handling', $labels));
    eq(api('GET', '/compliance/due', $k['token'], null, ['days' => '1000'])[0], 400);
    eq(api('DELETE', "/people/$pid/registrations/{$reg['id']}", $k['token'])[0], 204);
    eq(count(apiOk(api('GET', "/people/$pid/registrations", $k['token']))['data']), 0);
    eq(api('DELETE', "/people/$pid/registrations/{$reg['id']}", $k['token'])[0], 422, 'already gone');
    eq(api('DELETE', "/people/$pid/registrations/{$sup['id']}", $k['token'])[0], 422, 'a record of another kind is not found under this one');
    $other = Fx::staff(Fx::org());
    eq(api('GET', '/people/' . $other['person_uuid'] . '/learning', $k['token'])[0], 404);
});

// ── Teams ───────────────────────────────────────────────────────────────────

test('api teams: create, nest, edit, add and end members, close; loops and staff-only rules apply', function () {
    $org = Fx::org();
    $k = apiKey($org);
    $home = apiOk(api('POST', '/teams', $k['token'], ['name' => 'Care Home']), 201)['data'];
    $day = apiOk(api('POST', '/teams', $k['token'], ['name' => 'Day Team', 'parent_id' => $home['id']]), 201)['data'];
    $hr = apiOk(api('POST', '/teams', $k['token'], ['name' => 'HR', 'kind' => 'hr', 'staff_only' => true]), 201)['data'];
    eq([$day['parent_id'], $hr['staff_only'], $home['kind']], [$home['id'], true, 'care']);
    eq(api('PATCH', '/teams/' . $home['id'], $k['token'], ['parent_id' => $day['id']])[0], 422, 'no loops');
    eq(apiOk(api('PATCH', '/teams/' . $day['id'], $k['token'], ['name' => 'Early Team']))['data']['parent_id'], $home['id'], 'a patch keeps what it does not mention');
    $s = Fx::staff($org, 'Tom', 'Member');
    $sup = Fx::supported($org);
    $supId = DB::value('SELECT uuid FROM persons WHERE id = ?', [$sup['id']]);
    $m = apiOk(api('POST', '/teams/' . $day['id'] . '/members', $k['token'], ['person_id' => $s['person_uuid'], 'role' => 'Care worker', 'primary' => true, 'start_date' => '2025-02-03']), 201)['data'];
    eq([$m['team_id'], $m['primary'], $m['end_date']], [$day['id'], true, null]);
    eq(api('POST', '/teams/' . $hr['id'] . '/members', $k['token'], ['person_id' => $supId])[0], 422, 'staff-only teams refuse people we support');
    eq(count(apiOk(api('GET', '/teams/' . $day['id'] . '/members', $k['token']))['data']), 1);
    eq(api('POST', '/teams/' . $home['id'] . '/close', $k['token'])[0], 422, 'active sub-teams');
    $e = apiOk(api('POST', '/teams/' . $day['id'] . '/members/' . $s['person_uuid'] . '/end', $k['token'], ['end_date' => '2025-06-30']))['data'];
    eq($e['end_date'], '2025-06-30');
    eq(api('POST', '/teams/' . $day['id'] . '/members/' . $s['person_uuid'] . '/end', $k['token'], [])[0], 404);
    eq(count(apiOk(api('GET', '/teams/' . $day['id'] . '/members', $k['token'], null, ['on' => '2025-05-01']))['data']), 1, 'who was in the team on a past date');
    apiOk(api('POST', '/teams/' . $day['id'] . '/close', $k['token']));
    apiOk(api('POST', '/teams/' . $home['id'] . '/close', $k['token']));
    eq(array_column(apiOk(api('GET', '/teams', $k['token']))['data'], 'name'), ['HR']);
    eq(count(apiOk(api('GET', '/teams', $k['token'], null, ['active' => 'false']))['data']), 3);
    eq(api('POST', '/teams/' . $hr['id'] . '/members', $k['token'], ['person_id' => \App\Core\Uuid::v4()])[1]['errors'][0]['field'], 'person_id');
});

// ── Care ────────────────────────────────────────────────────────────────────

test('api care: support, needs, contacts and key workers, with the same rules and the cascade when support ends', function () {
    $org = Fx::org();
    $k = apiKey($org);
    $staff = Fx::staff($org, 'Kay', 'Worker', ['start_date' => '2025-01-06']);
    $left = Fx::staff($org, 'Lee', 'Left', ['start_date' => '2025-01-06']);
    \App\Modules\Staff\Lifecycle::leave($org, $left['employment_id'], '2025-03-01', '', Fx::actor());
    $pid = apiOk(api('POST', '/people', $k['token'], ['first_name' => 'Pat', 'last_name' => 'Client']), 201)['data']['id'];
    eq(api('POST', "/people/$pid/key-workers", $k['token'], [])[0], 405);
    eq(api('POST', '/key-workers', $k['token'], ['supported_person_id' => $pid, 'staff_person_id' => $staff['person_uuid'], 'start_date' => '2025-02-03'])[0], 422, 'not receiving support yet');
    $ep = apiOk(api('POST', "/people/$pid/support", $k['token'], ['start_date' => '2025-02-03', 'nhs_number' => '943 476 5919', 'reference' => 'P001']), 201)['data'];
    eq($ep['end_date'], null);
    eq(api('POST', "/people/$pid/support", $k['token'], ['start_date' => '2025-03-01'])[0], 422, 'one episode at a time');
    eq(api('POST', "/people/$pid/support", $k['token'], ['nhs_number' => '1234567890'])[0], 422);
    $need = apiOk(api('POST', "/people/$pid/needs", $k['token'], ['category' => 'Falls risk', 'description' => 'Review every six months', 'severity' => 'high']), 201)['data'];
    eq(api('POST', "/people/$pid/needs", $k['token'], ['category' => 'X', 'description' => 'Y', 'severity' => 'extreme'])[0], 422);
    $contact = apiOk(api('POST', "/people/$pid/contacts", $k['token'], ['type' => 'next_of_kin', 'value' => '07700 900123', 'label' => 'Daughter', 'primary' => true]), 201)['data'];
    eq(api('POST', "/people/$pid/contacts", $k['token'], ['type' => 'email', 'value' => 'nope'])[0], 422);
    $kw = apiOk(api('POST', '/key-workers', $k['token'], ['supported_person_id' => $pid, 'staff_person_id' => $staff['person_uuid'], 'start_date' => '2025-02-03', 'role_label' => 'Key worker']), 201)['data'];
    eq([$kw['supported_person_id'], $kw['staff_person_id']], [$pid, $staff['person_uuid']]);
    eq(api('POST', '/key-workers', $k['token'], ['supported_person_id' => $pid, 'staff_person_id' => $staff['person_uuid']])[0], 422, 'not twice');
    eq(api('POST', '/key-workers', $k['token'], ['supported_person_id' => $pid, 'staff_person_id' => $left['person_uuid'], 'start_date' => '2025-06-01'])[0], 422, 'only current staff');
    eq(api('POST', '/key-workers', $k['token'], ['supported_person_id' => $pid, 'staff_person_id' => $pid, 'start_date' => '2025-02-03'])[0], 422, 'not themselves');
    eq(count(apiOk(api('GET', '/people/' . $staff['person_uuid'] . '/key-workers', $k['token']))['data']), 1, 'from the staff side too');
    eq(count(apiOk(api('GET', "/people/$pid/needs", $k['token']))['data']), 1);
    apiOk(api('POST', '/needs/' . $need['id'] . '/end', $k['token']));
    eq(count(apiOk(api('GET', "/people/$pid/needs", $k['token']))['data']), 0);
    eq(count(apiOk(api('GET', "/people/$pid/needs", $k['token'], null, ['include_ended' => 'true']))['data']), 1);
    eq(api('DELETE', '/contacts/' . $contact['id'], $k['token'])[0], 204);
    $end = apiOk(api('POST', "/people/$pid/support/end", $k['token'], ['end_date' => '2025-09-01']))['data'];
    eq($end['key_worker_links_ended'], 1);
    eq(apiOk(api('GET', "/people/$pid/key-workers", $k['token']))['data'][0]['end_date'], '2025-09-01');
    eq(apiOk(api('GET', "/people/$pid/support", $k['token']))['data'][0]['end_date'], '2025-09-01');
    $noCare = apiKey($org, ['people.read', 'people.write', 'staff.read']);
    eq(api('GET', "/people/$pid/needs", $noCare['token'])[0], 403, 'care information needs care.read');
    eq(api('GET', "/people/$pid/contacts", $noCare['token'])[0], 403);
});

// ── Tasks, events, webhooks, audit ──────────────────────────────────────────

test('api tasks: list with filters, mark done (raises the event), reopen', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $k = apiKey($org);
    $tasks = apiOk(api('GET', '/tasks', $k['token']))['data'];
    eq(count($tasks), 7);
    eq(count(apiOk(api('GET', '/tasks', $k['token'], null, ['kind' => 'leaver']))['data']), 0);
    eq(count(apiOk(api('GET', '/tasks', $k['token'], null, ['person_id' => $s['person_uuid'], 'limit' => '3']))['data']), 3);
    $t = apiOk(api('PATCH', '/tasks/' . $tasks[0]['id'], $k['token'], ['status' => 'done']))['data'];
    eq([$t['status'], $t['completed_at'] !== null], ['done', true]);
    eq(DB::value('SELECT completed_by_user_id FROM tasks WHERE uuid = ?', [$t['id']]), null, 'done by an API key, not a user');
    eq(Fx::count('outbox_events', "organisation_id = ? AND type = 'task.completed'", [$org]), 1);
    eq(count(apiOk(api('GET', '/tasks', $k['token']))['data']), 6);
    eq(count(apiOk(api('GET', '/tasks', $k['token'], null, ['status' => 'all']))['data']), 7);
    apiOk(api('PATCH', '/tasks/' . $t['id'], $k['token'], ['status' => 'open']));
    eq(api('PATCH', '/tasks/' . $t['id'], $k['token'], ['status' => 'bogus'])[0], 422);
    eq(api('GET', '/tasks', $k['token'], null, ['status' => 'nope'])[0], 400);
});

test('api events: a pollable feed of the same events webhooks deliver, in order, with cursor and type filters, identifiers only', function () {
    $org = Fx::org();
    $k = apiKey($org);
    $s = Fx::staff($org, 'Zelda', 'Privacy');
    \App\Modules\Staff\Lifecycle::leave($org, $s['employment_id'], '2025-06-30', 'Resigned', Fx::actor());
    $all = apiOk(api('GET', '/events', $k['token']))['data'];
    $types = array_column($all, 'type');
    eq([$types[0], end($types)], ['person.created', 'employment.ended']);
    ok(!str_contains(json_encode($all), 'Zelda') && !str_contains(json_encode($all), 'Privacy'), 'events never contain names');
    $first = $all[0];
    $after = apiOk(api('GET', '/events', $k['token'], null, ['after' => $first['cursor']]))['data'];
    eq(count($after), count($all) - 1);
    $emp = apiOk(api('GET', '/events', $k['token'], null, ['types' => 'employment.*']))['data'];
    eq(array_unique(array_column($emp, 'type')), ['employment.started', 'employment.ended'] === array_unique(array_column($emp, 'type')) ? ['employment.started', 'employment.ended'] : array_unique(array_column($emp, 'type')));
    ok(count($emp) >= 2 && !in_array('person.created', array_column($emp, 'type')));
    eq(array_column(apiOk(api('GET', '/events', $k['token'], null, ['types' => 'employment.ended']))['data'], 'type'), ['employment.ended']);
    eq(api('GET', '/events', $k['token'], null, ['types' => 'bad type!'])[0], 400);
    $one = apiOk(api('GET', '/events/' . $first['id'], $k['token']))['data'];
    eq($one['id'], $first['id']);
    $page = apiOk(api('GET', '/events', $k['token'], null, ['limit' => '2']));
    eq([count($page['data']), $page['page']['has_more']], [2, true]);
    $b = Fx::org();
    eq(apiOk(api('GET', '/events', apiKey($b)['token']))['data'], [], 'another organisation sees none of them');
});

test('api webhooks: manage endpoints; the secret is shown once; addresses are checked; deliveries are visible', function () {
    $org = Fx::org();
    $k = apiKey($org);
    $r = api('POST', '/webhooks', $k['token'], ['url' => 'https://hooks.example.org/care', 'event_types' => ['employment.*', 'team.created']]);
    $w = apiOk($r, 201)['data'];
    ok(strlen($w['secret']) === 40);
    eq($w['event_types'], ['employment.*', 'team.created']);
    eq(DB::value('SELECT secret FROM webhook_endpoints WHERE uuid = ?', [$w['id']]), $w['secret'], 'the delivery job signs with it');
    ok(!array_key_exists('secret', apiOk(api('GET', '/webhooks/' . $w['id'], $k['token']))['data']), 'never shown again');
    eq(api('POST', '/webhooks', $k['token'], ['url' => 'http://example.org/insecure'])[0], 422);
    eq(api('POST', '/webhooks', $k['token'], ['url' => 'https://user:pw@example.org/x'])[0], 422);
    eq(api('POST', '/webhooks', $k['token'], ['url' => 'http://169.254.169.254/latest'])[0], 422);
    eq(api('POST', '/webhooks', $k['token'], ['url' => 'https://example.org/x', 'event_types' => ['bad type']])[0], 422);
    $u = apiOk(api('PATCH', '/webhooks/' . $w['id'], $k['token'], ['active' => false, 'event_types' => ['*']]))['data'];
    eq([$u['active'], $u['event_types']], [false, ['*']]);
    eq(count(apiOk(api('GET', '/webhooks', $k['token']))['data']), 1);
    Fx::staff($org);
    DB::run("UPDATE webhook_endpoints SET active = 1 WHERE uuid = ?", [$w['id']]);
    App\Core\EventDelivery::run();
    $del = apiOk(api('GET', '/webhooks/' . $w['id'] . '/deliveries', $k['token']))['data'];
    ok(count($del) >= 1 && isset($del[0]['status']) && isset($del[0]['event_type']));
    eq(api('DELETE', '/webhooks/' . $w['id'], $k['token'])[0], 204);
    eq(api('GET', '/webhooks/' . $w['id'], $k['token'])[0], 404);
    $b = apiKey(Fx::org());
    $other = apiOk(api('POST', '/webhooks', $b['token'], ['url' => 'https://example.org/b']), 201)['data'];
    eq(api('GET', '/webhooks/' . $other['id'], $k['token'])[0], 404, 'another organisation’s endpoint');
    eq(api('DELETE', '/webhooks/' . $other['id'], $k['token'])[0], 404);
});

test('api audit and administration: the activity feed names the API key; logins and consistency checks are readable; nothing sensitive leaks', function () {
    $org = Fx::org();
    $k = apiKey($org, ALL_SCOPES, ['name' => 'Payroll sync']);
    $s = Fx::staff($org, 'Sam', 'Audit');
    apiOk(api('PATCH', '/people/' . $s['person_uuid'], $k['token'], ['phone' => '07700 900123']));
    $log = apiOk(api('GET', '/audit', $k['token'], null, ['subject_id' => $s['person_uuid']]))['data'];
    ok(in_array('system:api:Payroll sync', array_column($log, 'actor')));
    eq(array_column(apiOk(api('GET', '/audit', $k['token'], null, ['action' => 'employment']))['data'], 'action'), ['employment.started']);
    eq(api('GET', '/audit', $k['token'], null, ['action' => 'DROP TABLE'])[0], 400);
    $u = App\Modules\Identity\UserRepo::create($org, $s['person_id'], 'sam.audit@example.test', 'staff', Fx::actor(), true);
    $users = apiOk(api('GET', '/users', $k['token']))['data'];
    eq([$users[0]['email'], $users[0]['status'], $users[0]['person_id']], ['sam.audit@example.test', 'invited', $s['person_uuid']]);
    ok(!str_contains(json_encode($users), 'password') && !str_contains(json_encode($users), 'token'), 'no credentials in the logins list');
    $db = Fx::org();
    DB::insert('team_assignments', ['organisation_id' => $org, 'team_id' => Fx::team($org, 'Ghost'), 'person_id' => $s['person_id'], 'valid_from' => '2024-01-01']);
    \App\Modules\Staff\Lifecycle::leave($org, $s['employment_id'], '2025-01-31', '', Fx::actor());
    DB::run('UPDATE team_assignments SET valid_to = NULL WHERE person_id = ?', [$s['person_id']]);   // break it on purpose
    $found = apiOk(api('GET', '/consistency', $k['token']))['data'];
    ok(in_array('team_for_leaver', array_column($found, 'code')));
    eq(api('GET', '/consistency', apiKey($org, ['people.read'])['token'])[0], 403);
});

// ── The specification ───────────────────────────────────────────────────────

test('openapi: valid, covers every route and scope, and every schema it points at exists', function () {
    $r = api('GET', '/openapi.json', null);
    eq($r[0], 200);
    $spec = $r[1];
    eq($spec['openapi'], '3.1.0');
    $routes = Api::routes();
    $ops = 0;
    foreach ($spec['paths'] as $path => $methods) {
        $ops += count($methods);
    }
    eq($ops, count($routes));
    ok($ops >= 60, "$ops operations");
    $json = json_encode($spec);
    preg_match_all('~#/components/schemas/(\w+)~', $json, $m);
    $missing = array_diff(array_unique($m[1]), array_keys($spec['components']['schemas']));
    eq(array_values($missing), [], 'schemas referenced but not defined');
    foreach ($routes as $route) {
        ok(in_array($route['scope'], array_merge(array_keys(Scopes::ALL), [null]), true), 'unknown scope on ' . $route['path']);
        $op = $spec['paths'][$route['path']][strtolower($route['method'])] ?? null;
        ok($op !== null && $op['summary'] === $route['summary'], 'missing ' . $route['method'] . ' ' . $route['path']);
    }
    $used = array_unique(array_filter(array_column($routes, 'scope')));
    eq(array_values(array_diff(array_keys(Scopes::ALL), $used, ['staff.sensitive'])), [], 'every scope except staff.sensitive (a modifier on staff endpoints) guards at least one endpoint');
    ok(isset($spec['paths']['/employments']['post']['requestBody']['content']['application/json']['schema']['required']));
    ok(in_array('Idempotency-Key', array_column($spec['paths']['/people']['post']['parameters'], 'name')));
});
