<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;
use App\Modules\Identity\UserRepo;
use App\Modules\People\PersonRepo;
use App\Modules\Teams\AssignmentRepo;
use App\Modules\Teams\TeamRepo;

/**
 * Joiners, movers and leavers.
 *
 * This is the reason the system exists. Every one of these changes touches several things (the employment, team places,
 * key worker links, the login, checklists) and they must change TOGETHER or not at all, so each runs in one
 * transaction and writes its audit rows and events in that same transaction. Nothing is ever deleted: ending something
 * sets an end date, so the history of who was where, and when, is always there.
 */
final class Lifecycle
{
    public const CONTRACTS = ['permanent', 'fixed_term', 'zero_hours', 'bank', 'apprenticeship', 'agency'];

    // ── Joiner ──────────────────────────────────────────────────────────────

    /**
     * Someone starts. An existing person (a rehire) gets a NEW employment; a new person is created first.
     *
     * @param array<string,mixed> $d person_uuid | first_name, last_name, email ...; job_title, contract_type, hours_per_week, start_date,
     *                                employee_ref, line_manager_person_id, team_id, team_role, create_login, login_role
     * @return array{person_id:int,person_uuid:string,employment_id:int,employment_uuid:string,invite_token:?string,tasks:int,rehire:bool}
     */
    public static function join(int $orgId, array $d, Actor $actor): array
    {
        $start = ($d['start_date'] ?? '') ?: Clock::today();
        $msgs = Check::all([
            Check::required($d['job_title'] ?? null, 'Job title'), Check::maxLen($d['job_title'] ?? null, 150, 'Job title'),
            Check::oneOf(($d['contract_type'] ?? '') ?: 'permanent', self::CONTRACTS, 'a contract type'),
            Check::date($start, 'Start date', true),
            Check::maxLen($d['employee_ref'] ?? null, 50, 'Employee reference'),
            self::hoursProblem($d['hours_per_week'] ?? null),
        ]);
        if ($msgs) {
            throw DomainException::of($msgs);
        }

        return DB::transaction(function () use ($orgId, $d, $actor, $start) {
            $rehire = false;
            if (!empty($d['person_uuid'])) {
                $person = PersonRepo::byUuid($orgId, (string) $d['person_uuid']) ?? throw new DomainException('Person not found.');
                $personId = (int) $person['id'];
                $personUuid = $person['uuid'];
                $rehire = (bool) DB::value('SELECT 1 FROM employments WHERE person_id = ? LIMIT 1', [$personId]);
            } else {
                if (!empty($d['email']) && DB::value('SELECT 1 FROM persons WHERE organisation_id = ? AND email = ?', [$orgId, trim((string) $d['email'])])) {
                    throw new DomainException('Someone with that email address already exists. Find them in People and start a new employment for them instead.');
                }
                $created = PersonRepo::create($orgId, $d, $actor);
                $personId = $created['id'];
                $personUuid = $created['uuid'];
            }

            if (DB::value('SELECT 1 FROM employments WHERE person_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) FOR UPDATE', [$personId, $start, $start])) {
                throw new DomainException('This person is already employed on that date.');
            }
            $lastEnd = DB::value('SELECT MAX(end_date) FROM employments WHERE person_id = ?', [$personId]);
            if ($lastEnd && $start <= $lastEnd) {
                throw new DomainException('Their previous employment ended on ' . date('j M Y', strtotime((string) $lastEnd)) . ', so the new start date must be later.');
            }
            $ref = trim((string) ($d['employee_ref'] ?? '')) ?: null;
            if ($ref && DB::value('SELECT 1 FROM employments WHERE organisation_id = ? AND employee_ref = ?', [$orgId, $ref])) {
                throw new DomainException('That employee reference is already used.');
            }
            $managerId = self::managerId($orgId, $d['line_manager_person_id'] ?? null, $personId);

            $empUuid = Uuid::v4();
            $empId = DB::insert('employments', [
                'uuid' => $empUuid, 'organisation_id' => $orgId, 'person_id' => $personId, 'employee_ref' => $ref,
                'job_title' => trim($d['job_title']), 'contract_type' => ($d['contract_type'] ?? '') ?: 'permanent',
                'hours_per_week' => ($d['hours_per_week'] ?? '') !== '' ? (float) $d['hours_per_week'] : null,
                'start_date' => $start, 'line_manager_person_id' => $managerId,
            ]);

            if (!empty($d['team_id'])) {
                AssignmentRepo::assign($orgId, (int) $d['team_id'], $personId, ($d['team_role'] ?? '') ?: null, true, $start, $actor, 'joiner');
            }

            $token = null;
            if (!empty($d['create_login'])) {
                $email = trim((string) ($d['email'] ?? DB::value('SELECT email FROM persons WHERE id = ?', [$personId]) ?? ''));
                $existing = DB::one('SELECT id FROM users WHERE person_id = ?', [$personId]);
                if (!$existing) {
                    $created = UserRepo::create($orgId, $personId, $email, $d['login_role'] ?? 'staff', $actor, true);
                    $token = $created['invite_token'];
                } else {
                    DB::update('users', ['access_ends_on' => null], ['id' => $existing['id']]);   // a returning leaver gets their login back
                }
            }

            $tasks = Checklists::createTasks($orgId, 'joiner', $personId, $empId, $start);
            $name = trim($d['first_name'] ?? '') !== '' ? trim($d['first_name'] . ' ' . ($d['last_name'] ?? '')) : PersonRepo::displayName((array) PersonRepo::byId($orgId, $personId));
            Audit::record($orgId, $actor, $rehire ? 'employment.restarted' : 'employment.started', 'person', $personUuid, ($rehire ? 'Rehired ' : 'Started ') . $name . ' as ' . trim($d['job_title']), ['employment' => $empUuid, 'start_date' => $start]);
            Events::emit($orgId, 'employment.started', $personUuid, ['employment' => $empUuid, 'start_date' => $start, 'rehire' => $rehire]);

            return ['person_id' => $personId, 'person_uuid' => $personUuid, 'employment_id' => $empId, 'employment_uuid' => $empUuid, 'invite_token' => $token, 'tasks' => $tasks, 'rehire' => $rehire];
        });
    }

    // ── Mover ───────────────────────────────────────────────────────────────

    /**
     * A change of role, contract, hours, line manager or team, effective on a date (today or earlier).
     *
     * @param array<string,mixed> $changes job_title, contract_type, hours_per_week, line_manager_person_id, new_team_id, new_team_role
     */
    public static function move(int $orgId, int $employmentId, array $changes, string $effective, Actor $actor): void
    {
        if ($m = Check::date($effective, 'Effective date', true)) {
            throw new DomainException($m);
        }
        if ($effective > Clock::today()) {
            throw new DomainException('A change of role must take effect today or earlier. Record future-dated changes on the day they happen.');
        }
        DB::transaction(function () use ($orgId, $employmentId, $changes, $effective, $actor) {
            $emp = self::lockActive($orgId, $employmentId, $effective);
            $person = PersonRepo::byId($orgId, (int) $emp['person_id']);
            $log = [];
            $set = [];

            if (($changes['job_title'] ?? '') !== '' && trim($changes['job_title']) !== $emp['job_title']) {
                if ($m = Check::maxLen($changes['job_title'], 150, 'Job title')) {
                    throw new DomainException($m);
                }
                $set['job_title'] = trim($changes['job_title']);
                $log[] = ['job_title', $emp['job_title'], $set['job_title']];
            }
            if (($changes['contract_type'] ?? '') !== '' && $changes['contract_type'] !== $emp['contract_type']) {
                if ($m = Check::oneOf($changes['contract_type'], self::CONTRACTS, 'a contract type')) {
                    throw new DomainException($m);
                }
                $set['contract_type'] = $changes['contract_type'];
                $log[] = ['contract_type', $emp['contract_type'], $set['contract_type']];
            }
            if (($changes['hours_per_week'] ?? '') !== '') {
                if ($m = self::hoursProblem($changes['hours_per_week'])) {
                    throw new DomainException($m);
                }
                if ((float) $changes['hours_per_week'] !== (float) $emp['hours_per_week']) {
                    $set['hours_per_week'] = (float) $changes['hours_per_week'];
                    $log[] = ['hours_per_week', (string) $emp['hours_per_week'], (string) $set['hours_per_week']];
                }
            }
            if (array_key_exists('line_manager_person_id', $changes) && $changes['line_manager_person_id'] !== '' && (int) $changes['line_manager_person_id'] !== (int) $emp['line_manager_person_id']) {
                $set['line_manager_person_id'] = self::managerId($orgId, $changes['line_manager_person_id'], (int) $emp['person_id']);
                $log[] = ['line_manager', (string) $emp['line_manager_person_id'], (string) $set['line_manager_person_id']];
            }

            $newTeam = !empty($changes['new_team_id']) ? (int) $changes['new_team_id'] : null;
            if ($newTeam !== null) {
                $team = TeamRepo::byId($orgId, $newTeam) ?? throw new DomainException('Team not found.');
                $dayBefore = date('Y-m-d', strtotime($effective . ' -1 day'));
                // Leave the teams they were primary in; keep any others (a person can be in several teams).
                $old = DB::all('SELECT team_id, valid_from FROM team_assignments WHERE person_id = ? AND valid_to IS NULL AND is_primary = 1 AND team_id <> ?', [(int) $emp['person_id'], $newTeam]);
                foreach ($old as $o) {
                    AssignmentRepo::end($orgId, (int) $o['team_id'], (int) $emp['person_id'], max($dayBefore, (string) $o['valid_from']), $actor);
                }
                AssignmentRepo::assign($orgId, $newTeam, (int) $emp['person_id'], ($changes['new_team_role'] ?? '') ?: null, true, $effective, $actor, 'mover');
                $log[] = ['team', $old ? (string) $old[0]['team_id'] : null, (string) $newTeam];
            }
            if (!$log) {
                throw new DomainException('Nothing has changed.');
            }
            if ($set) {
                DB::update('employments', $set, ['id' => $employmentId]);
            }
            foreach ($log as [$field, $from, $to]) {
                DB::insert('employment_changes', ['employment_id' => $employmentId, 'effective_date' => $effective, 'field' => $field, 'from_value' => $from, 'to_value' => $to, 'changed_by' => $actor->userId]);
            }
            Checklists::createTasks($orgId, 'mover', (int) $emp['person_id'], $employmentId, $effective);
            Audit::record($orgId, $actor, 'employment.changed', 'person', $person['uuid'], 'Changed role details for ' . PersonRepo::displayName($person), ['employment' => $emp['uuid'], 'effective' => $effective, 'fields' => array_column($log, 0)]);
            Events::emit($orgId, 'employment.changed', $person['uuid'], ['employment' => $emp['uuid'], 'effective_date' => $effective, 'fields' => array_column($log, 0)]);
        });
    }

    // ── Leaver ──────────────────────────────────────────────────────────────

    /**
     * What ending this employment would do, without doing it. Shown before the user confirms.
     *
     * @return array{teams:list<string>,keyworkers:list<string>,reports:list<string>,logins:list<string>,tasks:int}
     */
    public static function previewLeave(int $orgId, int $employmentId, string $leavingDate): array
    {
        $emp = DB::one('SELECT * FROM employments WHERE id = ? AND organisation_id = ?', [$employmentId, $orgId]) ?? throw new DomainException('Employment not found.');
        $pid = (int) $emp['person_id'];
        return [
            'teams'      => array_column(DB::all('SELECT t.name FROM team_assignments a JOIN teams t ON t.id = a.team_id WHERE a.person_id = ? AND (a.valid_to IS NULL OR a.valid_to > ?) ORDER BY t.name', [$pid, $leavingDate]), 'name'),
            'keyworkers' => array_map(static fn($r) => $r['first_name'] . ' ' . $r['last_name'], DB::all('SELECT p.first_name, p.last_name FROM key_worker_assignments k JOIN persons p ON p.id = k.supported_person_id WHERE k.staff_person_id = ? AND (k.valid_to IS NULL OR k.valid_to > ?) ORDER BY p.last_name', [$pid, $leavingDate])),
            'reports'    => array_map(static fn($r) => $r['first_name'] . ' ' . $r['last_name'], DB::all('SELECT p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.line_manager_person_id = ? AND e.end_date IS NULL ORDER BY p.last_name', [$pid])),
            'logins'     => array_column(DB::all('SELECT email FROM users WHERE person_id = ? AND (access_ends_on IS NULL OR access_ends_on > ?)', [$pid, $leavingDate]), 'email'),
            'tasks'      => (int) DB::value("SELECT COUNT(*) FROM checklist_template_items i JOIN checklist_templates t ON t.id = i.template_id WHERE t.organisation_id = ? AND t.kind = 'leaver' AND t.active = 1", [$orgId]),
        ];
    }

    /**
     * Someone leaves on a date (their last day). In ONE transaction: the employment ends, team places end, key worker
     * links end (with a task to reassign each person), their login stops working after that day, and the offboarding
     * checklist is created.
     *
     * @return array{teams:int,keyworkers:int,reports:int,logins:int,tasks:int}
     */
    public static function leave(int $orgId, int $employmentId, string $leavingDate, string $reason, Actor $actor): array
    {
        if ($m = Check::first([Check::date($leavingDate, 'Last day', true), Check::maxLen($reason, 80, 'Reason')])) {
            throw new DomainException($m);
        }
        return DB::transaction(function () use ($orgId, $employmentId, $leavingDate, $reason, $actor) {
            $emp = DB::one('SELECT * FROM employments WHERE id = ? AND organisation_id = ? FOR UPDATE', [$employmentId, $orgId]) ?? throw new DomainException('Employment not found.');
            if ($emp['end_date'] !== null) {
                throw new DomainException('This employment has already ended on ' . date('j M Y', strtotime((string) $emp['end_date'])) . '.');
            }
            if ($leavingDate < $emp['start_date']) {
                throw new DomainException('The last day cannot be before they started on ' . date('j M Y', strtotime((string) $emp['start_date'])) . '.');
            }
            $person = PersonRepo::byId($orgId, (int) $emp['person_id']);
            $pid = (int) $emp['person_id'];
            $name = PersonRepo::displayName($person);

            DB::update('employments', ['end_date' => $leavingDate, 'leaving_reason' => $reason !== '' ? $reason : null], ['id' => $employmentId]);

            // Team places: end on the last day (places that had not started yet are cancelled).
            $teams = DB::all('SELECT a.id, a.team_id, a.valid_from, t.uuid AS team_uuid FROM team_assignments a JOIN teams t ON t.id = a.team_id WHERE a.person_id = ? AND (a.valid_to IS NULL OR a.valid_to > ?) FOR UPDATE', [$pid, $leavingDate]);
            foreach ($teams as $t) {
                if ($t['valid_from'] > $leavingDate) {
                    DB::run('DELETE FROM team_assignments WHERE id = ?', [$t['id']]);
                } else {
                    DB::update('team_assignments', ['valid_to' => $leavingDate, 'is_primary' => 0], ['id' => $t['id']]);
                }
                Events::emit($orgId, 'team_assignment.ended', $person['uuid'], ['team' => $t['team_uuid'], 'valid_to' => $leavingDate, 'cause' => 'employment_ended']);
            }

            // Key worker links: end, and make sure each person we support gets a new key worker.
            $kws = DB::all('SELECT k.id, k.supported_person_id, k.valid_from, s.uuid AS supported_uuid, s.first_name, s.last_name FROM key_worker_assignments k JOIN persons s ON s.id = k.supported_person_id WHERE k.staff_person_id = ? AND (k.valid_to IS NULL OR k.valid_to > ?) FOR UPDATE', [$pid, $leavingDate]);
            foreach ($kws as $k) {
                if ($k['valid_from'] > $leavingDate) {
                    DB::run('DELETE FROM key_worker_assignments WHERE id = ?', [$k['id']]);
                } else {
                    DB::update('key_worker_assignments', ['valid_to' => $leavingDate], ['id' => $k['id']]);
                }
                Checklists::createTask($orgId, 'reassign', (int) $k['supported_person_id'], $employmentId, 'Find a new key worker for ' . $k['first_name'] . ' ' . $k['last_name'], $name . ' leaves on ' . date('j M Y', strtotime($leavingDate)) . '.', $leavingDate);
                Events::emit($orgId, 'keyworker.ended', $k['supported_uuid'], ['staff' => $person['uuid'], 'valid_to' => $leavingDate, 'cause' => 'employment_ended']);
            }

            // People who report to them need a new line manager.
            $reports = DB::all('SELECT e.id, p.id AS pid, p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.line_manager_person_id = ? AND e.end_date IS NULL', [$pid]);
            foreach ($reports as $r) {
                Checklists::createTask($orgId, 'reassign', (int) $r['pid'], (int) $r['id'], 'New line manager for ' . $r['first_name'] . ' ' . $r['last_name'], $name . ' leaves on ' . date('j M Y', strtotime($leavingDate)) . '.', $leavingDate);
            }

            // The login stops working after the last day, with no job to run.
            $logins = UserRepo::setAccessEnd($orgId, $pid, $leavingDate, $actor);

            $tasks = Checklists::createTasks($orgId, 'leaver', $pid, $employmentId, $leavingDate);

            Audit::record($orgId, $actor, 'employment.ended', 'person', $person['uuid'], $name . ' leaves on ' . date('j M Y', strtotime($leavingDate)) . ($reason !== '' ? ' (' . $reason . ')' : ''), [
                'employment' => $emp['uuid'], 'last_day' => $leavingDate, 'teams_ended' => count($teams), 'keyworkers_ended' => count($kws), 'logins' => $logins,
            ]);
            Events::emit($orgId, 'employment.ended', $person['uuid'], ['employment' => $emp['uuid'], 'end_date' => $leavingDate, 'reason' => $reason !== '' ? $reason : null]);

            return ['teams' => count($teams), 'keyworkers' => count($kws), 'reports' => count($reports), 'logins' => $logins, 'tasks' => $tasks];
        });
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> every stint of employment, newest first */
    public static function employments(int $personId): array
    {
        return DB::all(
            'SELECT e.*, m.uuid AS manager_uuid, m.first_name AS manager_first, m.last_name AS manager_last FROM employments e
             LEFT JOIN persons m ON m.id = e.line_manager_person_id WHERE e.person_id = ? ORDER BY e.start_date DESC',
            [$personId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function currentEmployment(int $personId, ?string $on = null): ?array
    {
        $on ??= Clock::today();
        return DB::one('SELECT * FROM employments WHERE person_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) ORDER BY start_date DESC LIMIT 1', [$personId, $on, $on]);
    }

    /** @return list<array<string,mixed>> */
    public static function changes(int $employmentId): array
    {
        return DB::all('SELECT * FROM employment_changes WHERE employment_id = ? ORDER BY effective_date DESC, id DESC', [$employmentId]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function lockActive(int $orgId, int $employmentId, string $on): array
    {
        $emp = DB::one('SELECT * FROM employments WHERE id = ? AND organisation_id = ? FOR UPDATE', [$employmentId, $orgId]) ?? throw new DomainException('Employment not found.');
        if ($emp['start_date'] > $on || ($emp['end_date'] !== null && $emp['end_date'] < $on)) {
            throw new DomainException('That employment is not active on ' . date('j M Y', strtotime($on)) . '.');
        }
        return $emp;
    }

    private static function managerId(int $orgId, mixed $managerPersonId, int $personId): ?int
    {
        if ($managerPersonId === null || $managerPersonId === '') {
            return null;
        }
        $m = (int) $managerPersonId;
        if ($m === $personId) {
            throw new DomainException('Someone cannot be their own line manager.');
        }
        if (!DB::value('SELECT 1 FROM persons WHERE id = ? AND organisation_id = ?', [$m, $orgId])) {
            throw new DomainException('Choose a line manager from the list.');
        }
        return $m;
    }

    private static function hoursProblem(mixed $hours): ?string
    {
        if ($hours === null || $hours === '') {
            return null;
        }
        return (is_numeric($hours) && (float) $hours > 0 && (float) $hours <= 168) ? null : 'Hours per week must be more than 0 and no more than 168.';
    }
}
