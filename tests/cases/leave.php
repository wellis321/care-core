<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Database as DB;
use App\Modules\Leave\LeaveRepo;
use App\Modules\Leave\LeaveSwap;

/** Next Monday on or after a date, so a test does not depend on the day it runs. */
function monday(int $plusDays = 7): string
{
    $d = new DateTimeImmutable(Clock::addDays(Clock::today(), $plusDays));
    while ((int) $d->format('N') !== 1) {
        $d = $d->modify('+1 day');
    }
    return $d->format('Y-m-d');
}

test('leave: working days are Monday to Friday, halves count as half, weekends alone are nothing', function () {
    eq(LeaveRepo::workingDays('2026-12-07', '2026-12-11'), 5.0);          // Mon to Fri
    eq(LeaveRepo::workingDays('2026-12-07', '2026-12-13'), 5.0);          // the week including a weekend
    eq(LeaveRepo::workingDays('2026-12-12', '2026-12-13'), 0.0);          // a weekend
    eq(LeaveRepo::workingDays('2026-12-07', '2026-12-07', true), 0.5);    // half day
    eq(LeaveRepo::workingDays('2026-12-07', '2026-12-11', true, true), 4.0);
    eq(LeaveRepo::workingDays('2026-12-11', '2026-12-14', false, true), 1.5);   // Fri, then half of Mon
    eq(LeaveRepo::workingDays('2026-12-14', '2026-12-07'), 0.0);
});

test('leave: a request is checked, and overlapping leave is refused', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $mon = monday();
    $fri = Clock::addDays($mon, 4);
    throws(fn() => LeaveRepo::create($org, $s['person_id'], ['kind' => 'nonsense', 'starts_on' => $mon, 'ends_on' => $fri], Fx::actor()), 'kind');
    throws(fn() => LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => $fri, 'ends_on' => $mon], Fx::actor()), 'before');
    throws(fn() => LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => Clock::addDays(Clock::today(), -3), 'ends_on' => Clock::today()], Fx::actor()), 'today or later');
    throws(fn() => LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => '', 'ends_on' => $fri], Fx::actor()), 'First day');
    throws(fn() => LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => Clock::addDays($mon, 5), 'ends_on' => Clock::addDays($mon, 6)], Fx::actor()), 'weekend');
    throws(fn() => LeaveRepo::create($org, 999999, ['kind' => 'annual', 'starts_on' => $mon, 'ends_on' => $fri], Fx::actor()), 'Person');
    $a = LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => $mon, 'ends_on' => $fri, 'reason' => 'Holiday'], Fx::actor());
    eq([$a['status'], $a['days']], ['requested', 5.0]);
    throws(fn() => LeaveRepo::create($org, $s['person_id'], ['kind' => 'unpaid', 'starts_on' => Clock::addDays($mon, 2), 'ends_on' => Clock::addDays($mon, 8)], Fx::actor()), 'already');
    $other = Fx::staff($org, 'Olly', 'Other');
    LeaveRepo::create($org, $other['person_id'], ['kind' => 'annual', 'starts_on' => $mon, 'ends_on' => $fri], Fx::actor());   // someone else may be off the same week
    ok(true);
});

test('leave: a manager decides, never their own, declining needs a reason, and days may be corrected', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $mgr = Fx::staff($org, 'Mary', 'Manager');
    $mon = monday();
    $a = LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => $mon, 'ends_on' => Clock::addDays($mon, 4)], Fx::actor());
    throws(fn() => LeaveRepo::decide($org, $a['uuid'], true, '', null, 1, $s['person_id'], Fx::actor()), 'own');
    throws(fn() => LeaveRepo::decide($org, $a['uuid'], false, '', null, 1, $mgr['person_id'], Fx::actor()), 'why');
    throws(fn() => LeaveRepo::decide($org, $a['uuid'], true, '', '3.3', 1, $mgr['person_id'], Fx::actor()), 'halves');
    LeaveRepo::decide($org, $a['uuid'], true, 'Enjoy', '4', 1, $mgr['person_id'], Fx::actor());
    $row = LeaveRepo::byUuid($org, $a['uuid']);
    eq([$row['status'], (float) $row['days'], $row['decision_note']], ['approved', 4.0, 'Enjoy']);
    throws(fn() => LeaveRepo::decide($org, $a['uuid'], false, 'x', null, 1, $mgr['person_id'], Fx::actor()), 'already');
    ok(LeaveRepo::byUuid(Fx::org(), $a['uuid']) === null, 'another organisation cannot see it');
    $b = LeaveRepo::create($org, $s['person_id'], ['kind' => 'unpaid', 'starts_on' => Clock::addDays($mon, 14), 'ends_on' => Clock::addDays($mon, 14)], Fx::actor());
    LeaveRepo::decide($org, $b['uuid'], false, 'Short staffed that day', null, 1, $mgr['person_id'], Fx::actor());
    eq(LeaveRepo::byUuid($org, $b['uuid'])['status'], 'declined');
    // declined leave no longer blocks the dates
    LeaveRepo::create($org, $s['person_id'], ['kind' => 'unpaid', 'starts_on' => Clock::addDays($mon, 14), 'ends_on' => Clock::addDays($mon, 14)], Fx::actor());
    ok(true);
});

test('leave: cancelling follows who and when; sickness is only cancelled by a manager', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $mon = monday();
    $a = LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => $mon, 'ends_on' => $mon], Fx::actor());
    throws(fn() => LeaveRepo::cancel($org, $a['uuid'], false, false, Fx::actor()), 'cannot');
    LeaveRepo::cancel($org, $a['uuid'], true, false, Fx::actor());
    eq(LeaveRepo::byUuid($org, $a['uuid'])['status'], 'cancelled');
    throws(fn() => LeaveRepo::cancel($org, $a['uuid'], true, true, Fx::actor()), 'cannot be cancelled');
    $sick = LeaveRepo::create($org, $s['person_id'], ['kind' => 'sickness', 'starts_on' => Clock::today(), 'ends_on' => ''], Fx::actor());
    throws(fn() => LeaveRepo::cancel($org, $sick['uuid'], true, false, Fx::actor()), 'manager');
    LeaveRepo::cancel($org, $sick['uuid'], true, true, Fx::actor());
    eq(LeaveRepo::byUuid($org, $sick['uuid'])['status'], 'cancelled');
});

test('leave: sickness needs no approval, can stay open, ends with a return-to-work task, and the summary counts spells and days', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $start = '2026-03-02';   // a Monday
    Clock::freeze('2026-03-04');
    try {
        throws(fn() => LeaveRepo::create($org, $s['person_id'], ['kind' => 'sickness', 'starts_on' => '2026-03-09', 'ends_on' => ''], Fx::actor()), 'future');
        $a = LeaveRepo::create($org, $s['person_id'], ['kind' => 'sickness', 'starts_on' => $start, 'ends_on' => '', 'reason' => 'Flu'], Fx::actor());
        eq([$a['status'], $a['days']], ['approved', null]);
        eq(count(LeaveRepo::openSickness($org)), 1);
        eq(LeaveRepo::offOn($org, '2026-03-03')[0]['person_id'] ?? null, $s['person_id']);
        eq(LeaveRepo::sicknessSummary($s['person_id'], '2026-03-04'), ['spells' => 1, 'days' => 3.0]);   // Mon, Tue, Wed so far
        throws(fn() => LeaveRepo::endSickness($org, $a['uuid'], '2026-03-05', Fx::actor()), 'future');
        throws(fn() => LeaveRepo::endSickness($org, $a['uuid'], '2026-02-27', Fx::actor()), 'before');
        LeaveRepo::endSickness($org, $a['uuid'], '2026-03-03', Fx::actor());
        $row = LeaveRepo::byUuid($org, $a['uuid']);
        eq([$row['ends_on'], (float) $row['days']], ['2026-03-03', 2.0]);
        eq(count(LeaveRepo::openSickness($org)), 0);
        eq(Fx::count('tasks', "organisation_id = ? AND title LIKE 'Return-to-work%'", [$org]), 1);
        eq(count(LeaveRepo::awaitingReturnToWork($org)), 1);
        throws(fn() => LeaveRepo::returnToWorkHeld($org, $a['uuid'], '2026-03-05', Fx::actor()), 'future');
        LeaveRepo::returnToWorkHeld($org, $a['uuid'], '2026-03-04', Fx::actor());
        eq(count(LeaveRepo::awaitingReturnToWork($org)), 0);
        throws(fn() => LeaveRepo::endSickness($org, $a['uuid'], '2026-03-03', Fx::actor()), 'still open');
        // a second spell later
        LeaveRepo::create($org, $s['person_id'], ['kind' => 'sickness', 'starts_on' => '2026-03-04', 'ends_on' => '2026-03-04'], Fx::actor());
        eq(LeaveRepo::sicknessSummary($s['person_id'], '2026-03-04'), ['spells' => 2, 'days' => 3.0]);
    } finally {
        Clock::freeze(null);
    }
});

test('leave: the allowance is per leave year, the year start is a setting, and the balance counts approved and waiting days', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $mgr = Fx::staff($org, 'Mary', 'Manager');
    Clock::freeze('2026-06-15');
    try {
        eq(LeaveRepo::leaveYear($org, '2026-06-15'), ['2026-01-01', '2026-12-31']);
        LeaveRepo::setYearStartMonth($org, 4, Fx::actor());
        eq(LeaveRepo::leaveYear($org, '2026-06-15'), ['2026-04-01', '2027-03-31']);
        eq(LeaveRepo::leaveYear($org, '2026-02-10'), ['2025-04-01', '2026-03-31']);
        throws(fn() => LeaveRepo::setYearStartMonth($org, 13, Fx::actor()), 'month');
        eq(LeaveRepo::balance($org, $s['person_id'])['allowance'], null);
        throws(fn() => LeaveRepo::setAllowance($org, $s['person_id'], '2026-06-15', '28.3', null, Fx::actor()), 'halves');
        LeaveRepo::setAllowance($org, $s['person_id'], '2026-06-15', '28', 'Pro rata', Fx::actor());
        LeaveRepo::setAllowance($org, $s['person_id'], '2026-06-20', '30', null, Fx::actor());   // same year: replaces
        $a = LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => '2026-07-06', 'ends_on' => '2026-07-10'], Fx::actor());
        $b = LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-04'], Fx::actor());
        LeaveRepo::decide($org, $a['uuid'], true, '', null, 1, $mgr['person_id'], Fx::actor());
        eq(LeaveRepo::balance($org, $s['person_id']), ['year_start' => '2026-04-01', 'year_end' => '2027-03-31', 'allowance' => 30.0, 'booked' => 5.0, 'pending' => 2.0, 'remaining' => 25.0]);
        LeaveRepo::decide($org, $b['uuid'], false, 'No cover', null, 1, $mgr['person_id'], Fx::actor());
        eq(LeaveRepo::balance($org, $s['person_id'])['pending'], 0.0);
        // unpaid and other leave do not use the annual allowance
        $u = LeaveRepo::create($org, $s['person_id'], ['kind' => 'unpaid', 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-08'], Fx::actor());
        LeaveRepo::decide($org, $u['uuid'], true, '', null, 1, $mgr['person_id'], Fx::actor());
        eq(LeaveRepo::balance($org, $s['person_id'])['booked'], 5.0);
        // leave that straddles the year end only counts the part inside each year
        $c = LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => '2027-03-29', 'ends_on' => '2027-04-02'], Fx::actor());
        LeaveRepo::decide($org, $c['uuid'], true, '', null, 1, $mgr['person_id'], Fx::actor());
        eq(LeaveRepo::balance($org, $s['person_id'], '2026-06-15')['booked'], 8.0);        // 5 + Mon to Wed 29-31 March
        eq(LeaveRepo::balance($org, $s['person_id'], '2027-04-10')['booked'], 2.0);        // Thu 1 and Fri 2 April
    } finally {
        Clock::freeze(null);
    }
});

test('leave: managers recording leave puts it straight to approved; the month view and who is off today follow approved and waiting leave', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $team = Fx::team($org, 'North');
    DB::insert('team_assignments', ['uuid' => App\Core\Uuid::v4(), 'organisation_id' => $org, 'team_id' => $team, 'person_id' => $s['person_id'], 'valid_from' => '2025-01-01']);
    $loner = Fx::staff($org, 'Lone', 'Worker');
    Clock::freeze('2026-01-02');
    $a = LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => '2026-01-05', 'ends_on' => '2026-01-07'], Fx::actor(), true);
    eq($a['status'], 'approved');
    LeaveRepo::create($org, $loner['person_id'], ['kind' => 'annual', 'starts_on' => '2026-01-12', 'ends_on' => '2026-01-12'], Fx::actor());   // a request only (a manager recording is the other path)
    $m = LeaveRepo::month($org, '2026-01-01', null);
    eq([count($m['people']), $m['days']], [2, 31]);
    eq(count($m['absences'][$s['person_id']]), 1);
    eq(count($m['absences'][$loner['person_id']]), 1);
    $m = LeaveRepo::month($org, '2026-01-01', $team);
    eq(count($m['people']), 1);
    eq(count(LeaveRepo::offOn($org, '2026-01-06')), 1);
    eq(count(LeaveRepo::offOn($org, '2026-01-12')), 0, 'a request is not yet leave');
    eq(count(LeaveRepo::pending($org)), 1);
    Clock::freeze(null);
});

test('leave swap: two colleagues exchange approved annual leave, a manager decides, allowances do not change', function () {
    $org = Fx::org();
    $a = Fx::staff($org, 'Ann', 'Alpha'); $b = Fx::staff($org, 'Bob', 'Beta'); $mgr = Fx::staff($org, 'Mia', 'Manager');
    $m1 = monday(70); $m2 = monday(105);
    $mk = function (array $s, string $mon, int $len = 4) use ($org) {
        $x = LeaveRepo::create($org, $s['person_id'], ['kind' => 'annual', 'starts_on' => $mon, 'ends_on' => Clock::addDays($mon, $len)], Fx::actor());
        LeaveRepo::decide($org, $x['uuid'], true, '', null, 1, null, Fx::actor());
        return $x['uuid'];
    };
    $ua = $mk($a, $m1); $ub = $mk($b, $m2); $ub3 = $mk($b, monday(140), 2);
    ok(count(LeaveSwap::swappable($org, $a['person_id'])) === 1 && in_array($b['person_id'], array_map('intval', array_column(LeaveSwap::colleagues($org, $a['person_id']), 'id')), true), 'approved future annual leave can be swapped, and colleagues with some are listed');
    throws(fn() => LeaveSwap::propose($org, $a['person_id'], $ub, $ua, '', Fx::actor()), 'own');
    throws(fn() => LeaveSwap::propose($org, $a['person_id'], $ua, $ua, '', Fx::actor()), 'colleague');
    throws(fn() => LeaveSwap::propose($org, $a['person_id'], $ua, $ub3, '', Fx::actor()), 'same number');
    $before = LeaveRepo::balance($org, $a['person_id']);
    $sw = LeaveSwap::propose($org, $a['person_id'], $ua, $ub, 'Wedding', Fx::actor());
    throws(fn() => LeaveSwap::propose($org, $b['person_id'], $ub, $ua, '', Fx::actor()), 'already part');
    ok(count(LeaveSwap::swappable($org, $a['person_id'])) === 0, 'leave in an open request cannot be offered again');
    throws(fn() => LeaveSwap::decide($org, $sw, true, '', 1, $mgr['person_id'], Fx::actor()), 'not answered');
    throws(fn() => LeaveSwap::answer($org, $mgr['person_id'], $sw, true, Fx::actor()), 'not for you');
    LeaveSwap::answer($org, $b['person_id'], $sw, true, Fx::actor());
    ok(LeaveRepo::byUuid($org, $ua)['starts_on'] === $m1, 'nothing moves until a manager decides');
    throws(fn() => LeaveSwap::decide($org, $sw, true, '', 1, $a['person_id'], Fx::actor()), 'own');
    throws(fn() => LeaveSwap::decide($org, $sw, false, '', 1, $mgr['person_id'], Fx::actor()), 'why');
    LeaveSwap::decide($org, $sw, true, 'Fine', 1, $mgr['person_id'], Fx::actor());
    ok(LeaveRepo::byUuid($org, $ua)['starts_on'] === $m2 && LeaveRepo::byUuid($org, $ub)['starts_on'] === $m1 && LeaveSwap::byUuid($org, $sw)['status'] === 'approved', 'on approval the two records take each other\'s dates');
    ok(LeaveRepo::balance($org, $a['person_id'])['booked'] === $before['booked'], 'the allowance is untouched');
    ok((int) DB::value("SELECT COUNT(*) FROM outbox_events WHERE type = 'absence.decided' AND payload LIKE ?", ['%' . $m2 . '%']) >= 1, 'other systems are told the new dates');
    throws(fn() => LeaveSwap::decide($org, $sw, true, '', 1, $mgr['person_id'], Fx::actor()), 'already');
    // refuse, decline, withdraw
    $s2 = LeaveSwap::propose($org, $a['person_id'], $ua, $ub3 === '' ? $ub : $ub, '', Fx::actor());
    LeaveSwap::answer($org, $b['person_id'], $s2, true, Fx::actor());
    LeaveSwap::decide($org, $s2, false, 'Cover needed', 1, $mgr['person_id'], Fx::actor());
    ok(LeaveSwap::byUuid($org, $s2)['status'] === 'refused' && LeaveRepo::byUuid($org, $ua)['starts_on'] === $m2, 'a refused swap changes nothing');
    $s3 = LeaveSwap::propose($org, $a['person_id'], $ua, $ub, '', Fx::actor()); LeaveSwap::answer($org, $b['person_id'], $s3, false, Fx::actor());
    ok(LeaveSwap::byUuid($org, $s3)['status'] === 'declined', 'a colleague can say no');
    $s4 = LeaveSwap::propose($org, $a['person_id'], $ua, $ub, '', Fx::actor()); throws(fn() => LeaveSwap::cancel($org, $b['person_id'], $s4, Fx::actor()), 'not your');
    LeaveSwap::cancel($org, $a['person_id'], $s4, Fx::actor());
    ok(LeaveSwap::byUuid($org, $s4)['status'] === 'cancelled' && LeaveSwap::byUuid(Fx::org(), $s4) === null, 'it can be withdrawn, and another organisation cannot see it');
    // a clash with other leave stops it
    $c = Fx::staff($org, 'Cat', 'Gamma'); $uc = $mk($c, monday(200));
    LeaveRepo::create($org, $a['person_id'], ['kind' => 'unpaid', 'starts_on' => monday(200), 'ends_on' => Clock::addDays(monday(200), 1)], Fx::actor(), true);
    throws(fn() => LeaveSwap::propose($org, $c['person_id'], $uc, $ua, '', Fx::actor()), 'clash');
});
