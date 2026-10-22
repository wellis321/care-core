<?php
declare(strict_types=1);

namespace App\Modules\People;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Staff\Checklists;
use App\Modules\Staff\Reconcile;

final class DashboardController extends Controller
{
    public function index(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $today = Clock::today();
        $soon = date('Y-m-d', strtotime($today . ' +30 days'));
        $data = ['title' => 'Dashboard', 'isManager' => Auth::can('people.view')];

        if ($data['isManager']) {
            $data['stats'] = [
                'staff'     => (int) DB::value('SELECT COUNT(DISTINCT person_id) FROM employments WHERE organisation_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)', [$org, $today, $today]),
                'supported' => (int) DB::value('SELECT COUNT(DISTINCT person_id) FROM support_episodes WHERE organisation_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)', [$org, $today, $today]),
                'tasks'     => (int) DB::value("SELECT COUNT(*) FROM tasks WHERE organisation_id = ? AND status = 'open'", [$org]),
                'overdue'   => (int) DB::value("SELECT COUNT(*) FROM tasks WHERE organisation_id = ? AND status = 'open' AND due_date < ?", [$org, $today]),
            ];
            $data['joiners'] = DB::all('SELECT e.start_date, e.job_title, p.uuid, p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.organisation_id = ? AND e.start_date > ? AND e.start_date <= ? ORDER BY e.start_date', [$org, $today, $soon]);
            $data['leavers'] = DB::all('SELECT e.end_date, e.job_title, p.uuid, p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.organisation_id = ? AND e.end_date >= ? AND e.end_date <= ? ORDER BY e.end_date', [$org, $today, $soon]);
            $data['tasks'] = array_slice(Checklists::open($org), 0, 8);
            $data['issues'] = array_values(array_filter(Reconcile::check($org), static fn($i) => $i['severity'] === 'high'));
            $data['recent'] = Auth::can('audit.view') ? DB::all('SELECT * FROM audit_log WHERE organisation_id = ? ORDER BY id DESC LIMIT 8', [$org]) : [];
        } else {
            $me = Auth::user();
            $person = $me['person_id'] ? PersonRepo::byId($org, (int) $me['person_id']) : null;
            $data['person'] = $person;
            $data['teams'] = $person ? \App\Modules\Teams\AssignmentRepo::forPerson((int) $person['id']) : [];
            $data['employment'] = $person ? \App\Modules\Staff\Lifecycle::currentEmployment((int) $person['id']) : null;
        }
        return $this->view('dashboard/index', $data);
    }
}
