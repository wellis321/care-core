<?php
declare(strict_types=1);

use App\Core\Database as DB;
use App\Core\Session;
use App\Modules\Identity\Oidc;
use App\Modules\Identity\UserRepo;

/** A throwaway Entra: an RSA key, a JWKS, and a token endpoint that hands out signed id tokens. */
final class FakeEntra
{
    public $key;
    public string $kid;
    public string $tenant;
    public string $client = 'client-1234';
    /** @var array<string,mixed> claim overrides for the next token */
    public array $claims = [];
    public ?string $alg = null;
    public bool $tamper = false;

    public string $upn;
    private static ?self $last = null;

    public function __construct()
    {
        self::$last?->cleanup();
        self::$last = $this;
        $this->upn = 'ada' . bin2hex(random_bytes(4)) . '@example.test';
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->kid = 'kid-' . bin2hex(random_bytes(4));
        $this->tenant = sprintf('%08x-0000-4000-8000-%012x', random_int(1, PHP_INT_MAX >> 40), random_int(1, PHP_INT_MAX >> 16));
        putenv('ENTRA_TENANT_ID=' . $this->tenant);
        putenv('ENTRA_CLIENT_ID=' . $this->client);
        putenv('ENTRA_CLIENT_SECRET=secret');
        putenv('ENTRA_REDIRECT_URI=https://core.example.test/login/entra/callback');
        $_SESSION = [];
        Oidc::$http = fn(string $url, ?array $f) => $this->serve($url, $f);
    }

    public function cleanup(): void
    {
        @unlink(ROOT_PATH . '/storage/cache/entra-jwks-' . md5($this->tenant) . '.json');
        putenv('ENTRA_TENANT_ID');
        putenv('ENTRA_CLIENT_ID');
        putenv('ENTRA_CLIENT_SECRET');
        putenv('ENTRA_REDIRECT_URI');
        putenv('PASSWORD_LOGIN');
        Oidc::$http = null;
    }

    private static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public function jwk(): array
    {
        $d = openssl_pkey_get_details($this->key);
        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid, 'n' => self::b64u($d['rsa']['n']), 'e' => self::b64u($d['rsa']['e'])];
    }

    /** @param array<string,mixed> $over */
    public function token(string $nonce, array $over = []): string
    {
        $claims = $over + $this->claims + [
            'iss' => 'https://login.microsoftonline.com/' . $this->tenant . '/v2.0', 'aud' => $this->client, 'tid' => $this->tenant, 'oid' => 'oid-1111',
            'preferred_username' => $this->upn, 'nonce' => $nonce, 'iat' => time(), 'nbf' => time(), 'exp' => time() + 3600,
        ];
        $h = self::b64u(json_encode(['alg' => $this->alg ?? 'RS256', 'typ' => 'JWT', 'kid' => $this->kid]));
        $p = self::b64u(json_encode($claims));
        $sig = '';
        if ($this->alg === 'none') {
            return "$h.$p.";
        }
        openssl_sign("$h.$p", $sig, $this->key, OPENSSL_ALGO_SHA256);
        if ($this->tamper) {
            $p = self::b64u(json_encode(['oid' => 'oid-evil'] + $claims));
        }
        return "$h.$p." . self::b64u($sig);
    }

    /** @return array{0:int,1:string} */
    private function serve(string $url, ?array $fields): array
    {
        if (str_contains($url, '/discovery/v2.0/keys')) {
            return [200, json_encode(['keys' => [$this->jwk()]])];
        }
        if (str_contains($url, '/oauth2/v2.0/token')) {
            return [200, json_encode(['id_token' => $this->token($_SESSION['__nonce'] ?? ''), 'token_type' => 'Bearer'])];
        }
        return [404, ''];
    }

    /** Run begin(), then return the callback query a successful Microsoft sign-in would send. @return array<string,string> */
    public function callbackQuery(string $next = '/'): array
    {
        $url = Oidc::begin($next);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $_SESSION['__nonce'] = $q['nonce'];
        return ['code' => 'abc', 'state' => $q['state']];
    }
}

function ssoUser(int $org, string $email, string $status = 'active', ?string $endsOn = null): array
{
    $u = UserRepo::create($org, null, $email, 'staff', Fx::actor(), $status === 'invited');
    DB::update('users', ['status' => $status, 'access_ends_on' => $endsOn], ['id' => $u['id']]);
    return $u;
}

test('sso: a valid Microsoft sign-in signs in an existing login, matched by sign-in name, and links the stable object id', function () {
    $org = Fx::org();
    $e = new FakeEntra();
    ssoUser($org, $e->upn);
    [$user, $next] = Oidc::complete($e->callbackQuery('/people'));
    eq($user['email'], $e->upn);
    eq($next, '/people');
    eq($user['oidc_subject'], $e->tenant . ':oid-1111');
    eq(Fx::count('audit_log', "organisation_id = ? AND action = 'user.signed_in_sso'", [$org]), 1);
});

test('sso: after linking, the object id wins even if the email changes; the old email no longer matters', function () {
    $org = Fx::org();
    $e = new FakeEntra();
    ssoUser($org, $e->upn);
    $e->claims = ['oid' => 'oid-bea'];
    Oidc::complete($e->callbackQuery());
    DB::run("UPDATE users SET email = 'bea.renamed@example.test' WHERE email = ?", [$e->upn]);
    $e->claims = ['preferred_username' => 'someone.else@example.test', 'oid' => 'oid-bea'];
    [$user] = Oidc::complete($e->callbackQuery());
    eq($user['email'], 'bea.renamed@example.test');
});

test('sso: the unverified "email" claim is never used to find an account', function () {
    $org = Fx::org();
    $e = new FakeEntra();
    ssoUser($org, 'victim' . bin2hex(random_bytes(3)) . '@example.test');
    $victim = DB::value("SELECT email FROM users ORDER BY id DESC LIMIT 1");
    $e->claims = ['preferred_username' => 'attacker@evil.test', 'email' => $victim, 'oid' => 'oid-evil'];
    throws(fn() => Oidc::complete($e->callbackQuery()), 'not set up');
});

test('sso: a login already linked to one Microsoft account cannot be taken by another with the same name', function () {
    $org = Fx::org();
    $e = new FakeEntra();
    ssoUser($org, $e->upn);
    $e->claims = ['oid' => 'oid-first'];
    Oidc::complete($e->callbackQuery());
    $e->claims = ['oid' => 'oid-second'];
    throws(fn() => Oidc::complete($e->callbackQuery()), 'not set up');
});

test('sso: nobody is created; unknown, disabled and ended logins are refused', function () {
    $org = Fx::org();
    $e = new FakeEntra();
    $before = Fx::count('users');
    throws(fn() => Oidc::complete($e->callbackQuery()), 'not set up');
    eq(Fx::count('users'), $before, 'no account is created by signing in');
    ssoUser($org, $e->upn, 'disabled');
    throws(fn() => Oidc::complete($e->callbackQuery()), 'not set up');
    DB::run('DELETE FROM users WHERE email = ?', [$e->upn]);
    ssoUser($org, $e->upn, 'active', '2020-01-01');
    throws(fn() => Oidc::complete($e->callbackQuery()), 'not set up');
});

test('sso: an invited login becomes active when Microsoft proves who they are, and the invitation is spent', function () {
    $org = Fx::org();
    $e = new FakeEntra();
    $u = ssoUser($org, $e->upn, 'invited');
    ok($u['invite_token'] !== null);
    Oidc::complete($e->callbackQuery());
    $row = DB::one('SELECT status, invite_token_hash FROM users WHERE id = ?', [$u['id']]);
    eq($row['status'], 'active');
    eq($row['invite_token_hash'], null);
});

test('sso: every way of forging or replaying a token is refused', function () {
    $org = Fx::org();
    $e = new FakeEntra();
    ssoUser($org, $e->upn);
    $cases = [
        'wrong audience'  => fn() => $e->claims = ['aud' => 'some-other-app'],
        'wrong issuer'    => fn() => $e->claims = ['iss' => 'https://login.microsoftonline.com/other-tenant/v2.0'],
        'wrong tenant'    => fn() => $e->claims = ['tid' => 'other-tenant'],
        'expired'         => fn() => $e->claims = ['exp' => time() - 7200],
        'not yet valid'   => fn() => $e->claims = ['nbf' => time() + 7200],
        'wrong nonce'     => fn() => $e->claims = ['nonce' => 'not-the-nonce'],
        'no object id'    => fn() => $e->claims = ['oid' => ''],
    ];
    foreach ($cases as $name => $set) {
        $e->claims = [];
        $set();
        try {
            Oidc::complete($e->callbackQuery());
            throw new AssertionError("$name was accepted");
        } catch (App\Core\DomainException $x) {
            ok(true);
        }
    }
    $e->claims = [];
    foreach (['none', 'HS256'] as $alg) {
        $e->alg = $alg;
        try {
            Oidc::complete($e->callbackQuery());
            throw new AssertionError("algorithm $alg was accepted");
        } catch (App\Core\DomainException $x) {
            ok(true);
        }
    }
    $e->alg = null;
    $e->tamper = true;
    try {
        Oidc::complete($e->callbackQuery());
        throw new AssertionError('a tampered token was accepted');
    } catch (App\Core\DomainException $x) {
        ok(true);
    }
    $e->tamper = false;
    // The real thing still works afterwards.
    [$user] = Oidc::complete($e->callbackQuery());
    eq($user['email'], $e->upn);
});

test('sso: the state must match, the sign-in expires, and a callback cannot be replayed', function () {
    $org = Fx::org();
    $e = new FakeEntra();
    ssoUser($org, $e->upn);
    $q = $e->callbackQuery();
    throws(fn() => Oidc::complete(['state' => 'forged'] + $q), 'could not be checked');
    $q = $e->callbackQuery();
    Oidc::complete($q);
    throws(fn() => Oidc::complete($q), 'took too long');   // the saved state was used up
    $q = $e->callbackQuery();
    $_SESSION['oidc']['at'] = time() - 3600;
    throws(fn() => Oidc::complete($q), 'took too long');
    throws(fn() => Oidc::complete(['code' => 'x', 'state' => 'y']), 'took too long');
    $e->callbackQuery();
    throws(fn() => Oidc::complete(['error' => 'access_denied', 'error_description' => 'The user cancelled', 'state' => 'x']), 'did not sign you in');
});

test('sso: the sign-in address asks for the authorisation code flow with PKCE, state and nonce, for this tenant only', function () {
    $e = new FakeEntra();
    $url = Oidc::begin('/x');
    ok(str_starts_with($url, 'https://login.microsoftonline.com/' . $e->tenant . '/oauth2/v2.0/authorize?'));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    eq($q['response_type'], 'code');
    eq($q['code_challenge_method'], 'S256');
    eq($q['client_id'], $e->client);
    ok(strlen($q['state']) >= 30 && strlen($q['nonce']) >= 30 && strlen($q['code_challenge']) >= 40);
    eq($q['redirect_uri'], 'https://core.example.test/login/entra/callback');
    eq(rtrim(strtr(base64_encode(hash('sha256', $_SESSION['oidc']['verifier'], true)), '+/', '-_'), '='), $q['code_challenge'], 'the challenge matches the verifier we keep');
});

test('sso: with password sign-in switched off only administrators keep a password', function () {
    $e = new FakeEntra();
    ok(Oidc::passwordAllowed('staff'));
    putenv('PASSWORD_LOGIN=false');
    ok(!Oidc::passwordAllowed('staff') && !Oidc::passwordAllowed('manager'));
    ok(Oidc::passwordAllowed('admin'), 'an administrator can still get in if Microsoft is down or misconfigured');
});

test('sso: without settings nothing is offered and passwords carry on working', function () {
    (new FakeEntra())->cleanup();
    putenv('ENTRA_TENANT_ID');
    putenv('ENTRA_CLIENT_ID');
    putenv('ENTRA_CLIENT_SECRET');
    ok(!Oidc::configured());
    putenv('PASSWORD_LOGIN=false');
    ok(Oidc::passwordAllowed('staff'), 'switching passwords off does nothing until Microsoft sign-in is configured');
    putenv('PASSWORD_LOGIN');
});
