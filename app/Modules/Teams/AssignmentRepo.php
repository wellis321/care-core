<?php
declare(strict_types=1);

namespace App\Modules\Teams;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;

/**
 * Who is in which team, and when. Every stint is its own row, so leaving and rejoining never overwrites earlier dates.
 * Rules: one active stint per person per team; staff-only teams refuse people supported; a staff member has one
 * primary team; a stint cannot start before the previous one ended.
 */
final class AssignmentRepo
{
    /** @return int the new assignment id (or the existing active one, refreshed) */
    public static function assign(int $orgId, int $teamId, int $personId, ?string $role, bool $primary, ?string $validFrom, Actor $actor, string $source = 'manual', ?string $notes = null): int
    {
        $validFrom = $validFrom ?: Clock::today();
        $team   = TeamRepo::byId($orgId, $teamId) ?? throw new DomainException('Team not found.');
        $person = DB::one('SELECT id, uuid, first_name, last_name FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId])
            ?? throw new DomainException('Person not found.');
        if (!$team['active']) {
            throw new DomainException('That team is closed.');
        }
        if ($msg = Check::first([Check::date($validFrom, 'Start date', true), Check::maxLen($role, 80, 'Role'), Check::maxLen($notes, 500, 'Notes')])) {
            throw new DomainException($msg);
        }
        if ($team['staff_only'] && self::isSupported($personId, $validFrom) && !self::isStaff($personId, $validFrom)) {
            throw new DomainException('This is a staff-only team, so people we support cannot be added to it.');
        }

        return DB::transaction(function () use ($orgId, $team, $person, $teamId, $personId, $role, $primary, $validFrom, $actor, $source, $notes) {
            $active = DB::one('SELECT id FROM team_assignments WHERE team_id = ? AND person_id = ? AND valid_to IS NULL FOR UPDATE', [$teamId, $personId]);
            if ($active) {
                DB::update('team_assignments', ['role' => $role ?: null, 'is_primary' => $primary ? 1 : 0, 'notes' => $notes ?: null], ['id' => $active['id']]);
                if ($primary) {
                    self::clearPrimaryElsewhere($orgId, $personId, $teamId);
                }
                Audit::record($orgId, $actor, 'assignment.updated', 'person', $person['uuid'], 'Updated ' . $person['first_name'] . ' ' . $person['last_name'] . ' in ' . $team['name']);
                return (int) $active['id'];
            }
            $lastEnd = DB::value('SELECT MAX(valid_to) FROM team_assignments WHERE team_id = ? AND person_id = ?', [$teamId, $personId]);
            if ($lastEnd && $validFrom < $lastEnd) {
                throw new DomainException('They left this team on ' . date('j M Y', strtotime((string) $lastEnd)) . ', so the new start date cannot be earlier.');
            }
            $id = DB::insert('team_assignments', [
                'organisation_id' => $orgId, 'team_id' => $teamId, 'person_id' => $personId, 'role' => $role ?: null,
                'is_primary' => $primary ? 1 : 0, 'valid_from' => $validFrom, 'source' => $source, 'notes' => $notes ?: null,
            ]);
            if ($primary) {
                self::clearPrimaryElsewhere($orgId, $personId, $teamId);
            }
            Audit::record($orgId, $actor, 'assignment.started', 'person', $person['uuid'], 'Added ' . $person['first_name'] . ' ' . $person['last_name'] . ' to ' . $team['name'], ['team' => $team['uuid'], 'from' => $validFrom]);
            Events::emit($orgId, 'team_assignment.started', $person['uuid'], ['team' => $team['uuid'], 'valid_from' => $validFrom]);
            return $id;
        });
    }

    public static function end(int $orgId, int $teamId, int $personId, ?string $validTo, Actor $actor): bool
    {
        $validTo = $validTo ?: Clock::today();
        if ($msg = Check::date($validTo, 'Leaving date', true)) {
            throw new DomainException($msg);
        }
        $team   = TeamRepo::byId($orgId, $teamId) ?? throw new DomainException('Team not found.');
        $person = DB::one('SELECT id, uuid, first_name, last_name FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId])
            ?? throw new DomainException('Person not found.');
        return DB::transaction(function () use ($orgId, $team, $person, $teamId, $personId, $validTo, $actor) {
            $row = DB::one('SELECT id, valid_from FROM team_assignments WHERE team_id = ? AND person_id = ? AND valid_to IS NULL FOR UPDATE', [$teamId, $personId]);
            if (!$row) {
                return false;
            }
            if ($validTo < $row['valid_from']) {
                throw new DomainException('The leaving date cannot be before they joined on ' . date('j M Y', strtotime((string) $row['valid_from'])) . '.');
            }
            DB::update('team_assignments', ['valid_to' => $validTo, 'is_primary' => 0], ['id' => $row['id']]);
            Audit::record($orgId, $actor, 'assignment.ended', 'person', $person['uuid'], 'Removed ' . $person['first_name'] . ' ' . $person['last_name'] . ' from ' . $team['name'], ['team' => $team['uuid'], 'to' => $validTo]);
            Events::emit($orgId, 'team_assignment.ended', $person['uuid'], ['team' => $team['uuid'], 'valid_to' => $validTo]);
            return true;
        });
    }

    /** Active members of a team on a date. @return list<array<string,mixed>> */
    public static function members(int $teamId, ?string $on = null): array
    {
        $on ??= Clock::today();
        return DB::all(
            "SELECT a.*, p.uuid AS person_uuid, p.first_name, p.last_name,
                    EXISTS(SELECT 1 FROM employments e WHERE e.person_id = p.id AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?)) AS is_staff
             FROM team_assignments a JOIN persons p ON p.id = a.person_id
             WHERE a.team_id = ? AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?)
             ORDER BY is_staff DESC, p.last_name, p.first_name",
            [$on, $on, $teamId, $on, $on]
        );
    }

    /** Past stints in a team, most recent first. @return list<array<string,mixed>> */
    public static function past(int $teamId, ?string $on = null): array
    {
        $on ??= Clock::today();
        return DB::all(
            "SELECT a.*, p.uuid AS person_uuid, p.first_name, p.last_name
             FROM team_assignments a JOIN persons p ON p.id = a.person_id
             WHERE a.team_id = ? AND a.valid_to IS NOT NULL AND a.valid_to < ?
             ORDER BY a.valid_to DESC, a.valid_from DESC",
            [$teamId, $on]
        );
    }

    /** Every stint a person has had, current first. @return list<array<string,mixed>> */
    public static function forPerson(int $personId): array
    {
        return DB::all(
            'SELECT a.*, t.uuid AS team_uuid, t.name AS team_name FROM team_assignments a JOIN teams t ON t.id = a.team_id
             WHERE a.person_id = ? ORDER BY (a.valid_to IS NULL) DESC, a.valid_from DESC',
            [$personId]
        );
    }

    private static function clearPrimaryElsewhere(int $orgId, int $personId, int $exceptTeamId): void
    {
        DB::run('UPDATE team_assignments SET is_primary = 0 WHERE organisation_id = ? AND person_id = ? AND team_id <> ? AND is_primary = 1 AND valid_to IS NULL', [$orgId, $personId, $exceptTeamId]);
    }

    private static function isStaff(int $personId, string $on): bool
    {
        return (bool) DB::value('SELECT 1 FROM employments WHERE person_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) LIMIT 1', [$personId, $on, $on]);
    }

    private static function isSupported(int $personId, string $on): bool
    {
        return (bool) DB::value('SELECT 1 FROM support_episodes WHERE person_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) LIMIT 1', [$personId, $on, $on]);
    }
}
