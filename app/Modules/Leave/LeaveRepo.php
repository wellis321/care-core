<?php
declare(strict_types=1);

namespace App\Modules\Leave;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;
use App\Modules\Staff\Checklists;

/**
 * Leave and absence.
 *
 *  - Annual, other paid and unpaid leave are requested and decided by someone other than the person.
 *  - Sickness is recorded (by the person or a manager) and needs no approval; it may be open-ended until they are back.
 *  - Days are working days Monday to Friday. Bank holidays are NOT taken off (we do not guess a calendar): the approver
 *    can correct the figure when deciding. Half days count as 0.5.
 *  - Events leave out the kind of absence: other systems learn that someone is away, not why.
 */
final class LeaveRepo
{
    public const KINDS = ['annual' => 'Annual leave', 'sickness' => 'Sickness absence', 'other_paid' => 'Other paid leave', 'unpaid' => 'Unpaid leave'];
    public const REQUESTABLE = ['annual', 'other_paid', 'unpaid'];
    private const FAR = '9999-12-31';

    /** Monday to Friday between two dates, with half days at either end. */
    public static function workingDays(string $start, string $end, bool $startHalf = false, bool $endHalf = false): float
    {
        if ($end < $start) {
            return 0.0;
        }
        $n = 0.0;
        for ($d = new \DateTimeImmutable($start), $last = new \DateTimeImmutable($end); $d <= $last; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') <= 5) {
                $n += 1.0;
            }
        }
        $weekday = static fn(string $x): bool => (int) (new \DateTimeImmutable($x))->format('N') <= 5;
        if ($start === $end) {
            return ($startHalf || $endHalf) && $weekday($start) ? 0.5 : $n;
        }
        if ($startHalf && $weekday($start)) {
            $n -= 0.5;
        }
        if ($endHalf && $weekday($end)) {
            $n -= 0.5;
        }
        return $n;
    }

    /** Working days of an absence that fall between two dates (an open sickness counts to today). */
    public static function daysWithin(array $a, string $from, string $to): float
    {
        $end = $a['ends_on'] ?: min(Clock::today(), $to);
        $s = max($a['starts_on'], $from);
        $e = min($end, $to);
        if ($e < $s) {
            return 0.0;
        }
        return self::workingDays($s, $e, $s === $a['starts_on'] && (bool) $a['start_half'], $e === $end && (bool) $a['end_half']);
    }

    /** @param array<string,mixed> $d kind, starts_on, ends_on, start_half, end_half, reason @return list<string> */
    public static function problems(array $d, bool $manager): array
    {
        $kind = $d['kind'] ?? '';
        $open = $kind === 'sickness' && ($d['ends_on'] ?? '') === '';
        $msgs = [
            Check::oneOf($kind, array_keys(self::KINDS), 'a kind of leave'),
            Check::date($d['starts_on'] ?? null, 'First day', true),
            $open ? null : Check::date($d['ends_on'] ?? null, 'Last day', true),
            Check::maxLen($d['reason'] ?? null, 500, 'Reason'),
        ];
        if (!Check::first($msgs) && !$open && $d['ends_on'] < $d['starts_on']) {
            $msgs[] = 'The last day cannot be before the first day.';
        }
        if (!Check::first($msgs) && !$open && (new \DateTimeImmutable($d['starts_on']))->diff(new \DateTimeImmutable($d['ends_on']))->days > 366) {
            $msgs[] = 'That is longer than a year. Please split it.';
        }
        if (!Check::first($msgs) && !$manager && $kind !== 'sickness' && $d['starts_on'] < Clock::today()) {
            $msgs[] = 'Leave must start today or later. Ask a manager to record leave that has already happened.';
        }
        if (!Check::first($msgs) && $kind === 'sickness' && $d['starts_on'] > Clock::today()) {
            $msgs[] = 'Sickness cannot start in the future.';
        }
        return Check::all($msgs);
    }

    /**
     * Ask for leave, or record sickness. A manager recording on someone's behalf puts it straight to approved.
     *
     * @param array<string,mixed> $d
     * @return array{id:int,uuid:string,status:string,days:?float}
     */
    public static function create(int $orgId, int $personId, array $d, Actor $actor, bool $manager = false): array
    {
        if (!DB::value('SELECT 1 FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId])) {
            throw new DomainException('Person not found.');
        }
        $d['ends_on'] = (string) ($d['ends_on'] ?? '');
        if ($p = self::problems($d, $manager)) {
            throw DomainException::of($p);
        }
        $open = $d['kind'] === 'sickness' && $d['ends_on'] === '';
        $end = $open ? null : $d['ends_on'];
        $startHalf = !empty($d['start_half']) ? 1 : 0;
        $endHalf = !empty($d['end_half']) ? 1 : 0;
        $days = $open ? null : self::workingDays($d['starts_on'], $end, (bool) $startHalf, (bool) $endHalf);
        if (!$open && $days <= 0) {
            throw new DomainException('That is only a weekend, so there are no working days to take. Pick days from Monday to Friday.');
        }
        if (self::overlaps($personId, $d['starts_on'], $end ?? self::FAR, null)) {
            throw new DomainException('There is already leave or absence booked on some of those days.');
        }
        $status = ($d['kind'] === 'sickness' || $manager) ? 'approved' : 'requested';
        return DB::transaction(function () use ($orgId, $personId, $d, $actor, $end, $startHalf, $endHalf, $days, $status, $manager) {
            $uuid = Uuid::v4();
            $id = DB::insert('absences', [
                'uuid' => $uuid, 'organisation_id' => $orgId, 'person_id' => $personId, 'kind' => $d['kind'], 'starts_on' => $d['starts_on'], 'ends_on' => $end,
                'start_half' => $startHalf, 'end_half' => $endHalf, 'days' => $days, 'status' => $status,
                'reason' => trim((string) ($d['reason'] ?? '')) ?: null, 'requested_by_user_id' => $actor->userId,
                'decided_by_user_id' => $status === 'approved' && $d['kind'] !== 'sickness' ? $actor->userId : null, 'decided_at' => $status === 'approved' && $d['kind'] !== 'sickness' ? gmdate('Y-m-d H:i:s') : null,
            ]);
            $p = DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$personId]);
            $what = self::KINDS[$d['kind']];
            Audit::record($orgId, $actor, $status === 'requested' ? 'absence.requested' : 'absence.recorded', 'person', $p['uuid'], $what . ' ' . ($status === 'requested' ? 'requested' : 'recorded') . ' for ' . $p['first_name'] . ' ' . $p['last_name'] . ': ' . $d['starts_on'] . ($end ? ' to ' . $end : ' (ongoing)'), ['absence' => $uuid]);
            Events::emit($orgId, $status === 'requested' ? 'absence.requested' : 'absence.decided', $uuid, ['person' => $p['uuid'], 'starts_on' => $d['starts_on'], 'ends_on' => $end, 'status' => $status]);
            return ['id' => $id, 'uuid' => $uuid, 'status' => $status, 'days' => $days];
        });
    }

    /**
     * A manager (or administrator) decides a request. Nobody decides their own. Declining needs a reason.
     * The approver may correct the number of days (for example to take off a bank holiday).
     */
    public static function decide(int $orgId, string $uuid, bool $approve, string $note, ?string $days, int $deciderUserId, ?int $deciderPersonId, Actor $actor): void
    {
        $a = self::byUuid($orgId, $uuid) ?? throw new DomainException('Request not found.');
        if ($a['status'] !== 'requested') {
            throw new DomainException('That request has already been dealt with.');
        }
        if ($deciderPersonId !== null && (int) $a['person_id'] === $deciderPersonId) {
            throw new DomainException('You cannot decide your own leave. Ask another manager.');
        }
        if (!$approve && trim($note) === '') {
            throw new DomainException('Say why it is declined, so the person knows what to do next.');
        }
        if ($m = Check::maxLen($note, 500, 'Note')) {
            throw new DomainException($m);
        }
        $set = ['status' => $approve ? 'approved' : 'declined', 'decision_note' => trim($note) ?: null, 'decided_by_user_id' => $deciderUserId, 'decided_at' => gmdate('Y-m-d H:i:s')];
        if ($approve && $days !== null && trim($days) !== '') {
            if (!is_numeric($days) || (float) $days <= 0 || (float) $days > 366 || fmod((float) $days * 2, 1.0) !== 0.0) {
                throw new DomainException('Days must be a number in halves, such as 4 or 4.5.');
            }
            $set['days'] = (float) $days;
        }
        DB::transaction(function () use ($orgId, $a, $set, $approve, $actor, $uuid) {
            DB::update('absences', $set, ['id' => $a['id']]);
            $p = DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$a['person_id']]);
            Audit::record($orgId, $actor, 'absence.' . ($approve ? 'approved' : 'declined'), 'person', $p['uuid'], self::KINDS[$a['kind']] . ' ' . ($approve ? 'approved' : 'declined') . ' for ' . $p['first_name'] . ' ' . $p['last_name'] . ': ' . $a['starts_on'] . ' to ' . $a['ends_on'], ['absence' => $uuid]);
            Events::emit($orgId, 'absence.decided', $uuid, ['person' => $p['uuid'], 'starts_on' => $a['starts_on'], 'ends_on' => $a['ends_on'], 'status' => $set['status']]);
        });
    }

    /** The person (or a manager) withdraws it. After it has started only a manager can. */
    public static function cancel(int $orgId, string $uuid, bool $isOwner, bool $isManager, Actor $actor): void
    {
        $a = self::byUuid($orgId, $uuid) ?? throw new DomainException('Request not found.');
        if (!in_array($a['status'], ['requested', 'approved'], true)) {
            throw new DomainException('That cannot be cancelled.');
        }
        $started = $a['starts_on'] <= Clock::today();
        $allowed = $isManager || ($isOwner && $a['kind'] !== 'sickness' && ($a['status'] === 'requested' || !$started));
        if (!$allowed) {
            throw new DomainException($isOwner ? 'Ask a manager to cancel that: it has already started or is sickness.' : 'You cannot cancel that.');
        }
        DB::transaction(function () use ($orgId, $a, $actor, $uuid) {
            DB::update('absences', ['status' => 'cancelled'], ['id' => $a['id']]);
            $p = DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$a['person_id']]);
            Audit::record($orgId, $actor, 'absence.cancelled', 'person', $p['uuid'], self::KINDS[$a['kind']] . ' cancelled for ' . $p['first_name'] . ' ' . $p['last_name'], ['absence' => $uuid]);
            Events::emit($orgId, 'absence.cancelled', $uuid, ['person' => $p['uuid'], 'starts_on' => $a['starts_on'], 'ends_on' => $a['ends_on']]);
        });
    }

    /** Someone is back. Closes an open sickness and, for sickness, raises a return-to-work task for a manager. */
    public static function endSickness(int $orgId, string $uuid, string $lastDay, Actor $actor): void
    {
        $a = self::byUuid($orgId, $uuid) ?? throw new DomainException('Absence not found.');
        if ($a['kind'] !== 'sickness' || $a['status'] !== 'approved' || $a['ends_on'] !== null) {
            throw new DomainException('Only sickness that is still open can be closed this way.');
        }
        if ($m = Check::date($lastDay, 'Last day off', true)) {
            throw new DomainException($m);
        }
        if ($lastDay < $a['starts_on']) {
            throw new DomainException('The last day cannot be before the first day.');
        }
        if ($lastDay > Clock::today()) {
            throw new DomainException('The last day off cannot be in the future. Leave it open until they are back.');
        }
        if (self::overlaps((int) $a['person_id'], $a['starts_on'], $lastDay, (int) $a['id'])) {
            throw new DomainException('That overlaps other leave or absence.');
        }
        $days = self::workingDays($a['starts_on'], $lastDay, (bool) $a['start_half'], false);
        DB::transaction(function () use ($orgId, $a, $lastDay, $days, $actor, $uuid) {
            DB::update('absences', ['ends_on' => $lastDay, 'days' => $days], ['id' => $a['id']]);
            $p = DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$a['person_id']]);
            Checklists::createTask($orgId, 'manual', (int) $a['person_id'], null, 'Return-to-work conversation with ' . $p['first_name'] . ' ' . $p['last_name'], 'They were off sick from ' . $a['starts_on'] . ' to ' . $lastDay . '. Hold the conversation, then tick “held” on the Leave page and record it under Appraisals as a return-to-work conversation.', null, 'manager');
            Audit::record($orgId, $actor, 'absence.closed', 'person', $p['uuid'], 'Sickness ended for ' . $p['first_name'] . ' ' . $p['last_name'] . ' (last day ' . $lastDay . ')', ['absence' => $uuid]);
            Events::emit($orgId, 'absence.decided', $uuid, ['person' => $p['uuid'], 'starts_on' => $a['starts_on'], 'ends_on' => $lastDay, 'status' => 'approved']);
        });
    }

    public static function returnToWorkHeld(int $orgId, string $uuid, string $on, Actor $actor): void
    {
        $a = self::byUuid($orgId, $uuid) ?? throw new DomainException('Absence not found.');
        if ($a['kind'] !== 'sickness' || $a['ends_on'] === null) {
            throw new DomainException('A return-to-work conversation follows sickness that has ended.');
        }
        if ($m = Check::date($on, 'Date held', true)) {
            throw new DomainException($m);
        }
        if ($on > Clock::today()) {
            throw new DomainException('The date held cannot be in the future.');
        }
        DB::update('absences', ['rtw_done_on' => $on], ['id' => $a['id']]);
        $p = DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$a['person_id']]);
        Audit::record($orgId, $actor, 'absence.rtw', 'person', $p['uuid'], 'Return-to-work conversation held with ' . $p['first_name'] . ' ' . $p['last_name'] . ' on ' . $on, ['absence' => $uuid]);
    }

    // ── Allowance and balance ──────────────────────────────────────────────

    public static function yearStartMonth(int $orgId): int
    {
        $m = (int) DB::value("SELECT setting_value FROM organisation_settings WHERE organisation_id = ? AND setting_key = 'leave_year_start_month'", [$orgId]);
        return $m >= 1 && $m <= 12 ? $m : 1;
    }

    public static function setYearStartMonth(int $orgId, int $month, Actor $actor): void
    {
        if ($month < 1 || $month > 12) {
            throw new DomainException('Choose a month.');
        }
        DB::run("INSERT INTO organisation_settings (organisation_id, setting_key, setting_value) VALUES (?, 'leave_year_start_month', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$orgId, (string) $month]);
        Audit::record($orgId, $actor, 'leave.year_start', 'organisation', null, 'Leave year now starts in month ' . $month);
    }

    /** @return array{0:string,1:string} first and last day of the leave year containing $on */
    public static function leaveYear(int $orgId, string $on): array
    {
        $m = self::yearStartMonth($orgId);
        $y = (int) substr($on, 0, 4);
        $start = sprintf('%04d-%02d-01', $y, $m);
        if ($on < $start) {
            $start = sprintf('%04d-%02d-01', $y - 1, $m);
        }
        $end = (new \DateTimeImmutable($start))->modify('+1 year -1 day')->format('Y-m-d');
        return [$start, $end];
    }

    public static function setAllowance(int $orgId, int $personId, string $on, string $days, ?string $note, Actor $actor): void
    {
        if (!DB::value('SELECT 1 FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId])) {
            throw new DomainException('Person not found.');
        }
        if (!is_numeric($days) || (float) $days < 0 || (float) $days > 366 || fmod((float) $days * 2, 1.0) !== 0.0) {
            throw new DomainException('The allowance must be a number of days in halves, from 0 to 366.');
        }
        if ($m = Check::maxLen($note, 200, 'Note')) {
            throw new DomainException($m);
        }
        [$start] = self::leaveYear($orgId, $on);
        DB::run('INSERT INTO leave_allowances (organisation_id, person_id, year_start, days, note) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE days = VALUES(days), note = VALUES(note)', [$orgId, $personId, $start, (float) $days, trim((string) $note) ?: null]);
        $p = DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$personId]);
        Audit::record($orgId, $actor, 'leave.allowance', 'person', $p['uuid'], 'Leave allowance for ' . $p['first_name'] . ' ' . $p['last_name'] . ' from ' . $start . ': ' . (float) $days . ' days');
    }

    /** @return array{year_start:string,year_end:string,allowance:?float,booked:float,pending:float,remaining:?float} */
    public static function balance(int $orgId, int $personId, ?string $on = null): array
    {
        $on ??= Clock::today();
        [$from, $to] = self::leaveYear($orgId, $on);
        $allow = DB::value('SELECT days FROM leave_allowances WHERE person_id = ? AND year_start = ?', [$personId, $from]);
        $booked = $pending = 0.0;
        foreach (DB::all("SELECT * FROM absences WHERE person_id = ? AND kind = 'annual' AND status IN ('requested','approved') AND starts_on <= ? AND ends_on >= ?", [$personId, $to, $from]) as $a) {
            $d = self::daysWithin($a, $from, $to);
            // an approver may have corrected the total: scale that to the part in this year
            $full = self::workingDays($a['starts_on'], $a['ends_on'], (bool) $a['start_half'], (bool) $a['end_half']);
            if ($full > 0 && $a['days'] !== null && abs((float) $a['days'] - $full) > 0.0001) {
                $d = $d * ((float) $a['days'] / $full);
            }
            if ($a['status'] === 'approved') {
                $booked += $d;
            } else {
                $pending += $d;
            }
        }
        return ['year_start' => $from, 'year_end' => $to, 'allowance' => $allow === null || $allow === false ? null : (float) $allow, 'booked' => round($booked, 1), 'pending' => round($pending, 1),
            'remaining' => ($allow === null || $allow === false) ? null : round((float) $allow - $booked, 1)];
    }

    /** Spells and working days of sickness in the 12 months to $on. A fact for a conversation, not a score. @return array{spells:int,days:float} */
    public static function sicknessSummary(int $personId, ?string $on = null): array
    {
        $on ??= Clock::today();
        $from = Clock::addDays((new \DateTimeImmutable($on))->modify('-1 year')->format('Y-m-d'), 1);
        $spells = 0;
        $days = 0.0;
        foreach (DB::all("SELECT * FROM absences WHERE person_id = ? AND kind = 'sickness' AND status = 'approved' AND starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?)", [$personId, $on, $from]) as $a) {
            $spells++;
            $days += self::daysWithin($a, $from, $on);
        }
        return ['spells' => $spells, 'days' => $days];
    }

    // ── Reading ────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one('SELECT * FROM absences WHERE uuid = ? AND organisation_id = ?', [$uuid, $orgId]) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function forPerson(int $personId, int $limit = 50): array
    {
        return DB::all('SELECT * FROM absences WHERE person_id = ? ORDER BY starts_on DESC, id DESC LIMIT ' . (int) $limit, [$personId]);
    }

    /** Requests waiting for a decision, oldest first. @return list<array<string,mixed>> */
    public static function pending(int $orgId): array
    {
        return DB::all("SELECT a.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM absences a JOIN persons p ON p.id = a.person_id WHERE a.organisation_id = ? AND a.status = 'requested' ORDER BY a.starts_on, a.id", [$orgId]);
    }

    /** Sickness still open. @return list<array<string,mixed>> */
    public static function openSickness(int $orgId): array
    {
        return DB::all("SELECT a.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM absences a JOIN persons p ON p.id = a.person_id WHERE a.organisation_id = ? AND a.kind = 'sickness' AND a.status = 'approved' AND a.ends_on IS NULL ORDER BY a.starts_on", [$orgId]);
    }

    /** Sickness that has ended and has no return-to-work conversation recorded. @return list<array<string,mixed>> */
    public static function awaitingReturnToWork(int $orgId): array
    {
        return DB::all("SELECT a.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM absences a JOIN persons p ON p.id = a.person_id WHERE a.organisation_id = ? AND a.kind = 'sickness' AND a.status = 'approved' AND a.ends_on IS NOT NULL AND a.rtw_done_on IS NULL ORDER BY a.ends_on DESC", [$orgId]);
    }

    /** Who is away on a date (approved only). @return list<array<string,mixed>> */
    public static function offOn(int $orgId, string $date): array
    {
        return DB::all("SELECT a.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM absences a JOIN persons p ON p.id = a.person_id
                        WHERE a.organisation_id = ? AND a.status = 'approved' AND a.starts_on <= ? AND (a.ends_on IS NULL OR a.ends_on >= ?) ORDER BY p.last_name, p.first_name", [$orgId, $date, $date]);
    }

    /**
     * A month at a glance: current staff (optionally one team) and the absences touching the month.
     *
     * @return array{people:list<array<string,mixed>>,absences:array<int,list<array<string,mixed>>>,days:int,first:string}
     */
    public static function month(int $orgId, string $firstOfMonth, ?int $teamId): array
    {
        $last = (new \DateTimeImmutable($firstOfMonth))->modify('last day of this month')->format('Y-m-d');
        $params = [$orgId, $last, $firstOfMonth];
        $team = '';
        if ($teamId) {
            $team = ' AND EXISTS (SELECT 1 FROM team_assignments ta WHERE ta.person_id = p.id AND ta.team_id = ? AND ta.valid_from <= ? AND (ta.valid_to IS NULL OR ta.valid_to >= ?))';
            array_push($params, $teamId, $last, $firstOfMonth);
        }
        $people = DB::all('SELECT DISTINCT p.id, p.uuid, p.first_name, p.last_name FROM persons p JOIN employments e ON e.person_id = p.id
                           WHERE p.organisation_id = ? AND e.start_date <= ? AND (e.end_date IS NULL OR e.end_date >= ?)' . $team . ' ORDER BY p.last_name, p.first_name', $params);
        $abs = [];
        if ($people) {
            $ids = implode(',', array_map('intval', array_column($people, 'id')));
            foreach (DB::all("SELECT * FROM absences WHERE person_id IN ($ids) AND status IN ('requested','approved') AND starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?)", [$last, $firstOfMonth]) as $a) {
                $abs[(int) $a['person_id']][] = $a;
            }
        }
        return ['people' => $people, 'absences' => $abs, 'days' => (int) substr($last, 8, 2), 'first' => $firstOfMonth];
    }

    /** @param int|null $except an absence to leave out of the check (when changing it) */
    private static function overlaps(int $personId, string $start, string $end, ?int $except): bool
    {
        return (bool) DB::value("SELECT 1 FROM absences WHERE person_id = ? AND status IN ('requested','approved') AND starts_on <= ? AND COALESCE(ends_on, '" . self::FAR . "') >= ?" . ($except ? ' AND id <> ' . (int) $except : '') . ' LIMIT 1', [$personId, $end, $start]);
    }
}
