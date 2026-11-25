<?php
declare(strict_types=1);

namespace App\Modules\Leave;

use App\Core\Actor;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Teams\TeamRepo;

/** Leave and absence: your own page for everyone, and a team page for managers. */
final class LeaveController extends Controller
{
    public function mine(Request $r, array $p = []): Response
    {
        $pid = $this->myPersonId();
        $org = $this->orgId();
        return $this->view('leave/index', [
            'title' => 'Leave', 'pid' => $pid, 'kinds' => LeaveRepo::KINDS, 'requestable' => LeaveRepo::REQUESTABLE, 'old' => [], 'today' => Clock::today(),
            'balance' => $pid ? LeaveRepo::balance($org, $pid) : null, 'list' => $pid ? LeaveRepo::forPerson($pid) : [], 'isManager' => Auth::can('staff.manage'),
        ]);
    }

    /** Ask for leave. */
    public function request(Request $r, array $p = []): Response
    {
        return $this->mineAction(fn(int $pid) => LeaveRepo::create($this->orgId(), $pid, $this->leaveInput($r) + ['kind' => $r->input('kind')], $this->actor(), false)['status'] === 'requested'
            ? 'Your request has been sent. A manager will decide it.' : 'Recorded.');
    }

    /** Say you are off sick. No approval needed. */
    public function sick(Request $r, array $p = []): Response
    {
        return $this->mineAction(fn(int $pid) => LeaveRepo::create($this->orgId(), $pid, $this->leaveInput($r) + ['kind' => 'sickness'], $this->actor(), false) ? 'Recorded. Get well soon. A manager can see this.' : '');
    }

    public function cancel(Request $r, array $p): Response
    {
        $a = LeaveRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$a) {
            return Router::error(404, 'Not found', 'That could not be found.');
        }
        $mine = $this->myPersonId() === (int) $a['person_id'];
        try {
            LeaveRepo::cancel($this->orgId(), $p['uuid'], $mine, Auth::can('staff.manage'), $this->actor());
            $this->ok('Cancelled.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect($this->safeNext($r->input('back'), '/leave'));
    }

    /** "I am back": close open sickness with the last day off. */
    public function back(Request $r, array $p): Response
    {
        $a = LeaveRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$a || ($this->myPersonId() !== (int) $a['person_id'] && !Auth::can('staff.manage'))) {
            return Router::error(404, 'Not found', 'That could not be found.');
        }
        try {
            LeaveRepo::endSickness($this->orgId(), $p['uuid'], $r->input('ends_on') ?: Clock::today(), $this->actor());
            $this->ok('Welcome back. A manager will have a short conversation with you.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect($this->safeNext($r->input('back'), '/leave'));
    }

    // ── Managers ───────────────────────────────────────────────────────────

    public function team(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $m = preg_match('/^\d{4}-\d{2}$/', $r->input('month')) && Clock::valid($r->input('month') . '-01') ? $r->input('month') . '-01' : substr(Clock::today(), 0, 8) . '01';
        $teamId = (int) $r->input('team') ?: null;
        if ($teamId && !DB::value('SELECT 1 FROM teams WHERE id = ? AND organisation_id = ?', [$teamId, $org])) {
            $teamId = null;
        }
        $month = LeaveRepo::month($org, $m, $teamId);
        $rows = [];
        foreach ($month['people'] as $pe) {
            $rows[] = $pe + ['balance' => LeaveRepo::balance($org, (int) $pe['id']), 'sick' => LeaveRepo::sicknessSummary((int) $pe['id'])];
        }
        return $this->view('leave/team', [
            'title' => 'Team leave', 'pending' => LeaveRepo::pending($org), 'off' => LeaveRepo::offOn($org, Clock::today()), 'sickOpen' => LeaveRepo::openSickness($org), 'awaitingRtw' => LeaveRepo::awaitingReturnToWork($org),
            'month' => $month, 'rows' => $rows, 'teams' => TeamRepo::all($org), 'teamId' => $teamId, 'today' => Clock::today(), 'kinds' => LeaveRepo::KINDS,
            'prev' => (new \DateTimeImmutable($m))->modify('-1 month')->format('Y-m'), 'next' => (new \DateTimeImmutable($m))->modify('+1 month')->format('Y-m'),
            'yearMonth' => LeaveRepo::yearStartMonth($org), 'myPid' => $this->myPersonId(),
        ]);
    }

    public function decide(Request $r, array $p): Response
    {
        try {
            $approve = $r->input('decision') === 'approve';
            LeaveRepo::decide($this->orgId(), $p['uuid'], $approve, $r->input('note'), $r->input('days') ?: null, (int) Auth::user()['id'], $this->myPersonId(), $this->actor());
            $this->ok($approve ? 'Approved.' : 'Declined.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/leave/team');
    }

    /** Record leave or sickness for someone, already agreed or already happened. */
    public function record(Request $r, array $p = []): Response
    {
        $pe = DB::one('SELECT id FROM persons WHERE uuid = ? AND organisation_id = ?', [$r->input('person'), $this->orgId()]);
        try {
            if (!$pe) {
                throw new DomainException('Choose a person.');
            }
            LeaveRepo::create($this->orgId(), (int) $pe['id'], $this->leaveInput($r) + ['kind' => $r->input('kind')], $this->actor(), true);
            $this->ok('Recorded.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/leave/team');
    }

    public function allowance(Request $r, array $p = []): Response
    {
        $pe = DB::one('SELECT id FROM persons WHERE uuid = ? AND organisation_id = ?', [$r->input('person'), $this->orgId()]);
        try {
            if (!$pe) {
                throw new DomainException('Choose a person.');
            }
            LeaveRepo::setAllowance($this->orgId(), (int) $pe['id'], Clock::today(), $r->input('days'), $r->input('note'), $this->actor());
            $this->ok('Allowance saved for this leave year.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/leave/team#allowances');
    }

    public function settings(Request $r, array $p = []): Response
    {
        try {
            LeaveRepo::setYearStartMonth($this->orgId(), (int) $r->input('month'), $this->actor());
            $this->ok('Leave year updated. Allowances are kept per leave year, so check them.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/leave/team#allowances');
    }

    public function rtw(Request $r, array $p): Response
    {
        try {
            LeaveRepo::returnToWorkHeld($this->orgId(), $p['uuid'], $r->input('on') ?: Clock::today(), $this->actor());
            $this->ok('Recorded.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/leave/team');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function myPersonId(): ?int
    {
        $id = Auth::user()['person_id'] ?? null;
        return $id ? (int) $id : null;
    }

    /** @return array<string,string> */
    private function leaveInput(Request $r): array
    {
        return ['starts_on' => $r->input('starts_on'), 'ends_on' => $r->input('ends_on'), 'start_half' => $r->has('start_half') ? '1' : '', 'end_half' => $r->has('end_half') ? '1' : '', 'reason' => $r->input('reason')];
    }

    private function mineAction(callable $do): Response
    {
        $pid = $this->myPersonId();
        if (!$pid) {
            $this->fail('Your login is not linked to a person, so there is nothing to record against.');
            return $this->redirect('/leave');
        }
        try {
            $this->ok($do($pid));
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/leave');
    }
}
