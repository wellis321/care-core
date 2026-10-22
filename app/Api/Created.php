<?php
declare(strict_types=1);

namespace App\Api;

/** A handler's way of saying "201 Created, and here is where it lives". */
final class Created
{
    /** @param array<string,mixed> $data */
    public function __construct(public readonly array $data, public readonly string $location)
    {
    }
}
