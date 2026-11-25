<?php
declare(strict_types=1);

namespace App\Modules\Recruitment;

use App\Core\Actor;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Modules;
use App\Core\Uuid;

/**
 * The public careers page. Read-only to the world except one thing: a person may apply. No account:
 * the form is protected by a hidden trap field, a signed "form shown at" stamp, and the existing one-application-per-email
 * rule. Only vacancies that are open, marked "advertise" and not past their closing date are visible.
 */
final class Careers
{
    private const MIN_SECONDS = 4;
    private const MAX_SECONDS = 86400;
    public const PER_VACANCY_PER_HOUR = 40;

    /** @return array<string,mixed>|null */
    public static function organisation(string $slug): ?array
    {
        $o = DB::one('SELECT * FROM organisations WHERE slug = ?', [$slug]);
        return $o && Modules::enabled((int) $o['id'], 'recruitment') ? $o : null;
    }

    /** @return list<array<string,mixed>> */
    public static function vacancies(int $orgId): array
    {
        return DB::all(
            "SELECT v.*, r.title AS role_title, t.name AS team_name FROM vacancies v JOIN job_roles r ON r.id = v.role_id LEFT JOIN teams t ON t.id = v.team_id
             WHERE v.organisation_id = ? AND v.status = 'open' AND v.advertise = 1 AND (v.closing_date IS NULL OR v.closing_date >= ?) ORDER BY v.opened_on DESC, v.id DESC",
            [$orgId, Clock::today()]
        );
    }

    /** @return array<string,mixed>|null */
    public static function vacancy(int $orgId, string $uuid): ?array
    {
        if (!Uuid::valid($uuid)) {
            return null;
        }
        foreach (self::vacancies($orgId) as $v) {
            if ($v['uuid'] === $uuid) {
                $v['role'] = RoleRepo::byId($orgId, (int) $v['role_id']);
                return $v;
            }
        }
        return null;
    }

    /** A signed stamp of when the form was shown. */
    public static function stamp(string $vacancyUuid, ?int $now = null): string
    {
        $t = (string) ($now ?? time());
        return $t . '.' . hash_hmac('sha256', 'apply:' . $vacancyUuid . ':' . $t, self::secret());
    }

    public static function stampOk(string $vacancyUuid, string $stamp, ?int $now = null): bool
    {
        if (!preg_match('/^(\d{9,11})\.([0-9a-f]{64})$/', $stamp, $m) || !hash_equals(hash_hmac('sha256', 'apply:' . $vacancyUuid . ':' . $m[1], self::secret()), $m[2])) {
            return false;
        }
        $age = ($now ?? time()) - (int) $m[1];
        return $age >= self::MIN_SECONDS && $age <= self::MAX_SECONDS;
    }

    /**
     * Take an application from the public form. A filled trap field is treated as success without storing anything
     * (a person cannot see that field, so only a robot fills it).
     *
     * @param array<string,string> $d first_name, last_name, email, phone, statement, hp_extra, ft
     * @return bool true when stored
     */
    public static function apply(int $orgId, string $vacancyUuid, array $d): bool
    {
        $v = self::vacancy($orgId, $vacancyUuid) ?? throw new DomainException('This vacancy is no longer open for applications.');
        if (trim((string) ($d['hp_extra'] ?? '')) !== '') {
            return false;
        }
        if (!self::stampOk($vacancyUuid, (string) ($d['ft'] ?? ''))) {
            throw new DomainException('We could not check that this form was filled in by a person. Please reload the page and send it again.');
        }
        if ((int) DB::value("SELECT COUNT(*) FROM applications WHERE vacancy_id = ? AND source = 'Careers page' AND created_at > ?", [$v['id'], gmdate('Y-m-d H:i:s', time() - 3600)]) >= self::PER_VACANCY_PER_HOUR) {
            throw new DomainException('We are getting a lot of applications for this vacancy just now. Please try again later.');
        }
        if (trim((string) ($d['email'] ?? '')) === '') {
            throw new DomainException('Email is required, so we can reply to you.');
        }
        if (mb_strlen((string) ($d['statement'] ?? '')) > 3000) {
            throw new DomainException('Your statement can be at most 3000 characters.');
        }
        ApplicationRepo::create($orgId, (int) $v['id'], [
            'first_name' => $d['first_name'] ?? '', 'last_name' => $d['last_name'] ?? '', 'email' => $d['email'] ?? '', 'phone' => $d['phone'] ?? '',
            'source' => 'Careers page', 'notes' => trim((string) ($d['statement'] ?? '')),
        ], Actor::system('careers page'));
        return true;
    }

    public static function url(array $org, ?string $vacancyUuid = null): string
    {
        return Config::appUrl() . '/careers/' . $org['slug'] . ($vacancyUuid ? '/' . $vacancyUuid : '');
    }

    private static function secret(): string
    {
        return (string) Config::get('APP_KEY', 'care-core');
    }
}
