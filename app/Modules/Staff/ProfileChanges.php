<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;

/**
 * Self-service. A member of staff can change some of their own details at once (phone, emergency contact) and ask for
 * others (name, address, National Insurance, bank, photo, signature). Requests wait for their line manager, or for HR.
 */
final class ProfileChanges
{
    private const FILE_FIELDS = ['photo_path' => 'Profile photo', 'signature_path' => 'Signature'];

    /** @param array<string,?string> $clean checked values @return array{applied:list<string>,pending:list<string>} */
    public static function submit(int $orgId, int $personId, int $userId, array $clean, Actor $actor): array
    {
        $current = StaffRecord::profile($personId) + DB::one('SELECT first_name, last_name, date_of_birth, phone FROM persons WHERE id = ?', [$personId]);
        $direct = [];
        $pending = [];
        foreach ($clean as $f => $v) {
            $meta = StaffRecord::FIELDS[$f] ?? null;
            if (!$meta || $meta['self'] === null) {
                throw new DomainException('You cannot change that yourself.');
            }
            if ((string) ($current[$f] ?? '') === (string) ($v ?? '')) {
                DB::run("UPDATE profile_changes SET status = 'withdrawn' WHERE person_id = ? AND field = ? AND status = 'pending'", [$personId, $f]);   // changed back to what it already is
                continue;
            }
            if ($meta['self'] === 'direct') {
                $direct[$f] = $v;
            } else {
                $pending[$f] = $v;
            }
        }
        DB::transaction(function () use ($orgId, $personId, $userId, $direct, $pending, $current, $actor) {
            StaffRecord::applyProfile($orgId, $personId, $direct, $actor, 'updated_by_self');
            foreach ($pending as $f => $v) {
                $meta = StaffRecord::FIELDS[$f];
                DB::run("UPDATE profile_changes SET status = 'withdrawn' WHERE person_id = ? AND field = ? AND status = 'pending'", [$personId, $f]);
                $secret = !empty($meta['secret']);
                DB::insert('profile_changes', [
                    'uuid' => Uuid::v4(), 'organisation_id' => $orgId, 'person_id' => $personId, 'submitted_by_user_id' => $userId,
                    'field' => $f, 'label' => $meta['label'], 'field_type' => $meta['type'],
                    'current_value' => $secret ? Crypto::encrypt($current[$f] ?? null) : ($current[$f] ?? null),
                    'proposed_value' => $secret ? Crypto::encrypt($v) : $v,
                ]);
            }
            if ($pending) {
                $person = DB::one('SELECT uuid FROM persons WHERE id = ?', [$personId]);
                Audit::record($orgId, $actor, 'person.change_requested', 'person', $person['uuid'], 'Asked to change: ' . implode(', ', array_map(static fn($f) => StaffRecord::FIELDS[$f]['label'], array_keys($pending))), ['fields' => array_keys($pending)]);
            }
        });
        return ['applied' => array_keys($direct), 'pending' => array_keys($pending)];
    }

    /** A new photo or signature, held until approved. */
    public static function submitFile(int $orgId, int $personId, int $userId, string $field, string $relPath, ?string $method, Actor $actor): void
    {
        if (!isset(self::FILE_FIELDS[$field])) {
            throw new DomainException('Unknown file.');
        }
        DB::transaction(function () use ($orgId, $personId, $userId, $field, $relPath, $method, $actor) {
            foreach (DB::all("SELECT id, proposed_value FROM profile_changes WHERE person_id = ? AND field = ? AND status = 'pending'", [$personId, $field]) as $old) {
                FileStore::delete($old['proposed_value']);
                DB::update('profile_changes', ['status' => 'withdrawn'], ['id' => $old['id']]);
            }
            DB::insert('profile_changes', [
                'uuid' => Uuid::v4(), 'organisation_id' => $orgId, 'person_id' => $personId, 'submitted_by_user_id' => $userId,
                'field' => $field, 'label' => self::FILE_FIELDS[$field], 'field_type' => 'file',
                'current_value' => StaffRecord::profile($personId)[$field] ?? null, 'proposed_value' => $relPath . ($method ? '|' . $method : ''),
            ]);
            $person = DB::one('SELECT uuid FROM persons WHERE id = ?', [$personId]);
            Audit::record($orgId, $actor, 'person.change_requested', 'person', $person['uuid'], 'Asked to change: ' . self::FILE_FIELDS[$field], ['fields' => [$field]]);
        });
    }

    /** Set a photo or signature on the live record (a manager doing it, or an approval). */
    public static function setFile(int $orgId, int $personId, string $field, string $relPath, ?string $method, Actor $actor): void
    {
        DB::transaction(function () use ($orgId, $personId, $field, $relPath, $method, $actor) {
            $old = StaffRecord::profile($personId)[$field] ?? null;
            $cols = [$field => $relPath];
            if ($field === 'signature_path') {
                $cols += ['signature_method' => $method, 'signature_at' => gmdate('Y-m-d H:i:s')];
            }
            StaffRecord::upsertProfile($orgId, $personId, $cols);
            if ($old && $old !== $relPath) {
                FileStore::delete($old);
            }
            $person = DB::one('SELECT uuid FROM persons WHERE id = ?', [$personId]);
            Audit::record($orgId, $actor, 'person.profile_updated', 'person', $person['uuid'], 'Staff record updated: ' . self::FILE_FIELDS[$field], ['fields' => [$field]]);
            Events::emit($orgId, 'person.updated', $person['uuid'], ['fields' => [$field]]);
        });
    }

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return DB::one('SELECT c.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM profile_changes c JOIN persons p ON p.id = c.person_id WHERE c.organisation_id = ? AND c.uuid = ?', [$orgId, $uuid]);
    }

    /** Whether this signed-in user may approve or reject the change. Nobody reviews their own request. */
    public static function canReview(array $user, array $change): bool
    {
        if ((int) $user['organisation_id'] !== (int) $change['organisation_id']) {
            return false;
        }
        if ($user['person_id'] && (int) $user['person_id'] === (int) $change['person_id']) {
            return false;
        }
        if (in_array($user['role'], ['admin', 'manager'], true)) {
            return true;
        }
        return $user['person_id'] && (bool) DB::value(
            'SELECT 1 FROM employments WHERE person_id = ? AND line_manager_person_id = ? AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE()) LIMIT 1',
            [$change['person_id'], $user['person_id']]
        );
    }

    /** Pending requests this user may review. @return list<array<string,mixed>> */
    public static function queueFor(array $user): array
    {
        $rows = DB::all(
            "SELECT c.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM profile_changes c JOIN persons p ON p.id = c.person_id
             WHERE c.organisation_id = ? AND c.status = 'pending' ORDER BY p.last_name, p.first_name, c.submitted_at",
            [$user['organisation_id']]
        );
        return array_values(array_filter($rows, static fn($c) => self::canReview($user, $c)));
    }

    public static function approve(array $change, array $reviewer, Actor $actor): void
    {
        self::guardPending($change, $reviewer);
        $orgId = (int) $change['organisation_id'];
        $personId = (int) $change['person_id'];
        DB::transaction(function () use ($change, $reviewer, $actor, $orgId, $personId) {
            $f = $change['field'];
            if ($change['field_type'] === 'file') {
                [$path, $method] = array_pad(explode('|', (string) $change['proposed_value'], 2), 2, null);
                self::setFile($orgId, $personId, $f, (string) $path, $method, $actor);
            } else {
                $meta = StaffRecord::FIELDS[$f] ?? null;
                if (!$meta) {
                    throw new DomainException('That request is for a field that no longer exists.');
                }
                $value = !empty($meta['secret']) ? Crypto::decrypt($change['proposed_value']) : $change['proposed_value'];
                [$clean, $errors] = StaffRecord::cleanProfile([$f => (string) $value]);
                if ($errors) {
                    throw new DomainException(reset($errors));
                }
                StaffRecord::applyProfile($orgId, $personId, $clean, $actor, 'approved');
            }
            DB::update('profile_changes', ['status' => 'approved', 'reviewer_user_id' => $reviewer['id'], 'reviewed_at' => gmdate('Y-m-d H:i:s')], ['id' => $change['id']]);
            Audit::record($orgId, $actor, 'person.change_approved', 'person', $change['person_uuid'] ?? null, 'Approved: ' . $change['label'], ['field' => $f]);
        });
    }

    public static function reject(array $change, array $reviewer, string $reason, Actor $actor): void
    {
        self::guardPending($change, $reviewer);
        $reason = trim($reason);
        if (mb_strlen($reason) > 300) {
            throw new DomainException('Keep the reason under 300 characters.');
        }
        DB::transaction(function () use ($change, $reviewer, $reason, $actor) {
            if ($change['field_type'] === 'file') {
                FileStore::delete(explode('|', (string) $change['proposed_value'])[0]);
            }
            DB::update('profile_changes', ['status' => 'rejected', 'reviewer_user_id' => $reviewer['id'], 'rejection_reason' => $reason !== '' ? $reason : null, 'reviewed_at' => gmdate('Y-m-d H:i:s')], ['id' => $change['id']]);
            Audit::record((int) $change['organisation_id'], $actor, 'person.change_rejected', 'person', $change['person_uuid'] ?? null, 'Declined: ' . $change['label'], ['field' => $change['field']]);
        });
    }

    public static function withdraw(int $personId, string $uuid, Actor $actor): void
    {
        $c = DB::one("SELECT * FROM profile_changes WHERE person_id = ? AND uuid = ? AND status = 'pending'", [$personId, $uuid]);
        if (!$c) {
            throw new DomainException('That request is no longer waiting.');
        }
        if ($c['field_type'] === 'file') {
            FileStore::delete(explode('|', (string) $c['proposed_value'])[0]);
        }
        DB::update('profile_changes', ['status' => 'withdrawn'], ['id' => $c['id']]);
    }

    /** @return list<array<string,mixed>> */
    public static function forPerson(int $personId, int $limit = 30): array
    {
        return DB::all('SELECT * FROM profile_changes WHERE person_id = ? ORDER BY submitted_at DESC, id DESC LIMIT ' . (int) $limit, [$personId]);
    }

    /** What the person sees about a request: secrets masked. */
    public static function shown(array $change, bool $reveal = false): array
    {
        foreach (['current_value', 'proposed_value'] as $k) {
            $v = $change[$k];
            if ($change['field_type'] === 'file') {
                $v = $v ? 'a picture' : '';
            } elseif (!empty(StaffRecord::FIELDS[$change['field']]['secret'])) {
                $v = Crypto::decrypt($v);
                $v = $reveal ? (string) $v : StaffRecord::mask($v);
            }
            $change[$k . '_shown'] = (string) $v;
        }
        return $change;
    }

    public static function markSeen(int $personId): void
    {
        DB::run("UPDATE profile_changes SET seen_at = UTC_TIMESTAMP() WHERE person_id = ? AND status IN ('approved','rejected') AND seen_at IS NULL", [$personId]);
    }

    /** @param array<string,mixed> $change */
    private static function guardPending(array $change, array $reviewer): void
    {
        if (($change['status'] ?? '') !== 'pending') {
            throw new DomainException('That request has already been dealt with.');
        }
        if (!self::canReview($reviewer, $change)) {
            throw new DomainException('You cannot review that request.');
        }
    }
}
