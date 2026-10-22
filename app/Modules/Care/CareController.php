<?php
declare(strict_types=1);

namespace App\Modules\Care;

use App\Core\Clock;
use App\Core\Controller;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\People\PersonRepo;

final class CareController extends Controller
{
    public function newForm(Request $r, array $p = []): Response
    {
        return $this->view('care/new', ['title' => 'Add a person we support', 'old' => ['start_date' => Clock::today()], 'error' => null]);
    }

    public function create(Request $r, array $p = []): Response
    {
        $d = [];
        foreach (['first_name', 'last_name', 'preferred_name', 'date_of_birth', 'email', 'phone', 'start_date', 'reference', 'nhs_number', 'local_authority_ref'] as $k) {
            $d[$k] = $r->input($k);
        }
        try {
            $org = $this->orgId();
            $result = \App\Core\Database::transaction(function () use ($org, $d) {
                $person = PersonRepo::create($org, $d, $this->actor());
                CareRepo::startSupport($org, $person['id'], $d, $this->actor());
                return $person;
            });
        } catch (DomainException $e) {
            return $this->view('care/new', ['title' => 'Add a person we support', 'old' => $d, 'error' => $e->getMessage()], 422);
        }
        $this->ok('Added. Support has started.');
        return $this->redirect('/people/' . $result['uuid']);
    }

    public function start(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], fn(array $person) => CareRepo::startSupport($this->orgId(), (int) $person['id'], ['start_date' => $r->input('start_date'), 'reference' => $r->input('reference'), 'nhs_number' => $r->input('nhs_number')], $this->actor()), 'Support started.');
    }

    public function end(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], function (array $person) use ($r) {
            $res = CareRepo::endSupport($this->orgId(), (int) $person['id'], $r->input('end_date') ?: null, $this->actor());
            $this->ok('Support ended. ' . $res['keyworkers'] . ' key worker link(s) and ' . $res['teams'] . ' team place(s) ended with it.');
        }, null);
    }

    public function addNeed(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], fn(array $person) => CareRepo::addNeed($this->orgId(), (int) $person['id'], ['category' => $r->input('category'), 'description' => $r->input('description'), 'severity' => $r->input('severity'), 'review_date' => $r->input('review_date')], $this->actor()), 'Care need added.');
    }

    public function endNeed(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], fn(array $person) => CareRepo::endNeed($this->orgId(), (int) $r->input('id'), $this->actor()), 'Care need closed.');
    }

    public function addContact(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], fn(array $person) => CareRepo::addContact($this->orgId(), (int) $person['id'], ['contact_type' => $r->input('contact_type'), 'label' => $r->input('label'), 'value' => $r->input('value'), 'is_primary' => $r->has('is_primary')], $this->actor()), 'Contact added.');
    }

    public function removeContact(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], fn(array $person) => CareRepo::removeContact($this->orgId(), (int) $r->input('id'), $this->actor()), 'Contact removed.');
    }

    public function assignKeyWorker(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], fn(array $person) => CareRepo::assignKeyWorker($this->orgId(), (int) $person['id'], (int) $r->input('staff_id'), $r->input('role_label') ?: null, null, $this->actor()), 'Key worker added.');
    }

    public function endKeyWorker(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], fn(array $person) => CareRepo::endKeyWorker($this->orgId(), (int) $r->input('id'), null, $this->actor()), 'Key worker link ended.');
    }

    /** Run one action on a person, then go back to their page with a message. */
    private function act(string $uuid, callable $do, ?string $success): Response
    {
        $person = PersonRepo::byUuid($this->orgId(), $uuid);
        if (!$person) {
            return Router::error(404, 'Not found', 'That person could not be found.');
        }
        try {
            $do($person);
            if ($success) {
                $this->ok($success);
            }
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/people/' . $person['uuid']);
    }
}
