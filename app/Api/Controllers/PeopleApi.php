<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Created;
use App\Api\Ctx;
use App\Api\Page;
use App\Api\Present;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Modules\People\PersonRepo;

final class PeopleApi extends BaseApi
{
    public const TAG = 'People';

    private const FIELDS = [
        'first_name'     => ['type' => 'string', 'required' => true, 'max' => 100, 'desc' => 'Required.'],
        'last_name'      => ['type' => 'string', 'required' => true, 'max' => 100, 'desc' => 'Required.'],
        'preferred_name' => ['type' => 'string', 'max' => 100, 'nullable' => true, 'desc' => 'Known as.'],
        'date_of_birth'  => ['type' => 'date', 'nullable' => true, 'desc' => 'Not in the future.'],
        'email'          => ['type' => 'string', 'max' => 190, 'nullable' => true],
        'phone'          => ['type' => 'string', 'max' => 40, 'nullable' => true],
        'notes'          => ['type' => 'string', 'max' => 5000, 'nullable' => true],
    ];

    public static function routes(): array
    {
        return [
            self::route('GET', '/people', 'people.read', 'index', 'List people', self::paging() + [
                'q' => ['string', 'Match on first name, last name, full name or email.'],
                'kind' => ['string', 'staff (employed today), supported (receiving support today; needs care.read), leavers (former staff).'],
                'employed_on' => ['date', 'Only people with an employment active on this date.'],
                'updated_since' => ['datetime', 'Only people changed on or after this UTC time (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SSZ). For syncing.'],
                'email' => ['string', 'Exact email address.'],
            ], [], 'list:Person', 200, 'Without the care.read scope, people who only receive support (never staff) are not listed and is_supported is null. Ordered by when the person was created.'),
            self::route('POST', '/people', 'people.write', 'create', 'Create a person', [], self::FIELDS, 'Person', 201, 'Creates the person record only. To make them staff use POST /employments.'),
            self::route('GET', '/people/{id}', 'people.read', 'show', 'Get a person', [], [], 'Person'),
            self::route('PATCH', '/people/{id}', 'people.write', 'update', 'Update a person', [], self::FIELDS, 'Person', 200, 'Send only the fields to change.'),
        ];
    }

    public function index(Ctx $c): array
    {
        [$select, $params] = self::select($c);
        $where = ' WHERE p.organisation_id = ?';
        $params[] = $c->org();
        if (!$c->can('care.read')) {
            $where .= ' AND EXISTS (SELECT 1 FROM employments ax WHERE ax.person_id = p.id)';
        }
        if ($q = $c->q('q')) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.email LIKE ?)";
            array_push($params, $like, $like, $like, $like);
        }
        if ($e = $c->q('email')) {
            $where .= ' AND p.email = ?';
            $params[] = strtolower($e);
        }
        $kind = $c->q('kind');
        if ($kind !== null) {
            if (!in_array($kind, ['staff', 'supported', 'leavers'], true)) {
                throw ApiException::badRequest('kind must be staff, supported or leavers.');
            }
            if ($kind === 'supported' && !$c->can('care.read')) {
                throw ApiException::forbidden('kind=supported needs the care.read scope.');
            }
            $where .= match ($kind) {
                'staff' => ' AND ce.id IS NOT NULL',
                'supported' => ' AND EXISTS (SELECT 1 FROM support_episodes se WHERE se.person_id = p.id AND se.start_date <= \'' . Clock::today() . '\' AND (se.end_date IS NULL OR se.end_date >= \'' . Clock::today() . '\'))',
                'leavers' => ' AND ce.id IS NULL AND EXISTS (SELECT 1 FROM employments le WHERE le.person_id = p.id AND le.end_date < \'' . Clock::today() . '\')',
            };
        }
        if ($on = $c->qDate('employed_on')) {
            $where .= ' AND EXISTS (SELECT 1 FROM employments oe WHERE oe.person_id = p.id AND oe.start_date <= ? AND (oe.end_date IS NULL OR oe.end_date >= ?))';
            array_push($params, $on, $on);
        }
        if ($u = $c->q('updated_since')) {
            $t = strtotime($u);
            if ($t === false) {
                throw ApiException::badRequest('updated_since must be a date or an ISO 8601 time.');
            }
            $where .= ' AND p.updated_at >= ?';
            $params[] = gmdate('Y-m-d H:i:s', $t);
        }
        $care = $c->can('care.read');
        $notes = $c->can('people.write');
        return Page::run($c, $select . $where, $params, 'p.id', static fn($r) => self::shape($r, $care, $notes));
    }

    public function show(Ctx $c): array
    {
        $uuid = $c->params['id'];
        [$select, $params] = self::select($c);
        $row = preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) ? DB::one($select . ' WHERE p.organisation_id = ? AND p.uuid = ?', [...$params, $c->org(), strtolower($uuid)]) : null;
        if (!$row || (!$c->can('care.read') && !DB::value('SELECT 1 FROM employments WHERE person_id = ?', [$row['id']]))) {
            throw ApiException::notFound('That person');
        }
        return ['data' => self::shape($row, $c->can('care.read'), $c->can('people.write'))];
    }

    public function create(Ctx $c): Created
    {
        $d = $c->input(self::FIELDS);
        $r = PersonRepo::create($c->org(), $d, $c->actor());
        return new Created(self::one($c, $r['uuid']), '/people/' . $r['uuid']);
    }

    public function update(Ctx $c): array
    {
        $p = $c->person();
        $in = $c->input(self::FIELDS, true);
        $merged = array_intersect_key($p, self::FIELDS);
        foreach ($in as $k => $v) {
            $merged[$k] = $v;
        }
        PersonRepo::update($c->org(), (int) $p['id'], $merged, $c->actor());
        return ['data' => self::one($c, $p['uuid'])];
    }

    /** @return array<string,mixed> */
    public static function one(Ctx $c, string $uuid): array
    {
        [$select, $params] = self::select($c);
        return self::shape(DB::one($select . ' WHERE p.organisation_id = ? AND p.uuid = ?', [...$params, $c->org(), $uuid]) ?? [], $c->can('care.read'), $c->can('people.write'));
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function select(Ctx $c): array
    {
        $today = Clock::today();
        return ["SELECT p.*, ce.id AS cur_emp_id, ce.uuid AS cur_emp_uuid, ce.job_title AS cur_job, ce.start_date AS cur_start, ce.end_date AS cur_end,
                    (ce.id IS NOT NULL) AS is_staff,
                    EXISTS(SELECT 1 FROM support_episodes s WHERE s.person_id = p.id AND s.start_date <= ? AND (s.end_date IS NULL OR s.end_date >= ?)) AS is_supported
                 FROM persons p
                 LEFT JOIN employments ce ON ce.person_id = p.id AND ce.start_date <= ? AND (ce.end_date IS NULL OR ce.end_date >= ?)", [$today, $today, $today, $today]];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function shape(array $r, bool $care, bool $notes): array
    {
        $out = Present::person($r);
        if (!$care) {
            $out['is_supported'] = null;
        }
        if (!$notes) {
            unset($out['notes']);
        }
        return $out;
    }
}
