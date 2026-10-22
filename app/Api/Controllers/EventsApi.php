<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Created;
use App\Api\Ctx;
use App\Api\Page;
use App\Api\Present;
use App\Core\Audit;
use App\Core\Database as DB;
use App\Core\UrlGuard;

final class EventsApi extends BaseApi
{
    public const TAG = 'Events and webhooks';

    private const WEBHOOK = [
        'url'         => ['type' => 'string', 'required' => true, 'max' => 500, 'desc' => 'An https:// address (http only for localhost).'],
        'event_types' => ['type' => 'array', 'desc' => 'Event types to receive: exact names or prefixes such as "employment.*". Default ["*"] (everything).'],
        'active'      => ['type' => 'bool', 'desc' => 'Pause or resume deliveries.'],
    ];

    public static function routes(): array
    {
        return [
            self::route('GET', '/events', 'events.read', 'index', 'Read the event feed', self::paging() + ['after' => ['string', 'Same as cursor: return events after this one.'], 'types' => ['string', 'Comma-separated event types or prefixes, for example employment.*,team_assignment.ended.']], [], 'list:Event', 200, 'The same events that webhooks deliver, oldest first, for systems that prefer to poll. Keep the last cursor you processed and pass it as after. Identifiers and dates only.'),
            self::route('GET', '/events/{id}', 'events.read', 'show', 'Get one event', [], [], 'Event'),
            self::route('GET', '/webhooks', 'webhooks.manage', 'webhooks', 'List webhook endpoints', [], [], 'list:Webhook'),
            self::route('POST', '/webhooks', 'webhooks.manage', 'createWebhook', 'Create a webhook endpoint', [], self::WEBHOOK, 'Webhook', 201, 'The response includes the signing secret once. Store it: it is never shown again.'),
            self::route('GET', '/webhooks/{id}', 'webhooks.manage', 'webhook', 'Get a webhook endpoint', [], [], 'Webhook'),
            self::route('PATCH', '/webhooks/{id}', 'webhooks.manage', 'updateWebhook', 'Change a webhook endpoint', [], self::WEBHOOK, 'Webhook'),
            self::route('DELETE', '/webhooks/{id}', 'webhooks.manage', 'deleteWebhook', 'Delete a webhook endpoint', [], [], '', 204),
            self::route('GET', '/webhooks/{id}/deliveries', 'webhooks.manage', 'deliveries', 'Recent deliveries to an endpoint', self::paging() + ['status' => ['string', 'pending, delivered or failed.']], [], 'list:WebhookDelivery', 200, 'Newest first.'),
        ];
    }

    public function index(Ctx $c): array
    {
        $where = ' WHERE organisation_id = ?';
        $params = [$c->org()];
        if ($t = $c->q('types')) {
            $or = [];
            foreach (array_filter(array_map('trim', explode(',', $t))) as $pat) {
                if ($pat === '*') {
                    $or = [];
                    break;
                }
                if (!preg_match('/^[a-z_]+(\.([a-z_]+|\*))?$/', $pat)) {
                    throw ApiException::badRequest("“{$pat}” is not a valid event type or prefix.");
                }
                if (str_ends_with($pat, '.*')) {
                    $or[] = 'type LIKE ?';
                    $params[] = substr($pat, 0, -1) . '%';
                } else {
                    $or[] = 'type = ?';
                    $params[] = $pat;
                }
            }
            if ($or) {
                $where .= ' AND (' . implode(' OR ', $or) . ')';
            }
        }
        return Page::run($c, 'SELECT * FROM outbox_events' . $where, $params, 'id', [Present::class, 'event']);
    }

    public function show(Ctx $c): array
    {
        return ['data' => Present::event($c->find('outbox_events', $c->params['id'], 'That event'))];
    }

    public function webhooks(Ctx $c): array
    {
        return ['data' => array_map([Present::class, 'webhook'], DB::all('SELECT * FROM webhook_endpoints WHERE organisation_id = ? ORDER BY id', [$c->org()]))];
    }

    public function webhook(Ctx $c): array
    {
        return ['data' => Present::webhook($c->find('webhook_endpoints', $c->params['id'], 'That webhook'))];
    }

    public function createWebhook(Ctx $c): Created
    {
        $in = $c->input(self::WEBHOOK);
        $types = self::types($in['event_types'] ?? ['*']);
        self::checkUrl($in['url']);
        $secret = bin2hex(random_bytes(20));
        $row = DB::transaction(function () use ($c, $in, $types, $secret) {
            $id = DB::insert('webhook_endpoints', ['organisation_id' => $c->org(), 'url' => $in['url'], 'secret' => $secret, 'event_types' => $types, 'active' => array_key_exists('active', $in) ? (int) (bool) $in['active'] : 1]);
            Audit::record($c->org(), $c->actor(), 'webhook.created', 'webhook', null, 'Added a webhook to ' . $in['url']);
            return DB::one('SELECT * FROM webhook_endpoints WHERE id = ?', [$id]);
        });
        return new Created(Present::webhook($row) + ['secret' => $secret], '/webhooks/' . $row['uuid']);
    }

    public function updateWebhook(Ctx $c): array
    {
        $w = $c->find('webhook_endpoints', $c->params['id'], 'That webhook');
        $in = $c->input(self::WEBHOOK, true);
        $set = [];
        if (isset($in['url'])) {
            self::checkUrl($in['url']);
            $set['url'] = $in['url'];
        }
        if (isset($in['event_types'])) {
            $set['event_types'] = self::types($in['event_types']);
        }
        if (array_key_exists('active', $in)) {
            $set['active'] = (int) (bool) $in['active'];
        }
        if ($set) {
            DB::transaction(function () use ($c, $w, $set) {
                DB::update('webhook_endpoints', $set, ['id' => $w['id']]);
                Audit::record($c->org(), $c->actor(), 'webhook.updated', 'webhook', $w['uuid'], 'Changed the webhook to ' . ($set['url'] ?? $w['url']));
            });
        }
        return ['data' => Present::webhook(DB::one('SELECT * FROM webhook_endpoints WHERE id = ?', [$w['id']]))];
    }

    public function deleteWebhook(Ctx $c): null
    {
        $w = $c->find('webhook_endpoints', $c->params['id'], 'That webhook');
        DB::transaction(function () use ($c, $w) {
            DB::run('DELETE FROM webhook_endpoints WHERE id = ?', [$w['id']]);
            Audit::record($c->org(), $c->actor(), 'webhook.deleted', 'webhook', $w['uuid'], 'Deleted the webhook to ' . $w['url']);
        });
        return null;
    }

    public function deliveries(Ctx $c): array
    {
        $w = $c->find('webhook_endpoints', $c->params['id'], 'That webhook');
        $where = ' WHERE d.endpoint_id = ?';
        $params = [$w['id']];
        if ($s = $c->q('status')) {
            if (!in_array($s, ['pending', 'delivered', 'failed'], true)) {
                throw ApiException::badRequest('status must be pending, delivered or failed.');
            }
            $where .= ' AND d.status = ?';
            $params[] = $s;
        }
        return Page::run($c, 'SELECT d.*, e.uuid AS event_uuid, e.type AS event_type FROM webhook_deliveries d JOIN outbox_events e ON e.id = d.outbox_event_id' . $where, $params, 'd.id', static fn($r) => [
            'event_id' => $r['event_uuid'], 'event_type' => $r['event_type'], 'status' => $r['status'], 'attempts' => (int) $r['attempts'], 'http_status' => $r['http_status'] === null ? null : (int) $r['http_status'],
            'error' => $r['error'], 'next_attempt_at' => Present::time($r['next_attempt_at']), 'delivered_at' => Present::time($r['delivered_at']), 'created_at' => Present::time($r['created_at']),
        ], 'DESC');
    }

    private static function checkUrl(string $url): void
    {
        if ($m = UrlGuard::problem($url)) {
            throw ApiException::invalid($m, [['field' => 'url', 'message' => $m]]);
        }
    }

    /** @param mixed $types @return string */
    private static function types(mixed $types): string
    {
        $list = array_values(array_filter(array_map(static fn($t) => is_string($t) ? trim($t) : '', (array) $types)));
        foreach ($list as $t) {
            if ($t !== '*' && !preg_match('/^[a-z_]+(\.([a-z_]+|\*))?$/', $t)) {
                throw ApiException::invalid("“{$t}” is not a valid event type or prefix.", [['field' => 'event_types', 'message' => "“{$t}” is not a valid event type or prefix."]]);
            }
        }
        return $list ? implode(',', $list) : '*';
    }
}
