<?php
declare(strict_types=1);

namespace App\Core;

/** Which modules an organisation has switched on. People and Identity are always on: they are the core. */
final class Modules
{
    public const ALL = ['people' => 'People', 'staff' => 'Staff', 'teams' => 'Teams', 'care' => 'Care', 'recruitment' => 'Recruitment'];
    private const ALWAYS = ['people'];

    public static function enabled(int $orgId, string $module): bool
    {
        if (in_array($module, self::ALWAYS, true)) {
            return true;
        }
        $v = Database::value('SELECT enabled FROM organisation_modules WHERE organisation_id = ? AND module = ?', [$orgId, $module]);
        return $v === null ? true : (bool) $v;   // no row means on
    }

    public static function set(int $orgId, string $module, bool $on): void
    {
        Database::run('INSERT INTO organisation_modules (organisation_id, module, enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)', [$orgId, $module, $on ? 1 : 0]);
    }
}
