<?php
declare(strict_types=1);

namespace App\Modules\Teams;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\People\PersonRepo;

final class TeamController extends Controller
{
    public function index(Request $r, array $p = []): Response
    {
        return $this->view('teams/index', ['title' => 'Teams', 'tree' => TeamRepo::tree($this->orgId()), 'teams' => TeamRepo::all($this->orgId()), 'canManage' => Auth::can('teams.manage'), 'error' => null, 'old' => []]);
    }

    public function create(Request $r, array $p = []): Response
    {
        $d = ['name' => $r->input('name'), 'parent_team_id' => $r->input('parent_team_id'), 'kind' => $r->input('kind'), 'staff_only' => $r->has('staff_only')];
        try {
            $t = TeamRepo::create($this->orgId(), $d, $this->actor());
        } catch (DomainException $e) {
            return $this->view('teams/index', ['title' => 'Teams', 'tree' => TeamRepo::tree($this->orgId()), 'teams' => TeamRepo::all($this->orgId()), 'canManage' => true, 'error' => $e->getMessage(), 'old' => $d], 422);
        }
        $this->ok('Team created.');
        return $this->redirect('/teams/' . $t['uuid']);
    }

    public function show(Request $r, array $p): Response
    {
        $org = $this->orgId();
        $team = TeamRepo::byUuid($org, $p['uuid']);
        if (!$team) {
            return Router::error(404, 'Not found', 'That team could not be found.');
        }
        $tid = (int) $team['id'];
        $today = Clock::today();
        $members = AssignmentRepo::members($tid);
        $inTeam = array_column($members, 'person_id');
        $candidates = Auth::can('teams.manage') ? array_values(array_filter(PersonRepo::search($org, '', 'all', 500), static fn($pp) => ($pp['is_staff'] || ($pp['is_supported'] && !$team['staff_only'])) && !in_array($pp['id'], $inTeam, true))) : [];
        return $this->view('teams/show', [
            'title' => $team['name'], 'team' => $team, 'path' => TeamRepo::path($tid), 'members' => $members, 'past' => AssignmentRepo::past($tid),
            'candidates' => $candidates, 'teams' => array_values(array_filter(TeamRepo::all($org), static fn($t) => (int) $t['id'] !== $tid)), 'canManage' => Auth::can('teams.manage'),
            'children' => DB::all('SELECT uuid, name FROM teams WHERE parent_team_id = ? AND active = 1 ORDER BY name', [$tid]), 'today' => $today, 'error' => null,
        ]);
    }

    public function edit(Request $r, array $p): Response
    {
        $team = TeamRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$team) {
            return Router::error(404, 'Not found', 'That team could not be found.');
        }
        try {
            TeamRepo::update($this->orgId(), (int) $team['id'], ['name' => $r->input('name'), 'parent_team_id' => $r->input('parent_team_id'), 'kind' => $r->input('kind'), 'staff_only' => $r->has('staff_only')], $this->actor());
            $this->ok('Team updated.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/teams/' . $team['uuid']);
    }

    public function close(Request $r, array $p): Response
    {
        $team = TeamRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$team) {
            return Router::error(404, 'Not found', 'That team could not be found.');
        }
        try {
            TeamRepo::deactivate($this->orgId(), (int) $team['id'], $this->actor());
            $this->ok('Team closed. Everyone\'s time in it is kept in its history.');
            return $this->redirect('/teams');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
            return $this->redirect('/teams/' . $team['uuid']);
        }
    }

    public function addMember(Request $r, array $p): Response
    {
        $team = TeamRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$team) {
            return Router::error(404, 'Not found', 'That team could not be found.');
        }
        try {
            AssignmentRepo::assign($this->orgId(), (int) $team['id'], (int) $r->input('person_id'), $r->input('role') ?: null, $r->has('is_primary'), $r->input('valid_from') ?: null, $this->actor());
            $this->ok('Added to the team.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/teams/' . $team['uuid']);
    }

    public function endMember(Request $r, array $p): Response
    {
        $team = TeamRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$team) {
            return Router::error(404, 'Not found', 'That team could not be found.');
        }
        try {
            AssignmentRepo::end($this->orgId(), (int) $team['id'], (int) $r->input('person_id'), $r->input('valid_to') ?: null, $this->actor());
            $this->ok('Removed from the team. Their time in it is kept in Past members.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/teams/' . $team['uuid']);
    }
}
