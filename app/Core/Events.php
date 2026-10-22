<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The outbox. Events are written in the same transaction as the change that caused them, so an event can never be
 * lost or sent for something that rolled back. A separate process (scripts/deliver-events.php) delivers them.
 *
 * The envelope is deliberately small and versioned: identifiers and dates only. No names, no health information.
 */
final class Events
{
    /** @var array<int,string> */
    private static array $orgUuids = [];

    /** @param array<string,mixed> $data */
    public static function emit(int $orgId, string $type, ?string $subjectUuid, array $data = []): string
    {
        $id  = Uuid::v4();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $envelope = [
            'id'           => $id,
            'type'         => $type,
            'version'      => 1,
            'occurred_at'  => str_replace(' ', 'T', $now) . 'Z',
            'organisation' => self::orgUuid($orgId),
            'subject'      => $subjectUuid,
            'data'         => $data,
        ];
        Database::insert('outbox_events', [
            'uuid'            => $id,
            'organisation_id' => $orgId,
            'type'            => $type,
            'subject_uuid'    => $subjectUuid,
            'payload'         => json_encode($envelope, JSON_UNESCAPED_SLASHES),
            'occurred_at'     => $now,
        ]);
        return $id;
    }

    private static function orgUuid(int $orgId): string
    {
        return self::$orgUuids[$orgId] ??= (string) Database::value('SELECT uuid FROM organisations WHERE id = ?', [$orgId]);
    }
}
