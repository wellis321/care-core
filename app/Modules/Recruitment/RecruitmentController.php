<?php
declare(strict_types=1);

namespace App\Modules\Recruitment;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Staff\Lifecycle;
use App\Modules\Teams\TeamRepo;

final class RecruitmentController extends Controller
{
    private const NOT_FOUND = ['Not found', 'That could not be found.'];

    // ── Vacancies ───────────────────────────────────────────────────────────

    public function index(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $status = in_array($r->input('status'), ['draft', 'open', 'filled', 'closed'], true) ? $r->input('status') : '';
        return $this->view('recruitment/index', [
            'title' => 'Recruitment', 'vacancies' => VacancyRepo::all($org, $status), 'status' => $status,
            'roles' => RoleRepo::all($org, true), 'teams' => TeamRepo::all($org), 'managers' => $this->managers(), 'contracts' => Lifecycle::CONTRACTS, 'error' => null, 'old' => $r->query,
        ]);
    }

    public function createVacancy(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $d = $this->input($r, ['role_id', 'title', 'team_id', 'line_manager_person_id', 'posts', 'contract_type', 'hours_per_week', 'closing_date', 'notes']);
        try {
            $v = VacancyRepo::create($org, $d, $this->actor());
        } catch (DomainException $e) {
            return $this->view('recruitment/index', ['title' => 'Recruitment', 'vacancies' => VacancyRepo::all($org), 'status' => '', 'roles' => RoleRepo::all($org, true), 'teams' => TeamRepo::all($org), 'managers' => $this->managers(), 'contracts' => Lifecycle::CONTRACTS, 'error' => $e->getMessage(), 'old' => $d], 422);
        }
        $this->ok('Vacancy ' . $v['reference'] . ' created as a draft. Open it when you are ready for candidates.');
        return $this->redirect('/recruitment/vacancies/' . $v['uuid']);
    }

    public function vacancy(Request $r, array $p): Response
    {
        $org = $this->orgId();
        $v = VacancyRepo::byUuid($org, $p['uuid']);
        if (!$v) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        $role = RoleRepo::byId($org, (int) $v['role_id']);
        return $this->view('recruitment/vacancy', [
            'title' => $v['reference'] . ' ' . $v['title'], 'v' => $v, 'role' => $role, 'applications' => ApplicationRepo::forVacancy((int) $v['id']), 'hired' => VacancyRepo::hired((int) $v['id']),
            'teams' => TeamRepo::all($org), 'managers' => $this->managers(), 'contracts' => Lifecycle::CONTRACTS, 'stages' => ApplicationRepo::STAGES, 'error' => null, 'old' => [],
        ]);
    }

    public function updateVacancy(Request $r, array $p): Response
    {
        $v = VacancyRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$v) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        try {
            VacancyRepo::update($this->orgId(), (int) $v['id'], $this->input($r, ['title', 'team_id', 'line_manager_person_id', 'posts', 'contract_type', 'hours_per_week', 'closing_date', 'notes']), $this->actor());
            $this->ok('Vacancy updated.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/recruitment/vacancies/' . $v['uuid']);
    }

    public function openVacancy(Request $r, array $p): Response
    {
        $v = VacancyRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$v) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        try {
            VacancyRepo::open($this->orgId(), (int) $v['id'], $this->actor());
            $this->ok('The vacancy is open: candidates can now be added.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/recruitment/vacancies/' . $v['uuid']);
    }

    public function closeVacancy(Request $r, array $p): Response
    {
        $v = VacancyRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$v) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        try {
            $n = VacancyRepo::close($this->orgId(), (int) $v['id'], $r->has('reject_remaining'), $this->actor());
            $this->ok('The vacancy is closed' . ($n ? " and $n open application" . ($n === 1 ? ' was' : 's were') . ' closed too.' : '.'));
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/recruitment/vacancies/' . $v['uuid']);
    }

    // ── Candidates ──────────────────────────────────────────────────────────

    public function addCandidate(Request $r, array $p): Response
    {
        $v = VacancyRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$v) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        try {
            $a = ApplicationRepo::create($this->orgId(), (int) $v['id'], $this->input($r, ['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'source', 'notes']), $this->actor());
            $this->ok('Candidate added.');
            return $this->redirect('/recruitment/applications/' . $a['uuid']);
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/recruitment/vacancies/' . $v['uuid']);
    }

    public function application(Request $r, array $p): Response
    {
        return $this->applicationView($p['uuid'], null, []);
    }

    public function updateApplication(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], function ($a) use ($r) {
            ApplicationRepo::update($this->orgId(), (int) $a['id'], $this->input($r, ['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'source', 'notes', 'interview_notes']), $this->actor());
            return 'Saved.';
        });
    }

    public function stage(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], function ($a) use ($r) {
            ApplicationRepo::setStage($this->orgId(), (int) $a['id'], (string) $r->input('stage'), $this->input($r, ['interview_date', 'offer_date', 'proposed_start_date', 'reason']), $this->actor());
            return 'Moved to ' . $r->input('stage') . '.';
        });
    }

    public function acceptOffer(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], function ($a) use ($r) {
            ApplicationRepo::acceptOffer($this->orgId(), (int) $a['id'], $r->input('accepted_on'), $this->actor());
            return 'Offer accepted. They can be hired once the required checks are done.';
        });
    }

    public function check(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], function ($a) use ($r) {
            ApplicationRepo::setCheck($this->orgId(), (int) $a['id'], (int) $r->input('check'), $r->has('clear') ? null : ($r->input('done_on') ?: Clock::today()), $r->input('notes'), $this->actor());
            return 'Check updated.';
        });
    }

    public function removeDetails(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], function ($a) {
            ApplicationRepo::removeDetails($this->orgId(), (int) $a['id'], $this->actor());
            return 'Their personal details have been removed.';
        });
    }

    public function hire(Request $r, array $p): Response
    {
        $a = ApplicationRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$a) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        $d = $this->input($r, ['start_date', 'job_title', 'employee_ref', 'line_manager_person_id', 'team_id', 'contract_type', 'hours_per_week', 'login_role']);
        $d['create_login'] = $r->has('create_login');
        try {
            $res = ApplicationRepo::hire($this->orgId(), (int) $a['id'], $d, $this->actor());
        } catch (DomainException $e) {
            return $this->applicationView($p['uuid'], $e->getMessage(), $d, 422);
        }
        $this->ok(($res['rehire'] ? 'Rehired' : 'Hired') . ' and onboarding started: ' . $res['tasks'] . ' task' . ($res['tasks'] === 1 ? '' : 's') . ' created.' . ($res['filled'] ? ' That fills the vacancy.' : ''));
        if ($res['invite_token']) {
            $this->reveal('Invitation link for their login', 'Invitation link', Config::appUrl() . '/invite/' . $res['invite_token'], 'Send this link to them yourself: Care Core does not send email. It works once and lasts 7 days. Opening it lets them choose a password.');
        }
        return $this->redirect('/people/' . $res['person_uuid'] . ($res['invite_token'] ? '#new-secret' : ''));
    }

    // ── Roles ───────────────────────────────────────────────────────────────

    public function roles(Request $r, array $p = []): Response
    {
        return $this->view('recruitment/roles', ['title' => 'Job roles', 'roles' => RoleRepo::all($this->orgId()), 'allRoles' => RoleRepo::all($this->orgId(), true), 'teams' => TeamRepo::all($this->orgId()), 'contracts' => Lifecycle::CONTRACTS, 'error' => null, 'old' => []]);
    }

    public function createRole(Request $r, array $p = []): Response
    {
        $d = $this->input($r, ['title', 'code', 'description', 'person_specification', 'reports_to_role_id', 'default_team_id', 'contract_type', 'hours_per_week', 'grade']);
        try {
            $role = RoleRepo::create($this->orgId(), $d, $this->actor());
        } catch (DomainException $e) {
            return $this->view('recruitment/roles', ['title' => 'Job roles', 'roles' => RoleRepo::all($this->orgId()), 'allRoles' => RoleRepo::all($this->orgId(), true), 'teams' => TeamRepo::all($this->orgId()), 'contracts' => Lifecycle::CONTRACTS, 'error' => $e->getMessage(), 'old' => $d], 422);
        }
        $this->ok('Role created. Add the checks someone needs before they can start.');
        return $this->redirect('/recruitment/roles/' . $role['uuid']);
    }

    public function role(Request $r, array $p): Response
    {
        $org = $this->orgId();
        $role = RoleRepo::byUuid($org, $p['uuid']);
        if (!$role) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        return $this->view('recruitment/role', [
            'title' => $role['title'], 'role' => $role, 'requirements' => RoleRepo::requirements((int) $role['id']), 'allRoles' => array_values(array_filter(RoleRepo::all($org, true), static fn($x) => (int) $x['id'] !== (int) $role['id'])),
            'teams' => TeamRepo::all($org), 'contracts' => Lifecycle::CONTRACTS, 'vacancies' => DB::all('SELECT uuid, reference, title, status FROM vacancies WHERE role_id = ? ORDER BY id DESC', [$role['id']]),
            'reports' => DB::all('SELECT uuid, title FROM job_roles WHERE reports_to_role_id = ? AND organisation_id = ? ORDER BY title', [$role['id'], $org]),
        ]);
    }

    public function updateRole(Request $r, array $p): Response
    {
        $role = RoleRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$role) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        try {
            RoleRepo::update($this->orgId(), (int) $role['id'], $this->input($r, ['title', 'code', 'description', 'person_specification', 'reports_to_role_id', 'default_team_id', 'contract_type', 'hours_per_week', 'grade']), $this->actor());
            $this->ok('Role updated.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/recruitment/roles/' . $role['uuid']);
    }

    public function roleCheck(Request $r, array $p): Response
    {
        $role = RoleRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$role) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        try {
            if ($r->has('remove')) {
                RoleRepo::removeRequirement($this->orgId(), (int) $role['id'], (int) $r->input('remove'), $this->actor());
                $this->ok('Check removed. Candidates already added keep it.');
            } else {
                RoleRepo::addRequirement($this->orgId(), (int) $role['id'], (string) $r->input('title'), $r->input('description'), $r->has('is_required'), $this->actor());
                $this->ok('Check added. It applies to candidates added from now on.');
            }
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/recruitment/roles/' . $role['uuid']);
    }

    public function roleActive(Request $r, array $p): Response
    {
        $role = RoleRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$role) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        RoleRepo::setActive($this->orgId(), (int) $role['id'], $r->has('on'), $this->actor());
        $this->ok($r->has('on') ? 'Role brought back.' : 'Role retired. Existing vacancies keep it.');
        return $this->redirect('/recruitment/roles/' . $role['uuid']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Run an action on an application and come back to it with the result. */
    private function act(string $uuid, callable $do): Response
    {
        $a = ApplicationRepo::byUuid($this->orgId(), $uuid);
        if (!$a) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        try {
            $this->ok($do($a));
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/recruitment/applications/' . $uuid);
    }

    /** @param array<string,mixed> $old */
    private function applicationView(string $uuid, ?string $error, array $old, int $status = 200): Response
    {
        $org = $this->orgId();
        $a = ApplicationRepo::byUuid($org, $uuid);
        if (!$a) {
            return Router::error(404, ...self::NOT_FOUND);
        }
        $v = VacancyRepo::byId($org, (int) $a['vacancy_id']);
        return $this->view('recruitment/application', [
            'title' => $a['first_name'] . ' ' . $a['last_name'], 'a' => $a, 'v' => $v, 'checks' => ApplicationRepo::checks((int) $a['id']), 'outstanding' => ApplicationRepo::outstanding((int) $a['id']),
            'person' => $a['person_id'] ? DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$a['person_id']]) : null,
            'teams' => TeamRepo::all($org), 'managers' => $this->managers(), 'contracts' => Lifecycle::CONTRACTS, 'error' => $error, 'old' => $old,
            'next' => ApplicationRepo::STAGES, 'today' => Clock::today(),
        ], $status);
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private function input(Request $r, array $keys): array
    {
        $d = [];
        foreach ($keys as $k) {
            if ($r->has($k)) {
                $d[$k] = $r->input($k);
            }
        }
        return $d;
    }

    /** Current staff, for choosing a line manager. @return list<array<string,mixed>> */
    private function managers(): array
    {
        $t = Clock::today();
        return DB::all('SELECT DISTINCT p.id, p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.organisation_id = ? AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?) ORDER BY p.last_name, p.first_name', [$this->orgId(), $t, $t]);
    }
}
