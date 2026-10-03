<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Single, central PDO connection. Always use prepared statements with bound parameters. */
final class Database
{
    private static ?PDO $pdo = null;

    /** Nesting depth, so a helper called inside a service transaction joins it instead of failing. */
    private static int $txDepth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('database');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
            self::$pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Start a transaction, or join the one already running.
     *
     * PDO refuses a nested beginTransaction(), which would break any helper that manages its
     * own transaction when called from inside a service transaction (for example certificate
     * numbering during approval). An inner commit is therefore a no-op and an inner rollback
     * discards the whole unit of work, which is correct here because every caller rethrows.
     */
    public static function beginTransaction(): void
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            self::$txDepth++;
            return;
        }
        $pdo->beginTransaction();
        self::$txDepth = 1;
    }

    public static function commit(): void
    {
        if (self::$txDepth > 1) {
            self::$txDepth--;
            return;
        }
        self::$txDepth = 0;
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollBack(): void
    {
        self::$txDepth = 0;
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
