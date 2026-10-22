<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Field-level encryption for the most sensitive values (National Insurance and bank details): AES-256-GCM through
 * OpenSSL, which every PHP host has. Key: APP_KEY (base64, 32 bytes). Stored as "enc2:" + base64(iv . tag . ciphertext).
 */
final class Crypto
{
    private const PREFIX = 'enc2:';

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return $stored;   // empty, or a value written before encryption was switched on
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function isEncrypted(?string $stored): bool
    {
        return $stored !== null && str_starts_with($stored, self::PREFIX);
    }

    private static function key(): string
    {
        $k = base64_decode((string) Config::get('APP_KEY', ''), true);
        if ($k === false || strlen($k) !== 32) {
            throw new \RuntimeException('APP_KEY is not set. Generate one with: php scripts/make-key.php');
        }
        return $k;
    }
}
