<?php
declare(strict_types=1);

namespace GPC;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                App::config('db.host'),
                (int) App::config('db.port', 3306),
                App::config('db.name')
            );
            self::$pdo = new PDO($dsn, (string) App::config('db.user'), (string) App::config('db.password'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
            // Every read sees the latest committed data. Together with the per-space row lock taken
            // before checking for overlaps (see Bookings::lockSpaces), this makes double booking impossible.
            self::$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [])
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $value) {
            $sets[] = "$col = :set_$col";
            $params["set_$col"] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::run($sql, array_merge($params, $whereParams))->rowCount();
    }

    /** Run $fn inside a transaction; rolls back on any exception. */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Build "IN (?, ?, ?)" placeholders. */
    public static function in(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }
}
