<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Actor;
use App\Core\Database as DB;
use App\Core\Request;

/** Everything a handler needs about the call it is serving. */
final class Ctx
{
    /** @var array<string,mixed>|null */
    private ?array $json = null;

    /** @param array<string,mixed> $key @param array<string,string> $params @param list<string> $scopes */
    public function __construct(public readonly Request $req, public readonly array $key, public readonly array $params, public readonly array $scopes, public readonly string $requestId, public readonly ?array $spec = null)
    {
    }

    public function org(): int
    {
        return (int) $this->key['organisation_id'];
    }

    public function actor(): Actor
    {
        return Actor::system('api:' . $this->key['name']);
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** The JSON body as an array (empty when there is none). @return array<string,mixed> */
    public function body(): array
    {
        if ($this->json === null) {
            $raw = trim($this->req->body);
            if ($raw === '') {
                return $this->json = [];
            }
            $d = json_decode($raw, true);
            if (!is_array($d) || array_is_list($d) && $d !== []) {
                throw ApiException::badRequest('The request body must be a JSON object.');
            }
            $this->json = $d;
        }
        return $this->json;
    }

    /** The body checked against a field spec. @param array<string,array<string,mixed>> $spec @return array<string,mixed> */
    public function input(array $spec, bool $partial = false): array
    {
        return Schema::validate($spec, $this->body(), $partial);
    }

    public function q(string $name, ?string $default = null): ?string
    {
        $v = $this->req->query[$name] ?? $default;
        return is_string($v) && $v !== '' ? $v : $default;
    }

    public function qDate(string $name): ?string
    {
        $v = $this->q($name);
        if ($v !== null && !\App\Core\Clock::valid($v)) {
            throw ApiException::badRequest("Query parameter $name must be a date in the form YYYY-MM-DD.");
        }
        return $v;
    }

    public function limit(): int
    {
        $l = $this->q('limit');
        if ($l === null) {
            return 50;
        }
        if (!ctype_digit($l) || (int) $l < 1 || (int) $l > 200) {
            throw ApiException::badRequest('limit must be a whole number from 1 to 200.');
        }
        return (int) $l;
    }

    public function cursor(): int
    {
        $c = $this->q('cursor') ?? $this->q('after');
        if ($c === null) {
            return 0;
        }
        $d = base64_decode(strtr($c, '-_', '+/'), true);
        if ($d === false || !preg_match('/^v1:(\d+)$/', $d, $m)) {
            throw ApiException::badRequest('cursor is not valid. Use the next_cursor value from a previous response.');
        }
        return (int) $m[1];
    }

    // ── Resolving ids inside this organisation ────────────────────────────────

    /** @return array<string,mixed> */
    public function person(?string $uuid = null): array
    {
        return $this->find('persons', $uuid ?? $this->params['id'] ?? '', 'That person');
    }

    /** @return array<string,mixed> */
    public function employment(?string $uuid = null): array
    {
        return $this->find('employments', $uuid ?? $this->params['id'] ?? '', 'That employment');
    }

    /** @return array<string,mixed> */
    public function team(?string $uuid = null): array
    {
        return $this->find('teams', $uuid ?? $this->params['id'] ?? '', 'That team');
    }

    /** @return array<string,mixed> */
    public function find(string $table, string $uuid, string $what): array
    {
        $row = preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) ? DB::one("SELECT * FROM `$table` WHERE organisation_id = ? AND uuid = ?", [$this->org(), strtolower($uuid)]) : null;
        return $row ?? throw ApiException::notFound($what);
    }
}
