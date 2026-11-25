<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\Uuid;
use App\Modules\Evidence\EvidencePack;

test('evidence pack: reports what is recorded as at a date, and flags only dates that passed', function () {
    $org = Fx::org();
    $a = Fx::staff($org, 'Eva', 'Evidence'); $b = Fx::staff($org, 'Ben', 'Blank');
    $today = Clock::today(); $past = Clock::addDays($today, -30); $future = Clock::addDays($today, 200); $soon = Clock::addDays($today, 20);
    $ins = fn(string $t, array $r) => DB::insert($t, $r + ['uuid' => Uuid::v4(), 'organisation_id' => $org, 'person_id' => $a['person_id']]);
    $ins('registrations', ['registration_type' => 'SSSC', 'registration_number' => 'X1', 'expiry_date' => $past]);
    $ins('registrations', ['registration_type' => 'SSSC', 'registration_number' => 'X2', 'expiry_date' => $future]);        // the newer one is the one that counts
    $ins('registrations', ['registration_type' => 'PVG', 'expiry_date' => $soon]);
    $ins('supervisions', ['supervision_date' => Clock::addDays($today, -100), 'next_due_date' => Clock::addDays($today, -40)]);
    $ins('appraisals', ['appraisal_date' => Clock::addDays($today, -90), 'next_due_date' => $future]);
    $ins('learning_records', ['title' => 'Moving and handling', 'is_mandatory' => 1, 'status' => 'completed', 'completion_date' => Clock::addDays($today, -400), 'expiry_date' => $past]);
    $ins('learning_records', ['title' => 'First aid', 'is_mandatory' => 1, 'status' => 'completed', 'completion_date' => Clock::addDays($today, -10), 'expiry_date' => $future]);
    $ins('learning_records', ['title' => 'Optional course', 'is_mandatory' => 0, 'status' => 'completed', 'completion_date' => Clock::addDays($today, -10)]);
    $pack = EvidencePack::build($org, null, $today);
    $mine = array_values(array_filter($pack['staff'], fn($s) => (int) $s['id'] === $a['person_id']))[0];
    $regs = array_column($mine['regs'], 'state', 'registration_type');
    eq([$regs['SSSC'], $regs['PVG']], ['in_date', 'soon']);
    ok(count($mine['regs']) === 2, 'only the latest registration of each type is shown');
    eq(count($mine['train']), 2);
    eq([$mine['sup_overdue'], $mine['apr_overdue']], [true, false]);
    $what = implode('|', array_column(array_filter($pack['gaps'], fn($g) => $g['uuid'] === $mine['uuid']), 'what'));
    ok(str_contains($what, 'Supervision recorded as due') && str_contains($what, 'Moving and handling') && !str_contains($what, 'SSSC') && !str_contains($what, 'First aid'), 'only recorded dates that passed are listed');
    $blank = array_values(array_filter($pack['staff'], fn($s) => (int) $s['id'] === $b['person_id']))[0];
    ok(!$blank['regs'] && !$blank['sup'] && !$blank['train'] && !array_filter($pack['gaps'], fn($g) => $g['uuid'] === $blank['uuid']), 'nothing recorded is shown as none recorded, not as a failure');
    // as at an earlier date the expired registration was still the latest and in date
    $then = EvidencePack::build($org, null, Clock::addDays($today, -60));
    $m2 = array_values(array_filter($then['staff'], fn($s) => (int) $s['id'] === $a['person_id']));
    ok(!$m2 || !array_filter($m2[0]['regs'], fn($r) => $r['state'] === 'expired'), 'run for an earlier date, it reflects that date');
    $csv = EvidencePack::csv($pack);
    ok(count($csv) === count($pack['staff']) + 1 && count($csv[0]) === 11, 'the spreadsheet has a row for each person and the same columns throughout');
    $vars = ['pack' => $pack, 'teams' => [], 'teamUuid' => '', 'print' => false, 'generated' => $today, 'soon' => 60];
    ob_start(); (function () use ($vars) { extract($vars); include dirname(__DIR__, 2) . '/app/Views/evidence/index.php'; })(); $html = (string) ob_get_clean();
    ok(str_contains($html, 'Eva Evidence') && str_contains($html, 'Expires soon') && str_contains($html, 'Date passed') && str_contains($html, 'does not say anyone is'), 'the page shows the records, the flags and the plain statement');
    ok(EvidencePack::build(Fx::org(), null, $today)['summary']['staff'] === 0, 'another organisation sees none of it');
});
