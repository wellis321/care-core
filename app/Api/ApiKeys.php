<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Uuid;

/** Creating, checking and revoking API keys. A key is shown once; only its SHA-256 hash is kept. */
final class ApiKeys
{
    /**
     * @param list<string> $scopes
     * @return array{id:int,uuid:string,token:string}
     */
    public static function create(int $orgId, string $name, array $scopes, ?string $allowedIps, ?string $expiresAt, int $rateLimit, ?int $userId, Actor $actor): array
    {
        $name = trim($name);
        $scopes = array_values(array_intersect(array_keys(Scopes::ALL), $scopes));
        $problems = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $problems[] = 'Give the key a name (up to 100 characters), for example the system that will use it.';
        }
        if (!$scopes) {
            $problems[] = 'Choose at least one thing the key may do.';
        }
        if ($rateLimit < 10 || $rateLimit > 6000) {
            $problems[] = 'The rate limit must be between 10 and 6000 requests per minute.';
        }
        if ($expiresAt !== null && $expiresAt !== '' && (!\App\Core\Clock::valid($expiresAt) || $expiresAt <= \App\Core\Clock::today())) {
            $problems[] = 'The expiry must be a date in the future.';
        }
        if ($allowedIps !== null && trim($allowedIps) !== '') {
            foreach (self::splitIps($allowedIps) as $ip) {
                if (!self::validIpOrCidr($ip)) {
                    $problems[] = "“{$ip}” is not a valid IP address or range.";
                }
            }
        }
        if ($problems) {
            throw DomainException::of($problems);
        }
        $prefix = 'cc_' . bin2hex(random_bytes(4));
        $token = $prefix . '_' . bin2hex(random_bytes(24));
        return DB::transaction(function () use ($orgId, $name, $scopes, $allowedIps, $expiresAt, $rateLimit, $userId, $actor, $prefix, $token) {
            $uuid = Uuid::v4();
            $id = DB::insert('api_keys', [
                'uuid' => $uuid, 'organisation_id' => $orgId, 'name' => $name, 'key_prefix' => $prefix, 'key_hash' => hash('sha256', $token),
                'scopes' => implode(',', $scopes), 'allowed_ips' => trim((string) $allowedIps) ?: null, 'rate_limit_per_minute' => $rateLimit,
                'expires_at' => ($expiresAt ?: null) ? $expiresAt . ' 23:59:59' : null, 'created_by_user_id' => $userId,
            ]);
            Audit::record($orgId, $actor, 'apikey.created', 'apikey', $uuid, 'Created the API key "' . $name . '"', ['scopes' => $scopes]);
            return ['id' => $id, 'uuid' => $uuid, 'token' => $token];
        });
    }

    public static function revoke(int $orgId, int $keyId, Actor $actor): void
    {
        $k = DB::one('SELECT id, uuid, name FROM api_keys WHERE id = ? AND organisation_id = ?', [$keyId, $orgId]) ?? throw new DomainException('API key not found.');
        DB::transaction(function () use ($orgId, $k, $actor) {
            DB::update('api_keys', ['active' => 0, 'revoked_at' => gmdate('Y-m-d H:i:s')], ['id' => $k['id']]);
            Audit::record($orgId, $actor, 'apikey.revoked', 'apikey', $k['uuid'], 'Revoked the API key "' . $k['name'] . '"');
        });
    }

    /** @return list<array<string,mixed>> */
    public static function forOrg(int $orgId): array
    {
        return DB::all('SELECT id, uuid, name, key_prefix, scopes, allowed_ips, rate_limit_per_minute, expires_at, active, last_used_at, last_used_ip, created_at, revoked_at FROM api_keys WHERE organisation_id = ? ORDER BY id DESC', [$orgId]);
    }

    /**
     * Find the key for a bearer token, or null. Does not check the address or rate limit.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $token): ?array
    {
        if (!preg_match('/^(cc_[0-9a-f]{8})_[0-9a-f]{48}$/', $token, $m)) {
            return null;
        }
        $key = DB::one('SELECT * FROM api_keys WHERE key_prefix = ?', [$m[1]]);
        if (!$key || !hash_equals((string) $key['key_hash'], hash('sha256', $token))) {
            return null;
        }
        return $key;
    }

    /** Whether a key is usable now: active, not revoked, not expired. */
    public static function usable(array $key, ?string $now = null): bool
    {
        $now ??= gmdate('Y-m-d H:i:s');
        return (int) $key['active'] === 1 && $key['revoked_at'] === null && ($key['expires_at'] === null || $key['expires_at'] >= $now);
    }

    /** Whether the caller's address is allowed by the key's list (empty list = any address). */
    public static function ipAllowed(?string $allowed, string $ip): bool
    {
        if ($allowed === null || trim($allowed) === '') {
            return true;
        }
        foreach (self::splitIps($allowed) as $rule) {
            if (self::matches($rule, $ip)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    public static function splitIps(string $csv): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $csv) ?: [])));
    }

    public static function validIpOrCidr(string $rule): bool
    {
        [$ip, $bits] = array_pad(explode('/', $rule, 2), 2, null);
        $bin = filter_var($ip, FILTER_VALIDATE_IP) ? @inet_pton($ip) : false;
        if ($bin === false) {
            return false;
        }
        return $bits === null || (ctype_digit($bits) && (int) $bits <= strlen($bin) * 8);
    }

    private static function matches(string $rule, string $ip): bool
    {
        [$net, $bits] = array_pad(explode('/', $rule, 2), 2, null);
        $a = @inet_pton($net);
        $b = @inet_pton($ip);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $full = intdiv($bits, 8);
        if (substr($a, 0, $full) !== substr($b, 0, $full)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
    }
}
