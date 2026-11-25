<?php
declare(strict_types=1);

namespace App\Modules\Recruitment;

use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;

/** Public pages for applicants: the careers page, the apply form, and a candidate's private interview link. No sign-in. */
final class CareersController extends Controller
{
    public function index(Request $r, array $p): Response
    {
        $org = Careers::organisation($p['slug']);
        if (!$org) {
            return Router::error(404, 'Page not found', 'We could not find that careers page.');
        }
        return $this->page('careers/index', ['title' => 'Jobs at ' . $org['name'], 'org' => $org, 'vacancies' => Careers::vacancies((int) $org['id'])]);
    }

    public function vacancy(Request $r, array $p): Response
    {
        $org = Careers::organisation($p['slug']);
        $v = $org ? Careers::vacancy((int) $org['id'], $p['uuid']) : null;
        if (!$org || !$v) {
            return Router::error(404, 'Page not found', 'That vacancy is not open for applications.');
        }
        return $this->show($org, $v, null, []);
    }

    public function apply(Request $r, array $p): Response
    {
        $org = Careers::organisation($p['slug']);
        $v = $org ? Careers::vacancy((int) $org['id'], $p['uuid']) : null;
        if (!$org || !$v) {
            return Router::error(404, 'Page not found', 'That vacancy is not open for applications.');
        }
        $d = [];
        foreach (['first_name', 'last_name', 'email', 'phone', 'statement', 'hp_extra', 'ft'] as $k) {
            $d[$k] = $r->input($k);
        }
        try {
            Careers::apply((int) $org['id'], $p['uuid'], $d);
        } catch (DomainException $e) {
            return $this->show($org, $v, $e->getMessage(), $d, 422);
        }
        // A robot's filled trap field looks the same as success, so there is nothing to learn from the page.
        return $this->page('careers/applied', ['title' => 'Application received', 'org' => $org, 'v' => $v]);
    }

    public function interview(Request $r, array $p): Response
    {
        $i = InterviewRepo::byToken($p['token']);
        if (!$i) {
            return Router::error(404, 'Page not found', 'That link is not valid.');
        }
        return $this->interviewPage($i, null);
    }

    public function respond(Request $r, array $p): Response
    {
        $i = InterviewRepo::byToken($p['token']);
        if (!$i) {
            return Router::error(404, 'Page not found', 'That link is not valid.');
        }
        try {
            InterviewRepo::respond($p['token'], $r->input('response'), $r->input('note'));
        } catch (DomainException $e) {
            return $this->interviewPage($i, $e->getMessage(), 422);
        }
        return $this->redirect('/interview/' . $p['token'] . '?sent=1');
    }

    /** @param array<string,mixed> $i */
    private function interviewPage(array $i, ?string $error, int $status = 200): Response
    {
        $a = DB::one('SELECT a.first_name, v.title FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ?', [$i['application_id']]);
        $org = DB::one('SELECT name FROM organisations WHERE id = ?', [$i['organisation_id']]);
        $open = $i['status'] === 'scheduled' && substr((string) $i['starts_at'], 0, 10) >= \App\Core\Clock::today();
        return $this->page('careers/interview', ['title' => 'Your interview', 'i' => $i, 'a' => $a, 'orgName' => $org['name'] ?? '', 'open' => $open, 'error' => $error, 'sent' => isset($_GET['sent'])], $status);
    }

    /** @param array<string,mixed> $org @param array<string,mixed> $v @param array<string,string> $old */
    private function show(array $org, array $v, ?string $error, array $old, int $status = 200): Response
    {
        return $this->page('careers/vacancy', ['title' => $v['title'] . ' · ' . $org['name'], 'org' => $org, 'v' => $v, 'error' => $error, 'old' => $old, 'stamp' => Careers::stamp($v['uuid'])], $status);
    }

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data, int $status = 200): Response
    {
        return Response::html(View::render($view, $data, 'layout_public'), $status);
    }
}
