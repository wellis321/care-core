<?php
declare(strict_types=1);

use App\Core\Database as DB;
use App\Core\DomainException;
use App\Modules\Import\Bundle;
use App\Modules\Import\Exporters\PeopleService;
use App\Modules\Import\Exporters\StaffService;
use App\Modules\Import\Exporters\TeamService;
use App\Modules\Import\Importer;
use App\Modules\Staff\Reconcile;
use App\Modules\Staff\StaffRecord;

require_once __DIR__ . '/../fixtures/OldSystems.php';

function bundleOf(array $parts): array
{
    return Bundle::normalise(['format' => 'carecore-import', 'version' => 1] + $parts);
}

function runImport(int $org, array $bundle, bool $dry = false, bool $skip = true): array
{
    return (new Importer($org, $dry, $skip))->run($bundle, 'test');
}

function person(string $ref, string $first, string $last, array $more = []): array
{
    return ['ref' => $ref, 'first_name' => $first, 'last_name' => $last] + $more;
}

// ── The bundle format ───────────────────────────────────────────────────────

test('import bundle: wrong format, wrong version and bad structure are refused with plain messages', function () {
    throws(fn() => Bundle::parse('not json'), 'not valid JSON');
    throws(fn() => Bundle::parse('{"format":"something-else","version":1}'), 'not a Care Core import bundle');
    throws(fn() => Bundle::parse('{"format":"carecore-import","version":9}'), 'version');
    throws(fn() => Bundle::parse('{"format":"carecore-import","version":1,"people":{"a":1}}'), 'must be a list');
    throws(fn() => Bundle::parse('{"format":"carecore-import","version":1,"people":["x"]}'), 'not an object');
    eq(Bundle::parse('{"format":"carecore-import","version":1}')['people'], []);
});

test('import bundle: several files merge, people with the same ref combine, and counts are right', function () {
    $a = bundleOf(['people' => [person('staff:1', 'Ann', 'One', ['email' => 'ann@example.test', 'registrations' => [['registration_type' => 'X', 'expiry_date' => '2030-01-01']]])]]);
    $b = bundleOf(['people' => [person('staff:1', 'Ann', 'One', ['phone' => '07700 900123', 'registrations' => [['registration_type' => 'Y', 'expiry_date' => '2031-01-01']]]), person('staff:2', 'Bob', 'Two')], 'teams' => [['ref' => 't:1', 'name' => 'T']]]);
    $m = Bundle::merge([$a, $b]);
    eq(count($m['people']), 2);
    eq([$m['people'][0]['email'], $m['people'][0]['phone'], count($m['people'][0]['registrations'])], ['ann@example.test', '07700 900123', 2]);
    eq(Bundle::counts($m)['registrations'], 2);
});

// ── The importer ────────────────────────────────────────────────────────────

test('import: a dry run does all the work and then keeps none of it; a real run keeps it; events stay quiet but the audit log speaks', function () {
    $org = Fx::org();
    $b = bundleOf(['teams' => [['ref' => 't:1', 'name' => 'Day Team']], 'people' => [person('staff:1', 'Ada', 'Import', ['email' => 'Ada@Example.Test', 'employments' => [['ref' => 'e:1', 'job_title' => 'Support Worker', 'start_date' => '2024-01-08', 'hours_per_week' => 30]]])],
        'team_places' => [['ref' => 'tp:1', 'team_ref' => 't:1', 'person_ref' => 'staff:1', 'role' => 'Care worker', 'primary' => true, 'start_date' => '2024-01-08']]]);
    $before = [Fx::count('persons'), Fx::count('employments'), Fx::count('teams'), Fx::count('audit_log'), Fx::count('import_refs')];
    $r = runImport($org, $b, true);
    eq([$r['mode'], $r['status'], $r['imported']], ['dry_run', 'rolled_back', false]);
    eq($r['counts']['people']['created'], 1, 'the report says what would be created');
    eq([Fx::count('persons'), Fx::count('employments'), Fx::count('teams'), Fx::count('audit_log'), Fx::count('import_refs')], $before, 'nothing is left behind');
    $r = runImport($org, $b);
    eq([$r['status'], $r['imported'], $r['errors']], ['completed', true, []]);
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 1);
    $p = DB::one('SELECT * FROM persons WHERE organisation_id = ?', [$org]);
    eq($p['email'], 'ada@example.test', 'emails are lower-cased');
    eq(Fx::count('team_assignments', 'person_id = ? AND valid_to IS NULL AND is_primary = 1', [$p['id']]), 1);
    eq(Fx::count('outbox_events', 'organisation_id = ?', [$org]), 0, 'no webhook flood for imported history');
    ok(Fx::count('audit_log', "organisation_id = ? AND actor_label = 'system:import'", [$org]) >= 3);
    eq(Fx::count('tasks', 'organisation_id = ?', [$org]), 0, 'no onboarding tasks for people who started years ago');
    eq(App\Core\Events::$muted, false, 'events are un-muted afterwards, even though the importer muted them');
    DB::run('INSERT INTO outbox_events (uuid, organisation_id, type, payload, occurred_at) SELECT ?, ?, ?, ?, NOW(3) FROM DUAL', [App\Core\Uuid::v4(), $org, 'x', '{}']);
    $q = App\Core\Events::emit($org, 'employment.started', null, []);
    eq(Fx::count('outbox_events', 'organisation_id = ? AND type = ?', [$org, 'employment.started']), 1, 'ordinary changes still raise events');
});

test('import: importing the same bundle again changes nothing (and a bundle with refs never duplicates)', function () {
    $org = Fx::org();
    $b = bundleOf(['teams' => [['ref' => 't:1', 'name' => 'Home']], 'people' => [
        person('staff:1', 'Sam', 'Staff', ['email' => 'sam@example.test', 'employments' => [['ref' => 'e:1', 'job_title' => 'Cook', 'start_date' => '2023-01-02']], 'registrations' => [['registration_type' => 'SSSC', 'expiry_date' => '2030-01-01']], 'contacts' => [['type' => 'phone', 'value' => '07700 900123']]]),
        person('people:1', 'Pat', 'Supported', ['support' => [['ref' => 's:1', 'start_date' => '2022-05-01']], 'needs' => [['category' => 'Falls', 'description' => 'Review']]])],
        'team_places' => [['ref' => 'tp:1', 'team_ref' => 't:1', 'person_ref' => 'staff:1', 'start_date' => '2023-01-02']],
        'key_workers' => [['ref' => 'k:1', 'supported_ref' => 'people:1', 'staff_ref' => 'staff:1', 'start_date' => '2022-06-01']]]);
    $first = runImport($org, $b);
    eq($first['errors'], []);
    $tables = ['persons', 'employments', 'teams', 'team_assignments', 'support_episodes', 'care_needs', 'person_contacts', 'registrations', 'key_worker_assignments', 'audit_log'];
    $snap = array_map(static fn($t) => Fx::count($t), $tables);
    $second = runImport($org, $b);
    eq(array_map(static fn($t) => Fx::count($t), $tables), $snap, 'a second run adds nothing');
    $created = 0;
    foreach ($second['counts'] as $c) {
        $created += $c['created'] ?? 0;
    }
    eq($created, 0);
    ok(($second['counts']['people']['already_imported'] ?? 0) === 2);
});

test('import: people already in Care Core are matched by email or by name and birth date, and only filled in, never overwritten', function () {
    $org = Fx::org();
    $existing = Fx::staff($org, 'Rae', 'Match', ['email' => 'rae@example.test', 'start_date' => '2020-01-06']);
    DB::update('persons', ['phone' => '01463 111111'], ['id' => $existing['person_id']]);
    $other = App\Modules\People\PersonRepo::create($org, ['first_name' => 'Lee', 'last_name' => 'Birth', 'date_of_birth' => '1980-02-03'], Fx::actor());
    $b = bundleOf(['people' => [
        person('staff:9', 'Rae', 'Different', ['email' => 'RAE@example.test', 'phone' => '07700 900999', 'date_of_birth' => '1990-01-01', 'profile' => ['address_city' => 'Elgin']]),
        person('staff:10', 'Lee', 'Birth', ['date_of_birth' => '1980-02-03', 'phone' => '07700 900123']),
        person('staff:11', 'Lee', 'Birth', ['date_of_birth' => '1999-09-09']),
    ]]);
    $r = runImport($org, $b);
    eq($r['counts']['people'], ['matched' => 2, 'created' => 1]);
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 3, 'nobody duplicated');
    $rae = DB::one('SELECT * FROM persons WHERE id = ?', [$existing['person_id']]);
    eq([$rae['first_name'], $rae['last_name'], $rae['phone'], $rae['date_of_birth']], ['Rae', 'Match', '01463 111111', '1990-01-01'], 'existing values stay; only blanks are filled');
    eq(StaffRecord::profile($existing['person_id'])['address_city'], 'Elgin');
    eq(DB::value('SELECT phone FROM persons WHERE id = ?', [$other['id']]), '07700 900123');
    eq(DB::value('SELECT target_uuid FROM import_refs WHERE organisation_id = ? AND source_ref = ?', [$org, 'staff:9']), $existing['person_uuid'], 'the ref now points at the existing person');
});

test('import: bad values are dropped with a warning, not allowed to break the record or the import', function () {
    $org = Fx::org();
    $b = bundleOf(['people' => [person('staff:1', 'Bad', 'Data', [
        'email' => 'nope', 'phone' => 'call me', 'date_of_birth' => '1990-13-45',
        'profile' => ['address_postcode' => 'ZZZZ', 'ni_number' => 'XX', 'address_city' => 'Inverness', 'bank_sort_code' => '12-34'],
        'employments' => [['ref' => 'e:1', 'job_title' => '', 'start_date' => '2024-03-01 09:30:00', 'end_date' => '2023-01-01', 'hours_per_week' => 500, 'details' => ['tupe' => ['is_tupe' => true, 'tupe_transfer_date' => 'yesterday'], 'wtd' => ['wtd_agreed' => true], 'bogus' => ['x' => 1]]]],
        'registrations' => [['registration_type' => 'SSSC', 'issue_date' => '2025-01-01', 'expiry_date' => '2024-01-01']],
    ])]]);
    $r = runImport($org, $b);
    eq($r['errors'], []);
    $text = implode(' | ', array_column($r['warnings'], 'message'));
    foreach (['email address', 'phone number', 'not a real date', 'Postcode', 'National Insurance', 'sort code', 'no job title', 'before the start date', 'Hours per week', 'TUPE transfer', 'Unknown employment details section', 'cannot expire before'] as $needle) {
        ok(stripos($text, $needle) !== false, "no warning mentioning: $needle in: $text");
    }
    $p = DB::one('SELECT * FROM persons WHERE organisation_id = ?', [$org]);
    eq([$p['email'], $p['phone'], $p['date_of_birth']], [null, null, null]);
    eq(StaffRecord::profile((int) $p['id'])['address_city'], 'Inverness', 'the good part of the profile still went in');
    $e = DB::one('SELECT * FROM employments WHERE person_id = ?', [$p['id']]);
    eq([$e['job_title'], $e['start_date'], $e['end_date'], $e['hours_per_week']], ['Employee', '2024-03-01', null, null]);
    eq(Fx::count('registrations', 'person_id = ?', [$p['id']]), 0);
    eq((int) StaffRecord::details((int) $e['id'])['wtd_agreed'], 1, 'a good section is kept when another is bad');
});

test('import: a record that cannot be imported is skipped alone; all-or-nothing mode imports nothing when there is any error', function () {
    $org = Fx::org();
    $b = bundleOf(['people' => [person('staff:1', 'Good', 'One'), ['ref' => 'staff:2', 'first_name' => 'No', 'last_name' => ''], person('staff:3', 'Good', 'Two')]]);
    $r = runImport($org, $b, false, false);
    eq([$r['imported'], $r['status'], count($r['errors'])], [false, 'rolled_back', 1]);
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 0, 'nothing imported');
    $r = runImport($org, $b, false, true);
    eq([$r['imported'], count($r['errors'])], [true, 1]);
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 2);
    ok(str_contains($r['errors'][0]['message'], 'first and last name'));
    eq(Fx::count('import_refs', 'organisation_id = ? AND source_ref = ?', [$org, 'staff:2']), 0);
    eq(Fx::count('import_runs', 'organisation_id = ?', [$org]), 2, 'every run is logged');
});

test('import: employment rules — no overlap, unique employee references, line managers resolved afterwards', function () {
    $org = Fx::org();
    $b = bundleOf(['people' => [
        person('staff:1', 'Boss', 'Person', ['employments' => [['ref' => 'e:1', 'job_title' => 'Manager', 'start_date' => '2018-01-01', 'employee_ref' => 'E1']]]),
        person('staff:2', 'Worker', 'Person', ['employments' => [
            ['ref' => 'e:2a', 'job_title' => 'Carer', 'start_date' => '2019-01-01', 'end_date' => '2020-12-31', 'employee_ref' => 'E1', 'line_manager_ref' => 'staff:1'],
            ['ref' => 'e:2b', 'job_title' => 'Carer again', 'start_date' => '2020-06-01', 'end_date' => '2021-06-01'],
            ['ref' => 'e:2c', 'job_title' => 'Senior carer', 'start_date' => '2022-01-01', 'line_manager_ref' => 'staff:1']]]),
        person('staff:3', 'Self', 'Managed', ['employments' => [['ref' => 'e:3', 'job_title' => 'X', 'start_date' => '2022-01-01', 'line_manager_ref' => 'staff:3']]]),
        person('staff:4', 'Orphan', 'Managed', ['employments' => [['ref' => 'e:4', 'job_title' => 'X', 'start_date' => '2022-01-01', 'line_manager_ref' => 'staff:404']]]),
    ]]);
    $r = runImport($org, $b);
    eq($r['counts']['employments'], ['created' => 5, 'skipped' => 1]);
    $text = implode(' | ', array_column($r['warnings'], 'message'));
    ok(str_contains($text, 'already used by someone else') && str_contains($text, 'overlaps') && str_contains($text, 'staff:404'), $text);
    $emps = DB::all('SELECT e.*, p.first_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.organisation_id = ? ORDER BY e.id', [$org]);
    $boss = DB::value("SELECT id FROM persons WHERE organisation_id = ? AND first_name = 'Boss'", [$org]);
    $worker = array_values(array_filter($emps, fn($e) => $e['first_name'] === 'Worker'));
    eq(count($worker), 2, 'the overlapping stint was skipped, the others kept');
    eq(array_column($worker, 'line_manager_person_id'), [$boss, $boss]);
    eq($worker[0]['employee_ref'], null, 'the duplicate reference was dropped');
    eq(Fx::count('employments', 'organisation_id = ? AND line_manager_person_id IS NOT NULL', [$org]), 2, 'nobody is their own manager; an unknown manager is just left out');
});

test('import: history stays history — ended employments, closed team places, closed key worker links — and nothing outlives the people in it', function () {
    $org = Fx::org();
    $b = bundleOf(['teams' => [['ref' => 't:1', 'name' => 'Parent'], ['ref' => 't:2', 'name' => 'Child', 'parent_ref' => 't:1'], ['ref' => 't:3', 'name' => 'Closed', 'active' => false], ['ref' => 't:4', 'name' => 'HR', 'staff_only' => true]],
        'people' => [
            person('staff:1', 'Gone', 'Worker', ['employments' => [['ref' => 'e:1', 'job_title' => 'Carer', 'start_date' => '2021-01-04', 'end_date' => '2024-06-30']]]),
            person('staff:2', 'Here', 'Worker', ['employments' => [['ref' => 'e:2', 'job_title' => 'Carer', 'start_date' => '2021-01-04']]]),
            person('people:1', 'Pat', 'Supported', ['support' => [['ref' => 's:1', 'start_date' => '2021-02-01']]]),
            person('people:2', 'Dee', 'Discharged', ['support' => [['ref' => 's:2', 'start_date' => '2021-02-01', 'end_date' => '2023-03-31']]])],
        'team_places' => [
            ['ref' => 'tp:1', 'team_ref' => 't:2', 'person_ref' => 'staff:1', 'start_date' => '2021-01-04', 'primary' => true],      // never closed in the old system
            ['ref' => 'tp:2', 'team_ref' => 't:2', 'person_ref' => 'staff:2', 'start_date' => '2021-01-04', 'primary' => true],
            ['ref' => 'tp:3', 'team_ref' => 't:1', 'person_ref' => 'staff:2', 'start_date' => '2019-01-01', 'end_date' => '2020-12-31'],
            ['ref' => 'tp:4', 'team_ref' => 't:4', 'person_ref' => 'people:1', 'start_date' => '2022-01-01'],                        // supported person in a staff-only team
            ['ref' => 'tp:5', 'team_ref' => 't:2', 'person_ref' => 'staff:1', 'start_date' => '2025-01-01'],                        // would start after they left
            ['ref' => 'tp:6', 'team_ref' => 't:3', 'person_ref' => 'staff:2', 'start_date' => '2022-01-01', 'end_date' => '2022-12-31']],
        'key_workers' => [
            ['ref' => 'k:1', 'supported_ref' => 'people:1', 'staff_ref' => 'staff:1', 'start_date' => '2021-03-01'],                // staff left: must be closed on their last day
            ['ref' => 'k:2', 'supported_ref' => 'people:2', 'staff_ref' => 'staff:2', 'start_date' => '2021-03-01'],                // support ended: closed on that day
            ['ref' => 'k:3', 'supported_ref' => 'people:1', 'staff_ref' => 'staff:2', 'start_date' => '2021-03-01', 'end_date' => '2022-01-01']]]);
    $r = runImport($org, $b);
    eq($r['errors'], []);
    $closed = fn($ref) => DB::value('SELECT target_uuid FROM import_refs WHERE organisation_id = ? AND source_ref = ?', [$org, $ref]);
    eq(DB::value('SELECT valid_to FROM team_assignments WHERE uuid = ?', [$closed('tp:1')]), '2024-06-30', 'closed on the leaver’s last day');
    eq(DB::value('SELECT valid_to FROM team_assignments WHERE uuid = ?', [$closed('tp:2')]), null);
    eq(DB::value('SELECT valid_to FROM team_assignments WHERE uuid = ?', [$closed('tp:3')]), '2020-12-31');
    eq($closed('tp:4'), null, 'a supported person cannot join a staff-only team');
    eq($closed('tp:5'), null, 'a place that would start after they left is skipped');
    eq(DB::value('SELECT valid_to FROM key_worker_assignments WHERE uuid = ?', [$closed('k:1')]), '2024-06-30');
    eq(DB::value('SELECT valid_to FROM key_worker_assignments WHERE uuid = ?', [$closed('k:2')]), '2023-03-31');
    eq(DB::value('SELECT valid_to FROM key_worker_assignments WHERE uuid = ?', [$closed('k:3')]), '2022-01-01');
    eq(DB::value("SELECT active FROM teams WHERE organisation_id = ? AND name = 'Closed'", [$org]), 0, 'a closed team is closed, after its history went in');
    eq(DB::value("SELECT COUNT(*) FROM team_assignments WHERE team_id = (SELECT id FROM teams WHERE organisation_id = ? AND name = 'Closed')", [$org]), 1);
    eq(DB::value("SELECT parent_team_id FROM teams WHERE organisation_id = ? AND name = 'Child'", [$org]), DB::value("SELECT id FROM teams WHERE organisation_id = ? AND name = 'Parent'", [$org]));
    eq(array_column(Reconcile::check($org), 'code'), ['no_keyworker'], 'the imported data is consistent: only the "no key worker" reminder remains');
});

test('import: one organisation’s import cannot touch another’s data or refs', function () {
    $a = Fx::org();
    $b = Fx::org();
    $bundle = bundleOf(['people' => [person('staff:1', 'Same', 'Ref', ['email' => 'same@example.test'])]]);
    runImport($a, $bundle);
    $r = runImport($b, $bundle);
    eq($r['counts']['people'], ['created' => 1], 'the same source ref and email in another organisation is a new person there');
    eq([Fx::count('persons', 'organisation_id = ?', [$a]), Fx::count('persons', 'organisation_id = ?', [$b])], [1, 1]);
    eq(Fx::count('import_refs', "source_ref = 'staff:1' AND organisation_id IN ($a, $b)"), 2);
});

test('import: photos and signatures come across only from the folder you name, are checked as real images, and never from outside it', function () {
    $org = Fx::org();
    $dir = sys_get_temp_dir() . '/cc-files-' . bin2hex(random_bytes(4));
    mkdir($dir . '/signatures', 0777, true);
    file_put_contents($dir . '/signatures/sig.png', base64_decode(PNG_1PX));
    file_put_contents($dir . '/evil.png', '<?php echo 1;');
    file_put_contents(dirname($dir) . '/outside-' . basename($dir) . '.png', base64_decode(PNG_1PX));
    $b = bundleOf(['people' => [
        person('staff:1', 'With', 'Signature', ['signature_file' => 'signatures/sig.png', 'signature_method' => 'draw', 'photo_file' => 'evil.png']),
        person('staff:2', 'Sneaky', 'Path', ['photo_file' => '../outside-' . basename($dir) . '.png'])]]);
    $none = (new Importer($org, false, true, null))->run($b, 't');
    eq($none['counts']['files'], ['not_requested' => 3], 'without a folder, files are left alone');
    DB::run('DELETE FROM import_refs WHERE organisation_id = ?', [$org]);
    DB::run('DELETE FROM persons WHERE organisation_id = ?', [$org]);
    $r = (new Importer($org, false, true, $dir))->run($b, 't');
    $p = DB::one("SELECT id FROM persons WHERE organisation_id = ? AND first_name = 'With'", [$org]);
    $prof = StaffRecord::profile((int) $p['id']);
    ok($prof['signature_path'] !== null && $prof['signature_method'] === 'draw', 'the signature came across');
    eq($prof['photo_path'], null, 'a script disguised as a photo was refused');
    $q = DB::one("SELECT id FROM persons WHERE organisation_id = ? AND first_name = 'Sneaky'", [$org]);
    eq(StaffRecord::profile((int) $q['id'])['photo_path'], null, 'a path that climbs out of the folder is not followed');
    ok(str_contains(implode(' ', array_column($r['warnings'], 'message')), 'was not found') && str_contains(implode(' ', array_column($r['warnings'], 'message')), 'not accepted'));
    App\Modules\Staff\FileStore::delete($prof['signature_path']);
    array_map('unlink', glob($dir . '/*.png') ?: []);
    @unlink($dir . '/signatures/sig.png');
    @unlink(dirname($dir) . '/outside-' . basename($dir) . '.png');
});

// ── Against the shape of the older systems ──────────────────────────────────

test('import from the older systems: export the Staff, People and Team services, merge, check, import, and the result is consistent', function () {
    $old = OldSystems::build();
    if ($old === null) {
        echo "      (skipped: this database server will not let the tests create the old-system databases)\n";
        return;
    }
    $staff = StaffService::export($old['staff'], ['org' => 1]);
    $people = PeopleService::export($old['people'], ['org' => 1]);
    $team = TeamService::export($old['team'], ['org' => 1]);
    eq(count($staff['people']), 7, 'seven staff records (one is a duplicate email); people we support held in the Staff Service are left to the People Service');
    ok(str_contains(implode(' ', $staff['_notes']), 'people we support held in the Staff Service'));
    ok(str_contains(implode(' ', $staff['_notes']), 'inactive with no end date'));
    $isla = array_values(array_filter($staff['people'], fn($p) => $p['ref'] === 'staff:2'))[0];
    eq([$isla['employments'][0]['line_manager_ref'], $isla['employments'][0]['details']['tupe']['is_tupe'], count($isla['employments'][0]['changes'])], ['staff:1', true, 3], 'line manager, TUPE and role history (two title changes and one hours change) carry over');
    $fergus = array_values(array_filter($staff['people'], fn($p) => $p['ref'] === 'staff:5'))[0];
    eq($fergus['employments'][0]['end_date'], '2024-11-30', 'an inactive staff member with no end date gets their last-updated date, and a note says so');
    $nina = array_values(array_filter($staff['people'], fn($p) => $p['ref'] === 'staff:6'))[0];
    eq($nina['employments'][0]['start_date'], '2023-08-14');
    eq(count($team['teams']), 5);
    eq(array_values(array_filter($team['teams'], fn($t) => $t['name'] === 'HR'))[0]['staff_only'], true, 'a staff-only team type becomes a staff-only team');
    eq(count($team['team_places']), 7, 'logins are not people');
    eq(array_values(array_filter($team['team_places'], fn($t) => $t['ref'] === 'teammember:1'))[0]['role'], 'Team Leader');
    $aileen = array_values(array_filter($people['people'], fn($p) => $p['ref'] === 'people:1'))[0];
    ok(str_contains($aileen['notes'], 'Pronouns: she/her') && str_contains($aileen['notes'], 'Likes the garden'));
    eq(count($people['key_workers']), 4);

    $org = Fx::org();
    $merged = Bundle::merge([Bundle::normalise($staff), Bundle::normalise($people), Bundle::normalise($team)]);
    $dry = runImport($org, $merged, true);
    eq([$dry['errors'], $dry['status'], Fx::count('persons', 'organisation_id = ?', [$org])], [[], 'rolled_back', 0]);
    $r = runImport($org, $merged);
    eq($r['errors'], []);
    eq(Fx::count('persons', 'organisation_id = ?', [$org]), 10, '7 staff records (one matched by email, so 6 people) plus 4 supported');
    $fiona = DB::one("SELECT * FROM persons WHERE organisation_id = ? AND last_name = 'Maclean'", [$org]);
    eq(StaffRecord::profile((int) $fiona['id'])['ni_number'], 'JA000001A', 'encrypted NI came across and reads back');
    ok(str_starts_with((string) DB::value('SELECT ni_number FROM person_profiles WHERE person_id = ?', [$fiona['id']]), 'enc2:'), 'and is stored encrypted');
    $isla = DB::one("SELECT * FROM persons WHERE organisation_id = ? AND last_name = 'Grant'", [$org]);
    $emp = DB::one('SELECT * FROM employments WHERE person_id = ?', [$isla['id']]);
    eq([$emp['job_title'], StaffRecord::details((int) $emp['id'])['is_tupe'] ?? null, Fx::count('employment_changes', 'employment_id = ?', [$emp['id']])], ['Deputy Manager', 1, 3]);
    eq(DB::value('SELECT p.last_name FROM employments e JOIN persons p ON p.id = e.line_manager_person_id WHERE e.id = ?', [$emp['id']]), 'Maclean');
    eq(DB::value("SELECT phone FROM persons WHERE id = ?", [$isla['id']]), '01463 000099', 'the duplicate record’s phone filled a blank on the matched person');
    $re = runImport($org, $merged);
    $created = 0;
    foreach ($re['counts'] as $c) {
        $created += $c['created'] ?? 0;
    }
    eq($created, 0, 'a second run imports nothing new');
    eq(count(array_filter(Reconcile::check($org), fn($f) => $f['severity'] === 'high')), 0, 'no high-severity inconsistencies after import');
    eq(Fx::count('outbox_events', 'organisation_id = ?', [$org]), 0);
});

test('import: structured person details (sex, NHS number, address, GP, employment) are taken, and only ever fill blanks', function () {
    $org = Fx::org();
    $details = ['sex' => 'M', 'nhs_number' => '0101011237', 'address_line1' => '1 High St', 'town' => 'Hamilton', 'area' => 'South Lanarkshire', 'postcode' => 'ML3 6AA', 'gp_practice' => 'Test Practice', 'employment_status' => 'Retired'];
    runImport($org, bundleOf(['people' => [person('p:1', 'Dee', 'Detail', ['date_of_birth' => '1950-01-01'] + $details)]]));
    $row = DB::one('SELECT * FROM persons WHERE organisation_id = ? AND last_name = ?', [$org, 'Detail']);
    eq([$row['sex'], $row['nhs_number'], $row['town'], $row['postcode'], $row['gp_practice'], $row['employment_status']], ['M', '0101011237', 'Hamilton', 'ML3 6AA', 'Test Practice', 'Retired']);
    // a second source with different values for the same person fills nothing that is already there
    runImport($org, bundleOf(['people' => [person('q:9', 'Dee', 'Detail', ['date_of_birth' => '1950-01-01', 'town' => 'Elsewhere', 'address_line2' => 'Flat 2'])]]));
    $row = DB::one('SELECT * FROM persons WHERE organisation_id = ? AND last_name = ?', [$org, 'Detail']);
    eq([$row['town'], $row['address_line2']], ['Hamilton', 'Flat 2']);
});
