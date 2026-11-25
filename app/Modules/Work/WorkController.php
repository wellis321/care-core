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
use App\Modules\Staff\Checklists;

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
