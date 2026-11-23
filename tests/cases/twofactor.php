<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database as DB;
use App\Core\Totp;
use App\Modules\Identity\TwoFactor;
use App\Modules\Identity\UserRepo;

/** A user with a password, and two-step sign-in switched on. @return array{0:array<string,mixed>,1:string,2:list<string>} */
function tf_user(int $org, bool $on = true): array
{
    $email = 'tf' . bin2hex(random_bytes(4)) . '@example.test';
    $u = UserRepo::create($org, null, $email, 'manager', Fx::actor(), false, 'a long enough password');
    $u = DB::one('SELECT * FROM users WHERE id = ?', [$u['id']]);
    $secret = ''; $codes = [];
    if ($on) {
        $secret = TwoFactor::begin((int) $u['id']);
        $codes = TwoFactor::enable($org, $u, Totp::code($secret), Fx::actor());
    }
    return [$u, $secret, $codes];
}

test('twofactor: the authenticator codes follow the standard (RFC 6238 test values)', function () {
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';   // "12345678901234567890"
    eq(Totp::code($secret, 59, 8), '94287082');
    eq(Totp::code($secret, 1111111109, 8), '07081804');
    eq(Totp::code($secret, 20000000000, 8), '65353130');
});

test('twofactor: setting it up needs a code from the app, and gives eight one-time recovery codes', function () {
    $org = Fx::org();
    [$u] = tf_user($org, false);
    $secret = TwoFactor::begin((int) $u['id']);
    ok(!TwoFactor::on((int) $u['id']), 'not on until proved');
    throws(fn() => TwoFactor::enable($org, $u, '000000', Fx::actor()), 'did not match');
    $codes = TwoFactor::enable($org, $u, Totp::code($secret), Fx::actor());
    ok(TwoFactor::on((int) $u['id']) && count($codes) === 8 && TwoFactor::recoveryLeft((int) $u['id']) === 8);
    ok(preg_match('/^[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}$/', $codes[0]) === 1);
});

test('twofactor: the secret is stored encrypted and recovery codes only as hashes', function () {
    $org = Fx::org();
    [$u, $secret, $codes] = tf_user($org);
    $stored = (string) DB::value('SELECT totp_secret_sealed FROM user_security WHERE user_id = ?', [$u['id']]);
    ok($stored !== $secret && !str_contains($stored, $secret) && str_starts_with($stored, 'enc2:'));
    $hashes = array_column(DB::all('SELECT code_hash FROM recovery_codes WHERE user_id = ?', [$u['id']]), 'code_hash');
    ok(!in_array($codes[0], $hashes, true) && in_array(hash('sha256', $codes[0]), $hashes, true));
});

test('twofactor: an app code works once, a wrong code does not work, and a recovery code works once', function () {
    $org = Fx::org();
    [$u, $secret, $codes] = tf_user($org);
    // the setup code is the current step and was used; the next step's code is accepted once
    $next = Totp::code($secret, time() + 30);
    ok(TwoFactor::check($u, $next, '10.0.0.1'), 'a fresh code is accepted');
    ok(!TwoFactor::check($u, $next, '10.0.0.1'), 'the same code is refused the second time');
    ok(TwoFactor::check($u, $codes[0], '10.0.0.1'), 'a recovery code is accepted');
    ok(!TwoFactor::check($u, $codes[0], '10.0.0.1'), 'and refused the second time');
    ok(TwoFactor::recoveryLeft((int) $u['id']) === 7);
});

test('twofactor: wrong codes are limited like wrong passwords', function () {
    $org = Fx::org();
    [$u] = tf_user($org);
    for ($i = 0; $i < 5; $i++) {
        ok(!TwoFactor::check($u, '000000', '10.0.0.2'));
    }
    throws(fn() => TwoFactor::check($u, '000000', '10.0.0.2'), 'Too many');
});

test('twofactor: it can be switched off only with the password, and an administrator can reset it', function () {
    $org = Fx::org();
    [$u] = tf_user($org);
    throws(fn() => TwoFactor::disable($org, $u, 'wrong password', Fx::actor()), 'not right');
    ok(TwoFactor::on((int) $u['id']));
    TwoFactor::disable($org, $u, 'a long enough password', Fx::actor());
    ok(!TwoFactor::on((int) $u['id']) && TwoFactor::recoveryLeft((int) $u['id']) === 0);
    [$v] = tf_user($org);
    TwoFactor::reset($org, $v, Fx::actor());
    ok(!TwoFactor::on((int) $v['id']));
    ok((int) DB::value("SELECT COUNT(*) FROM audit_log WHERE organisation_id = ? AND action IN ('twofactor.enabled','twofactor.disabled')", [$org]) >= 4, 'each change is in the activity log');
});

test('twofactor: the password step alone does not sign anyone in when it is on', function () {
    $org = Fx::org();
    [$u] = tf_user($org);
    $_SESSION = [];
    $v = Auth::verifyPassword($u['email'], 'a long enough password', '10.0.0.3');
    eq((int) $v['id'], (int) $u['id']);
    ok(empty($_SESSION['user_id']), 'verifying the password starts no session');
    Auth::complete($v);
    eq((int) ($_SESSION['user_id'] ?? 0), (int) $u['id']);
    [$w] = tf_user($org, false);
    $_SESSION = [];
    Auth::attempt($w['email'], 'a long enough password', '10.0.0.4');
    eq((int) ($_SESSION['user_id'] ?? 0), (int) $w['id'], 'without it, sign-in is as before');
});
