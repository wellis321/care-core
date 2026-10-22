<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Database as DB;

/** Cursor pagination: stable, cheap, and not thrown off by rows added while someone is paging. */
final class Page
{
    /**
     * @param string $sql  SELECT ... FROM ... WHERE ... (without ORDER BY or LIMIT)
     * @param list<mixed> $params
     * @param callable(array<string,mixed>):array<string,mixed> $present
     * @return array{data:list<array<string,mixed>>,page:array{limit:int,next_cursor:?string,has_more:bool}}
     */
    public static function run(Ctx $c, string $sql, array $params, string $idColumn, callable $present, string $direction = 'ASC'): array
    {
        $limit = $c->limit();
        $cursor = $c->cursor();
        if ($cursor > 0) {
            $sql .= ' AND ' . $idColumn . ($direction === 'ASC' ? ' > ' : ' < ') . '?';
            $params[] = $cursor;
        }
        $rows = DB::all($sql . ' ORDER BY ' . $idColumn . ' ' . $direction . ' LIMIT ' . ($limit + 1), $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $idKey = substr($idColumn, (int) strrpos($idColumn, '.') + (str_contains($idColumn, '.') ? 1 : 0));
        $last = $rows ? (int) end($rows)[$idKey] : 0;
        return [
            'data' => array_map($present, $rows),
            'page' => ['limit' => $limit, 'next_cursor' => $more ? rtrim(strtr(base64_encode('v1:' . $last), '+/', '-_'), '=') : null, 'has_more' => $more],
        ];
    }

    /** A plain list that is not paged (small, bounded collections). @param list<array<string,mixed>> $items @return array{data:list<array<string,mixed>>} */
    public static function all(array $items): array
    {
        return ['data' => $items];
    }
}
