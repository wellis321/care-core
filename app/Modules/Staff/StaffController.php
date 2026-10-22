<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Uuid;
use App\Modules\People\PersonRepo;
use App\Modules\Teams\TeamRepo;

final class StaffController extends Controller
{
    public function joinForm(Request $r, array $p = []): Response
    {
        $person = $r->input('person') !== '' ? PersonRepo::byUuid($this->orgId(), $r->input('person')) : null;
        return $this->view('staff/join', $this->joinData($person, ['start_date' => Clock::today(), 'contract_type' => 'permanent'], null));
    }

    public function join(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $person = $r->input('person_uuid') !== '' ? PersonRepo::byUuid($org, $r->input('person_uuid')) : null;
        $d = [];
        foreach (['first_name', 'last_name', 'preferred_name', 'date_of_birth', 'email', 'phone', 'job_title', 'contract_type', 'hours_per_week', 'start_date', 'employee_ref', 'line_manager_person_id', 'team_id', 'team_role', 'login_role'] as $k) {
            $d[$k] = $r->input($k);
        }
        $d['create_login'] = $r->has('create_login');
        if ($person) {
            $d['person_uuid'] = $person['uuid'];
        }
        try {
            $res = Lifecycle::join($org, $d, $this->actor());
        } catch (DomainException $e) {
            return $this->view('staff/join', $this->joinData($person, $d, $e->getMessage()), 422);
        }
        $this->ok(($res['rehire'] ? 'Rehired' : 'Added') . ' and onboarding started: ' . $res['tasks'] . ' task' . ($res['tasks'] === 1 ? '' : 's') . ' created.');
        if ($res['invite_token']) {
            $this->ok('Invitation link (shown once, valid for 7 days): ' . \App\Core\Config::appUrl() . '/invite/' . $res['invite_token']);
        }
        return $this->redirect('/people/' . $res['person_uuid']);
    }

    public function moveForm(Request $r, array $p): Response
    {
        $emp = $this->employment($p['uuid']);
        if (!$emp) {
            return Router::error(404, 'Not found', 'That employment could not be found.');
        }
        return $this->view('staff/move', $this->moveData($emp, ['effective' => Clock::today()], null));
    }

    public function move(Request $r, array $p): Response
    {
        $emp = $this->employment($p['uuid']);
        if (!$emp) {
            return Router::error(404, 'Not found', 'That employment could not be found.');
        }
        $in = [];
        foreach (['job_title', 'contract_type', 'hours_per_week', 'line_manager_person_id', 'new_team_id', 'new_team_role', 'effective'] as $k) {
            $in[$k] = $r->input($k);
        }
        try {
            Lifecycle::move($this->orgId(), (int) $emp['id'], $in, $in['effective'] ?: Clock::today(), $this->actor());
        } catch (DomainException $e) {
            return $this->view('staff/move', $this->moveData($emp, $in, $e->getMessage()), 422);
        }
        $this->ok('Changes recorded.');
        return $this->redirect('/people/' . $emp['person_uuid']);
    }

    public function leaveForm(Request $r, array $p): Response
    {
        $emp = $this->employment($p['uuid']);
        if (!$emp) {
            return Router::error(404, 'Not found', 'That employment could not be found.');
        }
        return $this->view('staff/leave', $this->leaveData($emp, $r->input('date') ?: Clock::today(), '', null));
    }

    public function leave(Request $r, array $p): Response
    {
        $emp = $this->employment($p['uuid']);
        if (!$emp) {
            return Router::error(404, 'Not found', 'That employment could not be found.');
        }
        $date = $r->input('date');
        try {
            $res = Lifecycle::leave($this->orgId(), (int) $emp['id'], $date, $r->input('reason'), $this->actor());
        } catch (DomainException $e) {
            return $this->view('staff/leave', $this->leaveData($emp, $date ?: Clock::today(), $r->input('reason'), $e->getMessage()), 422);
        }
        $this->ok('Recorded. ' . $res['teams'] . ' team place(s) and ' . $res['keyworkers'] . ' key worker link(s) end on their last day, ' . $res['logins'] . ' login(s) stop after it, and ' . $res['tasks'] . ' offboarding task(s) were created.');
        return $this->redirect('/people/' . $emp['person_uuid']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function employment(string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one('SELECT e.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.uuid = ? AND e.organisation_id = ?', [$uuid, $this->orgId()]) : null;
    }

    /** @return list<array<string,mixed>> */
    private function staffChoices(?int $excludePersonId = null): array
    {
        $today = Clock::today();
        return DB::all(
            'SELECT DISTINCT p.id, p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.organisation_id = ? AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?) AND p.id <> ? ORDER BY p.last_name, p.first_name',
            [$this->orgId(), $today, $today, $excludePersonId ?? 0]
        );
    }

    /** @param array<string,mixed>|null $person @param array<string,mixed> $old */
    private function joinData(?array $person, array $old, ?string $error): array
    {
        return ['title' => $person ? 'New employment' : 'New starter', 'person' => $person, 'old' => $old, 'error' => $error, 'managers' => $this->staffChoices($person ? (int) $person['id'] : null), 'teams' => TeamRepo::all($this->orgId()), 'contracts' => Lifecycle::CONTRACTS];
    }

    /** @param array<string,mixed> $emp @param array<string,mixed> $old */
    private function moveData(array $emp, array $old, ?string $error): array
    {
        return ['title' => 'Change role or team', 'emp' => $emp, 'old' => $old, 'error' => $error, 'managers' => $this->staffChoices((int) $emp['person_id']), 'teams' => TeamRepo::all($this->orgId()), 'contracts' => Lifecycle::CONTRACTS];
    }

    /** @param array<string,mixed> $emp */
    private function leaveData(array $emp, string $date, string $reason, ?string $error): array
    {
        $preview = Clock::valid($date) ? Lifecycle::previewLeave($this->orgId(), (int) $emp['id'], $date) : null;
        return ['title' => 'Record leaving', 'emp' => $emp, 'date' => $date, 'reason' => $reason, 'error' => $error, 'preview' => $preview];
    }
}
