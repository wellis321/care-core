<?php
declare(strict_types=1);

namespace App\Modules\People;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Modules;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Care\CareRepo;
use App\Modules\Staff\Checklists;
use App\Modules\Staff\Lifecycle;
use App\Modules\Teams\AssignmentRepo;

final class PeopleController extends Controller
{
    public function index(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $full = Auth::can('people.view');
        $kind = $full ? $r->input('kind', 'all') : 'staff';
        $kind = in_array($kind, ['all', 'staff', 'supported', 'leavers'], true) ? $kind : 'all';
        if ($kind === 'supported' && !Modules::enabled($org, 'care')) {
            $kind = 'all';
        }
        $q = $r->input('q');
        return $this->view('people/index', ['title' => 'People', 'people' => PersonRepo::search($org, $q, $kind), 'kind' => $kind, 'q' => $q, 'full' => $full, 'care' => Modules::enabled($org, 'care')]);
    }

    public function show(Request $r, array $p): Response
    {
        $org = $this->orgId();
        $person = PersonRepo::byUuid($org, $p['uuid']);
        if (!$person) {
            return \App\Core\Router::error(404, 'Not found', 'That person could not be found.');
        }
        $pid = (int) $person['id'];
        $full = Auth::can('people.view');
        $today = Clock::today();
        $employments = Lifecycle::employments($pid);
        $current = Lifecycle::currentEmployment($pid);
        if (!$full && !$employments) {
            return \App\Core\Router::error(403, 'Not allowed', 'Your role does not allow that.');
        }
        $careOn = Modules::enabled($org, 'care') && Auth::can('care.view');
        $data = [
            'title' => PersonRepo::displayName($person), 'person' => $person, 'full' => $full, 'today' => $today,
            'employments' => $employments, 'current' => $current,
            'changes' => $current ? Lifecycle::changes((int) $current['id']) : [],
            'teams' => AssignmentRepo::forPerson($pid), 'careOn' => $careOn,
            'canManage' => Auth::can('staff.manage'), 'canCare' => Auth::can('care.manage') && $careOn,
        ];
        if ($full) {
            $data['tasks'] = Checklists::forPerson($pid);
            $data['activity'] = DB::all('SELECT * FROM audit_log WHERE organisation_id = ? AND entity_type = \'person\' AND entity_uuid = ? ORDER BY id DESC LIMIT 25', [$org, $person['uuid']]);
            $data['supervises'] = $careOn ? CareRepo::keyWorkerFor($pid) : [];
        }
        if ($careOn) {
            $data['episode'] = CareRepo::currentEpisode($pid);
            $data['episodes'] = CareRepo::episodes($pid);
            $data['keyworkers'] = CareRepo::keyWorkersFor($pid);
            $data['needs'] = CareRepo::needs($pid);
            $data['contacts'] = CareRepo::contacts($pid);
            $data['staffChoices'] = $data['canCare'] ? DB::all('SELECT DISTINCT p.id, p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.organisation_id = ? AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?) ORDER BY p.last_name, p.first_name', [$org, $today, $today]) : [];
        }
        return $this->view('people/show', $data);
    }

    public function editForm(Request $r, array $p): Response
    {
        $person = PersonRepo::byUuid($this->orgId(), $p['uuid']) ?? null;
        return $person ? $this->view('people/edit', ['title' => 'Edit ' . PersonRepo::displayName($person), 'person' => $person, 'error' => null, 'old' => $person])
                       : \App\Core\Router::error(404, 'Not found', 'That person could not be found.');
    }

    public function edit(Request $r, array $p): Response
    {
        $person = PersonRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$person) {
            return \App\Core\Router::error(404, 'Not found', 'That person could not be found.');
        }
        $old = array_intersect_key($r->post, array_flip(array_merge(['first_name', 'last_name', 'preferred_name', 'date_of_birth', 'email', 'phone', 'notes'], PersonRepo::DETAILS)));
        try {
            PersonRepo::update($this->orgId(), (int) $person['id'], $old, $this->actor());
        } catch (DomainException $e) {
            return $this->view('people/edit', ['title' => 'Edit ' . PersonRepo::displayName($person), 'person' => $person, 'error' => $e->getMessage(), 'old' => $old + $person], 422);
        }
        $this->ok('Details saved.');
        return $this->redirect('/people/' . $person['uuid']);
    }
}
