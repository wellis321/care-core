<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Database as DB;
use App\Modules\Documents\DocumentRepo;
use App\Modules\Documents\SubjectAccess;
use App\Modules\Leave\LeaveRepo;

/** A real minimal PDF and PNG on disk, shaped like an entry of $_FILES. @return array<string,mixed> */
function upload(string $kind = 'pdf', string $name = 'contract.pdf'): array
{
    $bytes = match ($kind) {
        'pdf' => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        'exe' => "MZ\x90\x00\x03\x00\x00\x00 not a document",
        'html' => '<html><script>alert(1)</script></html>',
        default => '',
    };
    $tmp = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($tmp, $bytes);
    return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), '_test' => true];
}

test('documents: only real PDF, Word, PNG and JPEG files are kept, judged by content, and stored privately', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $uuid = DocumentRepo::add($org, $s['person_id'], upload(), ['title' => 'Signed contract', 'category' => 'contract', 'expires_on' => '2030-01-31'], Fx::actor());
    $d = DocumentRepo::byUuid($org, $uuid);
    eq([$d['title'], $d['category'], $d['mime'], $d['original_name'], $d['expires_on'], (int) $d['shared_with_person']], ['Signed contract', 'contract', 'application/pdf', 'contract.pdf', '2030-01-31', 0]);
    ok(preg_match('#^\d+/documents/[0-9a-f-]{36}\.pdf$#', $d['file_path']) === 1);
    ok(str_starts_with((string) DocumentRepo::read($d), '%PDF'));
    ok(!str_contains(DocumentRepo::root(), '/public'), 'outside the web root');
    DocumentRepo::add($org, $s['person_id'], upload('png', 'passport.PNG'), [], Fx::actor());
    eq(DB::value('SELECT title FROM staff_documents WHERE original_name = ?', ['passport.png']), 'passport');
    // a program renamed to look like a PDF, a web page, an empty file, no file, a missing person
    throws(fn() => DocumentRepo::add($org, $s['person_id'], upload('exe', 'nice.pdf'), [], Fx::actor()), 'Only PDF');
    throws(fn() => DocumentRepo::add($org, $s['person_id'], upload('html', 'page.pdf'), [], Fx::actor()), 'Only PDF');
    throws(fn() => DocumentRepo::add($org, $s['person_id'], upload('empty', 'x.pdf'), [], Fx::actor()), 'empty');
    throws(fn() => DocumentRepo::add($org, $s['person_id'], ['error' => UPLOAD_ERR_NO_FILE], [], Fx::actor()), 'Choose a file');
    throws(fn() => DocumentRepo::add($org, $s['person_id'], ['error' => UPLOAD_ERR_INI_SIZE], [], Fx::actor()), '10 MB');
    throws(fn() => DocumentRepo::add($org, 999999, upload(), [], Fx::actor()), 'Person');
    throws(fn() => DocumentRepo::add(Fx::org(), $s['person_id'], upload(), [], Fx::actor()), 'Person');   // another organisation's person
    throws(fn() => DocumentRepo::add($org, $s['person_id'], upload(), ['category' => 'bogus'], Fx::actor()), 'category');
    throws(fn() => DocumentRepo::add($org, $s['person_id'], upload(), ['expires_on' => '31/01/2030'], Fx::actor()), 'date');
    eq(Fx::count('staff_documents', 'person_id = ?', [$s['person_id']]), 2);
    // a hostile file name cannot escape the folder or become the stored name
    $u = upload('pdf', '../../../etc/passwd<script>.pdf');
    $id = DocumentRepo::add($org, $s['person_id'], $u, [], Fx::actor());
    $row = DocumentRepo::byUuid($org, $id);
    ok(!str_contains($row['original_name'], '/') && !str_contains($row['original_name'], '<') && str_ends_with($row['original_name'], '.pdf'), $row['original_name']);
    ok(preg_match('#^\d+/documents/[0-9a-f-]{36}\.pdf$#', $row['file_path']) === 1);
});

test('documents: managers download anything; the person only what is shared with them; other organisations nothing', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $o = Fx::staff($org, 'Olly', 'Other');
    $uuid = DocumentRepo::add($org, $s['person_id'], upload(), [], Fx::actor());
    $d = DocumentRepo::byUuid($org, $uuid);
    ok(DocumentRepo::canDownload(['person_id' => $o['person_id']], $d, true), 'manager');
    ok(!DocumentRepo::canDownload(['person_id' => $s['person_id']], $d, false), 'not shared yet');
    ok(!DocumentRepo::canDownload(['person_id' => $o['person_id']], $d, false), 'someone else');
    DocumentRepo::setShared($org, $uuid, true, Fx::actor());
    $d = DocumentRepo::byUuid($org, $uuid);
    ok(DocumentRepo::canDownload(['person_id' => $s['person_id']], $d, false), 'shared with them');
    ok(!DocumentRepo::canDownload(['person_id' => $o['person_id']], $d, false), 'still not someone else');
    ok(!DocumentRepo::canDownload(['person_id' => null], $d, false));
    ok(DocumentRepo::byUuid(Fx::org(), $uuid) === null, 'another organisation cannot look it up');
    eq(count(DocumentRepo::forPerson($s['person_id'], true)), 1);
    DocumentRepo::setShared($org, $uuid, false, Fx::actor());
    eq(count(DocumentRepo::forPerson($s['person_id'], true)), 0);
    eq(count(DocumentRepo::forPerson($s['person_id'])), 1);
});

test('documents: removing deletes the file and the record, and the review list shows what has expired or is about to', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $today = Clock::today();
    $a = DocumentRepo::add($org, $s['person_id'], upload(), ['title' => 'Expired', 'expires_on' => Clock::addDays($today, -5)], Fx::actor());
    DocumentRepo::add($org, $s['person_id'], upload(), ['title' => 'Soon', 'expires_on' => Clock::addDays($today, 30)], Fx::actor());
    DocumentRepo::add($org, $s['person_id'], upload(), ['title' => 'Later', 'expires_on' => Clock::addDays($today, 400)], Fx::actor());
    DocumentRepo::add($org, $s['person_id'], upload(), ['title' => 'Never'], Fx::actor());
    eq(array_column(DocumentRepo::expiring($org, 60), 'title'), ['Expired', 'Soon']);
    $row = DocumentRepo::byUuid($org, $a);
    $path = DocumentRepo::root() . '/' . $row['file_path'];
    ok(is_file($path));
    throws(fn() => DocumentRepo::remove(Fx::org(), $a, Fx::actor()), 'not found');
    ok(is_file($path), 'another organisation could not remove it');
    DocumentRepo::remove($org, $a, Fx::actor());
    ok(!is_file($path) && DocumentRepo::byUuid($org, $a) === null);
    eq(Fx::count('audit_log', "organisation_id = ? AND action = 'document.removed'", [$org]), 1);
});

test('documents: the retention review only lists leavers past the period you set, and never anyone still employed', function () {
    $org = Fx::org();
    eq(DocumentRepo::pastRetention($org), []);                      // off until set
    $old = Fx::staff($org, 'Olive', 'Leaver');
    $recent = Fx::staff($org, 'Rae', 'Recent');
    $here = Fx::staff($org, 'Stay', 'Here');
    $rehired = Fx::staff($org, 'Rita', 'Rehired');
    $today = Clock::today();
    DB::run('UPDATE employments SET end_date = ? WHERE person_id = ?', [Clock::addDays($today, -365 * 8), $old['person_id']]);
    DB::run('UPDATE employments SET end_date = ? WHERE person_id = ?', [Clock::addDays($today, -365 * 2), $recent['person_id']]);
    DB::run('UPDATE employments SET end_date = ? WHERE person_id = ?', [Clock::addDays($today, -365 * 9), $rehired['person_id']]);
    DB::insert('employments', ['uuid' => App\Core\Uuid::v4(), 'organisation_id' => $org, 'person_id' => $rehired['person_id'], 'job_title' => 'Back again', 'start_date' => Clock::addDays($today, -100)]);
    DocumentRepo::add($org, $old['person_id'], upload(), [], Fx::actor());
    throws(fn() => DocumentRepo::setRetentionYears($org, '0', Fx::actor()), 'whole number');
    throws(fn() => DocumentRepo::setRetentionYears($org, 'six', Fx::actor()), 'whole number');
    DocumentRepo::setRetentionYears($org, '6', Fx::actor());
    eq(DocumentRepo::retentionYears($org), 6);
    $past = DocumentRepo::pastRetention($org);
    eq(array_column($past, 'last_name'), ['Leaver']);
    eq((int) $past[0]['documents'], 1);
    DocumentRepo::setRetentionYears($org, '', Fx::actor());
    eq(DocumentRepo::pastRetention($org), []);
});

test('documents: the subject access export covers what is held about a person and says what it leaves out', function () {
    $org = Fx::org();
    $s = Fx::staff($org, 'Sara', 'Subject');
    $mgr = Fx::staff($org, 'Mary', 'Manager');
    [$clean] = App\Modules\Staff\StaffRecord::cleanProfile(['address_line1' => '1 Test Street', 'ni_number' => 'JA123456A', 'emergency_contact_name' => 'Her sister']);
    App\Modules\Staff\StaffRecord::applyProfile($org, $s['person_id'], $clean, Fx::actor());
    LeaveRepo::create($org, $s['person_id'], ['kind' => 'sickness', 'starts_on' => Clock::today(), 'ends_on' => '', 'reason' => 'Flu'], Fx::actor());
    DocumentRepo::add($org, $s['person_id'], upload(), ['title' => 'Contract'], Fx::actor());
    $x = SubjectAccess::build($org, $s['person_id'], Fx::actor());
    eq($x['about'], 'Sara Subject');
    eq($x['profile']['address_line1'], '1 Test Street');
    eq($x['profile']['ni_number'], 'JA123456A', 'decrypted for the person it is about');
    eq($x['leave_and_absence'][0]['reason'], 'Flu');
    eq($x['documents'][0]['title'], 'Contract');
    ok(count($x['employments']) === 1 && isset($x['employments'][0]['job_title']));
    ok(count($x['not_included']) >= 3);
    ok(!str_contains(json_encode($x), $mgr['person_uuid']), 'nothing about another person');
    ok(!array_key_exists('id', $x['person']) && !array_key_exists('organisation_id', $x['person']), 'no internal keys');
    throws(fn() => SubjectAccess::build(Fx::org(), $s['person_id'], Fx::actor()), 'not found');
    eq(Fx::count('audit_log', "organisation_id = ? AND action = 'person.subject_access'", [$org]), 1);
});
