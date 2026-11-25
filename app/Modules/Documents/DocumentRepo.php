<?php
declare(strict_types=1);

namespace App\Modules\Documents;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Uuid;

/**
 * Staff documents: private files kept outside the web root and only ever served as downloads after a permission check.
 * We check what a file really is (not what its name says), keep our own record of its type and size, and never open it.
 */
final class DocumentRepo
{
    public const CATEGORIES = ['contract' => 'Contract', 'identity' => 'Identity', 'right_to_work' => 'Right to work', 'qualification' => 'Qualification or certificate', 'reference' => 'Reference', 'training' => 'Training', 'other' => 'Other'];
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const TYPES = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'];
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public static function root(): string
    {
        return ROOT_PATH . '/storage/uploads';
    }

    /**
     * Store an uploaded file against a person.
     *
     * @param array<string,mixed> $upload one entry of $_FILES
     * @param array<string,mixed> $d title, category, expires_on, shared, notes
     */
    public static function add(int $orgId, int $personId, array $upload, array $d, Actor $actor): string
    {
        $person = DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId]) ?? throw new DomainException('Person not found.');
        $bytes = self::bytes($upload);
        [$mime, $ext] = self::kind($bytes, (string) ($upload['name'] ?? ''));
        $name = self::cleanName((string) ($upload['name'] ?? 'document.' . $ext), $ext);
        $title = trim((string) ($d['title'] ?? '')) !== '' ? trim((string) $d['title']) : pathinfo($name, PATHINFO_FILENAME);
        $cat = ($d['category'] ?? '') ?: 'other';
        if ($p = Check::all([Check::maxLen($title, 150, 'Title'), Check::oneOf($cat, array_keys(self::CATEGORIES), 'a category'), Check::date($d['expires_on'] ?? null, 'Expiry date'), Check::maxLen($d['notes'] ?? null, 500, 'Notes')])) {
            throw DomainException::of($p);
        }
        $uuid = Uuid::v4();
        $rel = $orgId . '/documents/' . $uuid . '.' . $ext;
        $dir = self::root() . '/' . $orgId . '/documents';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new DomainException('The document could not be saved. Please try again.');
        }
        if (file_put_contents(self::root() . '/' . $rel, $bytes) === false) {
            throw new DomainException('The document could not be saved. Please try again.');
        }
        try {
            DB::transaction(function () use ($orgId, $personId, $uuid, $title, $cat, $rel, $name, $mime, $bytes, $d, $actor, $person) {
                DB::insert('staff_documents', [
                    'uuid' => $uuid, 'organisation_id' => $orgId, 'person_id' => $personId, 'title' => $title, 'category' => $cat, 'file_path' => $rel,
                    'original_name' => $name, 'mime' => $mime, 'size_bytes' => strlen($bytes), 'expires_on' => ($d['expires_on'] ?? '') ?: null,
                    'shared_with_person' => !empty($d['shared']) ? 1 : 0, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'added_by_user_id' => $actor->userId,
                ]);
                Audit::record($orgId, $actor, 'document.added', 'person', $person['uuid'], 'Document added for ' . $person['first_name'] . ' ' . $person['last_name'] . ': ' . $title . ' (' . self::CATEGORIES[$cat] . ')', ['document' => $uuid]);
            });
        } catch (\Throwable $e) {
            @unlink(self::root() . '/' . $rel);
            throw $e;
        }
        return $uuid;
    }

    public static function remove(int $orgId, string $uuid, Actor $actor): void
    {
        $d = self::byUuid($orgId, $uuid) ?? throw new DomainException('Document not found.');
        $person = DB::one('SELECT uuid, first_name, last_name FROM persons WHERE id = ?', [$d['person_id']]);
        DB::transaction(function () use ($orgId, $d, $actor, $person, $uuid) {
            DB::run('DELETE FROM staff_documents WHERE id = ?', [$d['id']]);
            Audit::record($orgId, $actor, 'document.removed', 'person', $person['uuid'], 'Document removed for ' . $person['first_name'] . ' ' . $person['last_name'] . ': ' . $d['title'], ['document' => $uuid]);
        });
        $path = self::path($d['file_path']);
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    public static function setShared(int $orgId, string $uuid, bool $shared, Actor $actor): void
    {
        $d = self::byUuid($orgId, $uuid) ?? throw new DomainException('Document not found.');
        DB::update('staff_documents', ['shared_with_person' => $shared ? 1 : 0], ['id' => $d['id']]);
        $person = DB::one('SELECT uuid FROM persons WHERE id = ?', [$d['person_id']]);
        Audit::record($orgId, $actor, 'document.sharing', 'person', $person['uuid'], 'Document "' . $d['title'] . '" ' . ($shared ? 'shared with' : 'no longer shared with') . ' the person', ['document' => $uuid]);
    }

    /** The bytes of a document, or null if the file has gone missing. @param array<string,mixed> $d */
    public static function read(array $d): ?string
    {
        $path = self::path((string) $d['file_path']);
        $b = $path && is_file($path) ? file_get_contents($path) : false;
        return $b === false ? null : $b;
    }

    /** Whether a person may download a document: managers always; the person only when it is shared with them. @param array<string,mixed> $d @param array<string,mixed> $user */
    public static function canDownload(array $user, array $d, bool $isManager): bool
    {
        return $isManager || (!empty($user['person_id']) && (int) $user['person_id'] === (int) $d['person_id'] && (int) $d['shared_with_person'] === 1);
    }

    // ── Reading ────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one('SELECT * FROM staff_documents WHERE uuid = ? AND organisation_id = ?', [$uuid, $orgId]) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function forPerson(int $personId, bool $sharedOnly = false): array
    {
        return DB::all('SELECT * FROM staff_documents WHERE person_id = ?' . ($sharedOnly ? ' AND shared_with_person = 1' : '') . ' ORDER BY created_at DESC, id DESC', [$personId]);
    }

    /** Documents that have expired or expire within a number of days, soonest first. @return list<array<string,mixed>> */
    public static function expiring(int $orgId, int $withinDays = 60): array
    {
        return DB::all(
            'SELECT d.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM staff_documents d JOIN persons p ON p.id = d.person_id
             WHERE d.organisation_id = ? AND d.expires_on IS NOT NULL AND d.expires_on <= ? ORDER BY d.expires_on, d.id',
            [$orgId, Clock::addDays(Clock::today(), $withinDays)]
        );
    }

    // ── Retention ──────────────────────────────────────────────────────────

    public static function retentionYears(int $orgId): ?int
    {
        $v = DB::value("SELECT setting_value FROM organisation_settings WHERE organisation_id = ? AND setting_key = 'retention_years'", [$orgId]);
        return $v !== null && $v !== false && (int) $v > 0 ? (int) $v : null;
    }

    public static function setRetentionYears(int $orgId, ?string $years, Actor $actor): void
    {
        $years = trim((string) $years);
        if ($years === '') {
            DB::run("DELETE FROM organisation_settings WHERE organisation_id = ? AND setting_key = 'retention_years'", [$orgId]);
            Audit::record($orgId, $actor, 'retention.setting', 'organisation', null, 'Retention period cleared');
            return;
        }
        if (!ctype_digit($years) || (int) $years < 1 || (int) $years > 50) {
            throw new DomainException('Enter a whole number of years from 1 to 50, or leave it empty to turn the review off.');
        }
        DB::run("INSERT INTO organisation_settings (organisation_id, setting_key, setting_value) VALUES (?, 'retention_years', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$orgId, $years]);
        Audit::record($orgId, $actor, 'retention.setting', 'organisation', null, 'Retention period set to ' . (int) $years . ' years');
    }

    /**
     * People whose employment ended more than the retention period ago and who have no current employment. A list to
     * review, nothing more: Care Core never deletes a person, and removing documents is a deliberate act.
     *
     * @return list<array<string,mixed>>
     */
    public static function pastRetention(int $orgId): array
    {
        $years = self::retentionYears($orgId);
        if ($years === null) {
            return [];
        }
        $cutoff = (new \DateTimeImmutable(Clock::today()))->modify("-$years years")->format('Y-m-d');
        return DB::all(
            'SELECT p.uuid, p.first_name, p.last_name, MAX(e.end_date) AS last_day, (SELECT COUNT(*) FROM staff_documents d WHERE d.person_id = p.id) AS documents
             FROM persons p JOIN employments e ON e.person_id = p.id
             WHERE p.organisation_id = ? GROUP BY p.id, p.uuid, p.first_name, p.last_name
             HAVING SUM(e.end_date IS NULL OR e.end_date >= ?) = 0 AND MAX(e.end_date) < ? ORDER BY last_day',
            [$orgId, Clock::today(), $cutoff]
        );
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /** @param array<string,mixed> $u */
    private static function bytes(array $u): string
    {
        $err = $u['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            throw new DomainException('Choose a file to upload.');
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new DomainException('That file is too large. The limit is 10 MB.');
        }
        if ($err !== UPLOAD_ERR_OK || empty($u['tmp_name'])) {
            throw new DomainException('The file did not upload. Please try again.');
        }
        $b = (string) (is_uploaded_file((string) $u['tmp_name']) || !empty($u['_test']) ? file_get_contents((string) $u['tmp_name']) : '');
        if ($b === '') {
            throw new DomainException('That file is empty.');
        }
        if (strlen($b) > self::MAX_BYTES) {
            throw new DomainException('That file is too large. The limit is 10 MB.');
        }
        return $b;
    }

    /** @return array{0:string,1:string} mime and extension, from the content, never from the name */
    private static function kind(string $bytes, string $name): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if ($mime === 'application/zip' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'docx' && str_contains($bytes, 'word/document.xml')) {
            $mime = self::DOCX;
        }
        if (!isset(self::TYPES[$mime])) {
            throw new DomainException('Only PDF, Word (.docx), PNG and JPEG files can be stored.');
        }
        return [$mime, self::TYPES[$mime]];
    }

    private static function cleanName(string $name, string $ext): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base), " ._-");
        return mb_substr($base !== '' ? $base : 'document', 0, 150) . '.' . $ext;
    }

    /** Only paths this class created: org/documents/uuid.ext. */
    private static function path(string $rel): ?string
    {
        return preg_match('#^\d+/documents/[0-9a-f-]{36}\.(pdf|png|jpg|docx)$#', $rel) ? self::root() . '/' . $rel : null;
    }
}
