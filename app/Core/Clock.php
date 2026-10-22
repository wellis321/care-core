<?php
declare(strict_types=1);

namespace App\Core;

/** "Today" in one place, so effective-dated rules can be tested on any date. */
final class Clock
{
    private static ?string $fixed = null;

    public static function today(): string
    {
        return self::$fixed ?? date('Y-m-d');
    }

    public static function freeze(?string $date): void
    {
        self::$fixed = $date;
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    public static function valid(?string $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
