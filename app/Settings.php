<?php
declare(strict_types=1);

namespace App;

/**
 * Key-value settings store (DB-backed, cached per request).
 */
final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT skey, svalue FROM settings') as $row) {
                self::$cache[$row['skey']] = $row['svalue'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : (int)$v;
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : (float)$v;
    }

    public static function set(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO settings(skey, svalue, updated_at) VALUES(?, ?, ?)
             ON CONFLICT(skey) DO UPDATE SET svalue = excluded.svalue, updated_at = excluded.updated_at',
            [$key, $value, now()]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function reload(): void
    {
        self::$cache = null;
    }
}
