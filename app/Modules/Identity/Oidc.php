<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Audit;
use App\Core\Actor;
use App\Core\Config;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Session;

/**
 * Sign in with Microsoft Entra ID (OpenID Connect, authorisation code flow with PKCE, one tenant).
 *
 * Nobody is created here: SSO only signs in people who already have a login (SCIM or an administrator created it).
 * Every id token is checked in full: signature (RS256 against the tenant's published keys), issuer, audience, tenant,
 * expiry, and the nonce for this exact sign-in.
 *
 * Settings (.env): ENTRA_TENANT_ID, ENTRA_CLIENT_ID, ENTRA_CLIENT_SECRET, and ENTRA_REDIRECT_URI (or SITE_URL).
 */
final class Oidc
{
    /** @var (callable(string,?array):array{0:int,1:string})|null  url, form fields (null = GET) → [status, body]. Replaced in tests. */
    public static $http = null;

    public static function configured(): bool
    {
        return Config::get('ENTRA_TENANT_ID') && Config::get('ENTRA_CLIENT_ID') && Config::get('ENTRA_CLIENT_SECRET') && self::redirectUri() !== '';
    }

    /** Whether people other than administrators may still use a password. Administrators always can (a way back in if SSO breaks). */
    public static function passwordAllowed(string $role): bool
    {
        return $role === 'admin' || Config::bool('PASSWORD_LOGIN', true) || !self::configured();
    }

    public static function redirectUri(): string
    {
        $u = Config::get('ENTRA_REDIRECT_URI');
        if ($u) {
            return $u;
        }
        $site = rtrim((string) Config::get('SITE_URL', ''), '/');
        return $site !== '' ? $site . '/login/entra/callback' : '';
    }

    /** Start a sign-in: returns the Microsoft address to send the browser to. Remembers state, nonce and the PKCE verifier. */
    public static function begin(string $next = '/'): string
    {
        $state = self::b64u(random_bytes(24));
        $nonce = self::b64u(random_bytes(24));
        $verifier = self::b64u(random_bytes(48));
        Session::set('oidc', ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'next' => $next, 'at' => time()]);
        return self::base() . '/authorize?' . http_build_query([
            'client_id' => Config::get('ENTRA_CLIENT_ID'), 'response_type' => 'code', 'redirect_uri' => self::redirectUri(),
            'response_mode' => 'query', 'scope' => 'openid profile email', 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => self::b64u(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Finish a sign-in. Returns the user row and where to go next.
     *
     * @param array<string,string> $query the callback's query string
     * @return array{0:array<string,mixed>,1:string}
     */
    public static function complete(array $query): array
    {
        $saved = Session::get('oidc');
        Session::forget('oidc');
        if (!is_array($saved) || time() - (int) $saved['at'] > 600) {
            throw new DomainException('That sign-in took too long or was not started here. Please try again.');
        }
        if (!empty($query['error'])) {
            throw new DomainException('Microsoft did not sign you in' . (!empty($query['error_description']) ? ': ' . mb_substr(strtok((string) $query['error_description'], "\r\n"), 0, 200) : '.'));
        }
        if (!hash_equals((string) $saved['state'], (string) ($query['state'] ?? '')) || empty($query['code'])) {
            throw new DomainException('That sign-in could not be checked. Please try again.');
        }
        [$status, $body] = self::request(self::base() . '/token', [
            'client_id' => Config::get('ENTRA_CLIENT_ID'), 'client_secret' => Config::get('ENTRA_CLIENT_SECRET'), 'grant_type' => 'authorization_code',
            'code' => $query['code'], 'redirect_uri' => self::redirectUri(), 'code_verifier' => $saved['verifier'],
        ]);
        $tok = json_decode($body, true);
        if ($status !== 200 || !is_array($tok) || empty($tok['id_token'])) {
            throw new DomainException('Microsoft would not complete the sign-in. Please try again.');
        }
        $claims = self::verify((string) $tok['id_token'], (string) $saved['nonce']);
        return [self::userFor($claims), (string) $saved['next']];
    }

    /**
     * Check an id token and return its claims. Throws a DomainException with a safe message for anything wrong.
     *
     * @return array<string,mixed>
     */
    public static function verify(string $jwt, string $nonce, ?int $now = null): array
    {
        $now ??= time();
        $bad = new DomainException('Your Microsoft sign-in could not be verified.');
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw $bad;
        }
        $header = json_decode((string) self::unb64u($parts[0]), true);
        $claims = json_decode((string) self::unb64u($parts[1]), true);
        $sig = self::unb64u($parts[2]);
        if (!is_array($header) || !is_array($claims) || $sig === null || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw $bad;   // never accept "none", HS256 or anything else
        }
        $jwk = self::signingKey((string) $header['kid']);
        $pem = $jwk ? self::pem((string) $jwk['n'], (string) $jwk['e']) : null;
        if (!$pem || openssl_verify($parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw $bad;
        }
        $tenant = (string) Config::get('ENTRA_TENANT_ID');
        $aud = $claims['aud'] ?? null;
        $ok = ($claims['iss'] ?? '') === 'https://login.microsoftonline.com/' . $tenant . '/v2.0'
            && ($claims['tid'] ?? '') === $tenant
            && (is_array($aud) ? in_array(Config::get('ENTRA_CLIENT_ID'), $aud, true) : $aud === Config::get('ENTRA_CLIENT_ID'))
            && isset($claims['exp']) && (int) $claims['exp'] > $now - 60
            && (!isset($claims['nbf']) || (int) $claims['nbf'] <= $now + 60)
            && hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            && !empty($claims['oid']);
        if (!$ok) {
            throw $bad;
        }
        return $claims;
    }

    /**
     * The login for this Microsoft identity. First time: found by the sign-in name (UPN) and linked to the stable object id,
     * so a later change of email address cannot hand the account to someone else. Never creates a user.
     *
     * @param array<string,mixed> $claims @return array<string,mixed>
     */
    public static function userFor(array $claims): array
    {
        $subject = $claims['tid'] . ':' . $claims['oid'];
        $user = DB::one('SELECT * FROM users WHERE oidc_subject = ?', [$subject]);
        if (!$user) {
            // Match on the sign-in name only. The "email" claim is not verified by Microsoft and must not be used to take over an account.
            $upn = strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? '')));
            $user = $upn !== '' ? DB::one('SELECT * FROM users WHERE email = ?', [$upn]) : null;
            if ($user && $user['oidc_subject'] !== null && $user['oidc_subject'] !== $subject) {
                $user = null;   // that login already belongs to a different Microsoft account
            }
        }
        if (!$user || $user['status'] === 'disabled' || !UserRepo::canSignIn(['status' => $user['status'] === 'invited' ? 'active' : $user['status']] + $user)) {
            throw new DomainException('Your Microsoft account is not set up for Care Core, or your access has ended. Please ask your manager or HR.');
        }
        DB::transaction(function () use ($user, $subject) {
            $set = ['oidc_subject' => $subject, 'last_login_at' => gmdate('Y-m-d H:i:s')];
            if ($user['status'] === 'invited') {
                $set += ['status' => 'active', 'invite_token_hash' => null, 'invite_expires_at' => null];   // Microsoft has proved who they are
            }
            DB::update('users', $set, ['id' => $user['id']]);
            Audit::record((int) $user['organisation_id'], Actor::user((int) $user['id'], $user['email']), 'user.signed_in_sso', 'user', $user['uuid'], 'Signed in with Microsoft');
        });
        return DB::one('SELECT * FROM users WHERE id = ?', [$user['id']]) ?? $user;
    }

    // ── The tenant's signing keys ────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private static function signingKey(string $kid): ?array
    {
        $cache = ROOT_PATH . '/storage/cache/entra-jwks-' . md5((string) Config::get('ENTRA_TENANT_ID')) . '.json';
        $keys = is_file($cache) && filemtime($cache) > time() - 3600 ? json_decode((string) file_get_contents($cache), true) : null;
        foreach (['cached', 'fresh'] as $pass) {
            if ($pass === 'fresh' || !is_array($keys)) {
                [$status, $body] = self::request('https://login.microsoftonline.com/' . rawurlencode((string) Config::get('ENTRA_TENANT_ID')) . '/discovery/v2.0/keys', null);
                $keys = $status === 200 ? json_decode($body, true) : null;
                if (is_array($keys) && !empty($keys['keys'])) {
                    @mkdir(dirname($cache), 0750, true);
                    @file_put_contents($cache, $body);
                }
            }
            foreach ($keys['keys'] ?? [] as $k) {
                if (($k['kid'] ?? null) === $kid && ($k['kty'] ?? '') === 'RSA' && isset($k['n'], $k['e'])) {
                    return $k;
                }
            }
            if ($pass === 'fresh') {
                break;
            }
        }
        return null;
    }

    /** RSA public key (JWK n and e) as a PEM that OpenSSL can verify with. */
    public static function pem(string $n, string $e): ?string
    {
        $mod = self::unb64u($n);
        $exp = self::unb64u($e);
        if ($mod === null || $exp === null || $mod === '' || $exp === '') {
            return null;
        }
        $int = static fn(string $b): string => "\x02" . self::len(strlen(($b[0] >= "\x80" ? "\x00" : '') . $b)) . ($b[0] >= "\x80" ? "\x00" : '') . $b;
        $rsa = "\x30" . self::len(strlen($int($mod) . $int($exp))) . $int($mod) . $int($exp);
        $bits = "\x03" . self::len(strlen($rsa) + 1) . "\x00" . $rsa;
        $alg = hex2bin('300d06092a864886f70d0101010500');
        $spki = "\x30" . self::len(strlen($alg . $bits)) . $alg . $bits;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($b)) . $b;
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    private static function base(): string
    {
        return 'https://login.microsoftonline.com/' . rawurlencode((string) Config::get('ENTRA_TENANT_ID')) . '/oauth2/v2.0';
    }

    /** @param array<string,string>|null $fields @return array{0:int,1:string} */
    private static function request(string $url, ?array $fields): array
    {
        if (self::$http) {
            return (self::$http)($url, $fields);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false]);
        if ($fields !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields)]);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$status, is_string($body) ? $body : ''];
    }

    private static function b64u(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    private static function unb64u(string $s): ?string
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $d === false ? null : $d;
    }
}
