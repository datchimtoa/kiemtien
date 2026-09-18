<?php
declare(strict_types=1);

namespace App;

/**
 * Hardened session bootstrap + current-user context.
 */
final class Session
{
    private static ?array $user = null;
    private static bool $loaded = false;

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
        session_set_cookie_params([
            'lifetime' => (int)($cfg['lifetime'] ?? 604800),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $savePath = STORAGE_PATH . '/sessions';
        if (!is_dir($savePath)) {
            mkdir($savePath, 0770, true);
        }
        session_save_path($savePath);
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
