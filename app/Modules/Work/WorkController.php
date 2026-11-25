<?php
declare(strict_types=1);

namespace App\Modules\Work;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Modules;
use App\Modules\People\PersonRepo;
use App\Modules\Staff\Checklists;
use App\Modules\Teams\TeamRepo;

final class WorkController extends Controller
{
    /** Everything waiting for the signed-in person, across every connected product, on one page. */
    public function index(Request $r, array $p = []): Response
    {
        $u = Auth::user();
        $person = $u['person_id'] ? DB::one('SELECT uuid FROM persons WHERE id = ?', [$u['person_id']]) : null;
        $rows = WorkSources::fetchFor($this->orgId(), ['uuid' => (string) $u['uuid'], 'email' => (string) $u['email'], 'person_uuid' => $person['uuid'] ?? null], $r->input('refresh') === '1');
        $tasks = Auth::can('tasks.view') ? Checklists::open($this->orgId(), Auth::can('tasks.manage') ? null : 'staff', 8) : [];
        return $this->view('work/index', ['title' => 'My work', 'rows' => $rows, 'tasks' => $tasks, 'today' => Clock::today(), 'connected' => count(WorkSources::all($this->orgId()))]);
    }

    /**
     * One search box for the whole suite. Care Core answers for people, staff and teams (exactly what the People and Teams pages would show this person); every connected product
     * answers for what this person may see in it. Each product's answer is its own: a product that does not answer shows as such and never stops the others.
     */
    public function search(Request $r, array $p = []): Response
    {
        $q = trim($r->input('q'));
        $org = $this->orgId();
        $own = [];
        $products = [];
        if (mb_strlen($q) >= 2) {
            if (Auth::can('people.view') || Auth::can('people.view_staff')) {
                $kind = Auth::can('people.view') ? 'all' : 'staff';
                foreach (PersonRepo::search($org, $q, $kind, 8) as $pp) {
                    $own[] = ['title' => PersonRepo::displayName($pp), 'kind' => $pp['is_staff'] ? 'Staff' : ($pp['is_supported'] ? 'Person supported' : 'Person'), 'detail' => (string) ($pp['current_job_title'] ?? ''), 'url' => '/people/' . $pp['uuid']];
                }
            }
            if (Modules::enabled($org, 'teams') && Auth::can('teams.view')) {
                foreach (TeamRepo::all($org) as $t) {
                    if (stripos((string) $t['name'], $q) !== false) {
                        $own[] = ['title' => (string) $t['name'], 'kind' => 'Team', 'detail' => '', 'url' => '/teams/' . $t['uuid']];
                    }
                    if (count($own) >= 12) {
                        break;
                    }
                }
            }
            $u = Auth::user();
            $person = $u['person_id'] ? DB::one('SELECT uuid FROM persons WHERE id = ?', [$u['person_id']]) : null;
            $products = WorkSources::searchFor($org, ['uuid' => (string) $u['uuid'], 'email' => (string) $u['email'], 'person_uuid' => $person['uuid'] ?? null], $q);
        }
        return $this->view('work/search', ['title' => 'Search', 'q' => $q, 'own' => $own, 'products' => $products, 'connected' => count(WorkSources::all($org))]);
    }

    public function sources(Request $r, array $p = []): Response
    {
        return $this->view('work/sources', ['title' => 'My work: connected products', 'sources' => WorkSources::all($this->orgId())]);
    }

    public function addSource(Request $r, array $p = []): Response
    {
        try {
            WorkSources::add($this->orgId(), (string) $r->input('name'), (string) $r->input('url'), (string) $r->input('key'), $this->actor());
            $this->ok('Connected.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/admin/my-work');
    }

    public function removeSource(Request $r, array $p = []): Response
    {
        try {
            WorkSources::remove($this->orgId(), (int) $r->input('id'), $this->actor());
            $this->ok('Disconnected.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/admin/my-work');
    }

    public function toggleSource(Request $r, array $p = []): Response
    {
        WorkSources::setActive($this->orgId(), (int) $r->input('id'), $r->input('on') === '1');
        return $this->redirect('/admin/my-work');
    }
}
