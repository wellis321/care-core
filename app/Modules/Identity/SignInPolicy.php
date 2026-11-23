<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Database as DB;

/**
 * Whether an organisation REQUIRES two-step sign-in, and for whom. Off by default. It applies to people who sign in with a password: sign-in with Microsoft is left to
 * Microsoft's own settings, and the shared demonstration accounts are exempt. Someone it applies to is taken to set it up before they can do anything else.
 */
final class SignInPolicy
{
    public const KEY = 'require_two_step';
    public const LEVELS = [
        'off'       => 'Not required: each person chooses',
        'admins'    => 'Required for administrators',
        'managers'  => 'Required for administrators and managers',
        'everyone'  => 'Required for everyone who signs in with a password',
    ];

    public static function level(int $orgId): string
    {
        $v = (string) DB::value('SELECT setting_value FROM organisation_settings WHERE organisation_id = ? AND setting_key = ?', [$orgId, self::KEY]);
        return isset(self::LEVELS[$v]) ? $v : 'off';
    }

    public static function set(int $orgId, string $level): void
    {
        if (!isset(self::LEVELS[$level])) {
            throw new \App\Core\DomainException('Choose one of the options.');
        }
        DB::run('INSERT INTO organisation_settings (organisation_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$orgId, self::KEY, $level]);
    }

    /** Does the requirement apply to this person's role? */
    public static function covers(string $level, string $role): bool
    {
        return match ($level) {
            'admins' => $role === 'admin',
            'managers' => in_array($role, ['admin', 'manager'], true),
            'everyone' => true,
            default => false,
        };
    }

    /** Must this signed-in person set up two-step sign-in before going on? @param array<string,mixed> $user */
    public static function mustSetUp(array $user): bool
    {
        if (empty($user['password_hash']) || UserRepo::isDemoAccount((string) $user['email'])) {
            return false;   // signs in elsewhere (Microsoft), or a shared demonstration account
        }
        if (!self::covers(self::level((int) $user['organisation_id']), (string) $user['role'])) {
            return false;
        }
        return !TwoFactor::on((int) $user['id']);
    }

    /** The paths a person may still use while they set it up. */
    public const ALLOWED = ['/account', '/account/2fa/start', '/account/2fa/enable', '/account/2fa/codes', '/logout'];
}
