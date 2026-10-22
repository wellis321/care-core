<?php
declare(strict_types=1);

namespace App\Api;

/** Builds the OpenAPI 3.1 document from the same route table the API serves from, so the specification cannot drift from the code. */
final class OpenApi
{
    /** @param list<array<string,mixed>> $routes @return array<string,mixed> */
    public static function build(array $routes, string $baseUrl): array
    {
        $paths = [];
        $tags = [];
        foreach ($routes as $r) {
            $tags[$r['tag']] = ['name' => $r['tag']];
            $op = [
                'tags' => [$r['tag']], 'summary' => $r['summary'], 'operationId' => lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^a-z0-9]+/i', ' ', $r['method'] . ' ' . $r['path'])))),
                'security' => $r['scope'] === null ? [['bearerAuth' => []]] : [['bearerAuth' => [$r['scope']]]],
                'parameters' => [], 'responses' => [],
            ];
            if ($r['description'] !== '' || $r['scope'] !== null) {
                $op['description'] = trim($r['description'] . ($r['scope'] !== null ? "\n\nRequires the `{$r['scope']}` scope." : ''));
            }
            preg_match_all('/\{(\w+)\}/', $r['path'], $m);
            foreach ($m[1] as $name) {
                $op['parameters'][] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => in_array($name, ['id', 'person', 'record'], true) ? 'The id (UUID).' : 'Path value.'];
            }
            foreach ($r['query'] as $name => [$type, $desc]) {
                $op['parameters'][] = ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $desc, 'schema' => self::queryType($type)];
            }
            if ($r['body']) {
                $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => self::object($r['body'])]]];
            } elseif (in_array($r['method'], ['POST'], true)) {
                $op['requestBody'] = ['required' => false, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];
            }
            if ($r['method'] === 'POST') {
                $op['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 100], 'description' => 'Makes a retry safe: the same key with the same body returns the first response instead of doing it twice.'];
            }
            $ok = (string) $r['status'];
            $op['responses'][$ok] = self::okResponse($r);
            foreach (['400' => 'Bad request', '401' => 'Missing, invalid, revoked or expired key', '403' => 'The key lacks the scope, or may not be used from this address', '404' => 'Not found', '422' => 'A rule was broken or a field is invalid', '429' => 'Rate limit exceeded'] as $code => $desc) {
                if ($code === '422' && !in_array($r['method'], ['POST', 'PATCH', 'PUT'], true)) {
                    continue;
                }
                if ($code === '404' && !str_contains($r['path'], '{') && $r['method'] === 'GET') {
                    continue;
                }
                $op['responses'][$code] = ['description' => $desc, 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
            }
            $paths[$r['path']][strtolower($r['method'])] = $op;
        }
        ksort($paths);
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Care Core API', 'version' => '1.0.0',
                'description' => "The Care Core API lets other systems read and change the people, staff, teams and care records in Care Core, with the same rules, audit trail and events as the web interface.\n\nAuthenticate with an API key: `Authorization: Bearer cc_…`. Keys are created by an administrator under Admin › Integrations and carry scopes. Full guide: {$baseUrl}/docs/api",
            ],
            'servers' => [['url' => $baseUrl . Api::PREFIX]],
            'tags' => array_values($tags),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API key from Admin › Integrations › API keys.']],
                'schemas' => ['Problem' => [
                    'type' => 'object', 'description' => 'An RFC 9457 problem document.',
                    'properties' => ['type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'detail' => ['type' => 'string'],
                        'errors' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['field' => ['type' => ['string', 'null']], 'message' => ['type' => 'string']]]], 'request_id' => ['type' => 'string']],
                ]] + Models::schemas(),
            ],
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function okResponse(array $r): array
    {
        $desc = ['200' => 'OK', '201' => 'Created', '204' => 'No content'][(string) $r['status']] ?? 'OK';
        if ($r['status'] === 204 || $r['response'] === '') {
            return ['description' => $desc];
        }
        $name = $r['response'];
        if (str_starts_with($name, 'list:')) {
            $item = ['$ref' => '#/components/schemas/' . substr($name, 5)];
            $schema = ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $item], 'page' => ['$ref' => '#/components/schemas/Page']]];
        } else {
            $schema = ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/' . $name]]];
        }
        $res = ['description' => $desc, 'content' => ['application/json' => ['schema' => $schema]]];
        if ($r['status'] === 201) {
            $res['headers'] = ['Location' => ['schema' => ['type' => 'string'], 'description' => 'Where the new resource lives.']];
        }
        return $res;
    }

    /** @param array<string,array<string,mixed>> $spec @return array<string,mixed> */
    public static function object(array $spec): array
    {
        $props = [];
        $required = [];
        foreach ($spec as $name => $f) {
            $s = match ($f['type'] ?? 'string') {
                'date' => ['type' => 'string', 'format' => 'date'], 'int' => ['type' => 'integer'], 'number' => ['type' => 'number'], 'bool' => ['type' => 'boolean'],
                'uuid' => ['type' => 'string', 'format' => 'uuid'], 'array' => ['type' => 'array', 'items' => ['type' => 'string']],
                'enum' => ['type' => 'string', 'enum' => $f['enum']], default => ['type' => 'string'],
            };
            foreach (['max' => ['string' => 'maxLength', 'integer' => 'maximum', 'number' => 'maximum'], 'min' => ['integer' => 'minimum', 'number' => 'minimum']] as $k => $map) {
                if (isset($f[$k]) && isset($map[$s['type']])) {
                    $s[$map[$s['type']]] = $f[$k];
                }
            }
            if (!empty($f['nullable'])) {
                $s['type'] = [$s['type'], 'null'];
            }
            if (!empty($f['desc'])) {
                $s['description'] = $f['desc'];
            }
            $props[$name] = $s;
            if (!empty($f['required'])) {
                $required[] = $name;
            }
        }
        return ['type' => 'object', 'properties' => $props, 'additionalProperties' => false] + ($required ? ['required' => $required] : []);
    }

    /** @return array<string,mixed> */
    private static function queryType(string $t): array
    {
        return match ($t) { 'integer' => ['type' => 'integer'], 'boolean' => ['type' => 'boolean'], 'date' => ['type' => 'string', 'format' => 'date'], 'datetime' => ['type' => 'string', 'format' => 'date-time'], 'uuid' => ['type' => 'string', 'format' => 'uuid'], default => ['type' => 'string'] };
    }
}
