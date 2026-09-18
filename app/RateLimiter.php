<?php
declare(strict_types=1);

namespace App;

/**
 * DB-backed fixed-window rate limiter.
 */
final class RateLimiter
{
    private static function key(string $name, string $id): string
    {
        return substr($name . ':' . $id, 0, 160);
    }

    /** Attempt an action. TRUE if allowed (records the hit). */
    public static function attempt(string $name, string $id, int $max, int $windowSeconds): bool
    {
        $bucket = self::key($name, $id);
        $now = time();
        $db = Database::pdo();
        $db->beginTransaction();
        try {
            $row = Database::one('SELECT hits, window_start FROM rate_buckets WHERE bucket = ?', [$bucket]);
            if ($row === null) {
                Database::run('INSERT INTO rate_buckets(bucket, hits, window_start) VALUES(?, 1, ?)', [$bucket, $now]);
                $db->commit();
                return true;
            }
            $windowStart = (int)$row['window_start'];
            if ($now - $windowStart >= $windowSeconds) {
                Database::run('UPDATE rate_buckets SET hits = 1, window_start = ? WHERE bucket = ?', [$now, $bucket]);
                $db->commit();
                return true;
            }
            if ((int)$row['hits'] >= $max) {
                $db->rollBack();
                return false;
            }
            Database::run('UPDATE rate_buckets SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function clear(string $name, string $id): void
    {
        Database::run('DELETE FROM rate_buckets WHERE bucket = ?', [self::key($name, $id)]);
    }

    public static function cleanup(): int
    {
        Database::run('DELETE FROM rate_buckets WHERE window_start < ?', [time() - 86400]);
        return 1;
    }
}
