<?php
declare(strict_types=1);

namespace App\Core;

/** A rule of the domain was broken (not a bug): the message is safe to show to the user. */
final class DomainException extends \RuntimeException
{
    /** @param list<string> $messages */
    public static function of(array $messages): self
    {
        return new self(implode(' ', $messages));
    }
}
