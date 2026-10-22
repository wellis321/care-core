<?php
declare(strict_types=1);

namespace App\Core;

/** Settings come from the real environment first, then the .env file (so a server setting always wins). */
final class Config
{
    /** @var array<string,string> */
    private static array $file = [];

    public static function load(string $path): void
    {
        self::$file = [];
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            self::$file[trim($k)] = trim(trim($v), "\"'");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return self::$file[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function demo(): bool
    {
        return self::bool('DEMO_MODE', false);
    }
}
