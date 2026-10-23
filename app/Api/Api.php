<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Config;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;

/**
 * The HTTP API, version 1, under /api/v1. JSON in, JSON out; a bearer API key per integration; scopes, rate limits,
 * idempotent POSTs, cursor pagination, problem+json errors. Every change goes through the same domain services as the
 * web interface, so the same rules, audit trail and events apply.
 */
final class Api
{
    public const PREFIX = '/api/v1';

    /** @var list<class-string> */
    private const CONTROLLERS = [
        Controllers\PeopleApi::class, Controllers\StaffApi::class, Controllers\ComplianceApi::class, Controllers\TeamsApi::class,
        Controllers\CareApi::class, Controllers\RecruitmentApi::class, Controllers\TasksApi::class, Controllers\EventsApi::class, Controllers\AdminApi::class, Controllers\MetaApi::class,
    ];

    /** @return list<array<string,mixed>> every route, with its documentation */
    public static function routes(): array
    {
        $all = [];
        foreach (self::CONTROLLERS as $class) {
            foreach ($class::routes() as $r) {
                $all[] = $r + ['controller' => $class, 'tag' => $class::TAG];
            }
        }
        return $all;
    }

    public static function handles(string $path): bool
    {
        return $path === self::PREFIX || str_starts_with($path, self::PREFIX . '/');
    }

    public static function handle(Request $r): Response
    {
        $requestId = bin2hex(random_bytes(8));
        try {
            $response = self::dispatch($r, $requestId);
        } catch (ApiException $e) {
            $response = self::problem($e, $requestId, $r);
        } catch (DomainException $e) {
            $response = self::problem(ApiException::invalid($e->getMessage()), $requestId, $r);
        } catch (\Throwable $e) {
            error_log('Care Core API error [' . $requestId . ']: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $response = self::problem(new ApiException(500, 'internal_error', Config::bool('APP_DEBUG') ? $e->getMessage() : 'Something went wrong on our side. Quote the request id if you contact support.'), $requestId, $r);
        }
        $response->headers['X-Request-Id'] = $requestId;
        $response->headers['Cache-Control'] ??= 'no-store';
        $response->headers['X-Content-Type-Options'] = 'nosniff';
        return $response;
    }

    private static function dispatch(Request $r, string $requestId): Response
    {
        $path = substr($r->path, strlen(self::PREFIX)) ?: '/';
        if ($path === '/openapi.json' && $r->method === 'GET') {
            $res = Response::json(OpenApi::build(self::routes(), self::baseUrl($r)), 200);
            $res->headers['Cache-Control'] = 'public, max-age=300';
            return $res;
        }
        if ($path === '/' && $r->method === 'GET') {
            return Response::json(['name' => 'Care Core API', 'version' => 'v1', 'documentation' => self::baseUrl($r) . '/docs/api', 'openapi' => self::baseUrl($r) . self::PREFIX . '/openapi.json', 'authentication' => 'Authorization: Bearer <API key>']);
        }

        self::requireHttps($r);
        $key = self::authenticate($r);
        $scopes = Scopes::parse((string) $key['scopes']);

        [$route, $params, $allowed] = self::match($r->method, $path);
        if (!$route) {
            if ($allowed) {
                throw new ApiException(405, 'method_not_allowed', 'That method is not allowed here. Allowed: ' . implode(', ', $allowed) . '.', [], ['Allow' => implode(', ', $allowed)]);
            }
            throw new ApiException(404, 'not_found', 'There is no such API endpoint.');
        }
        if ($route['scope'] !== null && !in_array($route['scope'], $scopes, true)) {
            throw ApiException::forbidden('This API key does not have the "' . $route['scope'] . '" scope that this endpoint needs.');
        }
        $limits = self::rateLimit($key);

        $ctx = new Ctx($r, $key, $params, $scopes, $requestId, $route);
        $writes = in_array($r->method, ['POST', 'PUT', 'PATCH'], true);
        if ($writes && trim($r->body) !== '' && !str_contains(strtolower($r->header('content-type') ?? ''), 'json')) {
            throw new ApiException(415, 'unsupported_media_type', 'Send the body as JSON with Content-Type: application/json.');
        }

        $idem = $r->method === 'POST' ? trim((string) $r->header('idempotency-key')) : '';
        if ($idem !== '') {
            if (strlen($idem) > 100) {
                throw ApiException::badRequest('The Idempotency-Key must be at most 100 characters.');
            }
            $replay = self::idempotencyBegin($key, $idem, hash('sha256', $r->method . ' ' . $r->path . "\n" . trim($r->body)));
            if ($replay) {
                $replay->headers += $limits + ['Idempotent-Replayed' => 'true'];
                return $replay;
            }
        }

        try {
            $result = (new $route['controller']())->{$route['handler']}($ctx);
        } catch (\Throwable $e) {
            if ($idem !== '') {
                DB::run('DELETE FROM api_idempotency WHERE key_id = ? AND idem_key = ?', [$key['id'], $idem]);   // a failed attempt can be retried
            }
            throw $e;
        }
        $response = self::toResponse($result, $route, $r);
        if ($idem !== '') {
            DB::run('UPDATE api_idempotency SET status = ?, response_body = ? WHERE key_id = ? AND idem_key = ?', [$response->status, $response->body, $key['id'], $idem]);
        }
        DB::run('UPDATE api_keys SET last_used_at = UTC_TIMESTAMP(), last_used_ip = ? WHERE id = ?', [mb_substr($r->ip, 0, 45), $key['id']]);
        $response->headers += $limits;
        return $response;
    }

    /** @return array<string,mixed> */
    private static function authenticate(Request $r): array
    {
        $h = $r->header('authorization') ?? '';
        $fail = static fn(string $why) => new ApiException(401, 'unauthorized', $why, [], ['WWW-Authenticate' => 'Bearer realm="Care Core API"']);
        if (!preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            throw $fail('Send your API key as: Authorization: Bearer <key>.');
        }
        $key = ApiKeys::find($m[1]);
        if (!$key) {
            throw $fail('That API key is not valid.');
        }
        if (!ApiKeys::usable($key)) {
            throw $fail('That API key has been revoked or has expired.');
        }
        if (!ApiKeys::ipAllowed($key['allowed_ips'], $r->ip)) {
            throw ApiException::forbidden('This API key may not be used from your address (' . $r->ip . ').');
        }
        return $key;
    }

    private static function requireHttps(Request $r): void
    {
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($r->header('host') ?? '')) ?? '');
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if (!$r->secure() && !$local && Config::get('APP_ENV', 'production') === 'production') {
            throw new ApiException(403, 'https_required', 'The API is only available over HTTPS. Never send an API key over plain HTTP.');
        }
    }

    /** @return array{0:?array<string,mixed>,1:array<string,string>,2:list<string>} route, path params, methods allowed for that path */
    private static function match(string $method, string $path): array
    {
        $allowed = [];
        foreach (self::routes() as $route) {
            $regex = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $route['path']) . '$#';
            if (!preg_match($regex, $path, $m)) {
                continue;
            }
            $allowed[] = $route['method'];
            if ($route['method'] === $method) {
                return [$route, array_map('rawurldecode', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)), []];
            }
        }
        return [null, [], array_values(array_unique($allowed))];
    }

    /** Count this call against the key's minute. @param array<string,mixed> $key @return array<string,string> headers */
    private static function rateLimit(array $key): array
    {
        $limit = (int) $key['rate_limit_per_minute'];
        $window = intdiv(time(), 60) * 60;
        DB::run('INSERT INTO api_rate (key_id, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$key['id'], $window]);
        $hits = (int) DB::value('SELECT hits FROM api_rate WHERE key_id = ? AND window_start = ?', [$key['id'], $window]);
        if (random_int(1, 100) === 1) {
            DB::run('DELETE FROM api_rate WHERE window_start < ?', [$window - 3600]);
            DB::run('DELETE FROM api_idempotency WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)');
        }
        $headers = ['X-RateLimit-Limit' => (string) $limit, 'X-RateLimit-Remaining' => (string) max(0, $limit - $hits), 'X-RateLimit-Reset' => (string) ($window + 60)];
        if ($hits > $limit) {
            throw new ApiException(429, 'rate_limited', "Too many requests: this key is limited to $limit a minute.", [], $headers + ['Retry-After' => (string) max(1, $window + 60 - time())]);
        }
        return $headers;
    }

    /** Claim an Idempotency-Key, or return the stored response of an earlier identical call. @param array<string,mixed> $key */
    private static function idempotencyBegin(array $key, string $idem, string $hash): ?Response
    {
        $inserted = DB::run('INSERT IGNORE INTO api_idempotency (key_id, idem_key, request_hash) VALUES (?, ?, ?)', [$key['id'], $idem, $hash])->rowCount();
        if ($inserted) {
            return null;
        }
        $row = DB::one('SELECT * FROM api_idempotency WHERE key_id = ? AND idem_key = ?', [$key['id'], $idem]);
        if (!$row) {
            return self::idempotencyBegin($key, $idem, $hash);   // it was removed between the two statements
        }
        if (!hash_equals((string) $row['request_hash'], $hash)) {
            throw new ApiException(422, 'idempotency_mismatch', 'That Idempotency-Key was already used for a different request. Use a new key for a new request.');
        }
        if ($row['status'] === null) {
            throw new ApiException(409, 'request_in_progress', 'A request with that Idempotency-Key is still being processed. Try again shortly.', [], ['Retry-After' => '2']);
        }
        $res = Response::json(json_decode((string) $row['response_body'], true), (int) $row['status']);
        $res->body = (string) $row['response_body'];
        return $res;
    }

    /** @param array<string,mixed> $route */
    private static function toResponse(mixed $result, array $route, Request $r): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        $status = $route['status'] ?? 200;
        if ($result instanceof Created) {
            $res = Response::json(['data' => $result->data], 201);
            $res->headers['Location'] = self::baseUrl($r) . self::PREFIX . $result->location;
            return $res;
        }
        if ($status === 204 || $result === null) {
            return Response::json(null, 204);
        }
        return Response::json(isset($result['page']) ? $result : ['data' => $result['data'] ?? $result], $status);
    }

    private static function problem(ApiException $e, string $requestId, Request $r): Response
    {
        $res = Response::json([
            'type' => self::baseUrl($r) . '/docs/api-errors#' . $e->errorCode, 'title' => self::title($e->status), 'status' => $e->status,
            'code' => $e->errorCode, 'detail' => $e->getMessage(), 'errors' => $e->errors, 'request_id' => $requestId,
        ], $e->status, 'application/problem+json');
        foreach ($e->headers as $k => $v) {
            $res->headers[$k] = $v;
        }
        return $res;
    }

    private static function title(int $status): string
    {
        return [400 => 'Bad request', 401 => 'Unauthorised', 403 => 'Forbidden', 404 => 'Not found', 405 => 'Method not allowed', 409 => 'Conflict', 415 => 'Unsupported media type', 422 => 'Unprocessable request', 429 => 'Too many requests', 500 => 'Server error'][$status] ?? 'Error';
    }

    public static function baseUrl(Request $r): string
    {
        $configured = Config::appUrl();
        if ($configured !== '') {
            return $configured;
        }
        return ($r->secure() ? 'https' : 'http') . '://' . ($r->header('host') ?? 'localhost');
    }
}
