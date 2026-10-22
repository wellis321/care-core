<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Ctx;
use App\Api\Page;
use App\Api\Present;
use App\Modules\Staff\Reconcile;

final class AdminApi extends BaseApi
{
    public const TAG = 'Audit and administration';

    public static function routes(): array
    {
        return [
            self::route('GET', '/audit', 'audit.read', 'audit', 'Read the activity log', self::paging() + ['after' => ['string', 'Same as cursor.'], 'subject_id' => ['uuid', 'Only entries about this person, team or key.'], 'action' => ['string', 'An action name or prefix such as employment.']], [], 'list:AuditEntry', 200, 'Oldest first, so a system can keep a cursor and read only what is new. Sensitive values are never in the log.'),
            self::route('GET', '/users', 'admin.read', 'users', 'List logins', self::paging(), [], 'list:User', 200, 'Read only: logins are created through the joiner process, Entra provisioning or the administration pages.'),
            self::route('GET', '/consistency', 'admin.read', 'consistency', 'Run the consistency checks', [], [], 'list:ConsistencyFinding', 200, 'Finds anything that disagrees with itself: a leaver still in a team, a login or a key worker link. Read only.'),
        ];
    }

    public function audit(Ctx $c): array
    {
        $where = ' WHERE organisation_id = ?';
        $params = [$c->org()];
        if ($s = $c->q('subject_id')) {
            $where .= ' AND entity_uuid = ?';
            $params[] = strtolower($s);
        }
        if ($a = $c->q('action')) {
            if (!preg_match('/^[a-z_.]+$/', $a)) {
                throw ApiException::badRequest('action must be an action name or prefix.');
            }
            $where .= ' AND (action = ? OR action LIKE ?)';
            array_push($params, $a, $a . '.%');
        }
        return Page::run($c, 'SELECT * FROM audit_log' . $where, $params, 'id', [Present::class, 'audit']);
    }

    public function users(Ctx $c): array
    {
        return Page::run($c, 'SELECT u.*, p.uuid AS person_uuid FROM users u LEFT JOIN persons p ON p.id = u.person_id WHERE u.organisation_id = ?', [$c->org()], 'u.id', [Present::class, 'user']);
    }

    public function consistency(Ctx $c): array
    {
        return ['data' => array_map(static fn($i) => ['code' => $i['code'], 'severity' => $i['severity'], 'person_id' => $i['person_uuid'], 'detail' => $i['detail'], 'fixable' => $i['fixable']], Reconcile::check($c->org()))];
    }
}
