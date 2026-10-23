<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Identity\UserRepo;

/** Sign-in, the current user, and what each role may do. */
final class Auth
{
    private const PERMISSIONS = [
        'admin'   => ['*'],
        'manager' => ['people.view', 'people.edit', 'staff.manage', 'recruitment.manage', 'teams.view', 'teams.manage', 'care.view', 'care.manage', 'tasks.view', 'tasks.manage', 'audit.view', 'reconcile.view'],
        'staff'   => ['people.view_staff', 'teams.view', 'tasks.view'],
    ];

    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    /** @return array<string,mixed> the user, or throws a DomainException with a message safe to show */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        $window = gmdate('Y-m-d H:i:s', time() - 900);
        $byEmail = (int) Database::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > ?', [$email, $window]);
        $byIp = (int) Database::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?', [$ip, $window]);
        if ($byEmail >= 5 || $byIp >= 20) {
            throw new DomainException('Too many sign-in attempts. Please wait 15 minutes and try again.');
        }
        $user = Database::one('SELECT * FROM users WHERE email = ?', [$email]);
        $hash = $user['password_hash'] ?? password_hash('not-a-real-password', PASSWORD_DEFAULT);   // equal work whether or not the account exists
        $ok = password_verify($password, (string) $hash) && $user && UserRepo::canSignIn($user) && \App\Modules\Identity\Oidc::passwordAllowed((string) $user['role']);
        if (!$ok) {
            Database::insert('login_attempts', ['email' => mb_substr($email, 0, 190), 'ip' => mb_substr($ip, 0, 45), 'attempted_at' => gmdate('Y-m-d H:i:s')]);
            throw new DomainException('That email address or password is not right.');
        }
        Database::run('DELETE FROM login_attempts WHERE email = ?', [$email]);
        Database::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], ['id' => $user['id']]);
        session_regenerate_id(true);
        Session::set('user_id', (int) $user['id']);
        self::$user = null;
        return $user;
    }

    /** Start a session for a user who has already proved who they are elsewhere (single sign-on). */
    public static function signInAs(array $user): void
    {
        session_regenerate_id(true);
        Session::set('user_id', (int) $user['id']);
        self::$user = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::$user = null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user === null) {
            $id = Session::get('user_id');
            if (!$id) {
                return null;
            }
            $u = Database::one('SELECT u.*, o.name AS org_name, o.slug AS org_slug FROM users u JOIN organisations o ON o.id = u.organisation_id WHERE u.id = ?', [$id]);
            // Re-checked on every request: a leaver's session stops working the day after their last day.
            if (!$u || !UserRepo::canSignIn($u)) {
                self::logout();
                return null;
            }
            self::$user = $u;
        }
        return self::$user;
    }

    public static function can(string $permission): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $set = self::PERMISSIONS[$u['role']] ?? [];
        return in_array('*', $set, true) || in_array($permission, $set, true);
    }

    public static function actor(): Actor
    {
        $u = self::user();
        return $u ? Actor::user((int) $u['id'], $u['email'], $_SERVER['REMOTE_ADDR'] ?? null) : Actor::system('anonymous');
    }

    public static function orgId(): int
    {
        return (int) (self::user()['organisation_id'] ?? 0);
    }
}
