<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Audit;
use App\Core\Actor;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database as DB;
use App\Core\DomainException;

/**
 * Care Core as an OpenID Connect provider: the authorisation code flow with PKCE (S256 only), ID tokens signed RS256.
 * The other products are registered clients. A product still owns its own users and roles: this only proves who someone is and that they are still allowed in.
 *
 * Rules: redirect addresses match exactly; a code lasts 60 seconds and works once; the secret is stored as a hash; an ID token lasts 5 minutes and is bound to one product;
 * the person must belong to the same organisation as the client; no refresh tokens, no access tokens (the ID token is all a product needs).
 */
final class OidcProvider
{
    public const CODE_SECONDS = 60;
    public const TOKEN_SECONDS = 300;

    public static function issuer(): string
    {
        return Config::appUrl();
    }

    public static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array<string,mixed> */
    public static function discovery(): array
    {
        $i = self::issuer();
        return [
            'issuer' => $i, 'authorization_endpoint' => $i . '/oidc/authorize', 'token_endpoint' => $i . '/oidc/token', 'jwks_uri' => $i . '/oidc/jwks',
            'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code'], 'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'], 'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'], 'scopes_supported' => ['openid', 'profile', 'email'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'amr', 'email', 'name', 'org', 'person', 'role'],
        ];
    }

    // ---- keys ----

    /** Make sure there is a signing key; returns its id. */
    public static function ensureKey(): string
    {
        $kid = DB::value('SELECT kid FROM sso_keys WHERE active = 1 ORDER BY created_at DESC, kid DESC LIMIT 1');
        if ($kid) {
            return (string) $kid;
        }
        return self::newKey();
    }

    /** Add a new signing key (the old ones stay published until retired, so tokens already issued still verify). */
    public static function newKey(): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false || !openssl_pkey_export($res, $private)) {
            throw new \RuntimeException('Could not create a signing key.');
        }
        $pub = openssl_pkey_get_details($res)['key'];
        $kid = substr(hash('sha256', $pub), 0, 16);
        DB::run('INSERT INTO sso_keys (kid, private_enc, public_pem, active) VALUES (?, ?, ?, 1)', [$kid, Crypto::encrypt($private), $pub]);
        return $kid;
    }

    /** @return array{keys:list<array<string,string>>} */
    public static function jwks(): array
    {
        self::ensureKey();
        $keys = [];
        foreach (DB::all('SELECT kid, public_pem FROM sso_keys WHERE active = 1 ORDER BY created_at DESC') as $k) {
            $d = openssl_pkey_get_details(openssl_pkey_get_public($k['public_pem']));
            $keys[] = ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $k['kid'], 'n' => self::b64u($d['rsa']['n']), 'e' => self::b64u($d['rsa']['e'])];
        }
        return ['keys' => $keys];
    }

    /** @param array<string,mixed> $claims */
    public static function sign(array $claims): string
    {
        $kid = self::ensureKey();
        $pem = Crypto::decrypt((string) DB::value('SELECT private_enc FROM sso_keys WHERE kid = ?', [$kid]));
        $header = self::b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]));
        $body = self::b64u(json_encode($claims, JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($header . '.' . $body, $sig, $pem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign the token.');
        }
        return $header . '.' . $body . '.' . self::b64u($sig);
    }

    // ---- clients ----

    /** Register a product. Returns the client id and the secret, which is shown once and never stored in the clear. @param list<string> $redirectUris @return array{client_id:string,secret:string} */
    public static function registerClient(int $orgId, string $name, array $redirectUris, Actor $actor): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('Give the product a name (up to 100 characters).');
        }
        $uris = self::cleanUris($redirectUris);
        $clientId = 'cc_' . bin2hex(random_bytes(10));
        $secret = bin2hex(random_bytes(24));
        DB::insert('sso_clients', ['organisation_id' => $orgId, 'client_id' => $clientId, 'name' => $name, 'secret_hash' => hash('sha256', $secret), 'redirect_uris' => implode("\n", $uris)]);
        Audit::record($orgId, $actor, 'sso.client_registered', 'sso_client', null, 'Registered ' . $name . ' for single sign-in');
        return ['client_id' => $clientId, 'secret' => $secret];
    }

    /** @return string the new secret */
    public static function rotateSecret(int $orgId, int $id, Actor $actor): string
    {
        $c = DB::one('SELECT * FROM sso_clients WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
        if (!$c) {
            throw new DomainException('Product not found.');
        }
        $secret = bin2hex(random_bytes(24));
        DB::update('sso_clients', ['secret_hash' => hash('sha256', $secret)], ['id' => $id]);
        Audit::record($orgId, $actor, 'sso.secret_rotated', 'sso_client', null, 'Replaced the secret for ' . $c['name']);
        return $secret;
    }

    public static function setActive(int $orgId, int $id, bool $active, Actor $actor): void
    {
        $c = DB::one('SELECT * FROM sso_clients WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
        if (!$c) {
            throw new DomainException('Product not found.');
        }
        DB::update('sso_clients', ['active' => $active ? 1 : 0], ['id' => $id]);
        Audit::record($orgId, $actor, $active ? 'sso.client_on' : 'sso.client_off', 'sso_client', null, ($active ? 'Switched on single sign-in for ' : 'Switched off single sign-in for ') . $c['name']);
    }

    /** @param list<string> $uris @return list<string> */
    private static function cleanUris(array $uris): array
    {
        $out = [];
        foreach ($uris as $u) {
            $u = trim($u);
            if ($u === '') {
                continue;
            }
            $p = parse_url($u);
            $local = in_array($p['host'] ?? '', ['localhost', '127.0.0.1'], true);
            if (!$p || empty($p['host']) || (($p['scheme'] ?? '') !== 'https' && !($local && ($p['scheme'] ?? '') === 'http')) || isset($p['fragment']) || isset($p['user'])) {
                throw new DomainException('Each redirect address must be a full https:// address (http only for localhost), with no # part: ' . mb_substr($u, 0, 80));
            }
            $out[] = $u;
        }
        if (!$out) {
            throw new DomainException('Give at least one redirect address.');
        }
        return array_values(array_unique($out));
    }

    // ---- authorise ----

    /**
     * Check an authorisation request. Throws a DomainException with `->getCode() === 0` for anything that must NOT be redirected (unknown product, bad redirect address),
     * or returns [client, null] when fine, or [client, error] when the request is bad but can be sent back to the product.
     *
     * @param array<string,string> $q
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function checkRequest(array $q): array
    {
        $c = DB::one('SELECT * FROM sso_clients WHERE client_id = ? AND active = 1', [(string) ($q['client_id'] ?? '')]);
        if (!$c) {
            throw new DomainException('This product is not registered for single sign-in.');
        }
        $uri = (string) ($q['redirect_uri'] ?? '');
        if ($uri === '' || !in_array($uri, explode("\n", (string) $c['redirect_uris']), true)) {
            throw new DomainException('This product asked to send you back to an address that is not on its list.');
        }
        $err = null;
        if (($q['response_type'] ?? '') !== 'code') {
            $err = 'unsupported_response_type';
        } elseif (!in_array('openid', preg_split('/\s+/', trim((string) ($q['scope'] ?? ''))) ?: [], true)) {
            $err = 'invalid_scope';
        } elseif (($q['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_\-]{43}$/', (string) ($q['code_challenge'] ?? ''))) {
            $err = 'invalid_request';
        }
        return [$c, $err];
    }

    /** Make a one-time code for a signed-in person. */
    public static function issueCode(array $client, array $user, array $q, int $authTime, string $amr): string
    {
        $code = self::b64u(random_bytes(32));
        DB::run('DELETE FROM sso_codes WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 3600)]);
        DB::insert('sso_codes', [
            'code_hash' => hash('sha256', $code), 'client_id' => $client['id'], 'user_id' => $user['id'], 'redirect_uri' => $q['redirect_uri'],
            'nonce' => isset($q['nonce']) ? mb_substr((string) $q['nonce'], 0, 200) : null, 'code_challenge' => $q['code_challenge'], 'auth_time' => $authTime, 'amr' => $amr,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::CODE_SECONDS),
        ]);
        Audit::record((int) $client['organisation_id'], Actor::user((int) $user['id'], (string) $user['email'], $_SERVER['REMOTE_ADDR'] ?? null), 'sso.code_issued', 'sso_client', null, 'Signed in to ' . $client['name'] . ' through Care Core');
        return $code;
    }

    // ---- token ----

    /**
     * Swap a code for an ID token. Throws DomainException whose message is an OAuth error name (invalid_client, invalid_grant, invalid_request).
     *
     * @param array<string,string> $post
     * @return array<string,mixed>
     */
    public static function exchange(array $post, ?string $basicId, ?string $basicSecret): array
    {
        $clientId = $basicId ?? (string) ($post['client_id'] ?? '');
        $secret = $basicSecret ?? (string) ($post['client_secret'] ?? '');
        $c = DB::one('SELECT * FROM sso_clients WHERE client_id = ? AND active = 1', [$clientId]);
        if (!$c || $secret === '' || !hash_equals((string) $c['secret_hash'], hash('sha256', $secret))) {
            throw new DomainException('invalid_client');
        }
        if (($post['grant_type'] ?? '') !== 'authorization_code' || empty($post['code']) || empty($post['redirect_uri']) || empty($post['code_verifier'])) {
            throw new DomainException('invalid_request');
        }
        $hash = hash('sha256', (string) $post['code']);
        // Mark it used first, in one statement, so two requests with the same code can never both win.
        $claimed = DB::run('UPDATE sso_codes SET used_at = ? WHERE code_hash = ? AND client_id = ? AND used_at IS NULL AND expires_at >= ?',
            [gmdate('Y-m-d H:i:s'), $hash, $c['id'], gmdate('Y-m-d H:i:s')])->rowCount();
        $row = $claimed === 1 ? DB::one('SELECT * FROM sso_codes WHERE code_hash = ?', [$hash]) : null;
        if (!$row || !hash_equals((string) $row['redirect_uri'], (string) $post['redirect_uri'])
            || !hash_equals((string) $row['code_challenge'], self::b64u(hash('sha256', (string) $post['code_verifier'], true)))) {
            throw new DomainException('invalid_grant');
        }
        $u = DB::one('SELECT u.*, o.uuid AS org_uuid FROM users u JOIN organisations o ON o.id = u.organisation_id WHERE u.id = ?', [$row['user_id']]);
        if (!$u || (int) $u['organisation_id'] !== (int) $c['organisation_id'] || !UserRepo::canSignIn($u)) {
            throw new DomainException('invalid_grant');
        }
        $p = $u['person_id'] ? DB::one('SELECT uuid, first_name, last_name, preferred_name FROM persons WHERE id = ?', [$u['person_id']]) : null;
        $now = time();
        $claims = [
            'iss' => self::issuer(), 'sub' => $u['uuid'], 'aud' => $c['client_id'], 'iat' => $now, 'exp' => $now + self::TOKEN_SECONDS,
            'auth_time' => (int) $row['auth_time'], 'amr' => explode(',', (string) $row['amr']), 'email' => $u['email'], 'email_verified' => true,
            'org' => $u['org_uuid'], 'role' => $u['role'],
        ];
        if ($row['nonce'] !== null) {
            $claims['nonce'] = $row['nonce'];
        }
        if ($p) {
            $claims['person'] = $p['uuid'];
            $claims['name'] = trim(($p['preferred_name'] ?: $p['first_name']) . ' ' . $p['last_name']);
        }
        DB::update('sso_clients', ['last_used_at' => gmdate('Y-m-d H:i:s')], ['id' => $c['id']]);
        return ['id_token' => self::sign($claims), 'token_type' => 'Bearer', 'expires_in' => self::TOKEN_SECONDS];
    }
}
