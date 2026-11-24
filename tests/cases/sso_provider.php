<?php
declare(strict_types=1);

use App\Core\Database as DB;
use App\Modules\Identity\OidcProvider as P;
use App\Modules\Identity\UserRepo;

function sso_setup(): array
{
    $org = Fx::org();
    $reg = P::registerClient($org, 'Incident Core', ['https://incidents.example.test/login/care-core/callback'], Fx::actor());
    $client = DB::one('SELECT * FROM sso_clients WHERE client_id = ?', [$reg['client_id']]);
    $u = UserRepo::create($org, null, 'sso' . bin2hex(random_bytes(4)) . '@example.test', 'manager', Fx::actor(), false, 'a long enough password');
    $user = DB::one('SELECT * FROM users WHERE id = ?', [$u['id']]);
    $verifier = P::b64u(random_bytes(40));
    $q = ['client_id' => $reg['client_id'], 'redirect_uri' => 'https://incidents.example.test/login/care-core/callback', 'response_type' => 'code', 'scope' => 'openid email',
          'code_challenge' => P::b64u(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'nonce' => 'n-123', 'state' => 's'];
    return [$org, $reg, $client, $user, $q, $verifier];
}

function sso_post(array $reg, string $code, string $verifier, array $over = []): array
{
    return $over + ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://incidents.example.test/login/care-core/callback', 'code_verifier' => $verifier,
                    'client_id' => $reg['client_id'], 'client_secret' => $reg['secret']];
}

function sso_verify(string $jwt): array
{
    [$h, $b, $s] = explode('.', $jwt);
    $header = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
    $key = null;
    foreach (P::jwks()['keys'] as $k) { if ($k['kid'] === $header['kid']) { $key = $k; } }
    ok($key !== null, 'the signing key is published');
    // Rebuild the public key from n and e, as a product would.
    $der = function (string $raw): string { return (ord($raw[0]) > 127 ? "\x00" : '') . $raw; };
    $n = $der(base64_decode(strtr($key['n'], '-_', '+/'))); $e = $der(base64_decode(strtr($key['e'], '-_', '+/')));
    $len = fn(string $x): string => strlen($x) < 128 ? chr(strlen($x)) : (strlen($x) < 256 ? "\x81" . chr(strlen($x)) : "\x82" . pack('n', strlen($x)));
    $int = fn(string $x): string => "\x02" . $len($x) . $x;
    $seq = fn(string $x): string => "\x30" . $len($x) . $x;
    $rsa = $seq($int($n) . $int($e));
    $spki = $seq($seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00") . "\x03" . $len("\x00" . $rsa) . "\x00" . $rsa);
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $good = openssl_verify($h . '.' . $b, base64_decode(strtr($s, '-_', '+/')), $pem, OPENSSL_ALGO_SHA256) === 1;
    ok($good, 'the signature checks against the published key');
    return json_decode(base64_decode(strtr($b, '-_', '+/')), true);
}

test('sso: discovery names the endpoints and only the safe options', function () {
    $d = P::discovery();
    ok(str_ends_with($d['authorization_endpoint'], '/oidc/authorize') && str_ends_with($d['token_endpoint'], '/oidc/token'));
    eq($d['code_challenge_methods_supported'], ['S256']);
    eq($d['id_token_signing_alg_values_supported'], ['RS256']);
});

test('sso: a registered product gets a secret that is stored only as a hash', function () {
    [, $reg, $client] = sso_setup();
    ok(strlen($reg['secret']) === 48 && $client['secret_hash'] === hash('sha256', $reg['secret']) && !str_contains(json_encode($client), $reg['secret']));
});

test('sso: redirect addresses must be https (http only for localhost) and exact', function () {
    $org = Fx::org();
    throws(fn() => P::registerClient($org, 'X', ['http://evil.example/cb'], Fx::actor()), 'https');
    throws(fn() => P::registerClient($org, 'X', ['https://a.example/cb#frag'], Fx::actor()), 'https');
    throws(fn() => P::registerClient($org, 'X', [], Fx::actor()), 'at least one');
    ok(is_array(P::registerClient($org, 'Local', ['http://localhost:8123/cb'], Fx::actor())));
    [, , , , $q] = sso_setup();
    throws(fn() => P::checkRequest(['redirect_uri' => 'https://incidents.example.test/login/care-core/callback/'] + $q), 'not on its list');
    throws(fn() => P::checkRequest(['client_id' => 'cc_unknown'] + $q), 'not registered');
});

test('sso: a request without PKCE S256, or without openid, is sent back as an error', function () {
    [, , , , $q] = sso_setup();
    eq(P::checkRequest($q)[1], null);
    eq(P::checkRequest(['code_challenge_method' => 'plain'] + $q)[1], 'invalid_request');
    eq(P::checkRequest(['code_challenge' => 'short'] + $q)[1], 'invalid_request');
    eq(P::checkRequest(['scope' => 'email'] + $q)[1], 'invalid_scope');
    eq(P::checkRequest(['response_type' => 'token'] + $q)[1], 'unsupported_response_type');
});

test('sso: a code becomes a signed ID token with who they are and how they signed in', function () {
    [$org, $reg, $client, $user, $q, $verifier] = sso_setup();
    $code = P::issueCode($client, $user, $q, time() - 5, 'pwd,otp');
    $out = P::exchange(sso_post($reg, $code, $verifier), null, null);
    $c = sso_verify($out['id_token']);
    eq($c['iss'], P::issuer()); eq($c['aud'], $reg['client_id']); eq($c['sub'], $user['uuid']); eq($c['nonce'], 'n-123'); eq($c['amr'], ['pwd', 'otp']); eq($c['email'], $user['email']);
    ok($c['exp'] - $c['iat'] === 300 && $c['exp'] > time());
    eq($c['org'], DB::value('SELECT uuid FROM organisations WHERE id = ?', [$org]));
});

test('sso: a code works once, and the secret may come in a Basic header', function () {
    [, $reg, $client, $user, $q, $verifier] = sso_setup();
    $code = P::issueCode($client, $user, $q, time(), 'pwd');
    $post = sso_post($reg, $code, $verifier); unset($post['client_id'], $post['client_secret']);
    ok(isset(P::exchange($post, $reg['client_id'], $reg['secret'])['id_token']));
    throws(fn() => P::exchange(sso_post($reg, $code, $verifier), null, null), 'invalid_grant');
});

test('sso: wrong secret, wrong verifier, wrong redirect or another product cannot use a code', function () {
    [, $reg, $client, $user, $q, $verifier] = sso_setup();
    $code = P::issueCode($client, $user, $q, time(), 'pwd');
    throws(fn() => P::exchange(sso_post($reg, $code, $verifier, ['client_secret' => 'nope']), null, null), 'invalid_client');
    throws(fn() => P::exchange(sso_post($reg, $code, 'x' . $verifier), null, null), 'invalid_grant');
    $code2 = P::issueCode($client, $user, $q, time(), 'pwd');
    throws(fn() => P::exchange(sso_post($reg, $code2, $verifier, ['redirect_uri' => 'https://incidents.example.test/other']), null, null), 'invalid_grant');
    [, $reg2] = sso_setup();
    $code3 = P::issueCode($client, $user, $q, time(), 'pwd');
    throws(fn() => P::exchange(sso_post($reg2, $code3, $verifier), null, null), 'invalid_grant');
});

test('sso: an expired code is refused', function () {
    [, $reg, $client, $user, $q, $verifier] = sso_setup();
    $code = P::issueCode($client, $user, $q, time(), 'pwd');
    DB::run('UPDATE sso_codes SET expires_at = ? WHERE code_hash = ?', [gmdate('Y-m-d H:i:s', time() - 5), hash('sha256', $code)]);
    throws(fn() => P::exchange(sso_post($reg, $code, $verifier), null, null), 'invalid_grant');
});

test('sso: someone who has left or been switched off between the code and the token gets nothing', function () {
    [, $reg, $client, $user, $q, $verifier] = sso_setup();
    $code = P::issueCode($client, $user, $q, time(), 'pwd');
    DB::update('users', ['status' => 'disabled'], ['id' => $user['id']]);
    throws(fn() => P::exchange(sso_post($reg, $code, $verifier), null, null), 'invalid_grant');
    DB::update('users', ['status' => 'active', 'access_ends_on' => gmdate('Y-m-d', time() - 86400 * 2)], ['id' => $user['id']]);
    $code = P::issueCode($client, $user, $q, time(), 'pwd');
    throws(fn() => P::exchange(sso_post($reg, $code, $verifier), null, null), 'invalid_grant');
});

test('sso: a person from another organisation cannot be signed in to this organisation\'s product', function () {
    [, $reg, $client, , $q, $verifier] = sso_setup();
    $other = Fx::org();
    $u = UserRepo::create($other, null, 'oth' . bin2hex(random_bytes(4)) . '@example.test', 'manager', Fx::actor(), false, 'a long enough password');
    $code = P::issueCode($client, DB::one('SELECT * FROM users WHERE id = ?', [$u['id']]), $q, time(), 'pwd');
    throws(fn() => P::exchange(sso_post($reg, $code, $verifier), null, null), 'invalid_grant');
});

test('sso: switching a product off, or replacing its secret, stops the old way in', function () {
    [$org, $reg, $client, $user, $q, $verifier] = sso_setup();
    $new = P::rotateSecret($org, (int) $client['id'], Fx::actor());
    $code = P::issueCode($client, $user, $q, time(), 'pwd');
    throws(fn() => P::exchange(sso_post($reg, $code, $verifier), null, null), 'invalid_client');
    ok(isset(P::exchange(sso_post($reg, $code, $verifier, ['client_secret' => $new]), null, null)['id_token']), 'the new secret works');
    P::setActive($org, (int) $client['id'], false, Fx::actor());
    throws(fn() => P::checkRequest($q), 'not registered');
});

test('sso: the private signing key is stored encrypted, and a new key keeps old tokens verifiable', function () {
    P::ensureKey();
    $row = DB::one('SELECT * FROM sso_keys WHERE active = 1 ORDER BY created_at LIMIT 1');
    ok(\App\Core\Crypto::isEncrypted($row['private_enc']) && !str_contains($row['private_enc'], 'PRIVATE KEY'));
    $before = count(P::jwks()['keys']);
    P::newKey();
    ok(count(P::jwks()['keys']) === $before + 1);
});
