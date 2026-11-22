<?php
declare(strict_types=1);

namespace App\Modules\Referrals;

use App\Core\Clock;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

final class ReferralController extends Controller
{
    private const KEYS = ['source', 'person_name', 'date_of_birth', 'nhs_number', 'received_on', 'referrer', 'reason', 'needs_summary', 'risks', 'what_matters', 'wanted_from'];

    public function index(Request $r, array $p = []): Response
    {
        $status = array_key_exists((string) $r->input('status'), ReferralRepo::STATUS) ? (string) $r->input('status') : '';
        return $this->view('referrals/index', ['title' => 'Referrals', 'referrals' => ReferralRepo::all($this->orgId(), $status), 'status' => $status, 'error' => null, 'old' => []]);
    }

    public function create(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $d = $this->data($r);
        try {
            $ref = ReferralRepo::create($org, $d, $this->actor());
        } catch (DomainException $e) {
            return $this->view('referrals/index', ['title' => 'Referrals', 'referrals' => ReferralRepo::all($org), 'status' => '', 'error' => $e->getMessage(), 'old' => $d], 422);
        }
        $this->ok('Referral ' . $ref['reference'] . ' recorded.');
        return $this->redirect('/referrals/' . $ref['uuid']);
    }

    public function show(Request $r, array $p): Response
    {
        $org = $this->orgId();
        $ref = ReferralRepo::byUuid($org, $p['uuid']);
        if (!$ref) {
            return Router::error(404, 'Not found', 'That referral could not be found.');
        }
        $person = $ref['person_id'] ? DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$ref['person_id']]) : null;
        return $this->view('referrals/show', [
            'title' => $ref['reference'] . ' ' . $ref['person_name'], 'r' => $ref, 'skills' => ReferralRepo::skills((int) $ref['id']), 'person' => $person,
            'people' => $ref['status'] === 'accepted' && !$ref['person_id'] ? $this->candidates($org) : [], 'error' => null,
        ]);
    }

    public function update(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], function (array $ref) use ($r) {
            ReferralRepo::update($this->orgId(), (int) $ref['id'], $this->data($r, true), $this->actor());
            $this->ok('Referral updated.');
        });
    }

    public function status(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], function (array $ref) use ($r) {
            ReferralRepo::setStatus($this->orgId(), (int) $ref['id'], (string) $r->input('status'), (string) $r->input('reason'), $this->actor());
            $this->ok('Referral marked ' . strtolower(ReferralRepo::STATUS[(string) $r->input('status')] ?? 'updated') . '.');
        });
    }

    public function link(Request $r, array $p): Response
    {
        return $this->act($p['uuid'], function (array $ref) use ($r) {
            $org = $this->orgId();
            if ((string) $r->input('person') === 'new') {
                $person = ReferralRepo::createPersonAndLink($org, (int) $ref['id'], $this->actor());
                $this->ok('A person record was started from the name on the referral and linked.');
                return;
            }
            $pid = (int) DB::value('SELECT id FROM persons WHERE uuid = ? AND organisation_id = ?', [strtolower((string) $r->input('person')), $org]);
            ReferralRepo::linkPerson($org, (int) $ref['id'], $pid, $this->actor());
            $this->ok('Linked to the person.');
        });
    }

    /** @param callable(array<string,mixed>):void $work */
    private function act(string $uuid, callable $work): Response
    {
        $ref = ReferralRepo::byUuid($this->orgId(), $uuid);
        if (!$ref) {
            return Router::error(404, 'Not found', 'That referral could not be found.');
        }
        try {
            $work($ref);
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/referrals/' . $ref['uuid']);
    }

    /** @return array<string,mixed> */
    private function data(Request $r, bool $partial = false): array
    {
        $d = [];
        foreach (self::KEYS as $k) {
            if (!$partial || $r->has($k)) {
                $d[$k] = $r->input($k);
            }
        }
        if (!$partial || $r->has('plan_types_present')) {
            $d['plan_types'] = $r->post['plan_types'] ?? [];
        }
        if (!$partial || $r->has('skills')) {
            $d['skills'] = $r->input('skills');
        }
        if (!$partial && ($d['received_on'] ?? '') === '') {
            $d['received_on'] = Clock::today();
        }
        return $d;
    }

    /** People who could be linked: anyone not already linked to a referral. @return list<array<string,mixed>> */
    private function candidates(int $org): array
    {
        return DB::all('SELECT p.uuid, p.first_name, p.last_name FROM persons p WHERE p.organisation_id = ? AND NOT EXISTS (SELECT 1 FROM referrals r WHERE r.person_id = p.id) ORDER BY p.last_name, p.first_name LIMIT 500', [$org]);
    }
}
