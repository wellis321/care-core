<?php
declare(strict_types=1);

namespace App\Core;

/** Who is making a change: a signed-in user, or a system process such as SCIM or a scheduled job. */
final class Actor
{
    private function __construct(
        public readonly ?int $userId,
        public readonly string $label,
        public readonly ?string $ip = null
    ) {
    }

    public static function user(int $userId, string $label, ?string $ip = null): self
    {
        return new self($userId, $label, $ip);
    }

    public static function system(string $name): self
    {
        return new self(null, 'system:' . $name);
    }
}
