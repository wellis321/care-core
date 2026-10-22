<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Delivers outbox events as signed webhooks, with retries. Run from cron every minute: php scripts/deliver-events.php
 *
 * Each request carries X-CareCore-Signature: sha256=HMAC(secret, body), the event id and the delivery attempt.
 * Receivers should treat the event id as the idempotency key.
 */
final class EventDelivery
{
    private const BACKOFF_MINUTES = [1, 5, 30, 120, 720];

    /** @return array{queued:int,delivered:int,failed:int,retrying:int} */
    public static function run(int $limit = 200): array
    {
        $summary = ['queued' => self::fanOut($limit), 'delivered' => 0, 'failed' => 0, 'retrying' => 0];

        $due = Database::all(
            "SELECT d.id, d.attempts, d.outbox_event_id, e.payload, e.type, e.uuid AS event_uuid, w.url, w.secret
             FROM webhook_deliveries d
             JOIN outbox_events e ON e.id = d.outbox_event_id
             JOIN webhook_endpoints w ON w.id = d.endpoint_id
             WHERE d.status = 'pending' AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= UTC_TIMESTAMP()) AND w.active = 1
             ORDER BY d.id LIMIT " . (int) $limit
        );
        foreach ($due as $d) {
            [$ok, $status, $error] = self::post($d['url'], $d['secret'], $d['payload'], $d['type'], $d['event_uuid'], (int) $d['attempts'] + 1);
            $attempts = (int) $d['attempts'] + 1;
            if ($ok) {
                Database::run("UPDATE webhook_deliveries SET status='delivered', attempts=?, http_status=?, error=NULL, delivered_at=UTC_TIMESTAMP(), next_attempt_at=NULL WHERE id=?", [$attempts, $status, $d['id']]);
                $summary['delivered']++;
            } elseif ($attempts > count(self::BACKOFF_MINUTES)) {
                Database::run("UPDATE webhook_deliveries SET status='failed', attempts=?, http_status=?, error=?, next_attempt_at=NULL WHERE id=?", [$attempts, $status, $error, $d['id']]);
                $summary['failed']++;
            } else {
                $minutes = self::BACKOFF_MINUTES[$attempts - 1];
                Database::run("UPDATE webhook_deliveries SET attempts=?, http_status=?, error=?, next_attempt_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL $minutes MINUTE) WHERE id=?", [$attempts, $status, $error, $d['id']]);
                $summary['retrying']++;
            }
        }
        return $summary;
    }

    /** Create a delivery row for every active endpoint that wants each new event. */
    private static function fanOut(int $limit): int
    {
        $queued = 0;
        $events = Database::all('SELECT id, organisation_id, type FROM outbox_events WHERE fanned_out_at IS NULL ORDER BY id LIMIT ' . (int) $limit);
        foreach ($events as $e) {
            $endpoints = Database::all('SELECT id, event_types FROM webhook_endpoints WHERE organisation_id = ? AND active = 1', [$e['organisation_id']]);
            foreach ($endpoints as $w) {
                if (self::matches($w['event_types'], $e['type'])) {
                    Database::run('INSERT IGNORE INTO webhook_deliveries (outbox_event_id, endpoint_id, next_attempt_at) VALUES (?, ?, NULL)', [$e['id'], $w['id']]);
                    $queued++;
                }
            }
            Database::run('UPDATE outbox_events SET fanned_out_at = UTC_TIMESTAMP() WHERE id = ?', [$e['id']]);
        }
        return $queued;
    }

    /** '*' matches everything; 'employment.*' matches a prefix; otherwise an exact type (comma separated). */
    public static function matches(string $patterns, string $type): bool
    {
        foreach (array_map('trim', explode(',', $patterns)) as $p) {
            if ($p === '*' || $p === $type || (str_ends_with($p, '.*') && str_starts_with($type, substr($p, 0, -1)))) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:bool,1:?int,2:?string} */
    private static function post(string $url, string $secret, string $body, string $type, string $eventId, int $attempt): array
    {
        if (UrlGuard::problem($url) !== null) {
            return [false, null, 'Address not allowed'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: CareCore-Webhook/1',
                'X-CareCore-Event: ' . $type,
                'X-CareCore-Event-Id: ' . $eventId,
                'X-CareCore-Attempt: ' . $attempt,
                'X-CareCore-Signature: sha256=' . hash_hmac('sha256', $body, $secret),
            ],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        if ($status >= 200 && $status < 300) {
            return [true, $status, null];
        }
        return [false, $status ?: null, mb_substr($error !== '' ? $error : 'HTTP ' . $status, 0, 300)];
    }
}
