<?php
declare(strict_types=1);

namespace App\Modules\Import;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;
use App\Modules\Care\CareRepo;
use App\Modules\People\PersonRepo;
use App\Modules\Staff\Compliance;
use App\Modules\Staff\FileStore;
use App\Modules\Staff\ProfileChanges;
use App\Modules\Staff\StaffRecord;
use App\Modules\Teams\AssignmentRepo;
use App\Modules\Teams\TeamRepo;

/**
 * Brings a bundle into Care Core through the same services and rules the application uses, so imported data is as
 * trustworthy as typed data. History is kept as history (ended employments, ended team places), webhooks are not flooded
 * (events are muted), and nothing is imported twice (each source ref is remembered).
 *
 * A dry run does exactly the same work inside a transaction and rolls it back, so its report is what a real import would do.
 */
final class Importer
{
    /** @var array<string,array<string,string>> entity => source ref => target uuid */
    private array $refs = [];
    /** @var array<string,array<string,int>> */
    private array $counts = [];
    /** @var list<array{ref:string,message:string}> */
    private array $warnings = [];
    /** @var list<array{ref:string,message:string}> */
    private array $errors = [];
    /** @var list<array{employment_id:int,person_id:int,manager_ref:string,ref:string}> */
    private array $pendingManagers = [];
    /** @var list<array{id:int,ref:string}> teams to close once their places are in */
    private array $inactiveTeams = [];
    private Actor $actor;
    private ?string $filesFrom;

    public function __construct(private readonly int $orgId, private readonly bool $dryRun, private readonly bool $skipErrors = false, ?string $filesFrom = null)
    {
        $this->actor = Actor::system('import');
        $this->filesFrom = $filesFrom !== null ? (realpath($filesFrom) ?: null) : null;
    }

    /**
     * @param array<string,mixed> $bundle a normalised bundle
     * @return array<string,mixed> the report
     */
    public function run(array $bundle, string $label = ''): array
    {
        $this->counts = [];
        $this->warnings = $this->errors = $this->pendingManagers = $this->inactiveTeams = [];
        $this->loadRefs();
        $pdo = DB::pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Run the importer outside a transaction: it manages its own.');
        }
        $status = 'completed';
        Events::$muted = true;
        $pdo->beginTransaction();
        try {
            $this->teams($bundle['teams']);
            foreach ($bundle['people'] as $i => $p) {
                $this->guarded('person', (string) ($p['ref'] ?? '#' . ($i + 1)), fn() => $this->person($p));
            }
            $this->lineManagers();
            foreach ($bundle['team_places'] as $i => $tp) {
                $this->guarded('team_place', (string) ($tp['ref'] ?? '#' . ($i + 1)), fn() => $this->teamPlace($tp));
            }
            foreach ($bundle['key_workers'] as $i => $kw) {
                $this->guarded('key_worker', (string) ($kw['ref'] ?? '#' . ($i + 1)), fn() => $this->keyWorker($kw));
            }
            foreach ($this->inactiveTeams as $t) {
                DB::update('teams', ['active' => 0], ['id' => $t['id']]);
            }
            if ($this->dryRun) {
                $pdo->rollBack();
                $status = 'rolled_back';
            } elseif ($this->errors && !$this->skipErrors) {
                $pdo->rollBack();
                $status = 'rolled_back';
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $status = 'failed';
            $this->errors[] = ['ref' => '(whole import)', 'message' => $e instanceof DomainException ? $e->getMessage() : 'Unexpected error: ' . $e->getMessage()];
        } finally {
            Events::$muted = false;
        }
        $report = [
            'mode' => $this->dryRun ? 'dry_run' : 'import', 'status' => $status, 'skip_errors' => $this->skipErrors,
            'imported' => !$this->dryRun && $status === 'completed', 'counts' => $this->counts, 'warnings' => $this->warnings, 'errors' => $this->errors,
        ];
        $this->logRun($report, $label);
        return $report;
    }

    // ── Teams ────────────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $teams */
    private function teams(array $teams): void
    {
        $byRef = [];
        foreach ($teams as $t) {
            $byRef[(string) ($t['ref'] ?? '')] = $t;
        }
        $done = [];
        $make = function (array $t, int $depth) use (&$make, &$done, $byRef): ?string {
            $ref = (string) ($t['ref'] ?? '');
            if ($ref !== '' && isset($this->refs['team'][$ref])) {
                $this->count('teams', 'already_imported');
                return $this->refs['team'][$ref];
            }
            if ($ref !== '' && array_key_exists($ref, $done)) {
                return $done[$ref];
            }
            if ($depth > 25) {
                $this->error($ref, 'The team hierarchy is too deep or loops back on itself.');
                return null;
            }
            $parentUuid = null;
            $parentRef = (string) ($t['parent_ref'] ?? '');
            if ($parentRef !== '') {
                if (isset($this->refs['team'][$parentRef])) {
                    $parentUuid = $this->refs['team'][$parentRef];
                } elseif (isset($byRef[$parentRef])) {
                    $parentUuid = $make($byRef[$parentRef], $depth + 1);
                }
                if ($parentUuid === null) {
                    $this->warn($ref, "Its parent team ($parentRef) is not in the import, so it was created at the top level.");
                }
            }
            $name = trim((string) ($t['name'] ?? ''));
            $existing = $name !== '' ? DB::one('SELECT id, uuid FROM teams WHERE organisation_id = ? AND name = ? AND ((parent_team_id IS NULL AND ? IS NULL) OR parent_team_id = (SELECT id FROM teams WHERE uuid = ?))', [$this->orgId, $name, $parentUuid, $parentUuid]) : null;
            try {
                if ($existing) {
                    $uuid = $existing['uuid'];
                    $this->count('teams', 'matched');
                    $id = (int) $existing['id'];
                } else {
                    $r = TeamRepo::create($this->orgId, [
                        'name' => $name, 'kind' => trim((string) ($t['kind'] ?? '')) ?: 'care', 'staff_only' => !empty($t['staff_only']),
                        'parent_team_id' => $parentUuid ? (int) DB::value('SELECT id FROM teams WHERE uuid = ?', [$parentUuid]) : null,
                    ], $this->actor);
                    $uuid = $r['uuid'];
                    $id = (int) $r['id'];
                    $this->count('teams', 'created');
                }
            } catch (DomainException $e) {
                $this->error($ref ?: $name, 'Team skipped: ' . $e->getMessage());
                return null;
            }
            if ($ref !== '') {
                $this->remember('team', $ref, $uuid);
                $done[$ref] = $uuid;
            }
            if (array_key_exists('active', $t) && !$t['active'] && !$existing) {
                $this->inactiveTeams[] = ['id' => $id, 'ref' => $ref];
            }
            return $uuid;
        };
        foreach ($teams as $t) {
            $make($t, 0);
        }
    }

    // ── People and everything that hangs off them ─────────────────────────────

    /** @param array<string,mixed> $p */
    private function person(array $p): void
    {
        $ref = (string) ($p['ref'] ?? '');
        $first = $this->s($p['first_name'] ?? null);
        $last = $this->s($p['last_name'] ?? null);
        if ($first === null || $last === null) {
            throw new DomainException('A person needs a first and last name.');
        }
        $label = $ref ?: "$first $last";
        $dob = $this->date($p['date_of_birth'] ?? null, $label, 'date of birth');
        $email = $this->s($p['email'] ?? null);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->warn($label, "The email address \"$email\" is not valid, so it was left out.");
            $email = null;
        }
        $email = $email !== null ? strtolower($email) : null;

        $known = $ref !== '' ? ($this->refs['person'][$ref] ?? null) : null;
        $person = null;
        $how = 'created';
        if ($known) {
            $person = DB::one('SELECT * FROM persons WHERE organisation_id = ? AND uuid = ?', [$this->orgId, $known]);
            $how = 'already_imported';
        }
        if (!$person && $email !== null) {
            $person = DB::one('SELECT * FROM persons WHERE organisation_id = ? AND email = ?', [$this->orgId, $email]);
            $how = $person ? 'matched' : $how;
        }
        if (!$person && $dob !== null) {
            $m = DB::all('SELECT * FROM persons WHERE organisation_id = ? AND first_name = ? AND last_name = ? AND date_of_birth = ?', [$this->orgId, $first, $last, $dob]);
            if (count($m) === 1) {
                $person = $m[0];
                $how = 'matched';
            }
        }
        $notes = $this->s($p['notes'] ?? null);
        // optional structured details (sex, NHS number, address, GP, employment): taken when present, and only ever fill blanks
        $details = [];
        foreach (PersonRepo::DETAILS as $k) {
            $v = $this->s($p[$k] ?? null);
            if ($v !== null) {
                $details[$k] = $v;
            }
        }
        if (!$person) {
            $created = PersonRepo::create($this->orgId, $details + [
                'first_name' => $first, 'last_name' => $last, 'preferred_name' => $this->s($p['preferred_name'] ?? null), 'date_of_birth' => $dob,
                'email' => $email, 'phone' => $this->phone($p['phone'] ?? null, $label), 'notes' => $notes,
            ], $this->actor);
            $person = DB::one('SELECT * FROM persons WHERE id = ?', [$created['id']]);
        } elseif ($how !== 'already_imported') {
            $this->fillBlanks($person, $details + ['preferred_name' => $this->s($p['preferred_name'] ?? null), 'date_of_birth' => $dob, 'email' => $email, 'phone' => $this->phone($p['phone'] ?? null, $label), 'notes' => $notes]);
        }
        $this->count('people', $how);
        $pending = [];
        if ($ref !== '') {
            $pending[] = ['person', $ref, $person['uuid']];
        }
        foreach ((array) ($p['aliases'] ?? []) as $alias) {
            $pending[] = ['person', (string) $alias, $person['uuid']];
        }
        // Everything below belongs to a person who is already in Care Core from an earlier import only if new refs appear.
        $pid = (int) $person['id'];
        $this->profile($p, $person, $label);
        foreach ((array) ($p['employments'] ?? []) as $e) {
            $this->employment($e, $person, $label);
        }
        $this->episodes((array) ($p['support'] ?? []), $person, $label);
        foreach ((array) ($p['needs'] ?? []) as $n) {
            $this->need($n, $person, $label);
        }
        foreach ((array) ($p['contacts'] ?? []) as $c) {
            $this->contact($c, $person, $label);
        }
        foreach (['registrations' => 'registration', 'supervisions' => 'supervision', 'appraisals' => 'appraisal', 'learning' => 'learning'] as $key => $kind) {
            foreach ((array) ($p[$key] ?? []) as $r) {
                $this->compliance($kind, $r, $person, $label);
            }
        }
        $this->files($p, $person, $label);
        foreach ($pending as [$e, $r, $u]) {
            $this->remember($e, $r, $u);
        }
        if ($how === 'created') {
            Audit::record($this->orgId, $this->actor, 'import.person', 'person', $person['uuid'], 'Imported ' . $first . ' ' . $last . ($ref !== '' ? " ($ref)" : ''), ['ref' => $ref]);
        }
    }

    /** @param array<string,mixed> $p @param array<string,mixed> $person */
    private function profile(array $p, array $person, string $label): void
    {
        $in = is_array($p['profile'] ?? null) ? $p['profile'] : [];
        if (!$in) {
            return;
        }
        $current = StaffRecord::profile((int) $person['id']);
        $clean = [];
        foreach (array_intersect_key($in, array_flip(array_keys(array_filter(StaffRecord::FIELDS, static fn($m) => $m['table'] === 'person_profiles')))) as $f => $v) {
            $v = is_scalar($v) ? trim((string) $v) : '';
            if ($v === '' || ($current[$f] ?? '') !== '' && $current[$f] !== null) {
                continue;   // nothing to add, or Care Core already has a value: never overwrite
            }
            [$ok, $err] = StaffRecord::cleanProfile([$f => $v]);
            if ($err) {
                $this->warn($label, StaffRecord::FIELDS[$f]['label'] . " \"" . (!empty(StaffRecord::FIELDS[$f]['secret']) ? '…' : $v) . '" was not accepted (' . reset($err) . ') and was left out.');
                continue;
            }
            $clean += $ok;
        }
        if ($clean) {
            StaffRecord::applyProfile($this->orgId, (int) $person['id'], $clean, $this->actor, 'imported');
            $this->count('profiles', 'filled');
        }
    }

    /** @param array<string,mixed> $e @param array<string,mixed> $person */
    private function employment(array $e, array $person, string $label): void
    {
        $ref = (string) ($e['ref'] ?? '');
        $where = $label . ($ref !== '' ? " › $ref" : ' › employment');
        if ($ref !== '' && isset($this->refs['employment'][$ref])) {
            $this->count('employments', 'already_imported');
            return;
        }
        $start = $this->date($e['start_date'] ?? null, $where, 'start date');
        if ($start === null) {
            $this->warn($where, 'The employment has no usable start date, so it was skipped.');
            $this->count('employments', 'skipped');
            return;
        }
        $end = $this->date($e['end_date'] ?? null, $where, 'end date');
        if ($end !== null && $end < $start) {
            $this->warn($where, "The end date ($end) is before the start date ($start), so the end date was left out.");
            $end = null;
        }
        $title = $this->s($e['job_title'] ?? null);
        if ($title === null) {
            $title = 'Employee';
            $this->warn($where, 'It has no job title, so "Employee" was used.');
        }
        $hours = $e['hours_per_week'] ?? null;
        if ($hours !== null && $hours !== '' && !(is_numeric($hours) && (float) $hours > 0 && (float) $hours <= 168)) {
            $this->warn($where, "Hours per week \"$hours\" is not between 0 and 168, so it was left out.");
            $hours = null;
        }
        $empRef = $this->s($e['employee_ref'] ?? null);
        if ($empRef !== null && DB::value('SELECT 1 FROM employments WHERE organisation_id = ? AND employee_ref = ?', [$this->orgId, $empRef])) {
            $this->warn($where, "The employee reference \"$empRef\" is already used by someone else, so it was left out.");
            $empRef = null;
        }
        foreach (DB::all('SELECT start_date, end_date FROM employments WHERE person_id = ?', [$person['id']]) as $x) {
            if ($this->overlaps($start, $end, $x['start_date'], $x['end_date'])) {
                $this->warn($where, "It overlaps an existing employment (from {$x['start_date']}), so it was skipped.");
                $this->count('employments', 'skipped');
                return;
            }
        }
        $uuid = Uuid::v4();
        $id = DB::insert('employments', [
            'uuid' => $uuid, 'organisation_id' => $this->orgId, 'person_id' => $person['id'], 'employee_ref' => $empRef, 'job_title' => mb_substr($title, 0, 150),
            'contract_type' => $this->s($e['contract_type'] ?? null) ?? 'permanent', 'hours_per_week' => ($hours === null || $hours === '') ? null : $hours,
            'start_date' => $start, 'end_date' => $end, 'leaving_reason' => $this->s($e['leaving_reason'] ?? null),
        ]);
        $this->count('employments', 'created');
        if ($ref !== '') {
            $this->remember('employment', $ref, $uuid);
        }
        if (!empty($e['line_manager_ref'])) {
            $this->pendingManagers[] = ['employment_id' => $id, 'person_id' => (int) $person['id'], 'manager_ref' => (string) $e['line_manager_ref'], 'ref' => $where];
        }
        $row = ['id' => $id, 'uuid' => $uuid, 'person_id' => $person['id'], 'job_title' => $title];
        foreach ((array) ($e['details'] ?? []) as $section => $vals) {
            if (!isset(StaffRecord::SECTIONS[$section]) || !is_array($vals)) {
                $this->warn($where, "Unknown employment details section \"$section\" was ignored.");
                continue;
            }
            $form = [];
            foreach ($vals as $k => $v) {
                $form[$k] = is_bool($v) ? ($v ? '1' : '') : (is_scalar($v) ? (string) $v : '');
            }
            [$clean, $errors] = StaffRecord::cleanSection($section, $form);
            if ($errors) {
                $this->warn($where, 'The ' . StaffRecord::SECTIONS[$section][0] . ' details were not accepted (' . implode(' ', $errors) . ') and were left out.');
                continue;
            }
            if (array_filter($clean, static fn($v) => $v !== null && $v !== 0 && $v !== '')) {
                StaffRecord::saveSection($this->orgId, $row, $section, $clean, $this->actor);
                $this->count('employment_details', 'created');
            }
        }
        foreach ((array) ($e['changes'] ?? []) as $c) {
            $eff = $this->date($c['effective_date'] ?? null, $where, 'change date');
            $field = (string) ($c['field'] ?? '');
            if ($eff === null || !in_array($field, ['job_title', 'contract_type', 'hours_per_week', 'line_manager', 'team'], true)) {
                continue;
            }
            DB::insert('employment_changes', ['employment_id' => $id, 'effective_date' => $eff, 'field' => $field, 'from_value' => isset($c['from']) ? mb_substr((string) $c['from'], 0, 190) : null, 'to_value' => isset($c['to']) ? mb_substr((string) $c['to'], 0, 190) : null]);
            $this->count('employment_changes', 'created');
        }
    }

    /** @param list<array<string,mixed>> $episodes @param array<string,mixed> $person */
    private function episodes(array $episodes, array $person, string $label): void
    {
        usort($episodes, static fn($a, $b) => strcmp((string) ($a['start_date'] ?? ''), (string) ($b['start_date'] ?? '')));
        foreach ($episodes as $s) {
            $ref = (string) ($s['ref'] ?? '');
            $where = $label . ($ref !== '' ? " › $ref" : ' › support');
            if ($ref !== '' && isset($this->refs['episode'][$ref])) {
                $this->count('support_episodes', 'already_imported');
                continue;
            }
            $start = $this->date($s['start_date'] ?? null, $where, 'support start date');
            $end = $this->date($s['end_date'] ?? null, $where, 'support end date');
            if ($start === null) {
                $this->warn($where, 'The support episode has no usable start date, so it was skipped.');
                $this->count('support_episodes', 'skipped');
                continue;
            }
            if ($end !== null && $end < $start) {
                $this->warn($where, 'The support end date is before its start, so the end date was left out.');
                $end = null;
            }
            $nhs = $this->s($s['nhs_number'] ?? null);
            if ($nhs !== null && Check::nhsNumber($nhs) !== null) {
                $this->warn($where, "The NHS number \"$nhs\" is not valid, so it was left out.");
                $nhs = null;
            }
            foreach (DB::all('SELECT start_date, end_date FROM support_episodes WHERE person_id = ?', [$person['id']]) as $x) {
                if ($this->overlaps($start, $end, $x['start_date'], $x['end_date'])) {
                    $this->warn($where, "It overlaps an existing support episode (from {$x['start_date']}), so it was skipped.");
                    $this->count('support_episodes', 'skipped');
                    continue 2;
                }
            }
            try {
                $uuid = CareRepo::startSupport($this->orgId, (int) $person['id'], ['start_date' => $start, 'reference' => $this->s($s['reference'] ?? null), 'nhs_number' => $nhs, 'local_authority_ref' => $this->s($s['local_authority_ref'] ?? null)], $this->actor);
            } catch (DomainException $e) {
                $this->warn($where, 'Support was skipped: ' . $e->getMessage());
                $this->count('support_episodes', 'skipped');
                continue;
            }
            if ($end !== null) {
                DB::update('support_episodes', ['end_date' => $end], ['uuid' => $uuid]);   // history: no cascade onto teams or key workers
            }
            $this->count('support_episodes', 'created');
            if ($ref !== '') {
                $this->remember('episode', $ref, $uuid);
            }
        }
    }

    /** @param array<string,mixed> $n @param array<string,mixed> $person */
    private function need(array $n, array $person, string $label): void
    {
        $where = $label . ' › care need';
        $cat = $this->s($n['category'] ?? null);
        $desc = $this->s($n['description'] ?? null);
        if ($cat === null || $desc === null) {
            $this->warn($where, 'A care need without a category and description was skipped.');
            $this->count('needs', 'skipped');
            return;
        }
        if (DB::value('SELECT 1 FROM care_needs WHERE person_id = ? AND category = ? AND description = ?', [$person['id'], $cat, $desc])) {
            $this->count('needs', 'duplicate');
            return;
        }
        $sev = $this->s($n['severity'] ?? null);
        if ($sev !== null && !in_array($sev, ['low', 'medium', 'high'], true)) {
            $sev = null;
        }
        try {
            $uuid = CareRepo::addNeed($this->orgId, (int) $person['id'], ['category' => $cat, 'description' => $desc, 'severity' => $sev, 'review_date' => $this->date($n['review_date'] ?? null, $where, 'review date')], $this->actor);
        } catch (DomainException $e) {
            $this->warn($where, 'A care need was skipped: ' . $e->getMessage());
            $this->count('needs', 'skipped');
            return;
        }
        if ($ended = $this->date($n['ended_on'] ?? null, $where, 'ended date')) {
            DB::update('care_needs', ['ended_on' => $ended], ['uuid' => $uuid]);
        }
        $this->count('needs', 'created');
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $person */
    private function contact(array $c, array $person, string $label): void
    {
        $where = $label . ' › contact';
        $type = $this->s($c['type'] ?? null) ?? 'other';
        if (!in_array($type, ['phone', 'email', 'address', 'next_of_kin', 'legal_guardian', 'social_worker', 'gp', 'other'], true)) {
            $type = 'other';
        }
        $value = $this->s($c['value'] ?? null);
        if ($value === null) {
            $this->count('contacts', 'skipped');
            return;
        }
        if (DB::value('SELECT 1 FROM person_contacts WHERE person_id = ? AND contact_type = ? AND value = ?', [$person['id'], $type, $value])) {
            $this->count('contacts', 'duplicate');
            return;
        }
        try {
            CareRepo::addContact($this->orgId, (int) $person['id'], ['contact_type' => $type, 'value' => $value, 'label' => $this->s($c['label'] ?? null), 'is_primary' => !empty($c['primary'])], $this->actor);
            $this->count('contacts', 'created');
        } catch (DomainException $e) {
            $this->warn($where, "The $type contact \"$value\" was skipped: " . $e->getMessage());
            $this->count('contacts', 'skipped');
        }
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $person */
    private function compliance(string $kind, array $r, array $person, string $label): void
    {
        $where = $label . " › $kind";
        $table = Compliance::KINDS[$kind]['table'];
        $form = [];
        foreach (Compliance::KINDS[$kind]['fields'] as $f => [$type]) {
            $v = $r[$f] ?? null;
            if ($type === 'person') {
                $ref = $r[preg_replace('/_person_id$/', '_ref', $f)] ?? $r[$f . '_ref'] ?? null;
                $v = $ref !== null && isset($this->refs['person'][(string) $ref]) ? $this->refs['person'][(string) $ref] : null;
            } elseif ($type === 'date') {
                $v = $this->date($v, $where, $f);
            } elseif (str_starts_with($type, 'enum:') && $v !== null && !in_array($v, explode(',', substr($type, 5)), true)) {
                $v = in_array('other', explode(',', substr($type, 5)), true) ? 'other' : null;
            }
            $form[$f] = is_bool($v) ? ($v ? '1' : '') : ($v === null ? '' : (string) $v);
        }
        [$clean, $errors] = Compliance::clean($this->orgId, $kind, $form);
        if ($errors) {
            $this->warn($where, 'A ' . $kind . ' record was skipped: ' . implode(' ', $errors));
            $this->count($table, 'skipped');
            return;
        }
        $key = ['registration' => ['registration_type', 'expiry_date'], 'supervision' => ['supervision_date', 'supervision_type'], 'appraisal' => ['appraisal_date', 'appraisal_type'], 'learning' => ['title', 'completion_date']][$kind];
        if (DB::value("SELECT 1 FROM $table WHERE person_id = ? AND {$key[0]} <=> ? AND {$key[1]} <=> ?", [$person['id'], $clean[$key[0]] ?? null, $clean[$key[1]] ?? null])) {
            $this->count($table, 'duplicate');
            return;
        }
        Compliance::add($this->orgId, ['id' => $person['id'], 'uuid' => $person['uuid']], $kind, $clean, $this->actor);
        $this->count($table, 'created');
    }

    /** @param array<string,mixed> $p @param array<string,mixed> $person */
    private function files(array $p, array $person, string $label): void
    {
        foreach (['photo_file' => ['photo', 'photo_path', null], 'signature_file' => ['signature', 'signature_path', $p['signature_method'] ?? 'upload']] as $key => [$kind, $field, $method]) {
            $rel = $this->s($p[$key] ?? null);
            if ($rel === null) {
                continue;
            }
            if ($this->filesFrom === null) {
                $this->count('files', 'not_requested');
                continue;
            }
            $file = $this->findFile($rel);
            if ($file === null) {
                $this->warn($label, "The $kind file \"$rel\" was not found under the files folder, so it was left out.");
                $this->count('files', 'skipped');
                continue;
            }
            if (StaffRecord::profile((int) $person['id'])[$field] ?? null) {
                $this->count('files', 'skipped');
                continue;   // Care Core already has one
            }
            try {
                $path = FileStore::saveBytes($this->orgId, $kind, (string) file_get_contents($file), $kind === 'photo' ? 3 * 1048576 : 1048576);
                ProfileChanges::setFile($this->orgId, (int) $person['id'], $field, $path, $kind === 'signature' ? (in_array($method, ['draw', 'digital'], true) ? 'draw' : 'upload') : null, $this->actor);
                $this->count('files', 'created');
            } catch (DomainException $e) {
                $this->warn($label, "The $kind file \"$rel\" was not accepted (" . $e->getMessage() . ') and was left out.');
                $this->count('files', 'skipped');
            }
        }
    }

    private function findFile(string $rel): ?string
    {
        foreach ([$rel, basename($rel), ltrim(preg_replace('#^(\./|uploads/)#', '', $rel) ?? $rel, '/')] as $try) {
            $path = realpath($this->filesFrom . '/' . $try);
            if ($path !== false && is_file($path) && str_starts_with($path, $this->filesFrom . DIRECTORY_SEPARATOR)) {
                return $path;   // never outside the folder the administrator named
            }
        }
        return null;
    }

    // ── Second pass ──────────────────────────────────────────────────────────

    private function lineManagers(): void
    {
        foreach ($this->pendingManagers as $m) {
            $uuid = $this->refs['person'][$m['manager_ref']] ?? null;
            $mgr = $uuid ? DB::one('SELECT id FROM persons WHERE uuid = ?', [$uuid]) : null;
            if (!$mgr) {
                $this->warn($m['ref'], "The line manager ({$m['manager_ref']}) is not in the import or in Care Core, so no line manager was set.");
                continue;
            }
            if ((int) $mgr['id'] === $m['person_id']) {
                continue;
            }
            DB::update('employments', ['line_manager_person_id' => $mgr['id']], ['id' => $m['employment_id']]);
            $this->count('line_managers', 'set');
        }
    }

    /** @param array<string,mixed> $tp */
    private function teamPlace(array $tp): void
    {
        $ref = (string) ($tp['ref'] ?? '');
        if ($ref !== '' && isset($this->refs['place'][$ref])) {
            $this->count('team_places', 'already_imported');
            return;
        }
        $teamUuid = $this->refs['team'][(string) ($tp['team_ref'] ?? '')] ?? null;
        $personUuid = $this->refs['person'][(string) ($tp['person_ref'] ?? '')] ?? null;
        $name = $ref ?: ($tp['person_ref'] ?? '?') . ' in ' . ($tp['team_ref'] ?? '?');
        $team = $teamUuid ? DB::one('SELECT id, active FROM teams WHERE uuid = ?', [$teamUuid]) : null;
        $person = $personUuid ? DB::one('SELECT id, uuid FROM persons WHERE uuid = ?', [$personUuid]) : null;
        if (!$team || !$person) {
            $this->warn($name, 'Skipped: ' . (!$team ? 'the team (' . ($tp['team_ref'] ?? '?') . ') is not in the import. ' : '') . (!$person ? 'the person (' . ($tp['person_ref'] ?? '?') . ') is not in the import.' : ''));
            $this->count('team_places', 'skipped');
            return;
        }
        $from = $this->date($tp['start_date'] ?? null, $name, 'start date');
        $to = $this->date($tp['end_date'] ?? null, $name, 'end date');
        if ($from === null) {
            $this->warn($name, 'Skipped: the team place has no usable start date.');
            $this->count('team_places', 'skipped');
            return;
        }
        if ($to !== null && $to < $from) {
            $this->warn($name, 'The end date is before the start date, so the end date was left out.');
            $to = null;
        }
        $to = $this->capped($to, $from, [(int) $person['id']], $name, 'team place');
        if ($to === false) {
            $this->count('team_places', 'skipped');
            return;
        }
        try {
            AssignmentRepo::assign($this->orgId, (int) $team['id'], (int) $person['id'], $this->s($tp['role'] ?? null), $to === null && !empty($tp['primary']), $from, $this->actor, 'import');
            if ($to !== null) {
                AssignmentRepo::end($this->orgId, (int) $team['id'], (int) $person['id'], $to, $this->actor);
            }
        } catch (DomainException $e) {
            $this->warn($name, 'Skipped: ' . $e->getMessage());
            $this->count('team_places', 'skipped');
            return;
        }
        $this->count('team_places', 'created');
        if ($ref !== '') {
            $uuid = (string) DB::value('SELECT uuid FROM team_assignments WHERE team_id = ? AND person_id = ? ORDER BY id DESC LIMIT 1', [$team['id'], $person['id']]);
            $this->remember('place', $ref, $uuid);
        }
    }

    /** @param array<string,mixed> $kw */
    private function keyWorker(array $kw): void
    {
        $ref = (string) ($kw['ref'] ?? '');
        if ($ref !== '' && isset($this->refs['keyworker'][$ref])) {
            $this->count('key_workers', 'already_imported');
            return;
        }
        $supUuid = $this->refs['person'][(string) ($kw['supported_ref'] ?? '')] ?? null;
        $staffUuid = $this->refs['person'][(string) ($kw['staff_ref'] ?? '')] ?? null;
        $name = $ref ?: ($kw['staff_ref'] ?? '?') . ' → ' . ($kw['supported_ref'] ?? '?');
        $sup = $supUuid ? DB::one('SELECT id FROM persons WHERE uuid = ?', [$supUuid]) : null;
        $staff = $staffUuid ? DB::one('SELECT id FROM persons WHERE uuid = ?', [$staffUuid]) : null;
        if (!$sup || !$staff) {
            $this->warn($name, 'Skipped: ' . (!$sup ? 'the person supported (' . ($kw['supported_ref'] ?? '?') . ') is not in the import. ' : '') . (!$staff ? 'the key worker (' . ($kw['staff_ref'] ?? '?') . ') is not in the import.' : ''));
            $this->count('key_workers', 'skipped');
            return;
        }
        $from = $this->date($kw['start_date'] ?? null, $name, 'start date') ?? Clock::today();
        $to = $this->date($kw['end_date'] ?? null, $name, 'end date');
        $to = $this->capped($to, $from, [(int) $sup['id'], (int) $staff['id']], $name, 'key worker link');
        if ($to === false) {
            $this->count('key_workers', 'skipped');
            return;
        }
        try {
            $id = CareRepo::assignKeyWorker($this->orgId, (int) $sup['id'], (int) $staff['id'], $this->s($kw['role_label'] ?? null), $from, $this->actor);
            if ($to !== null && $to >= $from) {
                CareRepo::endKeyWorker($this->orgId, $id, $to, $this->actor);
            }
        } catch (DomainException $e) {
            $this->warn($name, 'Skipped: ' . $e->getMessage());
            $this->count('key_workers', 'skipped');
            return;
        }
        $this->count('key_workers', 'created');
        if ($ref !== '') {
            $this->remember('keyworker', $ref, (string) DB::value('SELECT uuid FROM key_worker_assignments WHERE id = ?', [$id]));
        }
    }

    /**
     * A relationship cannot outlive the people in it. If someone has left (or support has ended) and the source never closed
     * the relationship, close it on their last day, and say so. Returns the end date to use, null for "still open", or false
     * when the relationship would start after the person had gone.
     *
     * @param list<int> $personIds
     * @return string|false|null
     */
    private function capped(?string $to, string $from, array $personIds, string $where, string $what): string|false|null
    {
        $today = Clock::today();
        $cap = null;
        foreach ($personIds as $pid) {
            $open = DB::value('SELECT 1 FROM employments WHERE person_id = ? AND (end_date IS NULL OR end_date >= ?) UNION ALL SELECT 1 FROM support_episodes WHERE person_id = ? AND (end_date IS NULL OR end_date >= ?) LIMIT 1', [$pid, $today, $pid, $today]);
            if ($open) {
                continue;
            }
            $end = DB::value('SELECT GREATEST(COALESCE((SELECT MAX(end_date) FROM employments WHERE person_id = ?), \'0000-00-00\'), COALESCE((SELECT MAX(end_date) FROM support_episodes WHERE person_id = ?), \'0000-00-00\'))', [$pid, $pid]);
            if ($end && $end !== '0000-00-00') {
                $cap = $cap === null || $end < $cap ? $end : $cap;
            }
        }
        if ($cap === null || ($to !== null && $to <= $cap)) {
            return $to;
        }
        if ($cap < $from) {
            $this->warn($where, "Skipped: the $what would start on $from, after the person left or support ended on $cap.");
            return false;
        }
        $this->warn($where, "The $what was not closed in the old system, but the person left or support ended on $cap, so it was ended then.");
        return $cap;
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    /** Run one record in a savepoint, so a problem skips that record and nothing else. */
    private function guarded(string $entity, string $ref, callable $work): void
    {
        $pdo = DB::pdo();
        $pdo->exec('SAVEPOINT rec');
        $before = $this->refs;
        $countsBefore = $this->counts;
        $warnN = count($this->warnings);
        try {
            $work();
            $pdo->exec('RELEASE SAVEPOINT rec');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT rec');
            $pdo->exec('RELEASE SAVEPOINT rec');
            $this->refs = $before;   // forget anything remembered for the rolled-back record
            $this->pendingManagers = array_values(array_filter($this->pendingManagers, static fn($m) => !str_starts_with($m['ref'], $ref)));
            $this->warnings = array_slice($this->warnings, 0, $warnN);
            $this->counts = $countsBefore;
            $this->count($entity === 'person' ? 'people' : $entity . 's', 'failed');
            $this->error($ref, ($e instanceof DomainException ? '' : 'Unexpected problem: ') . $e->getMessage() . ' The record was skipped.');
        }
    }

    private function loadRefs(): void
    {
        $this->refs = [];
        foreach (DB::all('SELECT entity, source_ref, target_uuid FROM import_refs WHERE organisation_id = ?', [$this->orgId]) as $r) {
            $this->refs[$r['entity']][$r['source_ref']] = $r['target_uuid'];
        }
    }

    private function remember(string $entity, string $ref, string $uuid): void
    {
        $this->refs[$entity][$ref] = $uuid;
        DB::run('INSERT IGNORE INTO import_refs (organisation_id, entity, source_ref, target_uuid) VALUES (?, ?, ?, ?)', [$this->orgId, $entity, $ref, $uuid]);
    }

    private function logRun(array $report, string $label): void
    {
        DB::insert('import_runs', [
            'uuid' => Uuid::v4(), 'organisation_id' => $this->orgId, 'label' => mb_substr($label, 0, 200) ?: null, 'mode' => $report['mode'], 'status' => $report['status'],
            'skip_errors' => $this->skipErrors ? 1 : 0, 'summary' => json_encode(['counts' => $report['counts'], 'warnings' => count($report['warnings']), 'errors' => count($report['errors'])]), 'actor_label' => 'system:import',
        ]);
    }

    private function count(string $entity, string $what): void
    {
        $this->counts[$entity][$what] = ($this->counts[$entity][$what] ?? 0) + 1;
    }

    private function warn(string $ref, string $message): void
    {
        $this->warnings[] = ['ref' => $ref, 'message' => $message];
    }

    private function error(string $ref, string $message): void
    {
        $this->errors[] = ['ref' => $ref, 'message' => $message];
    }

    private function s(mixed $v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function date(mixed $v, string $where, string $what): ?string
    {
        $s = $this->s($v);
        if ($s === null || str_starts_with($s, '0000-00-00')) {
            return null;
        }
        $d = substr($s, 0, 10);
        if (!Clock::valid($d)) {
            $this->warn($where, "The $what \"$s\" is not a real date, so it was left out.");
            return null;
        }
        return $d;
    }

    private function phone(mixed $v, string $where): ?string
    {
        $s = $this->s($v);
        if ($s !== null && !preg_match('/^[0-9+()\s-]{7,25}$/', $s)) {
            $this->warn($where, "The phone number \"$s\" does not look right, so it was left out.");
            return null;
        }
        return $s;
    }

    /** Periods overlap when each starts on or before the other ends (open-ended = no end). */
    private function overlaps(?string $s1, ?string $e1, ?string $s2, ?string $e2): bool
    {
        return ($e1 === null || $s2 <= $e1) && ($e2 === null || $s1 <= $e2);
    }

    /** Fill empty fields of an existing person; never overwrite what is there. @param array<string,mixed> $person @param array<string,?string> $values */
    private function fillBlanks(array $person, array $values): void
    {
        $set = [];
        foreach ($values as $k => $v) {
            if ($v !== null && ($person[$k] === null || $person[$k] === '')) {
                $set[$k] = $v;
            }
        }
        if ($set) {
            DB::update('persons', $set, ['id' => $person['id']]);
        }
    }
}
