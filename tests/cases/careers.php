<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\View;
use App\Modules\Recruitment\ApplicationRepo;
use App\Modules\Recruitment\Careers;
use App\Modules\Recruitment\InterviewRepo;
use App\Modules\Recruitment\VacancyRepo;

function advertised(int $org, array $set = []): array
{
    $s = recruitmentSetup($org);
    VacancyRepo::update($org, $s['vac']['id'], ['advertise' => '1', 'salary_text' => '£12 an hour', 'public_summary' => 'Join us.'] + $set, Fx::actor());
    $s['org'] = DB::one('SELECT * FROM organisations WHERE id = ?', [$org]);
    return $s;
}

function formData(string $uuid, array $more = []): array
{
    return $more + ['first_name' => 'Ann', 'last_name' => 'Applicant', 'email' => 'ann@example.test', 'phone' => '', 'statement' => 'Hello', 'hp_extra' => '', 'ft' => Careers::stamp($uuid, time() - 30)];
}

test('careers: only open, advertised vacancies in date show on the public page, and only for the right organisation', function () {
    $org = Fx::org();
    $s = recruitmentSetup($org);                       // open but not advertised
    eq(count(Careers::vacancies($org)), 0);
    VacancyRepo::update($org, $s['vac']['id'], ['advertise' => '1'], Fx::actor());
    eq(count(Careers::vacancies($org)), 1);
    ok(Careers::vacancy($org, $s['vac']['uuid']) !== null);
    ok(Careers::vacancy(Fx::org(), $s['vac']['uuid']) === null, 'another organisation cannot show it');
    VacancyRepo::update($org, $s['vac']['id'], ['closing_date' => Clock::addDays(Clock::today(), -1)], Fx::actor());
    eq(count(Careers::vacancies($org)), 0, 'past its closing date');
    VacancyRepo::update($org, $s['vac']['id'], ['closing_date' => ''], Fx::actor());
    VacancyRepo::close($org, $s['vac']['id'], false, Fx::actor());
    eq(count(Careers::vacancies($org)), 0, 'closed');
    eq(Careers::organisation('no-such-organisation'), null);
    eq(Careers::organisation($s['vac'] ? DB::value('SELECT slug FROM organisations WHERE id = ?', [$org]) : '')['id'], $org);
});

test('careers: the form stamp must be genuine, not too fast and not stale', function () {
    $u = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $t = 1800000000;
    ok(Careers::stampOk($u, Careers::stamp($u, $t), $t + 30));
    ok(!Careers::stampOk($u, Careers::stamp($u, $t), $t + 1), 'too fast');
    ok(!Careers::stampOk($u, Careers::stamp($u, $t), $t + 90000), 'stale');
    ok(!Careers::stampOk($u, Careers::stamp('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $t), $t + 30), 'for another vacancy');
    ok(!Careers::stampOk($u, $t . '.' . str_repeat('0', 64), $t + 30), 'forged');
    ok(!Careers::stampOk($u, 'nonsense', $t + 30));
});

test('careers: applying stores an application with the role checks, and refuses bad or repeated applications', function () {
    $org = Fx::org();
    $s = advertised($org);
    $u = $s['vac']['uuid'];
    ok(Careers::apply($org, $u, formData($u)));
    $a = DB::one('SELECT * FROM applications WHERE vacancy_id = ?', [$s['vac']['id']]);
    eq([$a['source'], $a['stage'], $a['email'], $a['notes']], ['Careers page', 'applied', 'ann@example.test', 'Hello']);
    ok(count(ApplicationRepo::checks((int) $a['id'])) === 2, 'role checks copied');
    throws(fn() => Careers::apply($org, $u, formData($u)), 'already applied');
    throws(fn() => Careers::apply($org, $u, formData($u, ['email' => 'b@example.test', 'ft' => Careers::stamp($u, time())])), 'reload');
    throws(fn() => Careers::apply($org, $u, formData($u, ['email' => '', 'first_name' => 'No'])), 'Email');
    throws(fn() => Careers::apply($org, $u, formData($u, ['email' => 'c@example.test', 'first_name' => ''])), 'First name');
    throws(fn() => Careers::apply($org, $u, formData($u, ['email' => 'd@example.test', 'statement' => str_repeat('x', 3001)])), 'statement');
    eq(Fx::count('applications', 'vacancy_id = ?', [$s['vac']['id']]), 1);
});

test('careers: a filled trap field stores nothing and looks like success; a closed vacancy takes no applications', function () {
    $org = Fx::org();
    $s = advertised($org);
    $u = $s['vac']['uuid'];
    eq(Careers::apply($org, $u, formData($u, ['hp_extra' => 'http://spam.example'])), false);
    eq(Fx::count('applications', 'vacancy_id = ?', [$s['vac']['id']]), 0);
    VacancyRepo::close($org, $s['vac']['id'], false, Fx::actor());
    throws(fn() => Careers::apply($org, $u, formData($u)), 'no longer open');
    throws(fn() => Careers::apply(Fx::org(), $u, formData($u)), 'no longer open');
});

test('careers: the public pages render and show nothing private', function () {
    $org = Fx::org();
    $s = advertised($org);
    $html = View::render('careers/index', ['title' => 'Jobs', 'org' => $s['org'], 'vacancies' => Careers::vacancies($org)], 'layout_public');
    ok(str_contains($html, 'Support Worker') && str_contains($html, '/careers/' . $s['org']['slug'] . '/' . $s['vac']['uuid']));
    $v = Careers::vacancy($org, $s['vac']['uuid']);
    $page = View::render('careers/vacancy', ['title' => 'x', 'org' => $s['org'], 'v' => $v, 'error' => null, 'old' => [], 'stamp' => Careers::stamp($s['vac']['uuid'])], 'layout_public');
    ok(str_contains($page, 'name="hp_extra"') && str_contains($page, '£12 an hour') && str_contains($page, 'Supports people at home.'));
    ok(!str_contains($page, 'Mary') && !str_contains($page, 'Hamilton'), 'no line manager or other internal names');
    ok(str_contains($page, 'noindex'));
});

test('interviews: booking needs a shortlisted candidate, moves them to interview and refuses the past', function () {
    $org = Fx::org();
    $s = recruitmentSetup($org);
    $c = candidate($org, $s['vac']['id']);
    $when = Clock::addDays(Clock::today(), 5) . 'T10:30';
    throws(fn() => InterviewRepo::schedule($org, $c['id'], ['starts_at' => $when, 'location' => 'Office'], Fx::actor()), 'Shortlist');
    ApplicationRepo::setStage($org, $c['id'], 'shortlisted', [], Fx::actor());
    throws(fn() => InterviewRepo::schedule($org, $c['id'], ['starts_at' => Clock::addDays(Clock::today(), -2) . 'T10:30', 'location' => 'Office'], Fx::actor()), 'past');
    throws(fn() => InterviewRepo::schedule($org, $c['id'], ['starts_at' => 'tomorrow', 'location' => 'Office'], Fx::actor()), 'date and time');
    throws(fn() => InterviewRepo::schedule($org, $c['id'], ['starts_at' => $when, 'location' => ''], Fx::actor()), 'Where');
    throws(fn() => InterviewRepo::schedule($org, $c['id'], ['starts_at' => $when, 'location' => 'Office', 'duration_minutes' => 5], Fx::actor()), 'minutes');
    $i = InterviewRepo::schedule($org, $c['id'], ['starts_at' => $when, 'location' => 'Office', 'panel' => 'Mary M', 'instructions' => 'Photo ID'], Fx::actor());
    $a = ApplicationRepo::byId($org, $c['id']);
    eq([$a['stage'], $a['interview_date']], ['interview', Clock::addDays(Clock::today(), 5)]);
    eq(strlen($i['token']), 32);
    eq(count(InterviewRepo::upcoming($org)), 1);
    // a second interview (a second round) is allowed while at the interview stage
    InterviewRepo::schedule($org, $c['id'], ['starts_at' => Clock::addDays(Clock::today(), 9) . 'T14:00', 'location' => 'Office'], Fx::actor());
    eq(count(InterviewRepo::forApplication($c['id'])), 2);
});

test('interviews: the candidate answers on a private link; moving it clears the answer; other organisations cannot touch it', function () {
    $org = Fx::org();
    $s = recruitmentSetup($org);
    $c = candidate($org, $s['vac']['id']);
    ApplicationRepo::setStage($org, $c['id'], 'shortlisted', [], Fx::actor());
    $i = InterviewRepo::schedule($org, $c['id'], ['starts_at' => Clock::addDays(Clock::today(), 5) . 'T10:30', 'location' => 'Office'], Fx::actor());
    ok(InterviewRepo::byToken('nonsense') === null);
    ok(InterviewRepo::byToken(str_repeat('0', 32)) === null);
    throws(fn() => InterviewRepo::respond($i['token'], 'maybe', ''), 'Choose');
    throws(fn() => InterviewRepo::respond($i['token'], 'confirmed', str_repeat('x', 501)), '500');
    InterviewRepo::respond($i['token'], 'cannot_attend', 'Working that day');
    $row = InterviewRepo::byUuid($org, $i['uuid']);
    eq([$row['candidate_response'], $row['response_note']], ['cannot_attend', 'Working that day']);
    ok($row['responded_at'] !== null);
    ok(InterviewRepo::byUuid(Fx::org(), $i['uuid']) === null, 'another organisation cannot see it');
    throws(fn() => InterviewRepo::reschedule(Fx::org(), $i['uuid'], ['starts_at' => Clock::addDays(Clock::today(), 6) . 'T09:00', 'location' => 'X'], Fx::actor()), 'not found');
    InterviewRepo::reschedule($org, $i['uuid'], ['starts_at' => Clock::addDays(Clock::today(), 6) . 'T09:00', 'location' => 'New room'], Fx::actor());
    $row = InterviewRepo::byUuid($org, $i['uuid']);
    eq([$row['candidate_response'], $row['response_note'], $row['location']], ['none', null, 'New room']);
    $page = View::render('careers/interview', ['title' => 'Your interview', 'i' => $row, 'a' => ['first_name' => 'Cat', 'title' => 'Support Worker'], 'orgName' => 'Org', 'open' => true, 'error' => null, 'sent' => false], 'layout_public');
    ok(str_contains($page, 'New room') && str_contains($page, 'Yes, I can come'));
    InterviewRepo::close($org, $i['uuid'], 'cancelled', Fx::actor());
    throws(fn() => InterviewRepo::respond($i['token'], 'confirmed', ''), 'no longer');
    throws(fn() => InterviewRepo::close($org, $i['uuid'], 'completed', Fx::actor()), 'already');
    eq(count(InterviewRepo::upcoming($org)), 0);
});

test('interviews: it can only be marked as having happened once its day has come, and the invitation text carries the details', function () {
    $org = Fx::org();
    $s = recruitmentSetup($org);
    $c = candidate($org, $s['vac']['id']);
    ApplicationRepo::setStage($org, $c['id'], 'shortlisted', [], Fx::actor());
    $i = InterviewRepo::schedule($org, $c['id'], ['starts_at' => Clock::addDays(Clock::today(), 3) . 'T10:30', 'location' => 'Office', 'panel' => 'Mary M', 'instructions' => 'Photo ID'], Fx::actor());
    throws(fn() => InterviewRepo::close($org, $i['uuid'], 'completed', Fx::actor()), 'not happened');
    throws(fn() => InterviewRepo::close($org, $i['uuid'], 'bogus', Fx::actor()), 'not something');
    $row = InterviewRepo::byUuid($org, $i['uuid']);
    $text = InterviewRepo::invitation($row, ApplicationRepo::byId($org, $c['id']), ['title' => 'Support Worker'], 'Hamilton Care', 'https://x.test/interview/' . $i['token']);
    foreach (['Dear Cat', 'Support Worker', 'Hamilton Care', 'Office', 'Mary M', 'Photo ID', 'https://x.test/interview/' . $i['token']] as $needle) {
        ok(str_contains($text, $needle), "invitation mentions $needle");
    }
    Clock::freeze(Clock::addDays(Clock::today(), 4));
    try {
        InterviewRepo::close($org, $i['uuid'], 'no_show', Fx::actor());
    } finally {
        Clock::freeze(null);
    }
    eq(InterviewRepo::byUuid($org, $i['uuid'])['status'], 'no_show');
});
