<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('carecore');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = [$type, $message];
    }

    /** @return list<array{0:string,1:string}> */
    public static function takeFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    /** A secret to show once on the next page (an API key, an invitation link): kept apart from ordinary messages. @param array{title:string,note:string,value:string,label:string} $secret */
    public static function reveal(array $secret): void
    {
        $_SESSION['reveal'] = $secret;
    }

    /** @return array{title:string,note:string,value:string,label:string}|null */
    public static function takeReveal(): ?array
    {
        $r = $_SESSION['reveal'] ?? null;
        unset($_SESSION['reveal']);
        return is_array($r) ? $r : null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
