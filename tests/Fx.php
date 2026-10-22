<?php
declare(strict_types=1);

use App\Core\Actor;
use App\Core\Database as DB;
use App\Core\Uuid;
use App\Modules\Care\CareRepo;
use App\Modules\People\PersonRepo;
use App\Modules\Staff\Checklists;
use App\Modules\Staff\Lifecycle;
use App\Modules\Teams\TeamRepo;

/** Test fixtures. */
final class Fx
{
    private static int $n = 0;

    public static function actor(): Actor
    {
        return Actor::system('test');
    }

    public static function org(): int
    {
        self::$n++;
        $slug = 'org' . substr(md5(uniqid('', true)), 0, 10);
        $id = DB::insert('organisations', ['uuid' => Uuid::v4(), 'name' => 'Test Org ' . self::$n, 'slug' => $slug]);
        Checklists::seedDefaults($id);
        return $id;
    }

    /** @return array{person_id:int,person_uuid:string,employment_id:int,employment_uuid:string,invite_token:?string,tasks:int,rehire:bool} */
    public static function staff(int $org, string $first = 'Sam', string $last = 'Staff', array $extra = []): array
    {
        self::$n++;
        return Lifecycle::join($org, $extra + [
            'first_name' => $first, 'last_name' => $last, 'email' => strtolower($first . '.' . $last . self::$n) . '@example.test',
            'job_title' => 'Support Worker', 'start_date' => '2025-01-06',
        ], self::actor());
    }

    /** @return array{id:int,uuid:string} */
    public static function supported(int $org, string $first = 'Pat', string $last = 'Client', string $start = '2025-02-03'): array
    {
        $p = PersonRepo::create($org, ['first_name' => $first, 'last_name' => $last], self::actor());
        CareRepo::startSupport($org, $p['id'], ['start_date' => $start], self::actor());
        return $p;
    }

    public static function team(int $org, string $name = 'Team', ?int $parent = null, bool $staffOnly = false): int
    {
        return TeamRepo::create($org, ['name' => $name, 'parent_team_id' => $parent, 'staff_only' => $staffOnly], self::actor())['id'];
    }

    public static function count(string $table, string $where = '1=1', array $params = []): int
    {
        return (int) DB::value("SELECT COUNT(*) FROM `$table` WHERE $where", $params);
    }
}
