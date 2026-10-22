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
use App\Modules\Teams\AssignmentRepo;
use App\Modules\Teams\TeamRepo;

final class TeamsApi extends BaseApi
{
    public const TAG = 'Teams';

    private const TEAM = [
        'name'      => ['type' => 'string', 'required' => true, 'max' => 150],
        'parent_id' => ['type' => 'uuid', 'nullable' => true, 'desc' => 'The team this one sits under. Loops are refused.'],
        'kind'      => ['type' => 'string', 'max' => 40, 'desc' => 'For example care, support, hr. Default care.'],
        'staff_only' => ['type' => 'bool', 'desc' => 'Refuse people who are only supported.'],
    ];

    private const PLACE = [
        'person_id'  => ['type' => 'uuid', 'required' => true],
        'role'       => ['type' => 'string', 'max' => 80, 'nullable' => true, 'desc' => 'Their role in the team.'],
        'primary'    => ['type' => 'bool', 'desc' => 'Make this their primary team.'],
        'start_date' => ['type' => 'date', 'desc' => 'Default today. Not before they last left this team.'],
    ];

    public static function routes(): array
    {
        return [
            self::route('GET', '/teams', 'teams.read', 'index', 'List teams', self::paging() + ['active' => ['boolean', 'true (default) for open teams only, false to include closed ones.']], [], 'list:Team'),
            self::route('POST', '/teams', 'teams.write', 'create', 'Create a team', [], self::TEAM, 'Team', 201),
            self::route('GET', '/teams/{id}', 'teams.read', 'show', 'Get a team', [], [], 'Team'),
            self::route('PATCH', '/teams/{id}', 'teams.write', 'update', 'Update a team', [], self::TEAM, 'Team', 200, 'Send only the fields to change.'),
            self::route('POST', '/teams/{id}/close', 'teams.write', 'close', 'Close a team', [], [], 'Team', 200, 'Ends every current place in it. Refused while it has active sub-teams.'),
            self::route('GET', '/teams/{id}/members', 'teams.read', 'members', 'List who is in a team', ['on' => ['date', 'Who was in the team on this date. Default today.']], [], 'list:TeamPlace'),
            self::route('POST', '/teams/{id}/members', 'teams.write', 'addMember', 'Add someone to a team', [], self::PLACE, 'TeamPlace', 201, 'Staff-only teams refuse people who are only supported. One active place per person per team; one primary team per staff member.'),
            self::route('POST', '/teams/{id}/members/{person}/end', 'teams.write', 'endMember', 'End someone’s place in a team', [], ['end_date' => ['type' => 'date', 'desc' => 'Their last day in the team. Default today.']], 'TeamPlace', 200),
            self::route('GET', '/people/{id}/teams', 'teams.read', 'forPerson', 'A person’s team history', [], [], 'list:TeamPlace', 200, 'Current places first.'),
        ];
    }

    public function index(Ctx $c): array
    {
        $where = ' WHERE t.organisation_id = ?';
        if (($c->q('active') ?? 'true') !== 'false') {
            $where .= ' AND t.active = 1';
        }
        return Page::run($c, 'SELECT t.*, pt.uuid AS parent_uuid FROM teams t LEFT JOIN teams pt ON pt.id = t.parent_team_id' . $where, [$c->org()], 't.id', [Present::class, 'team']);
    }

    public function show(Ctx $c): array
    {
        return ['data' => self::one($c->team()['id'])];
    }

    public function create(Ctx $c): Created
    {
        $d = self::data($c, $c->input(self::TEAM), null);
        $r = TeamRepo::create($c->org(), $d, $c->actor());
        return new Created(self::one((int) $r['id']), '/teams/' . $r['uuid']);
    }

    public function update(Ctx $c): array
    {
        $t = $c->team();
        $d = self::data($c, $c->input(self::TEAM, true), $t);
        TeamRepo::update($c->org(), (int) $t['id'], $d, $c->actor());
        return ['data' => self::one((int) $t['id'])];
    }

    public function close(Ctx $c): array
    {
        $t = $c->team();
        TeamRepo::deactivate($c->org(), (int) $t['id'], $c->actor());
        return ['data' => self::one((int) $t['id'])];
    }

    public function members(Ctx $c): array
    {
        $t = $c->team();
        $rows = AssignmentRepo::members((int) $t['id'], $c->qDate('on'));
        return ['data' => array_map(static fn($r) => Present::place($r + ['team_uuid' => $t['uuid']]), $rows)];
    }

    public function addMember(Ctx $c): Created
    {
        $t = $c->team();
        $in = $c->input(self::PLACE);
        $p = self::person($c, $in['person_id']);
        $id = AssignmentRepo::assign($c->org(), (int) $t['id'], (int) $p['id'], $in['role'] ?? null, !empty($in['primary']), $in['start_date'] ?? null, $c->actor(), 'api');
        $row = DB::one('SELECT a.*, ? AS team_uuid, ? AS person_uuid FROM team_assignments a WHERE a.id = ?', [$t['uuid'], $p['uuid'], $id]);
        return new Created(Present::place($row), "/teams/{$t['uuid']}/members");
    }

    public function endMember(Ctx $c): array
    {
        $t = $c->team();
        $p = self::person($c, $c->params['person']);
        $in = $c->input(['end_date' => ['type' => 'date']]);
        if (!AssignmentRepo::end($c->org(), (int) $t['id'], (int) $p['id'], $in['end_date'] ?? null, $c->actor())) {
            throw ApiException::notFound('An active place for that person in this team');
        }
        $row = DB::one('SELECT a.*, ? AS team_uuid, ? AS person_uuid FROM team_assignments a WHERE a.team_id = ? AND a.person_id = ? ORDER BY a.id DESC LIMIT 1', [$t['uuid'], $p['uuid'], $t['id'], $p['id']]);
        return ['data' => Present::place($row)];
    }

    public function forPerson(Ctx $c): array
    {
        $p = $c->person();
        return ['data' => array_map(static fn($r) => Present::place($r + ['person_uuid' => $p['uuid']]), AssignmentRepo::forPerson((int) $p['id']))];
    }

    /** @return array<string,mixed> */
    private static function one(int|string $id): array
    {
        return Present::team(DB::one('SELECT t.*, pt.uuid AS parent_uuid FROM teams t LEFT JOIN teams pt ON pt.id = t.parent_team_id WHERE t.id = ?', [$id]) ?? []);
    }

    /** @param array<string,mixed> $in @param array<string,mixed>|null $current @return array<string,mixed> */
    private static function data(Ctx $c, array $in, ?array $current): array
    {
        $d = ['name' => $in['name'] ?? $current['name'] ?? '', 'kind' => $in['kind'] ?? $current['kind'] ?? 'care', 'staff_only' => $in['staff_only'] ?? (bool) ($current['staff_only'] ?? false), 'parent_team_id' => $current['parent_team_id'] ?? null];
        if (array_key_exists('parent_id', $in)) {
            $d['parent_team_id'] = $in['parent_id'] === null ? null : (int) self::lookup($c, 'teams', $in['parent_id'], 'parent_id')['id'];
        }
        return $d;
    }

    /** @return array<string,mixed> */
    private static function person(Ctx $c, string $uuid): array
    {
        return self::lookup($c, 'persons', $uuid, 'person_id');
    }

    /** @return array<string,mixed> */
    private static function lookup(Ctx $c, string $table, string $uuid, string $field): array
    {
        try {
            return $c->find($table, $uuid, 'That ' . $field);
        } catch (ApiException) {
            throw ApiException::invalid('The request has 1 problem.', [['field' => $field, 'message' => 'No such record in this organisation.']]);
        }
    }
}
