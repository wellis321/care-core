<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** The one PDO connection, plus small helpers. All SQL uses bound parameters. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = Config::get('DB_HOST', '127.0.0.1');
            $port = Config::get('DB_PORT', '3306');
            $name = Config::get('DB_NAME', 'care_core');
            $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            self::$pdo = new PDO($dsn, (string) Config::get('DB_USER', 'root'), (string) Config::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** @param array<int|string,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::normalise($params));
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string,mixed> $data */
    /** Tables whose rows are exposed by public UUID: one is added when the caller does not supply it. */
    private const UUID_TABLES = ['team_assignments', 'care_needs', 'person_contacts', 'key_worker_assignments', 'webhook_endpoints'];

    public static function insert(string $table, array $data): int
    {
        if (in_array($table, self::UUID_TABLES, true) && !isset($data['uuid'])) {
            $data['uuid'] = Uuid::v4();
        }
        $cols = array_keys($data);
        $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $where */
    public static function update(string $table, array $data, array $where): int
    {
        $set = implode(',', array_map(static fn($c) => "`$c` = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn($c) => "`$c` = ?", array_keys($where)));
        return self::run("UPDATE `$table` SET $set WHERE $cond", array_merge(array_values($data), array_values($where)))->rowCount();
    }

    /**
     * Run work in a transaction. Nested calls join the outer transaction, so a lifecycle change and its audit and
     * event rows always commit or roll back together.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<int|string,mixed> $params */
    private static function normalise(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $params[$k] = $v ? 1 : 0;   // false must not be sent as '' (strict MySQL rejects it)
            }
        }
        return $params;
    }
}
