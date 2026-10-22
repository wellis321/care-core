<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database as DB;
use App\Modules\Staff\Compliance;
use App\Modules\Staff\FileStore;
use App\Modules\Staff\ProfileChanges;
use App\Modules\Staff\StaffRecord;

const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** A signed-in user row as the application sees it. */
function fakeUser(int $org, ?int $personId, string $role): array
{
    return ['id' => 0, 'organisation_id' => $org, 'person_id' => $personId, 'role' => $role];
}

// ── Checking values ─────────────────────────────────────────────────────────

test('profile checks: National Insurance, sort code, account number, postcode and phone are checked and tidied', function () {
    [$c, $e] = StaffRecord::cleanProfile(['ni_number' => 'ja 00 00 01 a', 'bank_sort_code' => '123456', 'bank_account_number' => '12345678', 'address_postcode' => 'iv2 3ps', 'phone' => '07700 900123']);
    eq($e, []);
    eq($c['ni_number'], 'JA000001A');
    eq($c['bank_sort_code'], '12-34-56');
    eq($c['address_postcode'], 'IV2 3PS');
    foreach ([['ni_number', 'QQ123456C'], ['ni_number', 'AB12345'], ['ni_number', 'BG123456A'], ['bank_sort_code', '12-34'], ['bank_account_number', '1234'], ['address_postcode', 'NOTAPC'], ['phone', 'call me'], ['date_of_birth', '2999-01-01'], ['first_name', '']] as [$f, $v]) {
        [, $e] = StaffRecord::cleanProfile([$f => $v]);
        ok(isset($e[$f]), "$f '$v' should be refused");
    }
});

test('profile checks: only known personal fields are ever read', function () {
    [$c] = StaffRecord::cleanProfile(['phone' => '07700 900123', 'is_admin' => '1', 'organisation_id' => '99', 'password_hash' => 'x']);
    eq(array_keys($c), ['phone']);
});

// ── Storage ─────────────────────────────────────────────────────────────────

test('profile: National Insurance and bank details are encrypted at rest and read back in clear', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    [$c] = StaffRecord::cleanProfile(['ni_number' => 'JA000001A', 'bank_account_number' => '12345678', 'address_city' => 'Inverness']);
    StaffRecord::applyProfile($org, $s['person_id'], $c, Fx::actor());
    $raw = DB::one('SELECT * FROM person_profiles WHERE person_id = ?', [$s['person_id']]);
    ok(str_starts_with($raw['ni_number'], 'enc2:') && str_starts_with($raw['bank_account_number'], 'enc2:'), 'secrets must be encrypted');
    ok(!str_contains($raw['ni_number'], 'JA000001A'));
    eq($raw['address_city'], 'Inverness');
    $p = StaffRecord::profile($s['person_id']);
    eq($p['ni_number'], 'JA000001A');
    eq($p['bank_account_number'], '12345678');
    eq(Crypto::decrypt(Crypto::encrypt('hello')), 'hello');
    eq(StaffRecord::mask('12345678'), '••••5678');
});

test('profile: audit and events name the fields changed, never the values', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    [$c] = StaffRecord::cleanProfile(['ni_number' => 'JA000002A', 'phone' => '07700 900777']);
    StaffRecord::applyProfile($org, $s['person_id'], $c, Fx::actor());
    foreach (DB::all("SELECT detail, summary FROM audit_log WHERE organisation_id = ? AND action LIKE 'person.profile%'", [$org]) as $a) {
        ok(!str_contains($a['detail'] . $a['summary'], 'JA000002A') && !str_contains($a['detail'] . $a['summary'], '900777'), 'audit leaks a value');
    }
    foreach (DB::all("SELECT payload FROM outbox_events WHERE organisation_id = ? AND type = 'person.updated'", [$org]) as $e) {
        ok(!str_contains($e['payload'], 'JA000002A') && !str_contains($e['payload'], '900777'), 'event leaks a value');
    }
});

test('employment sections: saving one section leaves the others alone, and checks dates and numbers', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $emp = DB::one('SELECT * FROM employments WHERE id = ?', [$s['employment_id']]);
    [$c] = StaffRecord::cleanSection('tupe', ['is_tupe' => '1', 'tupe_transfer_date' => '2025-03-01', 'tupe_previous_organisation' => 'Old Co']);
    StaffRecord::saveSection($org, $emp, 'tupe', $c, Fx::actor());
    [$c] = StaffRecord::cleanSection('leave', ['annual_leave_allocation' => '33', 'annual_leave_used' => '']);
    StaffRecord::saveSection($org, $emp, 'leave', $c, Fx::actor());
    $d = StaffRecord::details($s['employment_id']);
    eq((int) $d['is_tupe'], 1);
    eq($d['tupe_previous_organisation'], 'Old Co');
    eq((float) $d['annual_leave_allocation'], 33.0);
    eq((float) $d['annual_leave_used'], 0.0, 'a blank number that cannot be empty becomes zero');
    [, $e] = StaffRecord::cleanSection('apprentice', ['apprenticeship_start_date' => '2025-05-01', 'apprenticeship_end_date' => '2025-01-01']);
    ok(isset($e['apprenticeship_end_date']));
    [, $e] = StaffRecord::cleanSection('visa', ['has_visa' => '1']);
    ok(isset($e['visa_expiry_date']), 'a visa needs an expiry date');
    [, $e] = StaffRecord::cleanSection('leave', ['annual_leave_allocation' => '-3']);
    ok(isset($e['annual_leave_allocation']));
    throws(fn() => StaffRecord::cleanSection('nonsense', []), 'Unknown');
});

// ── Self-service and approval ───────────────────────────────────────────────

test('self-service: phone saves at once, address and bank wait for approval, nothing changes until approved', function () {
    $org = Fx::org();
    $s = Fx::staff($org, 'Kay', 'Self');
    [$c] = StaffRecord::cleanProfile(['phone' => '07700 900111', 'address_line1' => '1 New Street', 'bank_account_number' => '87654321']);
    $r = ProfileChanges::submit($org, $s['person_id'], 0, $c, Fx::actor());
    eq($r['applied'], ['phone']);
    eq($r['pending'], ['address_line1', 'bank_account_number']);
    eq(DB::value('SELECT phone FROM persons WHERE id = ?', [$s['person_id']]), '07700 900111');
    eq(StaffRecord::profile($s['person_id'])['address_line1'], null, 'the live record is unchanged');
    eq(Fx::count('profile_changes', "person_id = ? AND status = 'pending'", [$s['person_id']]), 2);
    $raw = DB::value("SELECT proposed_value FROM profile_changes WHERE person_id = ? AND field = 'bank_account_number'", [$s['person_id']]);
    ok(str_starts_with($raw, 'enc2:'), 'a pending secret is encrypted too');
});

test('self-service: a field that is not allowed cannot be requested', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    throws(fn() => ProfileChanges::submit($org, $s['person_id'], 0, ['job_title' => 'Director'], Fx::actor()), 'cannot change');
});

test('approval: the line manager approves and the change goes live; HR can too; the person cannot approve their own', function () {
    $org = Fx::org();
    $boss = Fx::staff($org, 'Bea', 'Boss');
    $s = Fx::staff($org, 'Tom', 'Report', ['line_manager_person_id' => $boss['person_id']]);
    $other = Fx::staff($org, 'Olly', 'Other');
    [$c] = StaffRecord::cleanProfile(['address_line1' => '9 Brae Road']);
    ProfileChanges::submit($org, $s['person_id'], 0, $c, Fx::actor());
    $change = DB::one("SELECT c.*, p.uuid AS person_uuid FROM profile_changes c JOIN persons p ON p.id = c.person_id WHERE c.person_id = ?", [$s['person_id']]);
    ok(ProfileChanges::canReview(fakeUser($org, $boss['person_id'], 'staff'), $change), 'line manager');
    ok(ProfileChanges::canReview(fakeUser($org, null, 'admin'), $change), 'admin / HR');
    ok(ProfileChanges::canReview(fakeUser($org, $other['person_id'], 'manager'), $change), 'manager role');
    ok(!ProfileChanges::canReview(fakeUser($org, $other['person_id'], 'staff'), $change), 'an unrelated colleague');
    ok(!ProfileChanges::canReview(fakeUser($org, $s['person_id'], 'admin'), $change), 'nobody approves their own');
    ok(!ProfileChanges::canReview(fakeUser(Fx::org(), null, 'admin'), $change), 'another organisation');
    throws(fn() => ProfileChanges::approve($change, fakeUser($org, $s['person_id'], 'admin'), Fx::actor()), 'cannot review');
    ProfileChanges::approve($change, fakeUser($org, $boss['person_id'], 'staff'), Fx::actor());
    eq(StaffRecord::profile($s['person_id'])['address_line1'], '9 Brae Road');
    eq(DB::value('SELECT status FROM profile_changes WHERE id = ?', [$change['id']]), 'approved');
    throws(fn() => ProfileChanges::approve(DB::one('SELECT c.*, 1 AS x FROM profile_changes c WHERE id = ?', [$change['id']]), fakeUser($org, null, 'admin'), Fx::actor()), 'already');
});

test('approval: a rejected change leaves the record alone and keeps the reason', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    [$c] = StaffRecord::cleanProfile(['first_name' => 'Renamed']);
    ProfileChanges::submit($org, $s['person_id'], 0, $c, Fx::actor());
    $change = DB::one("SELECT * FROM profile_changes WHERE person_id = ?", [$s['person_id']]);
    ProfileChanges::reject($change, fakeUser($org, null, 'admin'), 'Please send your deed poll.', Fx::actor());
    eq(DB::value('SELECT first_name FROM persons WHERE id = ?', [$s['person_id']]), 'Sam');
    eq(DB::value('SELECT rejection_reason FROM profile_changes WHERE id = ?', [$change['id']]), 'Please send your deed poll.');
});

test('approval: asking again replaces the earlier request; changing back to what it is withdraws it; queues are per reviewer', function () {
    $org = Fx::org();
    $boss = Fx::staff($org, 'Bea', 'Boss');
    $s = Fx::staff($org, 'Tom', 'Report', ['line_manager_person_id' => $boss['person_id']]);
    $stranger = Fx::staff($org, 'Sid', 'Stranger');
    foreach (['1 One Road', '2 Two Road'] as $a) {
        [$c] = StaffRecord::cleanProfile(['address_line1' => $a]);
        ProfileChanges::submit($org, $s['person_id'], 0, $c, Fx::actor());
    }
    eq(Fx::count('profile_changes', "person_id = ? AND status = 'pending'", [$s['person_id']]), 1);
    eq(DB::value("SELECT proposed_value FROM profile_changes WHERE person_id = ? AND status = 'pending'", [$s['person_id']]), '2 Two Road');
    eq(count(ProfileChanges::queueFor(fakeUser($org, $boss['person_id'], 'staff'))), 1);
    eq(count(ProfileChanges::queueFor(fakeUser($org, $stranger['person_id'], 'staff'))), 0);
    eq(count(ProfileChanges::queueFor(fakeUser($org, null, 'admin'))), 1);
    [$c] = StaffRecord::cleanProfile(['address_line1' => '']);   // same as what is on the record (nothing)
    ProfileChanges::submit($org, $s['person_id'], 0, $c, Fx::actor());
    eq(Fx::count('profile_changes', "person_id = ? AND status = 'pending'", [$s['person_id']]), 0);
});

test('approval: another organisation sees nothing and cannot approve', function () {
    $a = Fx::org();
    $b = Fx::org();
    $s = Fx::staff($a);
    [$c] = StaffRecord::cleanProfile(['address_city' => 'Elgin']);
    ProfileChanges::submit($a, $s['person_id'], 0, $c, Fx::actor());
    eq(count(ProfileChanges::queueFor(fakeUser($b, null, 'admin'))), 0);
    $change = ProfileChanges::byUuid($b, DB::value('SELECT uuid FROM profile_changes WHERE person_id = ?', [$s['person_id']]) ?? '');
    eq($change, null);
});

// ── Pictures ────────────────────────────────────────────────────────────────

test('pictures: a real image is stored privately; scripts, SVG and oversized files are refused; paths cannot escape', function () {
    $org = Fx::org();
    $png = base64_decode(PNG_1PX);
    $rel = FileStore::saveBytes($org, 'photo', $png, 1000000);
    ok(preg_match('#^\d+/photo/[0-9a-f-]{36}\.png$#', $rel) === 1);
    [$bytes, $mime] = FileStore::read($rel);
    eq($mime, 'image/png');
    eq($bytes, $png);
    throws(fn() => FileStore::saveBytes($org, 'photo', '<?php echo 1;', 1000000), 'PNG, JPEG');
    throws(fn() => FileStore::saveBytes($org, 'photo', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 1000000), 'PNG, JPEG');
    throws(fn() => FileStore::saveBytes($org, 'photo', $png, 10), 'too large');
    eq(FileStore::read('../../.env'), null);
    eq(FileStore::read('1/photo/../../../.env'), null);
    FileStore::delete($rel);
    eq(FileStore::read($rel), null);
});

test('signature: a drawn signature must be a PNG data URL; approval makes it the live signature and removes the old one', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    throws(fn() => FileStore::saveDataUrl($org, 'data:text/html;base64,PGI+'), 'Draw');
    throws(fn() => FileStore::saveDataUrl($org, 'data:image/png;base64,' . base64_encode('not a png')), '');
    $first = FileStore::saveDataUrl($org, 'data:image/png;base64,' . PNG_1PX);
    ProfileChanges::submitFile($org, $s['person_id'], 0, 'signature_path', $first, 'draw', Fx::actor());
    eq(StaffRecord::profile($s['person_id'])['signature_path'], null, 'not live until approved');
    $change = ProfileChanges::byUuid($org, DB::value("SELECT uuid FROM profile_changes WHERE person_id = ? AND status = 'pending'", [$s['person_id']]));
    ProfileChanges::approve($change, fakeUser($org, null, 'admin'), Fx::actor());
    $p = StaffRecord::profile($s['person_id']);
    eq($p['signature_path'], $first);
    eq($p['signature_method'], 'draw');
    ok($p['signature_at'] !== null);
    $second = FileStore::saveDataUrl($org, 'data:image/png;base64,' . PNG_1PX);
    ProfileChanges::setFile($org, $s['person_id'], 'signature_path', $second, 'upload', Fx::actor());
    eq(FileStore::read($first), null, 'the replaced file is deleted');
    FileStore::delete($second);
});

test('pictures: a rejected or withdrawn picture is deleted from disk', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $rel = FileStore::saveBytes($org, 'photo', base64_decode(PNG_1PX), 1000000);
    ProfileChanges::submitFile($org, $s['person_id'], 0, 'photo_path', $rel, null, Fx::actor());
    $change = DB::one("SELECT c.*, p.uuid AS person_uuid FROM profile_changes c JOIN persons p ON p.id = c.person_id WHERE c.person_id = ?", [$s['person_id']]);
    ProfileChanges::reject($change, fakeUser($org, null, 'admin'), '', Fx::actor());
    eq(FileStore::read($rel), null);
    $rel2 = FileStore::saveBytes($org, 'photo', base64_decode(PNG_1PX), 1000000);
    ProfileChanges::submitFile($org, $s['person_id'], 0, 'photo_path', $rel2, null, Fx::actor());
    ProfileChanges::withdraw($s['person_id'], DB::value("SELECT uuid FROM profile_changes WHERE person_id = ? AND status = 'pending'", [$s['person_id']]), Fx::actor());
    eq(FileStore::read($rel2), null);
});

// ── Compliance records ──────────────────────────────────────────────────────

test('compliance: registrations, supervisions, appraisals and learning are checked, stored and removed', function () {
    $org = Fx::org();
    $s = Fx::staff($org);
    $person = ['id' => $s['person_id'], 'uuid' => $s['person_uuid']];
    $boss = Fx::staff($org, 'Bea', 'Boss');
    [, $e] = Compliance::clean($org, 'registration', ['registration_type' => 'SSSC']);
    ok($e !== [], 'expiry date is required');
    [, $e] = Compliance::clean($org, 'registration', ['registration_type' => 'SSSC', 'issue_date' => '2025-05-01', 'expiry_date' => '2025-01-01']);
    ok($e !== [], 'cannot expire before issue');
    [$c, $e] = Compliance::clean($org, 'registration', ['registration_type' => 'SSSC', 'expiry_date' => '2027-01-01', 'is_required_for_role' => '1']);
    eq($e, []);
    $uuid = Compliance::add($org, $person, 'registration', $c, Fx::actor());
    eq(count(Compliance::list($org, $s['person_id'], 'registration')), 1);
    [$c, $e] = Compliance::clean($org, 'supervision', ['supervision_date' => '2026-01-10', 'supervisor_person_id' => $boss['person_uuid'], 'supervision_type' => 'individual', 'duration_minutes' => '45', 'next_due_date' => '2026-03-10']);
    eq($e, []);
    Compliance::add($org, $person, 'supervision', $c, Fx::actor());
    eq(Compliance::list($org, $s['person_id'], 'supervision')[0]['other_name'], 'Bea Boss');
    [, $e] = Compliance::clean($org, 'supervision', ['supervision_date' => '2026-01-10', 'supervision_type' => 'individual', 'next_due_date' => '2025-01-01']);
    ok($e !== []);
    [, $e] = Compliance::clean($org, 'supervision', ['supervision_date' => '2026-01-10', 'supervision_type' => 'weird']);
    ok($e !== [], 'type must be one of the list');
    [, $e] = Compliance::clean($org, 'supervision', ['supervision_date' => '2026-01-10', 'supervision_type' => 'individual', 'supervisor_person_id' => Fx::staff(Fx::org())['person_uuid']]);
    ok($e !== [], 'a supervisor from another organisation is refused');
    [$c] = Compliance::clean($org, 'appraisal', ['appraisal_date' => '2026-01-10', 'appraisal_type' => 'annual', 'outcome' => 'pending']);
    Compliance::add($org, $person, 'appraisal', $c, Fx::actor());
    [$c] = Compliance::clean($org, 'learning', ['title' => 'Moving and handling', 'record_type' => 'training', 'status' => 'completed', 'completion_date' => '2026-01-01', 'expiry_date' => '2027-01-01']);
    Compliance::add($org, $person, 'learning', $c, Fx::actor());
    eq(Fx::count('audit_log', "organisation_id = ? AND action LIKE '%.added'", [$org]), 4);
    Compliance::remove($org, $person, 'registration', $uuid, Fx::actor());
    eq(count(Compliance::list($org, $s['person_id'], 'registration')), 0);
    throws(fn() => Compliance::remove(Fx::org(), $person, 'registration', $uuid, Fx::actor()), 'could not be found');
    throws(fn() => Compliance::list($org, $s['person_id'], 'bogus'), 'Unknown');
});

test('compliance: due-soon lists current staff only, flags overdue, and uses the latest supervision', function () {
    $org = Fx::org();
    Clock::freeze('2026-06-01');
    $in = Fx::staff($org, 'In', 'Date', ['start_date' => '2025-01-06']);
    $gone = Fx::staff($org, 'Gone', 'Leaver', ['start_date' => '2025-01-06']);
    \App\Modules\Staff\Lifecycle::leave($org, $gone['employment_id'], '2026-05-01', 'Resigned', Fx::actor());
    foreach ([$in, $gone] as $s) {
        [$c] = Compliance::clean($org, 'registration', ['registration_type' => 'SSSC', 'expiry_date' => '2026-06-20', 'is_required_for_role' => '1']);
        Compliance::add($org, ['id' => $s['person_id'], 'uuid' => $s['person_uuid']], 'registration', $c, Fx::actor());
    }
    [$c] = Compliance::clean($org, 'registration', ['registration_type' => 'First aid', 'expiry_date' => '2026-05-15']);
    Compliance::add($org, ['id' => $in['person_id'], 'uuid' => $in['person_uuid']], 'registration', $c, Fx::actor());
    [$c] = Compliance::clean($org, 'supervision', ['supervision_date' => '2026-01-01', 'supervision_type' => 'individual', 'next_due_date' => '2026-02-01']);
    Compliance::add($org, ['id' => $in['person_id'], 'uuid' => $in['person_uuid']], 'supervision', $c, Fx::actor());
    [$c] = Compliance::clean($org, 'supervision', ['supervision_date' => '2026-05-20', 'supervision_type' => 'individual', 'next_due_date' => '2026-08-20']);
    Compliance::add($org, ['id' => $in['person_id'], 'uuid' => $in['person_uuid']], 'supervision', $c, Fx::actor());
    $due = Compliance::dueSoon($org, 60);
    $names = array_map(static fn($d) => $d['last_name'] . ':' . $d['label'] . ':' . ($d['overdue'] ? 'overdue' : 'soon'), $due);
    eq(count($due), 2, implode(', ', $names));
    ok(in_array('Date:First aid:overdue', $names) && in_array('Date:SSSC:soon', $names), implode(', ', $names));
});
