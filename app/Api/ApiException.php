<?php
declare(strict_types=1);

namespace App\Api;

/** An API error that becomes an RFC 9457 problem document. */
final class ApiException extends \RuntimeException
{
    /** @param list<array{field:?string,message:string}> $errors */
    public function __construct(public readonly int $status, public readonly string $errorCode, string $detail, public readonly array $errors = [], public readonly array $headers = [])
    {
        parent::__construct($detail);
    }

    public static function notFound(string $what = 'That resource'): self
    {
        return new self(404, 'not_found', "$what does not exist, or is not visible to this API key.");
    }

    public static function invalid(string $detail, array $errors = []): self
    {
        return new self(422, 'validation_failed', $detail, $errors);
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, 'bad_request', $detail);
    }

    public static function forbidden(string $detail): self
    {
        return new self(403, 'forbidden', $detail);
    }
}
