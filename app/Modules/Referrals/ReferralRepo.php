<?php
declare(strict_types=1);

namespace App\Modules\Referrals;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;
use App\Modules\People\PersonRepo;

/**
 * A referral is where a person's support starts: who asked, why, what the person needs, what matters to them, the risks,
 * which kinds of plan it calls for and which specialist skills the support will need. Care Core is the master for referrals
 * (as it is for people); other products read them by API and are told by an event that carries identifiers only.
 *
 * The person may not be a person here yet, so a referral carries a name until it is linked to one. Nothing is deleted: a
 * decided referral keeps its reason.
 */
final class ReferralRepo
{
    public const SOURCES = ['council' => 'Council social work', 'nhs' => 'NHS (hospital, community team, GP)', 'family' => 'Family or a friend', 'self' => 'The person themselves', 'provider' => 'Another care provider', 'other' => 'Someone else'];
    public const STATUS = ['received' => 'Received', 'assessing' => 'Being assessed', 'accepted' => 'Accepted', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn'];
    public const PLAN_TYPES = ['dementia' => 'Dementia', 'behaviour' => 'Distress and behaviour that challenges', 'recovery' => 'Mental health and recovery', 'home' => 'Care at home'];
    private const TEXT = ['referrer' => 200, 'reason' => 600, 'needs_summary' => 1000, 'risks' => 600, 'what_matters' => 600];

    /** @param array<string,mixed> $d @return list<string> */
    public static function problems(array $d, bool $partial = false): array
    {
        $checks = [];
        if (!$partial || array_key_exists('source', $d)) {
            $checks[] = Check::oneOf((string) ($d['source'] ?? ''), array_keys(self::SOURCES), 'who the referral came from');
        }
        if (!$partial || array_key_exists('person_name', $d)) {
            $n = trim((string) ($d['person_name'] ?? ''));
            $checks[] = (mb_strlen($n) < 3 || mb_strlen($n) > 150) ? 'Give the person’s name.' : null;
        }
        if (!$partial || array_key_exists('reason', $d)) {
            $checks[] = mb_strlen(trim((string) ($d['reason'] ?? ''))) < 5 ? 'Say why the referral was made, in a few words.' : null;
        }
        foreach (self::TEXT as $k => $max) {
            $checks[] = Check::maxLen((string) ($d[$k] ?? ''), $max, ucfirst(str_replace('_', ' ', $k)));
        }
        $received = (string) ($d['received_on'] ?? '');
        $checks[] = Check::date($received, 'The date received');
        $checks[] = ($received !== '' && Clock::valid($received) && $received > Clock::today()) ? 'The date received cannot be in the future.' : null;
        $checks[] = Check::date((string) ($d['wanted_from'] ?? ''), 'The date support is wanted from');
        return Check::all($checks);
    }

    /** @return list<string> valid plan-type keys, in a stable order */
    public static function planTypes(mixed $v): array
    {
        $list = is_array($v) ? $v : explode(',', (string) $v);
        $out = [];
        foreach (self::PLAN_TYPES as $k => $_) {
            if (in_array($k, array_map('trim', array_map('strval', $list)), true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function cleanSkills(mixed $v): array
    {
        $list = is_array($v) ? $v : preg_split('/\R|,/', (string) $v);
        $out = [];
        foreach ($list as $s) {
            $s = trim((string) $s);
            if ($s !== '' && mb_strlen($s) <= 150 && !in_array(mb_strtolower($s), array_map('mb_strtolower', $out), true)) {
                $out[] = $s;
            }
        }
        return array_slice($out, 0, 30);
    }

    /**
     * @param array<string,mixed> $d source, person_name, reason (required); received_on, referrer, needs_summary, risks, what_matters, plan_types, wanted_from, skills
     * @return array{id:int,uuid:string,reference:string}
     */
    public static function create(int $orgId, array $d, Actor $actor): array
    {
        if ($p = self::problems($d)) {
            throw DomainException::of($p);
        }
        $types = self::planTypes($d['plan_types'] ?? []);
        $skills = self::cleanSkills($d['skills'] ?? []);
        return DB::transaction(function () use ($orgId, $d, $actor, $types, $skills) {
            $uuid = Uuid::v4();
            $n = 1 + (int) DB::value("SELECT COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) FROM referrals WHERE organisation_id = ? AND reference LIKE 'REF-%'", [$orgId]);
            $ref = sprintf('REF-%04d', $n);
            $row = ['uuid' => $uuid, 'organisation_id' => $orgId, 'reference' => $ref, 'received_on' => ($d['received_on'] ?? '') ?: Clock::today(),
                'source' => $d['source'], 'person_name' => trim((string) $d['person_name']), 'reason' => trim((string) $d['reason']),
                'plan_types' => implode(',', $types), 'wanted_from' => ($d['wanted_from'] ?? '') ?: null];
            foreach (['referrer', 'needs_summary', 'risks', 'what_matters'] as $k) {
                $row[$k] = trim((string) ($d[$k] ?? '')) ?: null;
            }
            $id = DB::insert('referrals', $row);
            self::writeSkills($id, $skills);
            Audit::record($orgId, $actor, 'referral.received', 'referral', $uuid, "Recorded referral $ref (" . strtolower(self::SOURCES[$d['source']]) . ')');
            Events::emit($orgId, 'referral.received', $uuid, ['reference' => $ref]);
            return ['id' => $id, 'uuid' => $uuid, 'reference' => $ref];
        });
    }

    /** Only the keys present change; a decided referral cannot be edited. @param array<string,mixed> $d */
    public static function update(int $orgId, int $id, array $d, Actor $actor): void
    {
        $r = self::byId($orgId, $id) ?? throw new DomainException('Referral not found.');
        if (!in_array($r['status'], ['received', 'assessing'], true)) {
            throw new DomainException('A ' . strtolower(self::STATUS[$r['status']]) . ' referral cannot be changed.');
        }
        if ($p = self::problems($d, true)) {
            throw DomainException::of($p);
        }
        $set = [];
        foreach (['source', 'received_on', 'wanted_from'] as $k) {
            if (array_key_exists($k, $d)) {
                $set[$k] = ($d[$k] ?? '') !== '' ? $d[$k] : ($k === 'received_on' ? $r['received_on'] : null);
            }
        }
        foreach (['person_name', 'reason'] as $k) {
            if (array_key_exists($k, $d)) {
                $set[$k] = trim((string) $d[$k]);
            }
        }
        foreach (['referrer', 'needs_summary', 'risks', 'what_matters'] as $k) {
            if (array_key_exists($k, $d)) {
                $set[$k] = trim((string) $d[$k]) ?: null;
            }
        }
        if (array_key_exists('plan_types', $d)) {
            $set['plan_types'] = implode(',', self::planTypes($d['plan_types']));
        }
        DB::transaction(function () use ($orgId, $r, $set, $d, $actor) {
            if ($set) {
                DB::run('UPDATE referrals SET ' . implode(', ', array_map(static fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $r['id']]);
            }
            if (array_key_exists('skills', $d)) {
                self::writeSkills((int) $r['id'], self::cleanSkills($d['skills']));
            }
            Audit::record($orgId, $actor, 'referral.updated', 'referral', $r['uuid'], 'Updated referral ' . $r['reference'], ['fields' => array_values(array_unique([...array_keys($set), ...(array_key_exists('skills', $d) ? ['skills'] : [])]))]);
            Events::emit($orgId, 'referral.updated', $r['uuid'], ['reference' => $r['reference']]);
        });
    }

    /** received → assessing → accepted / declined / withdrawn. Declining or withdrawing needs a reason. */
    public static function setStatus(int $orgId, int $id, string $status, ?string $reason, Actor $actor): void
    {
        $r = self::byId($orgId, $id) ?? throw new DomainException('Referral not found.');
        if (!isset(self::STATUS[$status]) || $status === 'received') {
            throw new DomainException('Choose what has happened to the referral.');
        }
        if (in_array($r['status'], ['declined', 'withdrawn'], true)) {
            throw new DomainException('That referral is closed.');
        }
        if ($r['status'] === 'accepted' && $status !== 'withdrawn') {
            throw new DomainException('It has already been accepted.');
        }
        if ($r['status'] === $status) {
            throw new DomainException('It is already ' . strtolower(self::STATUS[$status]) . '.');
        }
        $reason = trim((string) $reason);
        if (in_array($status, ['declined', 'withdrawn'], true) && mb_strlen($reason) < 5) {
            throw new DomainException('Say why, in a few words: a declined or withdrawn referral keeps its reason on the record.');
        }
        if (mb_strlen($reason) > 400) {
            throw new DomainException('Please keep the reason to 400 characters.');
        }
        DB::transaction(function () use ($orgId, $r, $status, $reason, $actor) {
            $decided = in_array($status, ['accepted', 'declined', 'withdrawn'], true);
            DB::run('UPDATE referrals SET status = ?, decided_at = ?, decision_reason = ? WHERE id = ?', [$status, $decided ? gmdate('Y-m-d H:i:s') : null, $reason !== '' ? $reason : null, $r['id']]);
            Audit::record($orgId, $actor, 'referral.' . $status, 'referral', $r['uuid'], 'Referral ' . $r['reference'] . ' marked ' . strtolower(self::STATUS[$status]));
            Events::emit($orgId, 'referral.status_changed', $r['uuid'], ['reference' => $r['reference'], 'status' => $status, 'from' => $r['status']]);
        });
    }

    /** The person has come: link the accepted referral to them. @param int $personId internal id */
    public static function linkPerson(int $orgId, int $id, int $personId, Actor $actor): void
    {
        $r = self::byId($orgId, $id) ?? throw new DomainException('Referral not found.');
        if ($r['status'] !== 'accepted') {
            throw new DomainException('Only an accepted referral can be linked to a person.');
        }
        if ($r['person_id'] !== null) {
            throw new DomainException('That referral is already linked to a person.');
        }
        $p = DB::one('SELECT id, uuid, first_name, last_name FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId]) ?? throw new DomainException('That person was not found.');
        if (DB::value('SELECT 1 FROM referrals WHERE organisation_id = ? AND person_id = ? AND id <> ?', [$orgId, $personId, $r['id']])) {
            throw new DomainException('That person already has a referral linked.');
        }
        DB::transaction(function () use ($orgId, $r, $p, $actor) {
            DB::run('UPDATE referrals SET person_id = ? WHERE id = ? AND person_id IS NULL', [$p['id'], $r['id']]);
            Audit::record($orgId, $actor, 'referral.linked', 'referral', $r['uuid'], 'Linked referral ' . $r['reference'] . ' to ' . $p['first_name'] . ' ' . $p['last_name']);
            Events::emit($orgId, 'referral.linked', $r['uuid'], ['reference' => $r['reference'], 'person' => $p['uuid']]);
        });
    }

    /** Make a person from the name on the referral, then link them. @return array{id:int,uuid:string} the new person */
    public static function createPersonAndLink(int $orgId, int $id, Actor $actor): array
    {
        $r = self::byId($orgId, $id) ?? throw new DomainException('Referral not found.');
        if ($r['status'] !== 'accepted' || $r['person_id'] !== null) {
            throw new DomainException('Only an accepted referral that is not yet linked can start a person record.');
        }
        $name = trim((string) preg_replace('/\s+/', ' ', $r['person_name']));
        $cut = strrpos($name, ' ');
        if ($cut === false) {
            throw new DomainException('The name on the referral needs a first name and a last name before it can become a person record.');
        }
        $person = PersonRepo::create($orgId, ['first_name' => substr($name, 0, $cut), 'last_name' => substr($name, $cut + 1)], $actor);
        self::linkPerson($orgId, $id, (int) $person['id'], $actor);
        return $person;
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public static function byId(int $orgId, int $id): ?array
    {
        return DB::one('SELECT * FROM referrals WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
    }

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return DB::one('SELECT * FROM referrals WHERE uuid = ? AND organisation_id = ?', [strtolower($uuid), $orgId]);
    }

    /** @return list<array<string,mixed>> */
    public static function all(int $orgId, string $status = ''): array
    {
        return DB::all("SELECT r.*, p.uuid AS person_uuid, (SELECT COUNT(*) FROM referral_skills s WHERE s.referral_id = r.id) AS n_skills
                        FROM referrals r LEFT JOIN persons p ON p.id = r.person_id
                        WHERE r.organisation_id = ?" . ($status !== '' ? ' AND r.status = ?' : '') . "
                        ORDER BY FIELD(r.status, 'received', 'assessing', 'accepted', 'declined', 'withdrawn'), r.received_on DESC, r.id DESC",
            $status !== '' ? [$orgId, $status] : [$orgId]);
    }

    /** @return list<string> */
    public static function skills(int $referralId): array
    {
        return array_column(DB::all('SELECT skill FROM referral_skills WHERE referral_id = ? ORDER BY sort_order, id', [$referralId]), 'skill');
    }

    /** The referral that brought a person, if any. @return array<string,mixed>|null */
    public static function forPerson(int $orgId, int $personId): ?array
    {
        return DB::one('SELECT * FROM referrals WHERE organisation_id = ? AND person_id = ? ORDER BY id DESC LIMIT 1', [$orgId, $personId]);
    }

    /** @param list<string> $skills */
    private static function writeSkills(int $referralId, array $skills): void
    {
        DB::run('DELETE FROM referral_skills WHERE referral_id = ?', [$referralId]);
        foreach ($skills as $i => $s) {
            DB::insert('referral_skills', ['referral_id' => $referralId, 'skill' => $s, 'sort_order' => $i]);
        }
    }
}
