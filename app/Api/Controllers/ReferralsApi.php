<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Created;
use App\Api\Ctx;
use App\Api\Page;
use App\Api\Present;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Modules\Referrals\ReferralRepo;

final class ReferralsApi extends BaseApi
{
    public const TAG = 'Referrals';

    private const BODY = [
        'source'        => ['type' => 'enum', 'required' => true, 'enum' => ['council', 'nhs', 'family', 'self', 'provider', 'other'], 'desc' => 'Who the referral came from.'],
        'person_name'   => ['type' => 'string', 'required' => true, 'max' => 150, 'desc' => 'The person referred. They may not be a person in Care Core yet.'],
        'reason'        => ['type' => 'string', 'required' => true, 'max' => 600],
        'received_on'   => ['type' => 'date', 'desc' => 'Defaults to today. Cannot be in the future.'],
        'referrer'      => ['type' => 'string', 'max' => 200, 'nullable' => true],
        'needs_summary' => ['type' => 'string', 'max' => 1000, 'nullable' => true],
        'risks'         => ['type' => 'string', 'max' => 600, 'nullable' => true],
        'what_matters'  => ['type' => 'string', 'max' => 600, 'nullable' => true],
        'plan_types'    => ['type' => 'array', 'desc' => 'Any of dementia, behaviour, recovery, home.'],
        'wanted_from'   => ['type' => 'date', 'nullable' => true],
        'skills'        => ['type' => 'array', 'desc' => 'Specialist skills the support will need, as a list of names.'],
    ];
    private const STATUS = [
        'status' => ['type' => 'enum', 'required' => true, 'enum' => ['assessing', 'accepted', 'declined', 'withdrawn']],
        'reason' => ['type' => 'string', 'max' => 400, 'nullable' => true, 'desc' => 'Required to decline or withdraw.'],
    ];
    private const LINK = [
        'person_id'     => ['type' => 'uuid', 'nullable' => true, 'desc' => 'The person to link. Leave out with create_person true to start a person from the name on the referral.'],
        'create_person' => ['type' => 'bool', 'desc' => 'Start a new person from the name on the referral (needs a first and last name).'],
    ];

    public static function routes(): array
    {
        return [
            self::route('GET', '/referrals', 'referrals.read', 'referrals', 'List referrals', self::paging() + ['status' => ['string', 'received, assessing, accepted, declined or withdrawn.'], 'person_id' => ['string', 'Only the referral linked to this person.']], [], 'list:Referral', 200, 'Newest first.'),
            self::route('POST', '/referrals', 'referrals.write', 'create', 'Record a referral', [], self::BODY, 'Referral', 201, 'Raises a referral.received event (identifiers only).'),
            self::route('GET', '/referrals/{id}', 'referrals.read', 'one', 'Get a referral', [], [], 'Referral', 200, 'Includes the needs, risks, what matters to the person, the plans it calls for and the specialist skills.'),
            self::route('PATCH', '/referrals/{id}', 'referrals.write', 'update', 'Change a referral', [], self::BODY, 'Referral', 200, 'Only a referral that is received or being assessed can change.'),
            self::route('POST', '/referrals/{id}/status', 'referrals.write', 'status', 'Assess, accept, decline or withdraw a referral', [], self::STATUS, 'Referral', 200, 'Raises referral.status_changed.'),
            self::route('POST', '/referrals/{id}/link', 'referrals.write', 'link', 'Link an accepted referral to a person', [], self::LINK, 'Referral', 200, 'Raises referral.linked.'),
        ];
    }

    public function referrals(Ctx $c): array
    {
        $where = ' WHERE r.organisation_id = ?';
        $params = [$c->org()];
        if (($s = $c->q('status')) !== null) {
            if (!isset(ReferralRepo::STATUS[$s])) {
                throw ApiException::badRequest('status must be received, assessing, accepted, declined or withdrawn.');
            }
            $where .= ' AND r.status = ?';
            $params[] = $s;
        }
        if (($pid = $c->q('person_id')) !== null) {
            $where .= ' AND p.uuid = ?';
            $params[] = strtolower($pid);
        }
        return Page::run($c, self::select() . $where, $params, 'r.id', [Present::class, 'referral'], 'DESC');
    }

    public function one(Ctx $c): array
    {
        return ['data' => self::present((int) $c->find('referrals', $c->params['id'] ?? '', 'That referral')['id'])];
    }

    public function create(Ctx $c): Created
    {
        $r = self::run(fn() => ReferralRepo::create($c->org(), $c->input(self::BODY), $c->actor()));
        return new Created(self::present($r['id']), '/referrals/' . $r['uuid']);
    }

    public function update(Ctx $c): array
    {
        $ref = $c->find('referrals', $c->params['id'] ?? '', 'That referral');
        self::run(fn() => ReferralRepo::update($c->org(), (int) $ref['id'], $c->input(self::BODY, true), $c->actor()));
        return ['data' => self::present((int) $ref['id'])];
    }

    public function status(Ctx $c): array
    {
        $ref = $c->find('referrals', $c->params['id'] ?? '', 'That referral');
        $in = $c->input(self::STATUS);
        self::run(fn() => ReferralRepo::setStatus($c->org(), (int) $ref['id'], (string) $in['status'], $in['reason'] ?? null, $c->actor()));
        return ['data' => self::present((int) $ref['id'])];
    }

    public function link(Ctx $c): array
    {
        $ref = $c->find('referrals', $c->params['id'] ?? '', 'That referral');
        $in = $c->input(self::LINK);
        if (!empty($in['create_person'])) {
            self::run(fn() => ReferralRepo::createPersonAndLink($c->org(), (int) $ref['id'], $c->actor()));
        } elseif (!empty($in['person_id'])) {
            $person = $c->person((string) $in['person_id']);
            self::run(fn() => ReferralRepo::linkPerson($c->org(), (int) $ref['id'], (int) $person['id'], $c->actor()));
        } else {
            throw ApiException::badRequest('Send person_id, or create_person true.');
        }
        return ['data' => self::present((int) $ref['id'])];
    }

    /** Domain refusals become 422 responses with the same message the screens show. */
    private static function run(callable $work): mixed
    {
        try {
            return $work();
        } catch (DomainException $e) {
            throw ApiException::invalid($e->getMessage(), []);
        }
    }

    private static function select(): string
    {
        return 'SELECT r.*, p.uuid AS person_uuid, (SELECT GROUP_CONCAT(s.skill ORDER BY s.sort_order, s.id SEPARATOR \'\n\') FROM referral_skills s WHERE s.referral_id = r.id) AS skills_text
                FROM referrals r LEFT JOIN persons p ON p.id = r.person_id';
    }

    /** @return array<string,mixed> */
    private static function present(int $id): array
    {
        return Present::referral(DB::one(self::select() . ' WHERE r.id = ?', [$id]) ?? []);
    }
}
