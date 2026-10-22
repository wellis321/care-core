<?php
declare(strict_types=1);

namespace App\Core;

/** Checks an address before this server connects to it, so an administrator cannot aim it at files or internal services. */
final class UrlGuard
{
    /** Returns a message when the address cannot be used, or null when it is acceptable. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return 'Enter the full address, starting with https://';
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return 'Do not put a username or password in the address.';
        }
        $host    = strtolower($parts['host']);
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (strtolower($parts['scheme']) !== 'https' && !$isLocal) {
            return 'Use an https:// address.';
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP) && (str_starts_with($ip, '169.254.') || $ip === '0.0.0.0')) {
            return 'That address cannot be used.';
        }
        return null;
    }
}
