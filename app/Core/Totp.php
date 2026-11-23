<?php
declare(strict_types=1);
namespace App\Core;

/** Time-based one-time passwords (RFC 6238, as used by authenticator apps): 6 digits, 30 seconds, HMAC-SHA1. No library. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string { return self::b32(random_bytes(20)); }

    private static function b32(string $bin): string
    {
        $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = ''; foreach (str_split($bits, 5) as $chunk) $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }

    private static function unb32(string $s): string
    {
        $bits = ''; foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $s) ?? '')) as $c) $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        $out = ''; foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }

    public static function code(string $secret, ?int $time = null, int $digits = 6): string
    {
        $counter = intdiv($time ?? time(), 30); $h = hash_hmac('sha1', pack('N2', $counter >> 32, $counter & 0xFFFFFFFF), self::unb32($secret), true);
        $o = ord($h[19]) & 0x0F; $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Accepts the current code and the one either side (clock drift). @return int|null the 30-second step it matched, so a code cannot be used twice */
    public static function verify(string $secret, string $code, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? ''; if (!preg_match('/^\d{6}$/', $code)) return null; $t = $time ?? time();
        foreach ([0, -30, 30] as $d) if (hash_equals(self::code($secret, $t + $d), $code)) return intdiv($t + $d, 30);
        return null;
    }

    /** The address an authenticator app understands (shown as a link and as the setup key, since no QR library is used). */
    public static function uri(string $secret, string $account, string $issuer): string { return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30'; }
}
