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

/**
 * Two colleagues swap approved annual leave: A takes B's dates and B takes A's. Each keeps their own number of days, so
 * allowances do not change (the two must be the same number of working days). The colleague agrees, then a manager who is
 * neither of them decides. On approval the dates change places on the two existing leave records.
 */
final class LeaveSwap
{
    /** Approved annual leave that has not started, is not part of an open swap, and so can be swapped. @return list<array<string,mixed>> */
    public static function swappable(int $orgId, int $personId): array
    {
        return DB::all("SELECT a.* FROM absences a WHERE a.organisation_id = ? AND a.person_id = ? AND a.kind = 'annual' AND a.status = 'approved' AND a.ends_on IS NOT NULL AND a.starts_on > ?
            AND NOT EXISTS (SELECT 1 FROM leave_swaps w WHERE w.status IN ('requested','accepted') AND (w.from_absence_id = a.id OR w.to_absence_id = a.id)) ORDER BY a.starts_on", [$orgId, $personId, Clock::today()]);
    }

    /** People (other than this one) who have leave that could be swapped. @return list<array<string,mixed>> */
    public static function colleagues(int $orgId, int $personId): array
    {
        return DB::all("SELECT DISTINCT p.id, p.uuid, p.first_name, p.last_name FROM persons p JOIN absences a ON a.person_id = p.id WHERE p.organisation_id = ? AND p.id <> ? AND a.kind = 'annual' AND a.status = 'approved' AND a.ends_on IS NOT NULL AND a.starts_on > ?
            AND NOT EXISTS (SELECT 1 FROM leave_swaps w WHERE w.status IN ('requested','accepted') AND (w.from_absence_id = a.id OR w.to_absence_id = a.id)) ORDER BY p.last_name, p.first_name", [$orgId, $personId, Clock::today()]);
    }

    /** Why this pair of leave records cannot be swapped, or null. */
    private static function blocker(array $mine, array $theirs): ?string
    {
        foreach ([$mine, $theirs] as $a) {
            if ($a['kind'] !== 'annual' || $a['status'] !== 'approved' || $a['ends_on'] === null) return 'Only approved annual leave can be swapped, and it must still be approved.';
            if ($a['starts_on'] <= Clock::today()) return 'Leave that has already started cannot be swapped.';
        }
        if (abs((float) $mine['days'] - (float) $theirs['days']) > 0.0001) {
            return 'The two spells must be the same number of working days (' . rtrim(rtrim(number_format((float) $mine['days'], 1), '0'), '.') . ' and ' . rtrim(rtrim(number_format((float) $theirs['days'], 1), '0'), '.') . '), so nobody\'s allowance changes.';
        }
        if (self::clashes((int) $mine['person_id'], $theirs, (int) $mine['id'])) return 'The dates would clash with other leave or absence that the person asking already has.';
        if (self::clashes((int) $theirs['person_id'], $mine, (int) $theirs['id'])) return 'The dates would clash with other leave or absence that the colleague already has.';
        return null;
    }

    /** Would a person, after giving up $giving, have other leave on the dates of $taking? */
    private static function clashes(int $personId, array $taking, int $giving): bool
    {
        return (bool) DB::value("SELECT 1 FROM absences WHERE person_id = ? AND id <> ? AND status IN ('requested','approved') AND starts_on <= ? AND COALESCE(ends_on, '9999-12-31') >= ? LIMIT 1", [$personId, $giving, $taking['ends_on'], $taking['starts_on']]);
    }

    public static function propose(int $orgId, int $personId, string $mineUuid, string $theirUuid, string $note, Actor $actor): string
    {
        $mine = LeaveRepo::byUuid($orgId, $mineUuid); $theirs = LeaveRepo::byUuid($orgId, $theirUuid);
        if (!$mine || (int) $mine['person_id'] !== $personId) throw new DomainException('Choose one of your own approved annual leave spells.');
        if (!$theirs || (int) $theirs['person_id'] === $personId) throw new DomainException('Choose a colleague\'s leave to swap with.');
        if ($m = Check::maxLen($note, 300, 'Note')) throw new DomainException($m);
        if ($b = self::blocker($mine, $theirs)) throw new DomainException($b);
        foreach ([$mine['id'], $theirs['id']] as $id) {
            if (DB::value("SELECT 1 FROM leave_swaps WHERE status IN ('requested','accepted') AND (from_absence_id = ? OR to_absence_id = ?)", [$id, $id])) throw new DomainException('One of those spells is already part of a swap request.');
        }
        return DB::transaction(function () use ($orgId, $personId, $mine, $theirs, $note, $actor) {
            $uuid = Uuid::v4();
            DB::insert('leave_swaps', ['uuid' => $uuid, 'organisation_id' => $orgId, 'from_absence_id' => $mine['id'], 'to_absence_id' => $theirs['id'], 'from_person_id' => $personId, 'to_person_id' => $theirs['person_id'], 'note' => trim($note) ?: null]);
            $p = DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$personId]);
            Audit::record($orgId, $actor, 'leave_swap.requested', 'person', $p['uuid'], $p['first_name'] . ' ' . $p['last_name'] . ' asked to swap annual leave: ' . $mine['starts_on'] . ' to ' . $mine['ends_on'] . ' for ' . $theirs['starts_on'] . ' to ' . $theirs['ends_on'], ['swap' => $uuid]);
            return $uuid;
        });
    }

    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one(self::SELECT . ' WHERE w.uuid = ? AND w.organisation_id = ?', [$uuid, $orgId]) : null;
    }

    private const SELECT = "SELECT w.*, fa.starts_on AS f_start, fa.ends_on AS f_end, fa.days AS f_days, ta.starts_on AS t_start, ta.ends_on AS t_end, ta.days AS t_days,
            fp.first_name AS f_first, fp.last_name AS f_last, fp.uuid AS f_uuid, tp.first_name AS t_first, tp.last_name AS t_last, tp.uuid AS t_uuid
        FROM leave_swaps w JOIN absences fa ON fa.id = w.from_absence_id JOIN absences ta ON ta.id = w.to_absence_id JOIN persons fp ON fp.id = w.from_person_id JOIN persons tp ON tp.id = w.to_person_id";

    /** Open requests that involve this person (asked of them, or asked by them). @return list<array<string,mixed>> */
    public static function forPerson(int $orgId, int $personId): array
    {
        return DB::all(self::SELECT . " WHERE w.organisation_id = ? AND (w.from_person_id = ? OR w.to_person_id = ?) AND w.status IN ('requested','accepted') ORDER BY w.created_at", [$orgId, $personId, $personId]);
    }

    /** Accepted by the colleague, waiting for a manager. @return list<array<string,mixed>> */
    public static function toDecide(int $orgId): array
    {
        return DB::all(self::SELECT . " WHERE w.organisation_id = ? AND w.status = 'accepted' ORDER BY w.created_at", [$orgId]);
    }

    public static function answer(int $orgId, int $personId, string $uuid, bool $accept, Actor $actor): void
    {
        $w = self::byUuid($orgId, $uuid);
        if (!$w || (int) $w['to_person_id'] !== $personId) throw new DomainException('That request is not for you.');
        if ($w['status'] !== 'requested') throw new DomainException('It has already been answered.');
        if ($accept && ($b = self::blocker(DB::one('SELECT * FROM absences WHERE id = ?', [$w['from_absence_id']]), DB::one('SELECT * FROM absences WHERE id = ?', [$w['to_absence_id']])))) throw new DomainException($b);
        DB::transaction(function () use ($orgId, $w, $accept, $actor, $uuid) {
            DB::update('leave_swaps', ['status' => $accept ? 'accepted' : 'declined'], ['id' => $w['id']]);
            Audit::record($orgId, $actor, 'leave_swap.' . ($accept ? 'accepted' : 'declined'), 'person', $w['t_uuid'], $w['t_first'] . ' ' . $w['t_last'] . ($accept ? ' agreed to' : ' declined') . ' the annual leave swap with ' . $w['f_first'] . ' ' . $w['f_last'], ['swap' => $uuid]);
        });
    }

    public static function cancel(int $orgId, int $personId, string $uuid, Actor $actor): void
    {
        $w = self::byUuid($orgId, $uuid);
        if (!$w || (int) $w['from_person_id'] !== $personId) throw new DomainException('That is not your request.');
        if (!in_array($w['status'], ['requested', 'accepted'], true)) throw new DomainException('It has already been dealt with.');
        DB::transaction(function () use ($orgId, $w, $actor, $uuid) {
            DB::update('leave_swaps', ['status' => 'cancelled'], ['id' => $w['id']]);
            Audit::record($orgId, $actor, 'leave_swap.cancelled', 'person', $w['f_uuid'], 'The annual leave swap was withdrawn', ['swap' => $uuid]);
        });
    }

    /** A manager who is neither person decides. Approving moves the dates: each leave record takes the other's dates. */
    public static function decide(int $orgId, string $uuid, bool $approve, string $note, int $deciderUserId, ?int $deciderPersonId, Actor $actor): void
    {
        $w = self::byUuid($orgId, $uuid) ?? throw new DomainException('Request not found.');
        if ($w['status'] !== 'accepted') throw new DomainException($w['status'] === 'requested' ? 'The colleague has not answered yet.' : 'That request has already been dealt with.');
        if ($deciderPersonId !== null && in_array($deciderPersonId, [(int) $w['from_person_id'], (int) $w['to_person_id']], true)) throw new DomainException('You cannot decide your own leave. Ask another manager.');
        if (!$approve && trim($note) === '') throw new DomainException('Say why it is refused, so they know what to do next.');
        if ($m = Check::maxLen($note, 500, 'Note')) throw new DomainException($m);
        $fa = DB::one('SELECT * FROM absences WHERE id = ?', [$w['from_absence_id']]); $ta = DB::one('SELECT * FROM absences WHERE id = ?', [$w['to_absence_id']]);
        if ($approve && ($b = self::blocker($fa, $ta))) throw new DomainException($b);
        DB::transaction(function () use ($orgId, $w, $approve, $note, $deciderUserId, $actor, $uuid, $fa, $ta) {
            DB::update('leave_swaps', ['status' => $approve ? 'approved' : 'refused', 'decision_note' => trim($note) ?: null, 'decided_by_user_id' => $deciderUserId, 'decided_at' => gmdate('Y-m-d H:i:s')], ['id' => $w['id']]);
            if ($approve) {
                foreach ([[$fa, $ta, $w['from_person_id']], [$ta, $fa, $w['to_person_id']]] as [$old, $new, $pid]) {
                    DB::update('absences', ['starts_on' => $new['starts_on'], 'ends_on' => $new['ends_on'], 'start_half' => $new['start_half'], 'end_half' => $new['end_half']], ['id' => $old['id']]);
                    $p = DB::one('SELECT uuid FROM persons WHERE id = ?', [$pid]);
                    Events::emit($orgId, 'absence.decided', $old['uuid'], ['person' => $p['uuid'], 'starts_on' => $new['starts_on'], 'ends_on' => $new['ends_on'], 'status' => 'approved']);
                }
            }
            Audit::record($orgId, $actor, 'leave_swap.' . ($approve ? 'approved' : 'refused'), 'person', $w['f_uuid'], 'Annual leave swap between ' . $w['f_first'] . ' ' . $w['f_last'] . ' and ' . $w['t_first'] . ' ' . $w['t_last'] . ($approve ? ' approved: ' . $fa['starts_on'] . ' to ' . $fa['ends_on'] . ' and ' . $ta['starts_on'] . ' to ' . $ta['ends_on'] . ' changed places' : ' refused'), ['swap' => $uuid]);
        });
    }
}
