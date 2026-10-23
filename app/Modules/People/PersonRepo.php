<?php
declare(strict_types=1);

namespace App\Modules\People;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;

/** The people register: one permanent identity per human. What they ARE to the organisation is a relationship, held elsewhere. */
final class PersonRepo
{
    /** The structured details beyond name, date of birth, email and phone. Only the ones present in the data are changed. */
    public const DETAILS = ['sex', 'nhs_number', 'employment_status', 'gp_practice', 'address_line1', 'address_line2', 'town', 'area', 'postcode'];

    /** @param array<string,mixed> $d @return list<string> */
    public static function problems(array $d): array
    {
        return Check::all([
            Check::required($d['first_name'] ?? null, 'First name'),
            Check::required($d['last_name'] ?? null, 'Last name'),
            Check::maxLen($d['first_name'] ?? null, 100, 'First name'),
            Check::maxLen($d['last_name'] ?? null, 100, 'Last name'),
            Check::maxLen($d['preferred_name'] ?? null, 100, 'Preferred name'),
            Check::date($d['date_of_birth'] ?? null, 'Date of birth'),
            (($d['date_of_birth'] ?? '') !== '' && Clock::valid($d['date_of_birth']) && $d['date_of_birth'] > Clock::today()) ? 'Date of birth cannot be in the future.' : null,
            Check::email($d['email'] ?? null),
            Check::maxLen($d['phone'] ?? null, 40, 'Phone'),
            Check::oneOf(self::nullable($d['sex'] ?? null), ['M', 'F', 'X'], 'sex (M, F or X)', false),
            Check::nhsNumber(self::nullable($d['nhs_number'] ?? null)),
            Check::maxLen($d['employment_status'] ?? null, 100, 'Employment status'),
            Check::maxLen($d['gp_practice'] ?? null, 255, 'GP practice'),
            Check::maxLen($d['address_line1'] ?? null, 190, 'Address'),
            Check::maxLen($d['address_line2'] ?? null, 190, 'Address line 2'),
            Check::maxLen($d['town'] ?? null, 100, 'Town'),
            Check::maxLen($d['area'] ?? null, 100, 'Area'),
            Check::maxLen($d['postcode'] ?? null, 12, 'Postcode'),
        ]);
    }

    /** The details to write: only those present in the data, cleaned (empty becomes null, the NHS number loses spaces, sex is upper case). @param array<string,mixed> $d @return array<string,?string> */
    private static function detailColumns(array $d): array
    {
        $out = [];
        foreach (self::DETAILS as $k) {
            if (!array_key_exists($k, $d)) {
                continue;
            }
            $v = self::nullable($d[$k]);
            if ($v !== null && $k === 'nhs_number') {
                $v = preg_replace('/[\s-]/', '', $v);
            }
            if ($v !== null && $k === 'sex') {
                $v = strtoupper($v);
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** One NHS/CHI number belongs to one person. */
    private static function checkNhsFree(int $orgId, ?string $nhs, ?int $exceptPersonId): void
    {
        if ($nhs === null) {
            return;
        }
        $other = DB::one('SELECT id FROM persons WHERE organisation_id = ? AND nhs_number = ?' . ($exceptPersonId ? ' AND id <> ' . (int) $exceptPersonId : ''), [$orgId, $nhs]);
        if ($other) {
            throw DomainException::of(['Another person already has that NHS number.']);
        }
    }

    /** @param array<string,mixed> $d @return array{id:int,uuid:string} */
    public static function create(int $orgId, array $d, Actor $actor): array
    {
        if ($p = self::problems($d)) {
            throw DomainException::of($p);
        }
        $details = self::detailColumns($d);
        self::checkNhsFree($orgId, $details['nhs_number'] ?? null, null);
        return DB::transaction(function () use ($orgId, $d, $actor, $details) {
            $uuid = Uuid::v4();
            $id = DB::insert('persons', $details + [
                'uuid'            => $uuid,
                'organisation_id' => $orgId,
                'first_name'      => trim($d['first_name']),
                'last_name'       => trim($d['last_name']),
                'preferred_name'  => self::nullable($d['preferred_name'] ?? null),
                'date_of_birth'   => self::nullable($d['date_of_birth'] ?? null),
                'email'           => self::nullable($d['email'] ?? null),
                'phone'           => self::nullable($d['phone'] ?? null),
                'notes'           => self::nullable($d['notes'] ?? null),
            ]);
            Audit::record($orgId, $actor, 'person.created', 'person', $uuid, 'Added ' . trim($d['first_name']) . ' ' . trim($d['last_name']));
            Events::emit($orgId, 'person.created', $uuid);
            return ['id' => $id, 'uuid' => $uuid];
        });
    }

    /** @param array<string,mixed> $d */
    public static function update(int $orgId, int $personId, array $d, Actor $actor): void
    {
        if ($p = self::problems($d)) {
            throw DomainException::of($p);
        }
        $details = self::detailColumns($d);
        self::checkNhsFree($orgId, $details['nhs_number'] ?? null, $personId);
        DB::transaction(function () use ($orgId, $personId, $d, $actor, $details) {
            $person = self::byId($orgId, $personId) ?? throw new DomainException('Person not found.');
            DB::update('persons', $details + [
                'first_name'     => trim($d['first_name']),
                'last_name'      => trim($d['last_name']),
                'preferred_name' => self::nullable($d['preferred_name'] ?? null),
                'date_of_birth'  => self::nullable($d['date_of_birth'] ?? null),
                'email'          => self::nullable($d['email'] ?? null),
                'phone'          => self::nullable($d['phone'] ?? null),
                'notes'          => self::nullable($d['notes'] ?? null),
            ], ['id' => $personId, 'organisation_id' => $orgId]);
            Audit::record($orgId, $actor, 'person.updated', 'person', $person['uuid'], 'Updated ' . trim($d['first_name']) . ' ' . trim($d['last_name']));
            Events::emit($orgId, 'person.updated', $person['uuid']);
        });
    }

    /** @return array<string,mixed>|null */
    public static function byId(int $orgId, int $id): ?array
    {
        return DB::one('SELECT * FROM persons WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
    }

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one('SELECT * FROM persons WHERE uuid = ? AND organisation_id = ?', [$uuid, $orgId]) : null;
    }

    /**
     * People matching a search, with what they currently are (staff, supported, leaver).
     *
     * @return list<array<string,mixed>>
     */
    public static function search(int $orgId, string $q = '', string $kind = 'all', int $limit = 200): array
    {
        $today = Clock::today();
        $sql = "SELECT p.*,
                    (SELECT e.job_title FROM employments e WHERE e.person_id = p.id AND e.start_date <= :t1 AND (e.end_date IS NULL OR e.end_date >= :t2) ORDER BY e.start_date DESC LIMIT 1) AS current_job_title,
                    EXISTS(SELECT 1 FROM employments e WHERE e.person_id = p.id AND e.start_date <= :t3 AND (e.end_date IS NULL OR e.end_date >= :t4)) AS is_staff,
                    EXISTS(SELECT 1 FROM employments e WHERE e.person_id = p.id AND e.end_date < :t5) AS was_staff,
                    EXISTS(SELECT 1 FROM support_episodes s WHERE s.person_id = p.id AND s.start_date <= :t6 AND (s.end_date IS NULL OR s.end_date >= :t7)) AS is_supported
                FROM persons p WHERE p.organisation_id = :org";
        $params = [':t1' => $today, ':t2' => $today, ':t3' => $today, ':t4' => $today, ':t5' => $today, ':t6' => $today, ':t7' => $today, ':org' => $orgId];
        if ($q !== '') {
            $sql .= " AND (p.first_name LIKE :q1 OR p.last_name LIKE :q2 OR CONCAT(p.first_name, ' ', p.last_name) LIKE :q3 OR p.email LIKE :q4)";
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        $sql .= ' ORDER BY p.last_name, p.first_name LIMIT ' . (int) $limit;
        $rows = DB::all($sql, $params);
        if ($kind === 'staff') {
            $rows = array_values(array_filter($rows, static fn($r) => $r['is_staff']));
        } elseif ($kind === 'supported') {
            $rows = array_values(array_filter($rows, static fn($r) => $r['is_supported']));
        } elseif ($kind === 'leavers') {
            $rows = array_values(array_filter($rows, static fn($r) => !$r['is_staff'] && $r['was_staff']));
        }
        return $rows;
    }

    public static function displayName(array $person): string
    {
        return trim($person['first_name'] . ' ' . $person['last_name']);
    }

    private static function nullable(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
