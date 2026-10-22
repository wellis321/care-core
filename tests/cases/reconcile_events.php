<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\EventDelivery;
use App\Modules\Care\CareRepo;
use App\Modules\Staff\Lifecycle;
use App\Modules\Staff\Reconcile;

function codes(array $issues): array
{
    return array_values(array_unique(array_column($issues, 'code')));
}

test('reconcile: a clean organisation has no serious findings', function () {
    $org = Fx::org();
    $t = Fx::team($org, 'Clean');
    $s = Fx::staff($org, 'All', 'Good', ['team_id' => $t]);
    $c = Fx::supported($org, 'Well', 'Looked');
    CareRepo::assignKeyWorker($org, $c['id'], $s['person_id'], null, '2025-03-01', Fx::actor());
    eq(array_filter(Reconcile::check($org, '2025-06-01'), fn($i) => $i['severity'] === 'high'), []);
});

test('reconcile: finds a leaver who was ended behind the system\'s back, and fixes it', function () {
    $org = Fx::org();
    $t = Fx::team($org, 'Drift');
    $s = Fx::staff($org, 'Drift', 'Leaver', ['team_id' => $t, 'create_login' => true]);
    DB::update('users', ['status' => 'active'], ['person_id' => $s['person_id']]);
    $c = Fx::supported($org, 'Left', 'Behind');
    CareRepo::assignKeyWorker($org, $c['id'], $s['person_id'], null, '2025-03-01', Fx::actor());
    // Someone ends the employment directly in the database, skipping the lifecycle.
    DB::update('employments', ['end_date' => '2025-05-31'], ['id' => $s['employment_id']]);

    $issues = Reconcile::check($org, '2025-06-15');
    foreach (['team_for_leaver', 'login_for_leaver', 'keyworker_left'] as $code) {
        ok(in_array($code, codes($issues), true), "missing finding $code (got " . implode(',', codes($issues)) . ')');
    }
    $fix = Reconcile::fix($org, Fx::actor(), '2025-06-15');
    ok($fix['fixed'] >= 3, 'fixed ' . $fix['fixed']);
    eq(array_filter(Reconcile::check($org, '2025-06-15'), fn($i) => $i['severity'] === 'high'), [], 'nothing serious left');
    eq(DB::value('SELECT valid_to FROM team_assignments WHERE person_id = ?', [$s['person_id']]), '2025-05-31', 'ended on their last day, not today');
    eq(DB::value('SELECT access_ends_on FROM users WHERE person_id = ?', [$s['person_id']]), '2025-05-31');
});

test('reconcile: reports people with no key worker and staff in no team, without trying to fix them', function () {
    $org = Fx::org();
    Fx::staff($org, 'Lone', 'Staff');
    Fx::supported($org, 'No', 'Keyworker');
    $codes = codes(Reconcile::check($org, '2025-06-15'));
    ok(in_array('no_keyworker', $codes, true) && in_array('staff_no_team', $codes, true));
    eq(Reconcile::fix($org, Fx::actor(), '2025-06-15')['fixed'], 0);
});

test('events: written in the same transaction, so a rolled-back change leaves no event', function () {
    $org = Fx::org();
    $before = Fx::count('outbox_events', 'organisation_id = ?', [$org]);
    try {
        DB::transaction(function () use ($org) {
            Fx::staff($org, 'Roll', 'Back');
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }
    eq(Fx::count('outbox_events', 'organisation_id = ?', [$org]), $before);
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 0);
});

test('events: type patterns match as documented', function () {
    ok(EventDelivery::matches('*', 'employment.ended'));
    ok(EventDelivery::matches('employment.*', 'employment.ended'));
    ok(EventDelivery::matches('person.created, employment.ended', 'employment.ended'));
    ok(!EventDelivery::matches('employment.*', 'person.created'));
    ok(!EventDelivery::matches('employment.started', 'employment.ended'));
});

test('events: delivered as signed webhooks, retried when the receiver fails, and never to private addresses', function () {
    $log = sys_get_temp_dir() . '/carecore_hook.log';
    @unlink($log);
    $port = 8993;
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/../receiver.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(700000);
    try {
        $org = Fx::org();
        DB::insert('webhook_endpoints', ['organisation_id' => $org, 'url' => "http://127.0.0.1:$port/hook", 'secret' => 'topsecret', 'event_types' => 'employment.*']);
        DB::insert('webhook_endpoints', ['organisation_id' => $org, 'url' => "http://127.0.0.1:$port/hook?fail=1", 'secret' => 'other', 'event_types' => 'employment.started']);
        DB::insert('webhook_endpoints', ['organisation_id' => $org, 'url' => 'http://169.254.169.254/latest', 'secret' => 'x', 'event_types' => '*']);
        $s = Fx::staff($org, 'Hook', 'Test');
        $r = EventDelivery::run();
        ok($r['queued'] >= 3, 'queued ' . $r['queued']);
        $rows = array_map('json_decode', file($log, FILE_IGNORE_NEW_LINES));
        ok(count($rows) >= 2, 'receiver got requests');
        $good = array_values(array_filter($rows, fn($x) => $x->event === 'employment.started'));
        ok($good !== [], 'employment.started delivered');
        $expected = 'sha256=' . hash_hmac('sha256', $good[0]->body, 'topsecret');
        ok(in_array($expected, array_column($rows, 'sig'), true), 'a request carries a valid signature');
        $env = json_decode($good[0]->body, true);
        eq([$env['type'], $env['version'], $env['subject']], ['employment.started', 1, $s['person_uuid']]);
        ok(Fx::count('webhook_deliveries', "status = 'delivered'") >= 1, 'recorded as delivered');
        ok(Fx::count('webhook_deliveries', "status = 'pending' AND attempts = 1 AND next_attempt_at IS NOT NULL") >= 1, 'failed delivery is scheduled to retry');
        eq((int) DB::value("SELECT COUNT(*) FROM webhook_deliveries d JOIN webhook_endpoints w ON w.id = d.endpoint_id WHERE w.url LIKE 'http://169.254%' AND d.status = 'delivered'"), 0, 'metadata address never contacted');
        $again = EventDelivery::run();
        eq($again['delivered'], 0, 'nothing is delivered twice');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});
