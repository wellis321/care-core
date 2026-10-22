<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;

final class TaskController extends Controller
{
    public function index(Request $r, array $p = []): Response
    {
        $role = Auth::can('tasks.manage') ? null : 'staff';
        return $this->view('tasks/index', ['title' => 'Tasks', 'tasks' => Checklists::open($this->orgId(), $role), 'canManage' => Auth::can('tasks.manage')]);
    }

    public function update(Request $r, array $p): Response
    {
        try {
            Checklists::setStatus($this->orgId(), $p['uuid'], $r->input('status'), (int) Auth::user()['id'], $this->actor());
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect($this->safeNext($r->input('back'), '/tasks'));
    }
}
