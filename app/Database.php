<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO singleton + migration runner. Supports sqlite (dev), mysql and pgsql (Neon cloud).
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** TRUE khi driver hiện tại là postgres (Neon/Supabase/Render). */
    public static function isPgsql(): bool
    {
        return (config('db')['driver'] ?? 'sqlite') === 'pgsql';
    }

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
                $ssl = !empty($m['ssl']);
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s%s',
                    $m['host'],
                    (int)$m['port'],
                    $m['database'],
                    $m['charset'] ?? 'utf8mb4',
                    $ssl ? ';sslmode=require' : ''
                );
                $opts = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                // Aiven/Supabase-style CA cert (optional, via DB_SSL_CA path or PEM string).
                if (!empty($m['ssl_ca'])) {
                    $opts[PDO::MYSQL_ATTR_SSL_CA] = $m['ssl_ca'];
                } elseif ($ssl && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                }
                self::$pdo = new PDO($dsn, $m['username'], $m['password'], $opts);
            } elseif ($driver === 'pgsql') {
                // Neon/Supabase/Render Postgres. DATABASE_URL hoặc rời rạc.
                $pg = $cfg['pgsql'];
                if (!empty($pg['url'])) {
                    $u = parse_url((string)$pg['url']);
                    $pg['host'] = $u['host'] ?? $pg['host'];
                    $pg['port'] = isset($u['port']) ? (int)$u['port'] : $pg['port'];
                    $pg['database'] = isset($u['path']) ? ltrim((string)$u['path'], '/') : $pg['database'];
                    $pg['username'] = isset($u['user']) ? urldecode((string)$u['user']) : $pg['username'];
                    $pg['password'] = isset($u['pass']) ? urldecode((string)$u['pass']) : $pg['password'];
                    parse_str($u['query'] ?? '', $q);
                    if (!empty($q['sslmode'])) {
                        $pg['sslmode'] = (string)$q['sslmode'];
                    }
                }
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                    $pg['host'],
                    (int)$pg['port'],
                    $pg['database'],
                    $pg['sslmode'] ?? 'require'
                );
                self::$pdo = new PDO($dsn, $pg['username'], $pg['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } else {
                // Default: local SQLite.
                $path = $cfg['sqlite'];
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0770, true);
                }
                self::$pdo = new PDO('sqlite:' . $path, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                // SQLite-only pragmas (MySQL không có các lệnh này).
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
        // Postgres: lastInsertId() không trả về id từ INSERT WITHOUT RETURNING
        // → dùng CURRVAL của sequence postgres "table" (tên chuẩn BIGSERIAL: table_id_seq).
        if (self::isPgsql()) {
            return (int)self::value('SELECT lastval()');
        }
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

    /**
     * Cross-DB upsert: SQLite/Postgres dùng ON CONFLICT, MySQL dùng ON DUPLICATE KEY UPDATE.
     * $conflictCol: cột UNIQUE/PK để phát hiện trùng (vd 'skey', 'username', 'site_key').
     * $updateCols: các cột cần cập nhật khi trùng.
     */
    public static function upsert(string $table, array $insert, string $conflictCol, array $updateCols): void
    {
        $cols = array_keys($insert);
        $colList = implode(',', $cols);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        if (self::isSqlite() || self::isPgsql()) {
            $set = implode(', ', array_map(fn($c) => "{$c} = excluded.{$c}", $updateCols));
            $sql = "INSERT INTO {$table}({$colList}) VALUES({$placeholders})"
                . " ON CONFLICT({$conflictCol}) DO UPDATE SET {$set}";
            self::run($sql, array_values($insert));
        } else {
            $set = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $updateCols));
            $sql = "INSERT INTO {$table}({$colList}) VALUES({$placeholders}) ON DUPLICATE KEY UPDATE {$set}";
            self::run($sql, array_values($insert));
        }
    }
}
