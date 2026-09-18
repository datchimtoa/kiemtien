<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO singleton + migration runner. Supports sqlite (default) and mysql.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $cfg = config('db');
        $driver = $cfg['driver'] ?? 'sqlite';

        try {
            if ($driver === 'mysql') {
                $m = $cfg['mysql'];
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $m['host'], (int)$m['port'], $m['database'], $m['charset'] ?? 'utf8mb4');
                self::$pdo = new PDO($dsn, $m['username'], $m['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } else {
                $path = $cfg['sqlite'];
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0770, true);
                }
                self::$pdo = new PDO('sqlite:' . $path, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$pdo->exec('PRAGMA journal_mode=WAL');
                self::$pdo->exec('PRAGMA foreign_keys=ON');
                self::$pdo->exec('PRAGMA busy_timeout=5000');
                self::$pdo->exec('PRAGMA synchronous=NORMAL');
            }
        } catch (PDOException $e) {
            error_log('[db] connect failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed', 500, $e);
        }
        return self::$pdo;
    }

    /** Run a statement with params. Returns PDOStatement. */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function lastId(): int
    {
        return (int)self::pdo()->lastInsertId();
    }

    public static function begin(): void { self::pdo()->beginTransaction(); }
    public static function commit(): void { if (self::pdo()->inTransaction()) { self::pdo()->commit(); } }
    public static function rollback(): void { if (self::pdo()->inTransaction()) { self::pdo()->rollBack(); } }
    public static function inTransaction(): bool { return self::pdo()->inTransaction(); }

    /** TRUE if the driver is sqlite (affects SQL dialect). */
    public static function isSqlite(): bool
    {
        return (config('db')['driver'] ?? 'sqlite') === 'sqlite';
    }

    /** Create/update schema (idempotent). */
    public static function migrate(): void
    {
        Schema::migrate(self::pdo());
    }
}
