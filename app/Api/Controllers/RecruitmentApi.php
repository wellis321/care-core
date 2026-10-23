<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Created;
use App\Api\Ctx;
use App\Api\Page;
use App\Api\Present;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Modules\Recruitment\ApplicationRepo;
use App\Modules\Recruitment\RoleRepo;
use App\Modules\Recruitment\VacancyRepo;
use App\Modules\Staff\Lifecycle;

final class RecruitmentApi extends BaseApi
{
    public const TAG = 'Recruitment';

    private const CONTRACTS = ['permanent', 'fixed_term', 'zero_hours', 'bank', 'apprenticeship', 'agency'];

    private const ROLE = [
        'title'                => ['type' => 'string', 'required' => true, 'max' => 150],
        'code'                 => ['type' => 'string', 'max' => 30, 'nullable' => true, 'desc' => 'Optional, unique within the organisation.'],
        'description'          => ['type' => 'string', 'max' => 10000, 'nullable' => true, 'desc' => 'The job description.'],
        'person_specification' => ['type' => 'string', 'max' => 10000, 'nullable' => true],
        'reports_to_role_id'   => ['type' => 'uuid', 'nullable' => true, 'desc' => 'The role this one reports to. Loops are refused.'],
        'default_team_id'      => ['type' => 'uuid', 'nullable' => true, 'desc' => 'The team the role normally sits in.'],
        'contract_type'        => ['type' => 'enum', 'enum' => self::CONTRACTS],
        'hours_per_week'       => ['type' => 'number', 'nullable' => true, 'min' => 0.5],
        'grade'                => ['type' => 'string', 'max' => 60, 'nullable' => true],
    ];
    private const CHECK = [
        'title'       => ['type' => 'string', 'required' => true, 'max' => 150],
        'description' => ['type' => 'string', 'max' => 500, 'nullable' => true],
        'required'    => ['type' => 'bool', 'desc' => 'Whether it must be done before someone can start. Default true.'],
    ];
    private const VACANCY = [
        'role_id'         => ['type' => 'uuid', 'required' => true, 'desc' => 'The job role this is a post in.'],
        'title'           => ['type' => 'string', 'max' => 150, 'desc' => 'Defaults to the role’s title.'],
        'team_id'         => ['type' => 'uuid', 'nullable' => true, 'desc' => 'Defaults to the role’s usual team.'],
        'line_manager_id' => ['type' => 'uuid', 'nullable' => true, 'desc' => 'The person they will report to (a person id).'],
        'posts'           => ['type' => 'int', 'min' => 1, 'desc' => 'How many posts to fill. Default 1.'],
        'contract_type'   => ['type' => 'enum', 'enum' => self::CONTRACTS, 'desc' => 'Defaults to the role’s.'],
        'hours_per_week'  => ['type' => 'number', 'nullable' => true, 'min' => 0.5],
        'closing_date'    => ['type' => 'date', 'nullable' => true],
        'notes'           => ['type' => 'string', 'max' => 5000, 'nullable' => true],
    ];
    private const CANDIDATE = [
        'first_name'    => ['type' => 'string', 'required' => true, 'max' => 100],
        'last_name'     => ['type' => 'string', 'required' => true, 'max' => 100],
        'email'         => ['type' => 'string', 'max' => 190, 'nullable' => true],
        'phone'         => ['type' => 'string', 'max' => 40, 'nullable' => true],
        'date_of_birth' => ['type' => 'date', 'nullable' => true],
        'source'        => ['type' => 'string', 'max' => 100, 'nullable' => true],
        'notes'         => ['type' => 'string', 'max' => 5000, 'nullable' => true],
    ];
    private const STAGE = [
        'stage'               => ['type' => 'enum', 'required' => true, 'enum' => ['applied', 'shortlisted', 'interview', 'offer', 'rejected', 'withdrawn'], 'desc' => 'Hired is only reached by POST /applications/{id}/hire.'],
        'interview_date'      => ['type' => 'date', 'nullable' => true],
        'offer_date'          => ['type' => 'date', 'nullable' => true],
        'proposed_start_date' => ['type' => 'date', 'desc' => 'Required for the offer stage.'],
        'reason'              => ['type' => 'string', 'max' => 500, 'nullable' => true],
    ];
    private const HIRE = [
        'start_date'      => ['type' => 'date', 'desc' => 'Defaults to the offer’s proposed start date.'],
        'job_title'       => ['type' => 'string', 'max' => 150, 'desc' => 'Defaults to the vacancy’s title.'],
        'employee_ref'    => ['type' => 'string', 'max' => 50, 'nullable' => true],
        'line_manager_id' => ['type' => 'uuid', 'nullable' => true, 'desc' => 'Defaults to the vacancy’s.'],
        'team_id'         => ['type' => 'uuid', 'nullable' => true, 'desc' => 'Defaults to the vacancy’s.'],
        'contract_type'   => ['type' => 'enum', 'enum' => self::CONTRACTS],
        'hours_per_week'  => ['type' => 'number', 'min' => 0.5],
    ];

    public static function routes(): array
    {
        return [
            self::route('GET', '/roles', 'recruitment.read', 'roles', 'List job roles', self::paging() + ['active' => ['boolean', 'true (default) for roles in use only, false to include retired ones.']], [], 'list:JobRole'),
            self::route('POST', '/roles', 'recruitment.write', 'createRole', 'Create a job role', [], self::ROLE, 'JobRole', 201),
            self::route('GET', '/roles/{id}', 'recruitment.read', 'role', 'Get a job role', [], [], 'JobRole', 200, 'Includes the checks someone needs before they can start.'),
            self::route('PATCH', '/roles/{id}', 'recruitment.write', 'updateRole', 'Update a job role', [], self::ROLE, 'JobRole', 200, 'Send only the fields to change.'),
            self::route('POST', '/roles/{id}/checks', 'recruitment.write', 'addCheck', 'Add a check to a role', [], self::CHECK, 'JobRole', 201, 'Applies to candidates added afterwards.'),
            self::route('POST', '/roles/{id}/retire', 'recruitment.write', 'retireRole', 'Retire a job role', [], [], 'JobRole', 200, 'Existing vacancies keep it; no new vacancy can use it.'),
            self::route('GET', '/vacancies', 'recruitment.read', 'vacancies', 'List vacancies', self::paging() + ['status' => ['string', 'draft, open, filled or closed.'], 'role_id' => ['string', 'Only vacancies for this role.']], [], 'list:Vacancy'),
            self::route('POST', '/vacancies', 'recruitment.write', 'createVacancy', 'Create a vacancy', [], self::VACANCY, 'Vacancy', 201, 'Starts as a draft.'),
            self::route('GET', '/vacancies/{id}', 'recruitment.read', 'vacancy', 'Get a vacancy', [], [], 'Vacancy'),
            self::route('PATCH', '/vacancies/{id}', 'recruitment.write', 'updateVacancy', 'Update a vacancy', [], self::VACANCY, 'Vacancy', 200, 'Only a draft or open vacancy can change. Send only the fields to change.'),
            self::route('POST', '/vacancies/{id}/open', 'recruitment.write', 'openVacancy', 'Open a vacancy', [], [], 'Vacancy', 200, 'Candidates can then be added.'),
            self::route('POST', '/vacancies/{id}/close', 'recruitment.write', 'closeVacancy', 'Close a vacancy without filling it', [], ['reject_remaining' => ['type' => 'bool', 'desc' => 'Also close candidates still in progress.']], 'Vacancy'),
            self::route('GET', '/vacancies/{id}/applications', 'recruitment.read', 'applications', 'List a vacancy’s candidates', self::paging() + ['stage' => ['string', 'applied, shortlisted, interview, offer, hired, rejected or withdrawn.']], [], 'list:Application'),
            self::route('POST', '/vacancies/{id}/applications', 'recruitment.write', 'addApplication', 'Add a candidate', [], self::CANDIDATE, 'Application', 201, 'The vacancy must be open. The role’s checks are copied to the application.'),
            self::route('GET', '/applications/{id}', 'recruitment.read', 'application', 'Get an application', [], [], 'Application', 200, 'Includes its checks.'),
            self::route('PATCH', '/applications/{id}', 'recruitment.write', 'updateApplication', 'Update a candidate’s details', [], self::CANDIDATE + ['interview_notes' => ['type' => 'string', 'max' => 10000, 'nullable' => true]], 'Application', 200, 'Not once they have been hired.'),
            self::route('POST', '/applications/{id}/stage', 'recruitment.write', 'stage', 'Move an application to another stage', [], self::STAGE, 'Application', 200, 'applied → shortlisted → interview → offer, or out as rejected or withdrawn; a rejected or withdrawn one can be reopened.'),
            self::route('POST', '/applications/{id}/accept-offer', 'recruitment.write', 'acceptOffer', 'Record that the offer was accepted', [], ['accepted_on' => ['type' => 'date', 'desc' => 'Default today.']], 'Application'),
            self::route('POST', '/applications/{id}/checks/{check}', 'recruitment.write', 'setCheck', 'Mark a check done or not done', [], ['done_on' => ['type' => 'date', 'nullable' => true, 'desc' => 'The day it was done; null clears it. Default today.'], 'notes' => ['type' => 'string', 'max' => 500, 'nullable' => true]], 'Application'),
            self::route('POST', '/applications/{id}/hire', 'recruitment.write', 'hire', 'Hire the candidate', [], self::HIRE, 'Hired', 201, 'Needs an accepted offer and every required check done. Starts them through the ordinary joiner process: a person, an employment, their team and the onboarding checklist, and the employment.started event. Creating a login is not done here.'),
        ];
    }

    // ── Roles ───────────────────────────────────────────────────────────────

    public function roles(Ctx $c): array
    {
        $where = ' WHERE r.organisation_id = ?';
        if (($c->q('active') ?? 'true') !== 'false') {
            $where .= ' AND r.active = 1';
        }
        return Page::run($c, self::roleSelect() . $where, [$c->org()], 'r.id', [Present::class, 'role']);
    }

    public function role(Ctx $c): array
    {
        return ['data' => self::oneRole((int) self::findRole($c)['id'], true)];
    }

    public function createRole(Ctx $c): Created
    {
        $r = RoleRepo::create($c->org(), self::roleData($c, $c->input(self::ROLE)), $c->actor());
        return new Created(self::oneRole($r['id'], true), '/roles/' . $r['uuid']);
    }

    public function updateRole(Ctx $c): array
    {
        $role = self::findRole($c);
        RoleRepo::update($c->org(), (int) $role['id'], self::roleData($c, $c->input(self::ROLE, true)), $c->actor());
        return ['data' => self::oneRole((int) $role['id'], true)];
    }

    public function addCheck(Ctx $c): Created
    {
        $role = self::findRole($c);
        $in = $c->input(self::CHECK);
        RoleRepo::addRequirement($c->org(), (int) $role['id'], (string) $in['title'], $in['description'] ?? null, $in['required'] ?? true, $c->actor());
        return new Created(self::oneRole((int) $role['id'], true), '/roles/' . $role['uuid']);
    }

    public function retireRole(Ctx $c): array
    {
        $role = self::findRole($c);
        RoleRepo::setActive($c->org(), (int) $role['id'], false, $c->actor());
        return ['data' => self::oneRole((int) $role['id'], true)];
    }

    // ── Vacancies ───────────────────────────────────────────────────────────

    public function vacancies(Ctx $c): array
    {
        $where = ' WHERE v.organisation_id = ?';
        $params = [$c->org()];
        if (($s = $c->q('status')) !== null) {
            if (!in_array($s, ['draft', 'open', 'filled', 'closed'], true)) {
                throw ApiException::badRequest('status must be draft, open, filled or closed.');
            }
            $where .= ' AND v.status = ?';
            $params[] = $s;
        }
        if (($rid = $c->q('role_id')) !== null) {
            $where .= ' AND r.uuid = ?';
            $params[] = strtolower($rid);
        }
        return Page::run($c, self::vacancySelect() . $where, $params, 'v.id', [Present::class, 'vacancy']);
    }

    public function vacancy(Ctx $c): array
    {
        return ['data' => self::oneVacancy((int) self::findVacancy($c)['id'])];
    }

    public function createVacancy(Ctx $c): Created
    {
        $in = $c->input(self::VACANCY);
        $v = VacancyRepo::create($c->org(), self::vacancyData($c, $in), $c->actor());
        return new Created(self::oneVacancy($v['id']), '/vacancies/' . $v['uuid']);
    }

    public function updateVacancy(Ctx $c): array
    {
        $v = self::findVacancy($c);
        $in = $c->input(self::VACANCY, true);
        unset($in['role_id']);
        VacancyRepo::update($c->org(), (int) $v['id'], self::vacancyData($c, $in), $c->actor());
        return ['data' => self::oneVacancy((int) $v['id'])];
    }

    public function openVacancy(Ctx $c): array
    {
        $v = self::findVacancy($c);
        VacancyRepo::open($c->org(), (int) $v['id'], $c->actor());
        return ['data' => self::oneVacancy((int) $v['id'])];
    }

    public function closeVacancy(Ctx $c): array
    {
        $v = self::findVacancy($c);
        $in = $c->input(['reject_remaining' => ['type' => 'bool']]);
        VacancyRepo::close($c->org(), (int) $v['id'], !empty($in['reject_remaining']), $c->actor());
        return ['data' => self::oneVacancy((int) $v['id'])];
    }

    // ── Applications ────────────────────────────────────────────────────────

    public function applications(Ctx $c): array
    {
        $v = self::findVacancy($c);
        $where = ' WHERE a.vacancy_id = ?';
        $params = [$v['id']];
        if (($s = $c->q('stage')) !== null) {
            if (!in_array($s, ApplicationRepo::STAGES, true)) {
                throw ApiException::badRequest('stage must be one of: ' . implode(', ', ApplicationRepo::STAGES) . '.');
            }
            $where .= ' AND a.stage = ?';
            $params[] = $s;
        }
        $vu = $v['uuid'];
        return Page::run($c, 'SELECT a.*, ? AS vacancy_uuid, p.uuid AS person_uuid, e.uuid AS employment_uuid FROM applications a LEFT JOIN persons p ON p.id = a.person_id LEFT JOIN employments e ON e.id = a.employment_id' . $where, [$vu, ...$params], 'a.id', static fn($r) => Present::application($r));
    }

    public function addApplication(Ctx $c): Created
    {
        $v = self::findVacancy($c);
        $a = ApplicationRepo::create($c->org(), (int) $v['id'], $c->input(self::CANDIDATE), $c->actor());
        return new Created(self::oneApplication((int) $a['id']), '/applications/' . $a['uuid']);
    }

    public function application(Ctx $c): array
    {
        return ['data' => self::oneApplication((int) self::findApplication($c)['id'])];
    }

    public function updateApplication(Ctx $c): array
    {
        $a = self::findApplication($c);
        ApplicationRepo::update($c->org(), (int) $a['id'], $c->input(self::CANDIDATE + ['interview_notes' => ['type' => 'string', 'max' => 10000, 'nullable' => true]], true), $c->actor());
        return ['data' => self::oneApplication((int) $a['id'])];
    }

    public function stage(Ctx $c): array
    {
        $a = self::findApplication($c);
        $in = $c->input(self::STAGE);
        ApplicationRepo::setStage($c->org(), (int) $a['id'], (string) $in['stage'], $in, $c->actor());
        return ['data' => self::oneApplication((int) $a['id'])];
    }

    public function acceptOffer(Ctx $c): array
    {
        $a = self::findApplication($c);
        $in = $c->input(['accepted_on' => ['type' => 'date']]);
        ApplicationRepo::acceptOffer($c->org(), (int) $a['id'], $in['accepted_on'] ?? null, $c->actor());
        return ['data' => self::oneApplication((int) $a['id'])];
    }

    public function setCheck(Ctx $c): array
    {
        $a = self::findApplication($c);
        $in = $c->input(['done_on' => ['type' => 'date', 'nullable' => true], 'notes' => ['type' => 'string', 'max' => 500, 'nullable' => true]]);
        $check = DB::one('SELECT id FROM application_checks WHERE application_id = ? AND uuid = ?', [$a['id'], strtolower($c->params['check'] ?? '')]) ?? throw ApiException::notFound('That check');
        $done = array_key_exists('done_on', $in) ? $in['done_on'] : \App\Core\Clock::today();
        ApplicationRepo::setCheck($c->org(), (int) $a['id'], (int) $check['id'], $done, $in['notes'] ?? null, $c->actor());
        return ['data' => self::oneApplication((int) $a['id'])];
    }

    public function hire(Ctx $c): Created
    {
        $a = self::findApplication($c);
        $in = $c->input(self::HIRE);
        $d = $in;
        foreach (['line_manager_id' => 'line_manager_person_id'] as $from => $to) {
            if (array_key_exists($from, $in)) {
                $d[$to] = $in[$from] === null ? null : (int) self::lookup($c, 'persons', $in[$from], $from)['id'];
                unset($d[$from]);
            }
        }
        if (array_key_exists('team_id', $in)) {
            $d['team_id'] = $in['team_id'] === null ? null : (int) self::lookup($c, 'teams', $in['team_id'], 'team_id')['id'];
        }
        $d['create_login'] = false;
        $r = ApplicationRepo::hire($c->org(), (int) $a['id'], $d, $c->actor());
        return new Created(['application' => self::oneApplication((int) $a['id']), 'person_id' => $r['person_uuid'], 'employment_id' => $r['employment_uuid'], 'onboarding_tasks_created' => $r['tasks'], 'rehire' => $r['rehire'], 'vacancy_filled' => $r['filled']], '/applications/' . $a['uuid']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function findRole(Ctx $c): array
    {
        return $c->find('job_roles', $c->params['id'] ?? '', 'That role');
    }

    /** @return array<string,mixed> */
    private static function findVacancy(Ctx $c): array
    {
        return $c->find('vacancies', $c->params['id'] ?? '', 'That vacancy');
    }

    /** @return array<string,mixed> */
    private static function findApplication(Ctx $c): array
    {
        return $c->find('applications', $c->params['id'] ?? '', 'That application');
    }

    /** @return array<string,mixed> */
    private static function lookup(Ctx $c, string $table, string $uuid, string $field): array
    {
        try {
            return $c->find($table, $uuid, 'That ' . $field);
        } catch (ApiException) {
            throw ApiException::invalid('The request has 1 problem.', [['field' => $field, 'message' => 'No such record in this organisation.']]);
        }
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private static function roleData(Ctx $c, array $in): array
    {
        $d = $in;
        foreach (['reports_to_role_id' => 'job_roles', 'default_team_id' => 'teams'] as $k => $table) {
            if (array_key_exists($k, $in)) {
                $d[$k] = $in[$k] === null ? null : (int) self::lookup($c, $table, $in[$k], $k)['id'];
            }
        }
        return $d;
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private static function vacancyData(Ctx $c, array $in): array
    {
        $d = $in;
        if (array_key_exists('role_id', $in)) {
            $d['role_id'] = (int) self::lookup($c, 'job_roles', $in['role_id'], 'role_id')['id'];
        }
        if (array_key_exists('team_id', $in)) {
            $d['team_id'] = $in['team_id'] === null ? null : (int) self::lookup($c, 'teams', $in['team_id'], 'team_id')['id'];
        }
        if (array_key_exists('line_manager_id', $in)) {
            $d['line_manager_person_id'] = $in['line_manager_id'] === null ? null : (int) self::lookup($c, 'persons', $in['line_manager_id'], 'line_manager_id')['id'];
            unset($d['line_manager_id']);
        }
        return $d;
    }

    private static function roleSelect(): string
    {
        return 'SELECT r.*, rr.uuid AS reports_to_uuid, t.uuid AS team_uuid FROM job_roles r LEFT JOIN job_roles rr ON rr.id = r.reports_to_role_id LEFT JOIN teams t ON t.id = r.default_team_id';
    }

    private static function vacancySelect(): string
    {
        return 'SELECT v.*, r.uuid AS role_uuid, t.uuid AS team_uuid, m.uuid AS manager_uuid,
                    (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id AND a.stage = \'hired\') AS hired
                FROM vacancies v JOIN job_roles r ON r.id = v.role_id LEFT JOIN teams t ON t.id = v.team_id LEFT JOIN persons m ON m.id = v.line_manager_person_id';
    }

    /** @return array<string,mixed> */
    private static function oneRole(int $id, bool $withChecks): array
    {
        $row = DB::one(self::roleSelect() . ' WHERE r.id = ?', [$id]) ?? [];
        $out = Present::role($row);
        if ($withChecks) {
            $out['checks'] = array_map(static fn($q) => ['title' => $q['title'], 'description' => $q['description'], 'required' => (bool) $q['is_required']], RoleRepo::requirements($id));
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function oneVacancy(int $id): array
    {
        return Present::vacancy(DB::one(self::vacancySelect() . ' WHERE v.id = ?', [$id]) ?? []);
    }

    /** @return array<string,mixed> */
    private static function oneApplication(int $id): array
    {
        $row = DB::one('SELECT a.*, v.uuid AS vacancy_uuid, p.uuid AS person_uuid, e.uuid AS employment_uuid FROM applications a JOIN vacancies v ON v.id = a.vacancy_id LEFT JOIN persons p ON p.id = a.person_id LEFT JOIN employments e ON e.id = a.employment_id WHERE a.id = ?', [$id]) ?? [];
        $out = Present::application($row);
        $out['checks'] = array_map(static fn($k) => ['id' => $k['uuid'], 'title' => $k['title'], 'description' => $k['description'], 'required' => (bool) $k['is_required'], 'done_on' => $k['done_on'], 'notes' => $k['notes']], ApplicationRepo::checks($id));
        return $out;
    }
}
