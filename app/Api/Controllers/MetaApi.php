<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Ctx;
use App\Api\Present;
use App\Api\Scopes;
use App\Core\Database as DB;

final class MetaApi extends BaseApi
{
    public const TAG = 'Meta';

    public static function routes(): array
    {
        return [
            self::route('GET', '/ping', null, 'ping', 'Check your key', [], [], 'Ping', 200, 'Needs any valid key. Returns the organisation, the key’s scopes and limits, and the server time. Good for a health check.'),
            self::route('GET', '/scopes', null, 'scopes', 'List all scopes', [], [], 'list:Scope', 200, 'Needs any valid key.'),
        ];
    }

    public function ping(Ctx $c): array
    {
        $org = DB::one('SELECT uuid, name FROM organisations WHERE id = ?', [$c->org()]);
        return ['data' => [
            'organisation' => ['id' => $org['uuid'], 'name' => $org['name']],
            'key' => ['id' => $c->key['uuid'], 'name' => $c->key['name'], 'scopes' => $c->scopes, 'rate_limit_per_minute' => (int) $c->key['rate_limit_per_minute'], 'expires_at' => Present::time($c->key['expires_at'])],
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'), 'api_version' => 'v1',
        ]];
    }

    public function scopes(Ctx $c): array
    {
        $out = [];
        foreach (Scopes::ALL as $name => $desc) {
            $out[] = ['scope' => $name, 'description' => $desc, 'granted' => $c->can($name)];
        }
        return ['data' => $out];
    }
}
