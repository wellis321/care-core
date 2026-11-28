<?php
declare(strict_types=1);

/** Dates are UK dates; saved times are UTC. For an hour after midnight in summer the two disagree. */

use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\View;
use App\Modules\Staff\ProfileChanges;

test('uk dates: a saved UTC time is shown on the UK date and time it fell on', function () {
    eq(View::date('2026-06-14 23:30:00'), '15 Jun 2026');
    eq(View::date('2026-06-14 22:30:00'), '14 Jun 2026');
    eq(View::date('2026-12-14 23:30:00'), '14 Dec 2026');
    eq(View::date('2026-06-14'), '14 Jun 2026');
    eq(View::date(null, 'never'), 'never');
    eq(View::dateTime('2026-06-14 23:30:00'), '15 Jun 2026 00:30');
    eq(View::dateTime('2026-06-14 23:30:00.123'), '15 Jun 2026 00:30');
    eq(View::dateTime('2026-12-14 09:05:00'), '14 Dec 2026 09:05');
    eq(View::dateTime(null, 'never'), 'never');
});

test('uk dates: just after midnight the line manager of someone starting today can act, though the database still thinks it is yesterday', function () {
    $org = Fx::org();
    $boss = Fx::staff($org, 'Bea', 'Boss');
    $worker = Fx::staff($org, 'Wil', 'Worker');
    DB::run('UPDATE employments SET line_manager_person_id = ?, start_date = ? WHERE id = ?', [$boss['person_id'], Clock::today(), $worker['employment_id']]);
    DB::pdo()->exec('SET timestamp = ' . (strtotime(Clock::today() . ' 00:00:00 UTC') - 1800));
    try {
        ok(DB::value('SELECT CURDATE()') !== Clock::today(), 'the check really does put the database a day behind');
        ok(ProfileChanges::canReview(['organisation_id' => $org, 'person_id' => $boss['person_id'], 'role' => 'staff'], ['organisation_id' => $org, 'person_id' => $worker['person_id']]), 'their line manager may review');
    } finally {
        DB::pdo()->exec('SET timestamp = DEFAULT');
    }
});

test('uk dates: no query asks the database what today is, and no page cuts a date out of a saved time', function () {
    $bad = [];
    foreach (['app', 'scripts'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($f->getPathname());
            // (An API key's expiry is stored as the UK date chosen, at 23:59:59, so its date is read as written.)
            if (preg_match('/CURDATE\s*\(|CURRENT_DATE\b(?!\))|\bDATE\([a-z_.]*_at\)|substr\([^;]{0,60}(?<!expires)_at\'\][^;]{0,8}, 0, 10\)\s*(,|\))/i', $code) && !str_contains($f->getPathname(), '/Recruitment/')) {
                $bad[] = substr($f->getPathname(), strlen(ROOT_PATH) + 1);
            }
        }
    }
    eq($bad, [], 'pass Clock::today() into a query; show a saved time with View::date() or View::dateTime()');
});
