<?php
declare(strict_types=1);

namespace App\Core;

/** Append-only record of who did what. Always called inside the same transaction as the change. */
final class Audit
{
    /** @param array<string,mixed> $detail */
    public static function record(int $orgId, Actor $actor, string $action, string $entityType, ?string $entityUuid, string $summary, array $detail = []): void
    {
        Database::insert('audit_log', [
            'organisation_id' => $orgId,
            'actor_user_id'   => $actor->userId,
            'actor_label'     => $actor->label,
            'action'          => $action,
            'entity_type'     => $entityType,
            'entity_uuid'     => $entityUuid,
            'summary'         => mb_substr($summary, 0, 300),
            'detail'          => $detail ? json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'ip'              => $actor->ip,
        ]);
    }
}
