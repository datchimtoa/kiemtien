<?php
declare(strict_types=1);

namespace App;

/**
 * Hardened session bootstrap + current-user context.
 */
final class DatabaseSessionHandler implements \SessionHandlerInterface
{
    public function __construct(private readonly int $lifetime)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        try {
            $row = Database::one('SELECT data, last_activity FROM app_sessions WHERE id = ?', [$id]);
            if ($row === null) {
                return '';
            }
            if ((int)$row['last_activity'] < time() - $this->lifetime) {
                $this->destroy($id);
                return '';
            }
            return (string)$row['data'];
        } catch (\Throwable $e) {
            error_log('[session] read failed: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            Database::upsert('app_sessions', [
                'id' => $id,
                'data' => $data,
                'last_activity' => time(),
            ], 'id', ['data', 'last_activity']);
            return true;
        } catch (\Throwable $e) {
            error_log('[session] write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            Database::run('DELETE FROM app_sessions WHERE id = ?', [$id]);
            return true;
        } catch (\Throwable $e) {
            error_log('[session] destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return Database::run('DELETE FROM app_sessions WHERE last_activity < ?', [time() - $max_lifetime])->rowCount();
        } catch (\Throwable $e) {
            error_log('[session] gc failed: ' . $e->getMessage());
            return false;
        }
    }
}

final class Session
{
    private static ?array $user = null;
    private static bool $loaded = false;
    private static ?DatabaseSessionHandler $handler = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cfg = config('session') ?? [];
        $secure = (bool)($cfg['secure'] ?? true);
        // Allow plain HTTP on local/dev hosts.
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|192\.168\.|10\.)/', $host)) {
            $secure = false;
        }
        session_name($cfg['name'] ?? 'emvsid');
        $lifetime = (int)($cfg['lifetime'] ?? 604800);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $savePath = STORAGE_PATH . '/sessions';
        if (!is_dir($savePath)) {
            mkdir($savePath, 0770, true);
        }
        session_save_path($savePath);
        try {
            Database::value('SELECT 1 FROM app_sessions LIMIT 1');
            self::$handler = new DatabaseSessionHandler($lifetime);
            session_set_save_handler(self::$handler, true);
        } catch (\Throwable $e) {
            // Keep boot working if the database schema is unavailable.
            error_log('[session] database storage unavailable; using files: ' . $e->getMessage());
        }
        session_start();

        // Session fixation protection on privilege change.
        if (empty($_SESSION['_init'])) {
            $_SESSION['_init'] = true;
        }
    }

    public static function userId(): ?int
    {
        self::loadUser();
        return self::$user === null ? null : (int)self::$user['id'];
    }

    public static function user(): ?array
    {
        self::loadUser();
        return self::$user;
    }

    private static function loadUser(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $uid = $_SESSION['user_id'] ?? null;
        if ($uid === null) {
            return;
        }
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$uid]);
        if ($user === null || $user['status'] !== 'active') {
            self::logout();
            return;
        }
        self::$user = $user;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['login_time'] = now();
        self::$user = $user;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['login_time']);
        self::$user = null;
        self::$loaded = true;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }
}
