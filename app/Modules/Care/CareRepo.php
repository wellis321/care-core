<?php
declare(strict_types=1);

namespace App\Modules\Care;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;

/** People we support: periods of support, care needs, contacts and key workers. */
final class CareRepo
{
    // ── Support episodes ────────────────────────────────────────────────────

    /** @param array<string,mixed> $d @return string episode uuid */
    public static function startSupport(int $orgId, int $personId, array $d, Actor $actor): string
    {
        $start = ($d['start_date'] ?? '') ?: Clock::today();
        $msgs = Check::all([
            Check::date($start, 'Support start date', true),
            Check::nhsNumber($d['nhs_number'] ?? null),
            Check::maxLen($d['reference'] ?? null, 50, 'Reference'),
            Check::maxLen($d['local_authority_ref'] ?? null, 50, 'Local authority reference'),
        ]);
        if ($msgs) {
            throw DomainException::of($msgs);
        }
        $person = DB::one('SELECT id, uuid, first_name, last_name FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId]) ?? throw new DomainException('Person not found.');
        return DB::transaction(function () use ($orgId, $personId, $d, $actor, $start, $person) {
            if (DB::value('SELECT 1 FROM support_episodes WHERE person_id = ? AND end_date IS NULL FOR UPDATE', [$personId])) {
                throw new DomainException('This person is already receiving support.');
            }
            $lastEnd = DB::value('SELECT MAX(end_date) FROM support_episodes WHERE person_id = ?', [$personId]);
            if ($lastEnd && $start < $lastEnd) {
                throw new DomainException('Support ended on ' . date('j M Y', strtotime((string) $lastEnd)) . ', so the new start date cannot be earlier.');
            }
            $uuid = Uuid::v4();
            DB::insert('support_episodes', [
                'uuid' => $uuid, 'organisation_id' => $orgId, 'person_id' => $personId, 'start_date' => $start,
                'reference' => trim((string) ($d['reference'] ?? '')) ?: null,
                'nhs_number' => trim((string) ($d['nhs_number'] ?? '')) ?: null,
                'local_authority_ref' => trim((string) ($d['local_authority_ref'] ?? '')) ?: null,
            ]);
            Audit::record($orgId, $actor, 'support.started', 'person', $person['uuid'], 'Started support for ' . $person['first_name'] . ' ' . $person['last_name'], ['from' => $start]);
            Events::emit($orgId, 'support.started', $person['uuid'], ['start_date' => $start]);
            return $uuid;
        });
    }

    /**
     * Support ends: their key worker links and any team places end with it, in the same transaction.
     *
     * @return array{keyworkers:int,teams:int}
     */
    public static function endSupport(int $orgId, int $personId, ?string $endDate, Actor $actor): array
    {
        $endDate = $endDate ?: Clock::today();
        if ($m = Check::date($endDate, 'End date', true)) {
            throw new DomainException($m);
        }
        $person = DB::one('SELECT id, uuid, first_name, last_name FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId]) ?? throw new DomainException('Person not found.');
        return DB::transaction(function () use ($orgId, $personId, $endDate, $actor, $person) {
            $ep = DB::one('SELECT id, start_date FROM support_episodes WHERE person_id = ? AND end_date IS NULL FOR UPDATE', [$personId]) ?? throw new DomainException('This person is not receiving support.');
            if ($endDate < $ep['start_date']) {
                throw new DomainException('The end date cannot be before support started on ' . date('j M Y', strtotime((string) $ep['start_date'])) . '.');
            }
            DB::update('support_episodes', ['end_date' => $endDate], ['id' => $ep['id']]);
            $kw = DB::run('UPDATE key_worker_assignments SET valid_to = ? WHERE supported_person_id = ? AND valid_to IS NULL', [$endDate, $personId])->rowCount();
            $teams = DB::run('UPDATE team_assignments SET valid_to = ?, is_primary = 0 WHERE person_id = ? AND valid_to IS NULL', [$endDate, $personId])->rowCount();
            Audit::record($orgId, $actor, 'support.ended', 'person', $person['uuid'], 'Ended support for ' . $person['first_name'] . ' ' . $person['last_name'], ['to' => $endDate, 'keyworkers_ended' => $kw, 'teams_ended' => $teams]);
            Events::emit($orgId, 'support.ended', $person['uuid'], ['end_date' => $endDate]);
            return ['keyworkers' => $kw, 'teams' => $teams];
        });
    }

    /** @return array<string,mixed>|null the current episode */
    public static function currentEpisode(int $personId, ?string $on = null): ?array
    {
        $on ??= Clock::today();
        return DB::one('SELECT * FROM support_episodes WHERE person_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) ORDER BY start_date DESC LIMIT 1', [$personId, $on, $on]);
    }

    /** @return list<array<string,mixed>> */
    public static function episodes(int $personId): array
    {
        return DB::all('SELECT * FROM support_episodes WHERE person_id = ? ORDER BY start_date DESC', [$personId]);
    }

    // ── Key workers ─────────────────────────────────────────────────────────

    public static function assignKeyWorker(int $orgId, int $supportedId, int $staffId, ?string $roleLabel, ?string $validFrom, Actor $actor): int
    {
        $validFrom = $validFrom ?: Clock::today();
        if ($m = Check::first([Check::date($validFrom, 'Start date', true), Check::maxLen($roleLabel, 100, 'Role')])) {
            throw new DomainException($m);
        }
        $supported = DB::one('SELECT id, uuid, first_name, last_name FROM persons WHERE id = ? AND organisation_id = ?', [$supportedId, $orgId]) ?? throw new DomainException('Person not found.');
        $staff     = DB::one('SELECT id, uuid, first_name, last_name FROM persons WHERE id = ? AND organisation_id = ?', [$staffId, $orgId]) ?? throw new DomainException('Staff member not found.');
        if ($supportedId === $staffId) {
            throw new DomainException('Someone cannot be their own key worker.');
        }
        if (!self::currentEpisode($supportedId, $validFrom)) {
            throw new DomainException('Key workers can only be assigned to people who are receiving support.');
        }
        if (!DB::value('SELECT 1 FROM employments WHERE person_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) LIMIT 1', [$staffId, $validFrom, $validFrom])) {
            throw new DomainException('Only current staff can be key workers.');
        }
        return DB::transaction(function () use ($orgId, $supportedId, $staffId, $roleLabel, $validFrom, $actor, $supported, $staff) {
            if (DB::value('SELECT 1 FROM key_worker_assignments WHERE supported_person_id = ? AND staff_person_id = ? AND valid_to IS NULL FOR UPDATE', [$supportedId, $staffId])) {
                throw new DomainException($staff['first_name'] . ' ' . $staff['last_name'] . ' is already a key worker for this person.');
            }
            $id = DB::insert('key_worker_assignments', [
                'organisation_id' => $orgId, 'supported_person_id' => $supportedId, 'staff_person_id' => $staffId,
                'role_label' => trim((string) $roleLabel) ?: null, 'valid_from' => $validFrom,
            ]);
            Audit::record($orgId, $actor, 'keyworker.started', 'person', $supported['uuid'], $staff['first_name'] . ' ' . $staff['last_name'] . ' became key worker for ' . $supported['first_name'] . ' ' . $supported['last_name'], ['staff' => $staff['uuid'], 'from' => $validFrom]);
            Events::emit($orgId, 'keyworker.started', $supported['uuid'], ['staff' => $staff['uuid'], 'valid_from' => $validFrom]);
            return $id;
        });
    }

    public static function endKeyWorker(int $orgId, int $assignmentId, ?string $validTo, Actor $actor): void
    {
        $validTo = $validTo ?: Clock::today();
        if ($m = Check::date($validTo, 'End date', true)) {
            throw new DomainException($m);
        }
        DB::transaction(function () use ($orgId, $assignmentId, $validTo, $actor) {
            $row = DB::one(
                'SELECT k.*, s.uuid AS supported_uuid, f.uuid AS staff_uuid, s.first_name AS sf, s.last_name AS sl, f.first_name AS ff, f.last_name AS fl
                 FROM key_worker_assignments k JOIN persons s ON s.id = k.supported_person_id JOIN persons f ON f.id = k.staff_person_id
                 WHERE k.id = ? AND k.organisation_id = ? AND k.valid_to IS NULL FOR UPDATE',
                [$assignmentId, $orgId]
            ) ?? throw new DomainException('That key worker link has already ended.');
            if ($validTo < $row['valid_from']) {
                throw new DomainException('The end date cannot be before it started on ' . date('j M Y', strtotime((string) $row['valid_from'])) . '.');
            }
            DB::update('key_worker_assignments', ['valid_to' => $validTo], ['id' => $assignmentId]);
            Audit::record($orgId, $actor, 'keyworker.ended', 'person', $row['supported_uuid'], $row['ff'] . ' ' . $row['fl'] . ' is no longer key worker for ' . $row['sf'] . ' ' . $row['sl'], ['to' => $validTo]);
            Events::emit($orgId, 'keyworker.ended', $row['supported_uuid'], ['staff' => $row['staff_uuid'], 'valid_to' => $validTo]);
        });
    }

    /** Key workers for a supported person. @return list<array<string,mixed>> */
    public static function keyWorkersFor(int $supportedId): array
    {
        return DB::all(
            'SELECT k.*, p.uuid AS staff_uuid, p.first_name, p.last_name FROM key_worker_assignments k JOIN persons p ON p.id = k.staff_person_id
             WHERE k.supported_person_id = ? ORDER BY (k.valid_to IS NULL) DESC, k.valid_from DESC',
            [$supportedId]
        );
    }

    /** People a staff member is key worker for. @return list<array<string,mixed>> */
    public static function keyWorkerFor(int $staffId): array
    {
        return DB::all(
            'SELECT k.*, p.uuid AS supported_uuid, p.first_name, p.last_name FROM key_worker_assignments k JOIN persons p ON p.id = k.supported_person_id
             WHERE k.staff_person_id = ? ORDER BY (k.valid_to IS NULL) DESC, k.valid_from DESC',
            [$staffId]
        );
    }

    // ── Care needs and contacts ─────────────────────────────────────────────

    /** @param array<string,mixed> $d */
    public static function addNeed(int $orgId, int $personId, array $d, Actor $actor): void
    {
        $msgs = Check::all([
            Check::required($d['category'] ?? null, 'Category'), Check::maxLen($d['category'] ?? null, 100, 'Category'),
            Check::required($d['description'] ?? null, 'Description'),
            Check::oneOf($d['severity'] ?? '', ['low', 'medium', 'high'], 'a severity', false),
            Check::date($d['review_date'] ?? null, 'Review date'),
        ]);
        if ($msgs) {
            throw DomainException::of($msgs);
        }
        $person = DB::one('SELECT uuid FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId]) ?? throw new DomainException('Person not found.');
        DB::transaction(function () use ($orgId, $personId, $d, $actor, $person) {
            DB::insert('care_needs', [
                'organisation_id' => $orgId, 'person_id' => $personId, 'category' => trim($d['category']), 'description' => trim($d['description']),
                'severity' => ($d['severity'] ?? '') ?: null, 'review_date' => ($d['review_date'] ?? '') ?: null,
            ]);
            Audit::record($orgId, $actor, 'careneed.added', 'person', $person['uuid'], 'Added a care need (' . trim($d['category']) . ')');
        });
    }

    public static function endNeed(int $orgId, int $needId, Actor $actor): void
    {
        $row = DB::one('SELECT n.id, p.uuid FROM care_needs n JOIN persons p ON p.id = n.person_id WHERE n.id = ? AND n.organisation_id = ? AND n.ended_on IS NULL', [$needId, $orgId]) ?? throw new DomainException('Care need not found.');
        DB::transaction(function () use ($orgId, $needId, $actor, $row) {
            DB::update('care_needs', ['ended_on' => Clock::today()], ['id' => $needId]);
            Audit::record($orgId, $actor, 'careneed.ended', 'person', $row['uuid'], 'Closed a care need');
        });
    }

    /** @param array<string,mixed> $d */
    public static function addContact(int $orgId, int $personId, array $d, Actor $actor): void
    {
        $types = ['phone', 'email', 'address', 'next_of_kin', 'legal_guardian', 'social_worker', 'gp', 'other'];
        $type = $d['contact_type'] ?? '';
        $value = trim((string) ($d['value'] ?? ''));
        $msgs = [Check::oneOf($type, $types, 'a contact type'), Check::required($value, 'Contact value'), Check::maxLen($value, 500, 'Contact value'), Check::maxLen($d['label'] ?? null, 100, 'Label')];
        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $msgs[] = 'Enter a valid email address.';
        }
        if ($type === 'phone' && $value !== '' && !preg_match('/^[0-9+()\s-]{7,20}$/', $value)) {
            $msgs[] = 'Enter a valid phone number.';
        }
        if ($m = Check::all($msgs)) {
            throw DomainException::of($m);
        }
        $person = DB::one('SELECT uuid FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId]) ?? throw new DomainException('Person not found.');
        DB::transaction(function () use ($orgId, $personId, $d, $type, $value, $actor, $person) {
            DB::insert('person_contacts', [
                'organisation_id' => $orgId, 'person_id' => $personId, 'contact_type' => $type,
                'label' => trim((string) ($d['label'] ?? '')) ?: null, 'value' => $value, 'is_primary' => !empty($d['is_primary']) ? 1 : 0,
            ]);
            Audit::record($orgId, $actor, 'contact.added', 'person', $person['uuid'], 'Added a contact (' . $type . ')');
        });
    }

    public static function removeContact(int $orgId, int $contactId, Actor $actor): void
    {
        $row = DB::one('SELECT c.id, p.uuid FROM person_contacts c JOIN persons p ON p.id = c.person_id WHERE c.id = ? AND c.organisation_id = ?', [$contactId, $orgId]) ?? throw new DomainException('Contact not found.');
        DB::transaction(function () use ($orgId, $contactId, $actor, $row) {
            DB::run('DELETE FROM person_contacts WHERE id = ? AND organisation_id = ?', [$contactId, $orgId]);
            Audit::record($orgId, $actor, 'contact.removed', 'person', $row['uuid'], 'Removed a contact');
        });
    }

    /** @return list<array<string,mixed>> */
    public static function needs(int $personId): array
    {
        return DB::all('SELECT * FROM care_needs WHERE person_id = ? ORDER BY (ended_on IS NULL) DESC, created_at DESC', [$personId]);
    }

    /** @return list<array<string,mixed>> */
    public static function contacts(int $personId): array
    {
        return DB::all('SELECT * FROM person_contacts WHERE person_id = ? ORDER BY is_primary DESC, contact_type, id', [$personId]);
    }
}
