<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Database as DB;
use App\Core\Request;
use App\Core\Response;

/** HTTP wrapper for the SCIM endpoint: bearer token in, SCIM JSON out. */
final class ScimController
{
    public function handle(Request $r): Response
    {
        $auth = $r->header('authorization') ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return self::unauthorised();
        }
        $token = DB::one('SELECT id, organisation_id FROM scim_tokens WHERE token_hash = ? AND active = 1', [hash('sha256', $m[1])]);
        if (!$token) {
            return self::unauthorised();
        }
        DB::update('scim_tokens', ['last_used_at' => gmdate('Y-m-d H:i:s')], ['id' => $token['id']]);
        $path = substr($r->path, strlen('/scim/v2'));
        $body = in_array($r->method, ['POST', 'PUT', 'PATCH'], true) ? $r->json() : null;
        if (in_array($r->method, ['POST', 'PUT', 'PATCH'], true) && $body === null) {
            return Response::json(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => '400', 'detail' => 'The request body is not valid JSON'], 400, 'application/scim+json');
        }
        [$status, $data] = ScimService::handle((int) $token['organisation_id'], $r->method, $path === '' ? '/' : $path, $r->query, $body);
        return Response::json($data, $status, 'application/scim+json');
    }

    private static function unauthorised(): Response
    {
        return Response::json(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => '401', 'detail' => 'A valid bearer token is required'], 401, 'application/scim+json');
    }
}
