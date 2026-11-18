<?php

declare(strict_types=1);

namespace Cecureus;

use PDO;

/**
 * Thin PDO wrapper. ALWAYS pass user input as ? parameters — never concatenate it into SQL.
 * Timestamps in the mobile_* helper tables are UTC "Y-m-d H:i:s" strings written by PHP.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                Config::get('DB_HOST', '127.0.0.1'),
                Config::int('DB_PORT', 3306),
                Config::require('DB_NAME'),
                // Match what the existing website uses so names written here display there too.
                Config::get('DB_CHARSET', 'utf8mb4'),
            );
            self::$pdo = new PDO($dsn, Config::require('DB_USER'), Config::get('DB_PASS', '') ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    /** @return array<string, string|null>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($params));
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, string|null>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt->fetchAll();
    }

    public static function value(string $sql, array $params = []): ?string
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($params));
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /** Runs INSERT/UPDATE/DELETE; returns the number of changed rows. */
    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt->rowCount();
    }

    /** Inserts one row and returns the new auto-increment id. Column names come from code, never from input. */
    public static function insert(string $table, array $row): int
    {
        self::assertIdentifier($table);
        foreach (array_keys($row) as $column) {
            self::assertIdentifier((string) $column);
        }
        $columns = implode(', ', array_map(static fn ($c) => "`{$c}`", array_keys($row)));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        self::run("INSERT INTO `{$table}` ({$columns}) VALUES ({$marks})", array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    /** UPDATE `$table` SET … WHERE $where. Returns changed rows. */
    public static function update(string $table, array $set, string $where, array $whereParams): int
    {
        self::assertIdentifier($table);
        $assignments = [];
        foreach (array_keys($set) as $column) {
            self::assertIdentifier((string) $column);
            $assignments[] = "`{$column}` = ?";
        }
        return self::run(
            "UPDATE `{$table}` SET " . implode(', ', $assignments) . " WHERE {$where}",
            array_merge(array_values($set), array_values($whereParams)),
        );
    }

    /**
     * MySQL named lock. Works with the existing MyISAM tables (which have no transactions), so two
     * requests for the same counsellor can't both pass the "is this slot free?" check.
     */
    public static function lock(string $name, int $timeoutSeconds = 10): bool
    {
        return self::value('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds]) === '1';
    }

    public static function unlock(string $name): void
    {
        self::value('SELECT RELEASE_LOCK(?)', [$name]);
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Bad SQL identifier: {$name}");
        }
    }
}
