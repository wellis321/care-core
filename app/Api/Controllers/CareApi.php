<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Created;
use App\Api\Ctx;
use App\Api\Present;
use App\Core\Database as DB;
use App\Modules\Care\CareRepo;

final class CareApi extends BaseApi
{
    public const TAG = 'Care';

    private const SUPPORT = [
        'start_date' => ['type' => 'date', 'desc' => 'Default today.'],
        'reference' => ['type' => 'string', 'max' => 50, 'nullable' => true],
        'nhs_number' => ['type' => 'string', 'max' => 20, 'nullable' => true, 'desc' => '10 digits with a valid check digit.'],
        'local_authority_ref' => ['type' => 'string', 'max' => 50, 'nullable' => true],
    ];
    private const NEED = [
        'category' => ['type' => 'string', 'required' => true, 'max' => 100],
        'description' => ['type' => 'string', 'required' => true, 'max' => 5000],
        'severity' => ['type' => 'enum', 'enum' => ['low', 'medium', 'high'], 'nullable' => true],
        'review_date' => ['type' => 'date', 'nullable' => true],
    ];
    private const CONTACT = [
        'type' => ['type' => 'enum', 'required' => true, 'enum' => ['phone', 'email', 'address', 'next_of_kin', 'legal_guardian', 'social_worker', 'gp', 'other']],
        'value' => ['type' => 'string', 'required' => true, 'max' => 500],
        'label' => ['type' => 'string', 'max' => 100, 'nullable' => true],
        'primary' => ['type' => 'bool'],
    ];
    private const KEYWORKER = [
        'supported_person_id' => ['type' => 'uuid', 'required' => true, 'desc' => 'Must be receiving support.'],
        'staff_person_id' => ['type' => 'uuid', 'required' => true, 'desc' => 'Must be current staff.'],
        'role_label' => ['type' => 'string', 'max' => 100, 'nullable' => true],
        'start_date' => ['type' => 'date', 'desc' => 'Default today. Not before support started.'],
    ];

    public static function routes(): array
    {
        return [
            self::route('GET', '/people/{id}/support', 'care.read', 'support', 'List a person’s support episodes', [], [], 'list:SupportEpisode', 200, 'Newest first.'),
            self::route('POST', '/people/{id}/support', 'care.write', 'startSupport', 'Start support', [], self::SUPPORT, 'SupportEpisode', 201, 'A person has one episode at a time.'),
            self::route('POST', '/people/{id}/support/end', 'care.write', 'endSupport', 'End support', [], ['end_date' => ['type' => 'date', 'desc' => 'Their last day of support. Default today.']], 'SupportEnded', 200, 'Also ends their key worker links and team places on that day.'),
            self::route('GET', '/people/{id}/needs', 'care.read', 'needs', 'List care needs', ['include_ended' => ['boolean', 'Include needs that have ended.']], [], 'list:CareNeed'),
            self::route('POST', '/people/{id}/needs', 'care.write', 'addNeed', 'Add a care need', [], self::NEED, 'CareNeed', 201),
            self::route('POST', '/needs/{id}/end', 'care.write', 'endNeed', 'End a care need', [], [], 'CareNeed'),
            self::route('GET', '/people/{id}/contacts', 'care.read', 'contacts', 'List contacts', [], [], 'list:Contact'),
            self::route('POST', '/people/{id}/contacts', 'care.write', 'addContact', 'Add a contact', [], self::CONTACT, 'Contact', 201),
            self::route('DELETE', '/contacts/{id}', 'care.write', 'removeContact', 'Remove a contact', [], [], '', 204),
            self::route('GET', '/people/{id}/key-workers', 'care.read', 'keyWorkers', 'Key worker links for a person', [], [], 'list:KeyWorker', 200, 'Links where the person is the one supported, and links where the person is the key worker. Current first.'),
            self::route('POST', '/key-workers', 'care.write', 'assignKeyWorker', 'Assign a key worker', [], self::KEYWORKER, 'KeyWorker', 201, 'The key worker must be current staff, the person must be receiving support, and nobody can be their own key worker.'),
            self::route('POST', '/key-workers/{id}/end', 'care.write', 'endKeyWorker', 'End a key worker link', [], ['end_date' => ['type' => 'date', 'desc' => 'Default today.']], 'KeyWorker'),
        ];
    }

    public function support(Ctx $c): array
    {
        $p = $c->person();
        return ['data' => array_map(static fn($r) => Present::episode($r + ['person_uuid' => $p['uuid']]), CareRepo::episodes((int) $p['id']))];
    }

    public function startSupport(Ctx $c): Created
    {
        $p = $c->person();
        $uuid = CareRepo::startSupport($c->org(), (int) $p['id'], $c->input(self::SUPPORT), $c->actor());
        return new Created(Present::episode(DB::one('SELECT * FROM support_episodes WHERE uuid = ?', [$uuid]) + ['person_uuid' => $p['uuid']]), "/people/{$p['uuid']}/support");
    }

    public function endSupport(Ctx $c): array
    {
        $p = $c->person();
        $in = $c->input(['end_date' => ['type' => 'date']]);
        $r = CareRepo::endSupport($c->org(), (int) $p['id'], $in['end_date'] ?? null, $c->actor());
        return ['data' => ['key_worker_links_ended' => $r['keyworkers'], 'team_places_ended' => $r['teams']]];
    }

    public function needs(Ctx $c): array
    {
        $p = $c->person();
        $incl = $c->q('include_ended') === 'true';
        $rows = array_filter(CareRepo::needs((int) $p['id']), static fn($r) => $incl || $r['ended_on'] === null);
        return ['data' => array_map(static fn($r) => Present::need($r + ['person_uuid' => $p['uuid']]), array_values($rows))];
    }

    public function addNeed(Ctx $c): Created
    {
        $p = $c->person();
        $uuid = CareRepo::addNeed($c->org(), (int) $p['id'], $c->input(self::NEED), $c->actor());
        return new Created(Present::need(DB::one('SELECT * FROM care_needs WHERE uuid = ?', [$uuid]) + ['person_uuid' => $p['uuid']]), "/people/{$p['uuid']}/needs");
    }

    public function endNeed(Ctx $c): array
    {
        $n = $c->find('care_needs', $c->params['id'], 'That care need');
        CareRepo::endNeed($c->org(), (int) $n['id'], $c->actor());
        $p = DB::one('SELECT uuid FROM persons WHERE id = ?', [$n['person_id']]);
        return ['data' => Present::need(DB::one('SELECT * FROM care_needs WHERE id = ?', [$n['id']]) + ['person_uuid' => $p['uuid']])];
    }

    public function contacts(Ctx $c): array
    {
        $p = $c->person();
        return ['data' => array_map(static fn($r) => Present::contact($r + ['person_uuid' => $p['uuid']]), CareRepo::contacts((int) $p['id']))];
    }

    public function addContact(Ctx $c): Created
    {
        $p = $c->person();
        $in = $c->input(self::CONTACT);
        $uuid = CareRepo::addContact($c->org(), (int) $p['id'], ['contact_type' => $in['type'], 'value' => $in['value'], 'label' => $in['label'] ?? null, 'is_primary' => !empty($in['primary'])], $c->actor());
        return new Created(Present::contact(DB::one('SELECT * FROM person_contacts WHERE uuid = ?', [$uuid]) + ['person_uuid' => $p['uuid']]), "/people/{$p['uuid']}/contacts");
    }

    public function removeContact(Ctx $c): null
    {
        $row = $c->find('person_contacts', $c->params['id'], 'That contact');
        CareRepo::removeContact($c->org(), (int) $row['id'], $c->actor());
        return null;
    }

    public function keyWorkers(Ctx $c): array
    {
        $p = $c->person();
        $rows = DB::all(
            'SELECT k.*, s.uuid AS supported_uuid, f.uuid AS staff_uuid FROM key_worker_assignments k
             JOIN persons s ON s.id = k.supported_person_id JOIN persons f ON f.id = k.staff_person_id
             WHERE k.organisation_id = ? AND (k.supported_person_id = ? OR k.staff_person_id = ?) ORDER BY (k.valid_to IS NULL) DESC, k.valid_from DESC, k.id DESC',
            [$c->org(), $p['id'], $p['id']]
        );
        return ['data' => array_map([Present::class, 'keyWorker'], $rows)];
    }

    public function assignKeyWorker(Ctx $c): Created
    {
        $in = $c->input(self::KEYWORKER);
        $supported = self::person($c, $in['supported_person_id'], 'supported_person_id');
        $staff = self::person($c, $in['staff_person_id'], 'staff_person_id');
        $id = CareRepo::assignKeyWorker($c->org(), (int) $supported['id'], (int) $staff['id'], $in['role_label'] ?? null, $in['start_date'] ?? null, $c->actor());
        $row = self::kw((int) $id);
        return new Created(Present::keyWorker($row), '/people/' . $supported['uuid'] . '/key-workers');
    }

    public function endKeyWorker(Ctx $c): array
    {
        $k = $c->find('key_worker_assignments', $c->params['id'], 'That key worker link');
        $in = $c->input(['end_date' => ['type' => 'date']]);
        CareRepo::endKeyWorker($c->org(), (int) $k['id'], $in['end_date'] ?? null, $c->actor());
        return ['data' => Present::keyWorker(self::kw((int) $k['id']))];
    }

    /** @return array<string,mixed> */
    private static function kw(int $id): array
    {
        return DB::one('SELECT k.*, s.uuid AS supported_uuid, f.uuid AS staff_uuid FROM key_worker_assignments k JOIN persons s ON s.id = k.supported_person_id JOIN persons f ON f.id = k.staff_person_id WHERE k.id = ?', [$id]) ?? [];
    }

    /** @return array<string,mixed> */
    private static function person(Ctx $c, string $uuid, string $field): array
    {
        try {
            return $c->person($uuid);
        } catch (ApiException) {
            throw ApiException::invalid('The request body has 1 problem.', [['field' => $field, 'message' => 'No such person in this organisation.']]);
        }
    }
}
