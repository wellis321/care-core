<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Created;
use App\Api\Ctx;
use App\Api\Page;
use App\Api\Present;
use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database as DB;
use App\Modules\Staff\Lifecycle;
use App\Modules\Staff\StaffRecord;

final class StaffApi extends BaseApi
{
    public const TAG = 'Staff';

    private const JOIN = [
        'person_id'      => ['type' => 'uuid', 'desc' => 'An existing person (a rehire). Leave out to create a new person from the fields below.'],
        'first_name'     => ['type' => 'string', 'max' => 100, 'desc' => 'Required for a new person.'],
        'last_name'      => ['type' => 'string', 'max' => 100, 'desc' => 'Required for a new person.'],
        'preferred_name' => ['type' => 'string', 'max' => 100, 'nullable' => true],
        'date_of_birth'  => ['type' => 'date', 'nullable' => true],
        'email'          => ['type' => 'string', 'max' => 190, 'nullable' => true],
        'phone'          => ['type' => 'string', 'max' => 40, 'nullable' => true],
        'job_title'      => ['type' => 'string', 'required' => true, 'max' => 150],
        'start_date'     => ['type' => 'date', 'required' => true, 'desc' => 'May be in the future.'],
        'contract_type'  => ['type' => 'string', 'max' => 40, 'nullable' => true, 'desc' => 'For example permanent, fixed_term, bank. Default permanent.'],
        'hours_per_week' => ['type' => 'number', 'min' => 0.01, 'max' => 168, 'nullable' => true],
        'employee_ref'   => ['type' => 'string', 'max' => 50, 'nullable' => true, 'desc' => 'Unique within the organisation.'],
        'line_manager_id' => ['type' => 'uuid', 'nullable' => true, 'desc' => 'A person who is current staff.'],
        'team_id'        => ['type' => 'uuid', 'nullable' => true, 'desc' => 'Their primary team from the start date.'],
        'team_role'      => ['type' => 'string', 'max' => 80, 'nullable' => true],
    ];

    private const MOVE = [
        'effective_date'  => ['type' => 'date', 'desc' => 'Today or earlier. Default today.'],
        'job_title'       => ['type' => 'string', 'max' => 150],
        'contract_type'   => ['type' => 'string', 'max' => 40],
        'hours_per_week'  => ['type' => 'number', 'min' => 0.01, 'max' => 168],
        'line_manager_id' => ['type' => 'uuid'],
        'team_id'         => ['type' => 'uuid', 'desc' => 'Their new primary team.'],
        'team_role'       => ['type' => 'string', 'max' => 80, 'nullable' => true],
    ];

    private const END = [
        'last_day' => ['type' => 'date', 'required' => true, 'desc' => 'Their last day of work. Access ends the day after.'],
        'reason'   => ['type' => 'string', 'max' => 80, 'nullable' => true],
    ];

    private const PROFILE = [
        'address_line1' => ['type' => 'string', 'max' => 190, 'nullable' => true], 'address_line2' => ['type' => 'string', 'max' => 190, 'nullable' => true],
        'address_city' => ['type' => 'string', 'max' => 190, 'nullable' => true], 'address_county' => ['type' => 'string', 'max' => 190, 'nullable' => true],
        'address_postcode' => ['type' => 'string', 'max' => 12, 'nullable' => true, 'desc' => 'A UK postcode.'], 'address_country' => ['type' => 'string', 'max' => 190, 'nullable' => true],
        'emergency_contact_name' => ['type' => 'string', 'max' => 190, 'nullable' => true], 'emergency_contact_phone' => ['type' => 'string', 'max' => 40, 'nullable' => true],
        'ni_number' => ['type' => 'string', 'max' => 20, 'nullable' => true, 'desc' => 'Needs the staff.sensitive scope.'],
        'bank_account_name' => ['type' => 'string', 'max' => 190, 'nullable' => true, 'desc' => 'Needs the staff.sensitive scope.'],
        'bank_sort_code' => ['type' => 'string', 'max' => 12, 'nullable' => true, 'desc' => 'Needs the staff.sensitive scope.'],
        'bank_account_number' => ['type' => 'string', 'max' => 12, 'nullable' => true, 'desc' => 'Needs the staff.sensitive scope.'],
    ];
    private const SENSITIVE = ['ni_number', 'bank_account_name', 'bank_sort_code', 'bank_account_number'];

    public static function routes(): array
    {
        $sections = implode(', ', array_keys(StaffRecord::SECTIONS));
        return [
            self::route('GET', '/employments', 'staff.read', 'index', 'List employments', self::paging() + ['status' => ['string', 'current, upcoming or ended.'], 'person_id' => ['uuid', 'Only this person’s employments.']], [], 'list:Employment'),
            self::route('POST', '/employments', 'staff.write', 'join', 'Start someone (joiner)', [], self::JOIN, 'Employment', 201, 'Runs the joiner process in one transaction: the person (or a rehire), the employment, an optional team place, the new-starter checklist, the audit entry and the events. Logins are not created by the API.'),
            self::route('GET', '/employments/{id}', 'staff.read', 'show', 'Get an employment', [], [], 'Employment'),
            self::route('GET', '/people/{id}/employments', 'staff.read', 'forPerson', 'List a person’s employments', [], [], 'list:Employment', 200, 'Newest first. A rehire is a second employment.'),
            self::route('GET', '/employments/{id}/changes', 'staff.read', 'changes', 'History of changes to an employment', [], [], 'list:EmploymentChange'),
            self::route('POST', '/employments/{id}/changes', 'staff.write', 'move', 'Change role, hours, line manager or team (mover)', [], self::MOVE, 'Employment', 200, 'Runs the mover process. The effective date cannot be in the future. A team change ends the old primary team the day before and starts the new one on the effective date.'),
            self::route('GET', '/employments/{id}/leave-preview', 'staff.read', 'leavePreview', 'Preview what ending an employment will do', ['last_day' => ['date', 'The proposed last day. Required.']], [], 'LeavePreview', 200, 'Changes nothing.'),
            self::route('POST', '/employments/{id}/end', 'staff.write', 'end', 'Record someone leaving (leaver)', [], self::END, 'LeaveResult', 200, 'Ends the employment, team places and key worker links, creates reassign and offboarding tasks, and stops the login after the last day, all in one transaction.'),
            self::route('GET', '/employments/{id}/details', 'staff.read', 'details', 'Get employment terms', [], [], 'EmploymentDetails', 200, "Sections: $sections."),
            self::route('PATCH', '/employments/{id}/details/{section}', 'staff.write', 'saveDetails', 'Update one section of employment terms', [], [], 'EmploymentDetails', 200, "section is one of: $sections. Send only the fields to change; see the schema for each section."),
            self::route('GET', '/people/{id}/profile', 'staff.read', 'profile', 'Get a staff member’s personal profile', [], [], 'Profile', 200, 'National Insurance and bank fields are included only for keys with the staff.sensitive scope.'),
            self::route('PATCH', '/people/{id}/profile', 'staff.write', 'saveProfile', 'Update a staff member’s personal profile', [], self::PROFILE, 'Profile', 200, 'Changing National Insurance or bank fields needs the staff.sensitive scope. Values are checked and encrypted at rest.'),
            self::route('GET', '/profile-changes', 'staff.read', 'profileChanges', 'List self-service change requests', self::paging() + ['status' => ['string', 'pending, approved, rejected or withdrawn.'], 'person_id' => ['uuid', 'Only this person’s requests.']], [], 'list:ProfileChange', 200, 'Read-only. Values of sensitive fields are omitted unless the key has the staff.sensitive scope.'),
        ];
    }

    public function index(Ctx $c): array
    {
        $today = Clock::today();
        $where = ' WHERE e.organisation_id = ?';
        $params = [$c->org()];
        if ($s = $c->q('status')) {
            $where .= match ($s) {
                'current' => " AND e.start_date <= '$today' AND (e.end_date IS NULL OR e.end_date >= '$today')",
                'upcoming' => " AND e.start_date > '$today'",
                'ended' => " AND e.end_date < '$today'",
                default => throw ApiException::badRequest('status must be current, upcoming or ended.'),
            };
        }
        if ($pid = $c->q('person_id')) {
            $where .= ' AND p.id = ?';
            $params[] = (int) $c->person($pid)['id'];
        }
        return Page::run($c, self::select() . $where, $params, 'e.id', static fn($r) => Present::employment($r, $today));
    }

    public function show(Ctx $c): array
    {
        $e = $c->employment();
        return ['data' => self::one((int) $e['id'])];
    }

    public function forPerson(Ctx $c): array
    {
        $p = $c->person();
        $rows = DB::all(self::select() . ' WHERE e.person_id = ? ORDER BY e.start_date DESC, e.id DESC', [$p['id']]);
        return ['data' => array_map(static fn($r) => Present::employment($r), $rows)];
    }

    public function changes(Ctx $c): array
    {
        $e = $c->employment();
        return ['data' => array_map([Present::class, 'change'], Lifecycle::changes((int) $e['id']))];
    }

    public function join(Ctx $c): Created
    {
        $in = $c->input(self::JOIN);
        $d = array_diff_key($in, array_flip(['person_id', 'line_manager_id', 'team_id']));
        if (!empty($in['person_id'])) {
            $d['person_uuid'] = strtolower($in['person_id']);
        }
        if (!empty($in['line_manager_id'])) {
            $d['line_manager_person_id'] = self::personId($c, $in['line_manager_id'], 'line_manager_id');
        }
        if (!empty($in['team_id'])) {
            $d['team_id'] = (int) self::lookup($c, 'teams', $in['team_id'], 'team_id')['id'];
        }
        $d['create_login'] = false;
        $r = Lifecycle::join($c->org(), $d, $c->actor());
        $emp = self::one($r['employment_id']);
        $emp['rehire'] = $r['rehire'];
        $emp['tasks_created'] = $r['tasks'];
        return new Created($emp, '/employments/' . $r['employment_uuid']);
    }

    public function move(Ctx $c): array
    {
        $e = $c->employment();
        $in = $c->input(self::MOVE);
        $changes = [];
        foreach (['job_title', 'contract_type', 'hours_per_week', 'team_role'] as $k) {
            if (array_key_exists($k, $in)) {
                $changes[$k] = $in[$k];
            }
        }
        if (isset($in['line_manager_id'])) {
            $changes['line_manager_person_id'] = self::personId($c, $in['line_manager_id'], 'line_manager_id');
        }
        if (isset($in['team_id'])) {
            $changes['new_team_id'] = (int) self::lookup($c, 'teams', $in['team_id'], 'team_id')['id'];
        }
        if (isset($changes['team_role'])) {
            $changes['new_team_role'] = $changes['team_role'];
            unset($changes['team_role']);
        }
        Lifecycle::move($c->org(), (int) $e['id'], $changes, $in['effective_date'] ?? Clock::today(), $c->actor());
        return ['data' => self::one((int) $e['id'])];
    }

    public function leavePreview(Ctx $c): array
    {
        $e = $c->employment();
        $day = $c->qDate('last_day') ?? throw ApiException::badRequest('last_day is required (YYYY-MM-DD).');
        $p = Lifecycle::previewLeave($c->org(), (int) $e['id'], $day);
        return ['data' => ['last_day' => $day, 'team_places_that_will_end' => $p['teams'], 'people_who_lose_this_key_worker' => count($p['keyworkers']), 'people_who_lose_this_line_manager' => count($p['reports']), 'logins_that_will_stop' => count($p['logins']), 'offboarding_tasks_that_will_be_created' => $p['tasks']]];
    }

    public function end(Ctx $c): array
    {
        $e = $c->employment();
        $in = $c->input(self::END);
        $r = Lifecycle::leave($c->org(), (int) $e['id'], $in['last_day'], (string) ($in['reason'] ?? ''), $c->actor());
        return ['data' => ['employment' => self::one((int) $e['id']), 'team_places_ended' => $r['teams'], 'key_worker_links_ended' => $r['keyworkers'], 'reassign_tasks_for_reports' => $r['reports'], 'logins_set_to_end' => $r['logins'], 'offboarding_tasks_created' => $r['tasks']]];
    }

    public function details(Ctx $c): array
    {
        $e = $c->employment();
        return ['data' => self::sections((int) $e['id'])];
    }

    public function saveDetails(Ctx $c): array
    {
        $e = $c->employment();
        $section = $c->params['section'];
        if (!isset(StaffRecord::SECTIONS[$section])) {
            throw ApiException::notFound('That section');
        }
        $spec = [];
        foreach (StaffRecord::SECTIONS[$section][1] as $f => [$type, $label]) {
            $spec[$f] = ['type' => match ($type) { 'bool' => 'bool', 'date' => 'date', 'num' => 'number', default => 'string' }, 'nullable' => true, 'desc' => $label];
        }
        $in = $c->input($spec, true);
        $current = StaffRecord::details((int) $e['id']);
        $form = [];
        foreach ($spec as $f => $s) {
            $v = array_key_exists($f, $in) ? $in[$f] : ($current[$f] ?? null);
            $form[$f] = is_bool($v) ? ($v ? '1' : '') : ($v === null ? '' : (string) $v);
            if (!array_key_exists($f, $in) && $s['type'] === 'bool') {
                $form[$f] = !empty($current[$f]) ? '1' : '';
            }
        }
        [$clean, $errors] = StaffRecord::cleanSection($section, $form);
        if ($errors) {
            throw ApiException::invalid('The values were not accepted.', array_map(static fn($f, $m) => ['field' => $f, 'message' => $m], array_keys($errors), $errors));
        }
        StaffRecord::saveSection($c->org(), $e, $section, $clean, $c->actor());
        return ['data' => self::sections((int) $e['id'])];
    }

    public function profile(Ctx $c): array
    {
        $p = $c->person();
        return ['data' => self::profileOut($c, (int) $p['id'])];
    }

    public function saveProfile(Ctx $c): array
    {
        $p = $c->person();
        $in = $c->input(self::PROFILE, true);
        if (array_intersect(array_keys($in), self::SENSITIVE) && !$c->can('staff.sensitive')) {
            throw ApiException::forbidden('Changing National Insurance or bank details needs the staff.sensitive scope.');
        }
        [$clean, $errors] = StaffRecord::cleanProfile(array_map(static fn($v) => $v ?? '', $in));
        if ($errors) {
            throw ApiException::invalid('The values were not accepted.', array_map(static fn($f, $m) => ['field' => $f, 'message' => $m], array_keys($errors), $errors));
        }
        StaffRecord::applyProfile($c->org(), (int) $p['id'], $clean, $c->actor());
        return ['data' => self::profileOut($c, (int) $p['id'])];
    }

    public function profileChanges(Ctx $c): array
    {
        $where = ' WHERE c.organisation_id = ?';
        $params = [$c->org()];
        if ($s = $c->q('status')) {
            if (!in_array($s, ['pending', 'approved', 'rejected', 'withdrawn'], true)) {
                throw ApiException::badRequest('status must be pending, approved, rejected or withdrawn.');
            }
            $where .= ' AND c.status = ?';
            $params[] = $s;
        }
        if ($pid = $c->q('person_id')) {
            $where .= ' AND c.person_id = ?';
            $params[] = (int) $c->person($pid)['id'];
        }
        $sens = $c->can('staff.sensitive');
        return Page::run($c, 'SELECT c.*, p.uuid AS person_uuid FROM profile_changes c JOIN persons p ON p.id = c.person_id' . $where, $params, 'c.id', static function ($r) use ($sens) {
            $secret = !empty(StaffRecord::FIELDS[$r['field']]['secret']);
            $show = $r['field_type'] !== 'file' && (!$secret || $sens);
            return [
                'id' => $r['uuid'], 'person_id' => $r['person_uuid'], 'field' => $r['field'], 'label' => $r['label'], 'status' => $r['status'],
                'current_value' => $show ? ($secret ? Crypto::decrypt($r['current_value']) : $r['current_value']) : null,
                'proposed_value' => $show ? ($secret ? Crypto::decrypt($r['proposed_value']) : $r['proposed_value']) : null,
                'rejection_reason' => $r['rejection_reason'], 'submitted_at' => Present::time($r['submitted_at']), 'reviewed_at' => Present::time($r['reviewed_at']),
            ];
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private static function select(): string
    {
        return 'SELECT e.*, p.uuid AS person_uuid, m.uuid AS manager_uuid FROM employments e JOIN persons p ON p.id = e.person_id LEFT JOIN persons m ON m.id = e.line_manager_person_id';
    }

    /** @return array<string,mixed> */
    private static function one(int $id): array
    {
        return Present::employment(DB::one(self::select() . ' WHERE e.id = ?', [$id]) ?? []);
    }

    /** @return array<string,mixed> */
    private static function lookup(Ctx $c, string $table, string $uuid, string $field): array
    {
        try {
            return $c->find($table, $uuid, 'That ' . $field);
        } catch (ApiException) {
            throw ApiException::invalid('The request body has 1 problem.', [['field' => $field, 'message' => 'No such record in this organisation.']]);
        }
    }

    private static function personId(Ctx $c, string $uuid, string $field): int
    {
        return (int) self::lookup($c, 'persons', $uuid, $field)['id'];
    }

    /** @return array<string,array<string,mixed>> */
    private static function sections(int $employmentId): array
    {
        $d = StaffRecord::details($employmentId);
        $out = [];
        foreach (StaffRecord::SECTIONS as $name => [$title, $fields]) {
            foreach ($fields as $f => [$type]) {
                $v = $d[$f] ?? null;
                $out[$name][$f] = match ($type) { 'bool' => (bool) (int) $v, 'num' => Present::num($v), default => $v === '' ? null : $v };
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function profileOut(Ctx $c, int $personId): array
    {
        $p = StaffRecord::profile($personId);
        $out = [];
        foreach (StaffRecord::FIELDS as $f => $m) {
            if ($m['table'] === 'person_profiles' && (empty($m['secret']) || $c->can('staff.sensitive'))) {
                $out[$f] = $p[$f] === '' ? null : $p[$f];
            }
        }
        $out['has_photo'] = !empty($p['photo_path']);
        $out['has_signature'] = !empty($p['signature_path']);
        $out['signature_added_at'] = Present::time($p['signature_at']);
        return $out;
    }
}
