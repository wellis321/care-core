<?php
declare(strict_types=1);

namespace App\Modules\Teams;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;

/** Teams and their hierarchy. A team can never sit under itself or one of its own sub-teams. */
final class TeamRepo
{
    /** @return list<string> */
    public static function problems(int $orgId, array $d, ?int $teamId = null): array
    {
        $parentId = ($d['parent_team_id'] ?? '') !== '' ? (int) $d['parent_team_id'] : null;
        $msgs = [
            Check::required($d['name'] ?? null, 'Team name'),
            Check::maxLen($d['name'] ?? null, 200, 'Team name'),
            Check::maxLen($d['kind'] ?? null, 40, 'Team kind'),
        ];
        if ($parentId !== null) {
            if (!self::byId($orgId, $parentId)) {
                $msgs[] = 'Choose a parent team from the list.';
            } elseif ($teamId !== null && ($parentId === $teamId || in_array($parentId, self::descendantIds($teamId), true))) {
                $msgs[] = 'A team cannot sit under itself or one of its own sub-teams.';
            }
        }
        return Check::all($msgs);
    }

    /** @param array<string,mixed> $d @return array{id:int,uuid:string} */
    public static function create(int $orgId, array $d, Actor $actor): array
    {
        if ($p = self::problems($orgId, $d)) {
            throw DomainException::of($p);
        }
        return DB::transaction(function () use ($orgId, $d, $actor) {
            $uuid = Uuid::v4();
            $id = DB::insert('teams', [
                'uuid'            => $uuid,
                'organisation_id' => $orgId,
                'parent_team_id'  => ($d['parent_team_id'] ?? '') !== '' ? (int) $d['parent_team_id'] : null,
                'name'            => trim($d['name']),
                'kind'            => trim($d['kind'] ?? '') ?: 'care',
                'staff_only'      => !empty($d['staff_only']) ? 1 : 0,
            ]);
            Audit::record($orgId, $actor, 'team.created', 'team', $uuid, 'Created team ' . trim($d['name']));
            Events::emit($orgId, 'team.created', $uuid);
            return ['id' => $id, 'uuid' => $uuid];
        });
    }

    /** @param array<string,mixed> $d */
    public static function update(int $orgId, int $teamId, array $d, Actor $actor): void
    {
        $team = self::byId($orgId, $teamId) ?? throw new DomainException('Team not found.');
        if ($p = self::problems($orgId, $d, $teamId)) {
            throw DomainException::of($p);
        }
        DB::transaction(function () use ($orgId, $teamId, $d, $actor, $team) {
            DB::update('teams', [
                'parent_team_id' => ($d['parent_team_id'] ?? '') !== '' ? (int) $d['parent_team_id'] : null,
                'name'           => trim($d['name']),
                'kind'           => trim($d['kind'] ?? '') ?: 'care',
                'staff_only'     => !empty($d['staff_only']) ? 1 : 0,
            ], ['id' => $teamId, 'organisation_id' => $orgId]);
            Audit::record($orgId, $actor, 'team.updated', 'team', $team['uuid'], 'Updated team ' . trim($d['name']));
            Events::emit($orgId, 'team.updated', $team['uuid']);
        });
    }

    /** A team with active sub-teams cannot be closed: move or close them first. */
    public static function deactivate(int $orgId, int $teamId, Actor $actor): void
    {
        $team = self::byId($orgId, $teamId) ?? throw new DomainException('Team not found.');
        if (self::descendantIds($teamId)) {
            throw new DomainException('This team has active sub-teams. Move or close them first.');
        }
        DB::transaction(function () use ($orgId, $teamId, $actor, $team) {
            DB::update('teams', ['active' => 0], ['id' => $teamId, 'organisation_id' => $orgId]);
            // Closing a team ends everyone's time in it as of today: history is kept.
            DB::run('UPDATE team_assignments SET valid_to = ? WHERE team_id = ? AND valid_to IS NULL', [\App\Core\Clock::today(), $teamId]);
            Audit::record($orgId, $actor, 'team.closed', 'team', $team['uuid'], 'Closed team ' . $team['name']);
            Events::emit($orgId, 'team.closed', $team['uuid']);
        });
    }

    /** @return array<string,mixed>|null */
    public static function byId(int $orgId, int $id): ?array
    {
        return DB::one('SELECT * FROM teams WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
    }

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one('SELECT * FROM teams WHERE uuid = ? AND organisation_id = ?', [$uuid, $orgId]) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function all(int $orgId, bool $activeOnly = true): array
    {
        return DB::all('SELECT * FROM teams WHERE organisation_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY name', [$orgId]);
    }

    /** Active descendants (children, grandchildren, ...), safe against a loop. @return list<int> */
    public static function descendantIds(int $teamId): array
    {
        $ids = [];
        $queue = [$teamId];
        $seen = [$teamId => true];
        while ($queue) {
            $current = array_shift($queue);
            foreach (DB::all('SELECT id FROM teams WHERE parent_team_id = ? AND active = 1', [$current]) as $row) {
                $child = (int) $row['id'];
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $ids[] = $child;
                $queue[] = $child;
            }
        }
        return $ids;
    }

    /** Root-to-team path, safe against a loop. @return list<array<string,mixed>> */
    public static function path(int $teamId): array
    {
        $path = [];
        $seen = [];
        $id = $teamId;
        while ($id && !isset($seen[$id])) {
            $seen[$id] = true;
            $row = DB::one('SELECT id, uuid, name, parent_team_id FROM teams WHERE id = ?', [$id]);
            if (!$row) {
                break;
            }
            array_unshift($path, $row);
            $id = (int) $row['parent_team_id'];
        }
        return $path;
    }

    /** Teams as a tree, each with children. @return list<array<string,mixed>> */
    public static function tree(int $orgId): array
    {
        $all = self::all($orgId);
        $byParent = [];
        foreach ($all as $t) {
            $byParent[(int) $t['parent_team_id']][] = $t;
        }
        $build = static function (int $parent) use (&$build, &$byParent): array {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $t) {
                $t['children'] = $build((int) $t['id']);
                $out[] = $t;
            }
            return $out;
        };
        $ids = array_map(static fn($t) => (int) $t['id'], $all);
        $roots = [];
        foreach ($all as $t) {
            // A team whose parent is closed or missing is shown at the top rather than vanishing.
            if (!$t['parent_team_id'] || !in_array((int) $t['parent_team_id'], $ids, true)) {
                $t['children'] = $build((int) $t['id']);
                $roots[] = $t;
            }
        }
        return $roots;
    }
}
