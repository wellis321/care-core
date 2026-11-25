<?php
declare(strict_types=1);

use App\Core\Database as DB;
use App\Modules\Work\WorkSources as W;

test('my work: only safe items from a product are kept', function () {
    $base = 'https://incidents.example.test';
    $items = W::parse(json_encode(['items' => [
        ['title' => 'Review the fall on Tuesday', 'kind' => 'Action', 'due_on' => '2026-12-01', 'url' => '/incidents/4'],
        ['title' => '<b>x</b>', 'due_on' => 'tomorrow', 'url' => '//evil.example/x'],
        ['title' => 'Elsewhere', 'url' => 'https://evil.example/x'],
        ['title' => '   '],
        'not an item',
    ]]), $base);
    eq(count($items), 3);
    eq($items[0]['url'], $base . '/incidents/4');
    eq($items[0]['due_on'], '2026-12-01');
    eq($items[1]['due_on'], null, 'a date that is not a date is dropped');
    eq($items[1]['url'], null, 'a protocol-relative link is dropped');
    eq($items[2]['url'], null, 'a link to another site is dropped');
});

test('my work: an answer that is not the expected shape is refused', function () {
    throws(fn() => W::parse('<html>', 'https://x.example.test'), 'unexpected');
    throws(fn() => W::parse('{"items":"no"}', 'https://x.example.test'), 'unexpected');
});

test('my work: at most 25 items per product', function () {
    $many = array_map(fn($i) => ['title' => 'Item ' . $i], range(1, 60));
    eq(count(W::parse(json_encode(['items' => $many]), 'https://x.example.test')), 25);
});

test('my work: connecting a product checks the address and key, and seals the key', function () {
    $org = Fx::org();
    throws(fn() => W::add($org, 'Incident Core', 'http://insecure.example.test', 'ick_' . str_repeat('a', 24), Fx::actor()), 'https');
    throws(fn() => W::add($org, 'Incident Core', 'https://incidents.example.test', 'short', Fx::actor()), 'key');
    throws(fn() => W::add($org, '', 'https://incidents.example.test', 'ick_' . str_repeat('a', 24), Fx::actor()), 'name');
    $key = 'ick_' . bin2hex(random_bytes(12));
    W::add($org, 'Incident Core', 'https://incidents.example.test/', $key, Fx::actor());
    $row = DB::one('SELECT * FROM work_sources WHERE organisation_id = ?', [$org]);
    eq($row['base_url'], 'https://incidents.example.test');
    ok(!str_contains($row['key_sealed'], $key), 'the key is not stored in the clear');
    W::remove($org, (int) $row['id'], Fx::actor());
    eq(count(W::all($org)), 0);
});

test('search: only safe results from a product are kept', function () {
    $base = 'https://policies.example.test';
    $r = W::parseResults(json_encode(['results' => [
        ['title' => 'Medication policy', 'kind' => 'Policy', 'detail' => 'MED-01', 'url' => '/policies/medication'],
        ['title' => 'Bad link', 'url' => '//evil.example/x'],
        ['title' => 'Other site', 'url' => 'https://evil.example/x'],
        ['title' => ''],
    ]]), $base);
    eq(count($r), 3);
    eq($r[0]['url'], $base . '/policies/medication');
    eq($r[0]['detail'], 'MED-01');
    eq($r[1]['url'], null);
    eq($r[2]['url'], null);
    throws(fn() => W::parseResults('{"items":[]}', $base), 'unexpected');
    $many = array_map(fn($i) => ['title' => 'Hit ' . $i], range(1, 40));
    eq(count(W::parseResults(json_encode(['results' => $many]), $base)), 10, 'at most ten results per product');
});
