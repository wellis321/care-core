<?php
declare(strict_types=1);

namespace App\Api\Controllers;

/** Shared route-definition helper. Each controller lists its routes with their documentation next to them, so the OpenAPI document cannot drift from the code. */
abstract class BaseApi
{
    public const TAG = '';

    /**
     * @param array<string,array{0:string,1:string}> $query  name => [type, description]
     * @param array<string,array<string,mixed>> $body        a field spec (see App\Api\Schema)
     * @param array<string,mixed> $o                         extra: response (schema name, or 'list:Name'), status, description
     * @return array<string,mixed>
     */
    protected static function route(string $method, string $path, ?string $scope, string $handler, string $summary, array $query = [], array $body = [], string $response = '', int $status = 200, string $description = ''): array
    {
        return compact('method', 'path', 'scope', 'handler', 'summary', 'query', 'body', 'response', 'status', 'description');
    }

    /** Query parameters every list endpoint accepts. @return array<string,array{0:string,1:string}> */
    protected static function paging(): array
    {
        return ['limit' => ['integer', 'How many to return, 1 to 200. Default 50.'], 'cursor' => ['string', 'The next_cursor from the previous page.']];
    }
}
