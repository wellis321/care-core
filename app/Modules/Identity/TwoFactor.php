<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Totp;

/**
 * Two-step sign-in with an authenticator app (RFC 6238) and one-time recovery codes. Password sign-in asks for it once it is switched on; sign-in with Microsoft does not,
 * because Microsoft's own settings decide that. The secret is encrypted with APP_KEY; recovery codes are kept only as hashes and each works once; an app code works once.
 */
final class TwoFactor
{
    public static function on(int $userId): bool
    {
        return (bool) DB::value('SELECT 1 FROM user_security WHERE user_id = ? AND totp_enabled_at IS NOT NULL', [$userId]);
    }

    /** Start setting up: a new secret, kept encrypted and not yet in force. Returns it, to show. */
    public static function begin(int $userId): string
    {
        $s = Totp::newSecret();
        DB::run('INSERT INTO user_security (user_id, totp_secret_sealed, totp_enabled_at, totp_last_step) VALUES (?, ?, NULL, NULL) ON DUPLICATE KEY UPDATE totp_secret_sealed = VALUES(totp_secret_sealed), totp_enabled_at = NULL, totp_last_step = NULL', [$userId, Crypto::encrypt($s)]);
        return $s;
    }

    public static function pendingSecret(int $userId): ?string
    {
        $r = DB::one('SELECT totp_secret_sealed, totp_enabled_at FROM user_security WHERE user_id = ?', [$userId]);
        if (!$r || $r['totp_secret_sealed'] === null || $r['totp_enabled_at'] !== null) {
            return null;
        }
        return Crypto::decrypt($r['totp_secret_sealed']) ?: null;
    }

    /** Switch it on once the person has shown the app works. Returns the recovery codes, to show once. @return list<string> */
    public static function enable(int $orgId, array $user, string $code, Actor $actor): array
    {
        $secret = self::pendingSecret((int) $user['id']);
        if (!$secret) {
            throw new DomainException('Start again: there is nothing waiting to be switched on.');
        }
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            throw new DomainException('That code did not match. Check the time on your phone and try the next code.');
        }
        $codes = [];
        DB::transaction(function () use ($orgId, $user, $step, &$codes, $actor): void {
            DB::run('UPDATE user_security SET totp_enabled_at = ?, totp_last_step = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s'), $step, $user['id']]);
            $codes = self::newRecoveryCodes((int) $user['id']);
            Audit::record($orgId, $actor, 'twofactor.enabled', 'user', (string) $user['uuid'], 'Switched on two-step sign-in', []);
        });
        return $codes;
    }

    /** @return list<string> */
    public static function newRecoveryCodes(int $userId): array
    {
        DB::run('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
        $out = [];
        for ($i = 0; $i < 8; $i++) {
            $c = bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2));
            $out[] = $c;
            DB::run('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)', [$userId, hash('sha256', $c)]);
        }
        return $out;
    }

    public static function recoveryLeft(int $userId): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    /** Switch it off. Needs the person's password, so a stolen session cannot do it. */
    public static function disable(int $orgId, array $user, string $password, Actor $actor): void
    {
        $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new DomainException('Your password is not right.');
        }
        self::clear($orgId, $user, $actor, 'Switched off two-step sign-in');
    }

    /** An administrator removes it for someone who has lost their phone and their recovery codes. */
    public static function reset(int $orgId, array $user, Actor $actor): void
    {
        self::clear($orgId, $user, $actor, 'Two-step sign-in was reset by an administrator');
    }

    private static function clear(int $orgId, array $user, Actor $actor, string $summary): void
    {
        DB::transaction(function () use ($orgId, $user, $actor, $summary): void {
            DB::run('DELETE FROM user_security WHERE user_id = ?', [$user['id']]);
            DB::run('DELETE FROM recovery_codes WHERE user_id = ?', [$user['id']]);
            Audit::record($orgId, $actor, 'twofactor.disabled', 'user', (string) $user['uuid'], $summary, []);
        });
    }

    /** Check a code at sign-in: the app's current code or a recovery code, each only once. Wrong tries count towards the same limit as wrong passwords. */
    public static function check(array $user, string $code, string $ip): bool
    {
        $email = strtolower((string) $user['email']);
        $window = gmdate('Y-m-d H:i:s', time() - 900);
        if ((int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > ?', [$email, $window]) >= 5) {
            throw new DomainException('Too many tries. Please wait 15 minutes and sign in again.');
        }
        $ok = self::accept((int) $user['id'], $code);
        if ($ok) {
            DB::run('DELETE FROM login_attempts WHERE email = ?', [$email]);
        } else {
            DB::insert('login_attempts', ['email' => mb_substr($email, 0, 190), 'ip' => mb_substr($ip, 0, 45), 'attempted_at' => gmdate('Y-m-d H:i:s')]);
        }
        return $ok;
    }

    private static function accept(int $userId, string $code): bool
    {
        $code = strtolower(trim($code));
        $r = DB::one('SELECT * FROM user_security WHERE user_id = ? AND totp_enabled_at IS NOT NULL', [$userId]);
        if (!$r) {
            return false;
        }
        if (preg_match('/^\d{3}\s?\d{3}$/', $code)) {
            $step = Totp::verify((string) Crypto::decrypt((string) $r['totp_secret_sealed']), $code);
            if ($step === null) {
                return false;
            }
            return DB::run('UPDATE user_security SET totp_last_step = ? WHERE user_id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $userId, $step])->rowCount() === 1;   // a code works once
        }
        if (preg_match('/^[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}$/', $code)) {
            return DB::run('UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [gmdate('Y-m-d H:i:s'), $userId, hash('sha256', $code)])->rowCount() === 1;
        }
        return false;
    }
}
