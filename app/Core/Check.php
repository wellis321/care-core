<?php
declare(strict_types=1);

namespace App\Core;

/** Small input checks. Each returns a message for the user, or null when the value is acceptable. */
final class Check
{
    public static function required(?string $v, string $label): ?string
    {
        return trim((string) $v) === '' ? "$label is required." : null;
    }

    public static function maxLen(?string $v, int $max, string $label): ?string
    {
        return mb_strlen((string) $v) > $max ? "$label can be at most $max characters." : null;
    }

    public static function date(?string $v, string $label, bool $required = false): ?string
    {
        if ($v === null || $v === '') {
            return $required ? "$label is required." : null;
        }
        return Clock::valid($v) ? null : "$label is not a valid date.";
    }

    public static function email(?string $v, string $label = 'Email'): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return filter_var($v, FILTER_VALIDATE_EMAIL) && mb_strlen($v) <= 190 ? null : "$label is not a valid email address.";
    }

    /** @param list<string> $allowed */
    public static function oneOf(?string $v, array $allowed, string $label, bool $required = true): ?string
    {
        if (($v === null || $v === '') && !$required) {
            return null;
        }
        return in_array($v, $allowed, true) ? null : "Choose $label from the list.";
    }

    /** A UK NHS number: 10 digits with a modulus 11 check digit. Spaces and dashes are allowed when typing. */
    public static function nhsNumber(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = preg_replace('/[\s-]/', '', $v);
        if (!preg_match('/^\d{10}$/', (string) $d)) {
            return 'That NHS number is not valid. It should be 10 digits.';
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $d[$i] * (10 - $i);
        }
        $check = 11 - ($sum % 11);
        $check = $check === 11 ? 0 : $check;
        return ($check !== 10 && $check === (int) $d[9]) ? null : 'That NHS number is not valid. It should be 10 digits.';
    }

    /** First message from a list of checks, or null. @param list<?string> $results */
    public static function first(array $results): ?string
    {
        foreach ($results as $r) {
            if ($r !== null) {
                return $r;
            }
        }
        return null;
    }

    /** All messages from a list of checks. @param list<?string> $results @return list<string> */
    public static function all(array $results): array
    {
        return array_values(array_unique(array_filter($results, static fn($r) => $r !== null)));
    }
}
