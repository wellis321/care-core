<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Config;
use App\Core\Database;
use App\Core\View;

/**
 * One-click entry to the public demonstration, and the guard that keeps robots out of it. The demo has shared accounts, so a button that signs
 * anyone in must not be something a script can press in a loop: a form-shown token that must be at least two seconds old, a hidden field a person
 * never fills in, a refusal of obvious automation by user agent, and limits per address and for the whole demo. Only exists in demo mode.
 */
final class DemoEntry
{
    public const ROLES = ['manager' => 'a manager', 'staff' => 'a member of staff', 'admin' => 'an administrator'];
    public const MIN_AGE = 2;
    public const MAX_AGE = 7200;
    public const HONEYPOT = 'website';
    private const MARK = 'demo-enter';

    private static function key(): string { return (string) (Config::get('APP_KEY') ?: Config::get('DB_PASS') ?: 'care-core-demo'); }

    public static function token(): string
    {
        $t = (string) time();
        return $t . '.' . hash_hmac('sha256', 'demo-entry|' . $t, self::key());
    }

    public static function tokenOk(string $token): bool
    {
        if (!preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) return false;
        if (!hash_equals(hash_hmac('sha256', 'demo-entry|' . $m[1], self::key()), $m[2])) return false;
        $age = time() - (int) $m[1];
        return $age >= self::MIN_AGE && $age <= self::MAX_AGE;
    }

    public static function looksAutomated(?string $ua): bool
    {
        $ua = (string) $ua;
        if (strlen($ua) < 20) return true;
        return (bool) preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless|lighthouse|go-http|java\/|scrapy|httpclient|okhttp|axios|node-fetch|libwww|GPT|Claude|Perplexity/i', $ua);
    }

    /** Count this press and say whether it is allowed (20 an hour per address, 600 an hour for the whole demo). Uses the sign-in attempts table. */
    public static function allowed(string $ip): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
        Database::run('DELETE FROM login_attempts WHERE email = ? AND attempted_at < ?', [self::MARK, gmdate('Y-m-d H:i:s', time() - 86400)]);
        Database::insert('login_attempts', ['email' => self::MARK, 'ip' => mb_substr($ip, 0, 45), 'attempted_at' => gmdate('Y-m-d H:i:s')]);
        $mine = (int) Database::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at > ?', [self::MARK, mb_substr($ip, 0, 45), $since]);
        $all = (int) Database::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > ?', [self::MARK, $since]);
        return $mine <= 20 && $all <= 600;
    }

    /** The shared demo account for a role. @return array<string,mixed>|null */
    public static function userFor(string $role): ?array
    {
        if (!isset(self::ROLES[$role])) return null;
        $u = Database::one("SELECT * FROM users WHERE role = ? AND email LIKE '%@demo.lochview.test' ORDER BY id LIMIT 1", [$role]);
        return $u && UserRepo::canSignIn($u) ? $u : null;
    }

    public static function fields(): string
    {
        return '<input type="hidden" name="ft" value="' . View::e(self::token()) . '">'
            . '<div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="' . self::HONEYPOT . '" value="" tabindex="-1" autocomplete="off"></label></div>';
    }
}
