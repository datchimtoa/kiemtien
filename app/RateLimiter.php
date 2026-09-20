<?php
declare(strict_types=1);

namespace App;

/**
 * DB-backed fixed-window rate limiter.
 *
 * Nguyên tắc: limiter KHÔNG bao giờ được làm sập luồng nghiệp vụ.
 * Nếu DB lỗi (kể cả transaction bị aborted) → fail-open (cho phép) + ghi log.
 */
final class RateLimiter
{
    private static function key(string $name, string $id): string
    {
        return substr($name . ':' . $id, 0, 160);
    }

    /** Attempt an action. TRUE if allowed (records the hit). Fail-open on DB error. */
    public static function attempt(string $name, string $id, int $max, int $windowSeconds): bool
    {
        $bucket = self::key($name, $id);
        $now = time();
        try {
            Database::begin();
            $row = Database::one('SELECT hits, window_start FROM rate_buckets WHERE bucket = ?', [$bucket]);
            if ($row === null) {
                Database::run('INSERT INTO rate_buckets(bucket, hits, window_start) VALUES(?, 1, ?)', [$bucket, $now]);
                Database::commit();
                return true;
            }
            $windowStart = (int)$row['window_start'];
            if ($now - $windowStart >= $windowSeconds) {
                Database::run('UPDATE rate_buckets SET hits = 1, window_start = ? WHERE bucket = ?', [$now, $bucket]);
                Database::commit();
                return true;
            }
            if ((int)$row['hits'] >= $max) {
                Database::rollback();
                return false;
            }
            Database::run('UPDATE rate_buckets SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
            Database::commit();
            return true;
        } catch (\Throwable $e) {
            Database::rollbackQuietly();
            error_log('[rate] limiter error for ' . $bucket . ': ' . $e->getMessage() . ' — fail-open');
            return true; // không chặn người dùng thật vì DB trục trặc
        }
    }

    public static function clear(string $name, string $id): void
    {
        try {
            Database::run('DELETE FROM rate_buckets WHERE bucket = ?', [self::key($name, $id)]);
        } catch (\Throwable $e) {
            error_log('[rate] clear failed: ' . $e->getMessage());
        }
    }

    public static function cleanup(): int
    {
        try {
            // window_start có thể là BIGINT (pgsql/mysql) hoặc TEXT (sqlite) → dùng tham số chuỗi
            // để Postgres không lỗi "operator does not exist: character varying < integer".
            Database::run('DELETE FROM rate_buckets WHERE window_start < ?', [(string)(time() - 86400)]);
        } catch (\Throwable $e) {
            error_log('[rate] cleanup failed: ' . $e->getMessage());
        }
        return 1;
    }
}
