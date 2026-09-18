<?php
declare(strict_types=1);

namespace App;

/**
 * Admin guard (separate session key + brute-force throttling).
 */
final class AdminAuth
{
    public static function id(): ?int
    {
        $v = $_SESSION['admin_id'] ?? null;
        return $v === null ? null : (int)$v;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function require(): void
    {
        if (!self::check()) {
            redirect('/admin/login');
        }
    }

    public static function attempt(string $username, string $password): array
    {
        if (!RateLimiter::attempt('admin_login', $username . '|' . client_ip(), 5, 900)) {
            return [false, 'Đăng nhập sai quá nhiều lần. Thử lại sau 15 phút.'];
        }
        $row = Database::one("SELECT * FROM admin_users WHERE username = ? AND status = 'active'", [$username]);
        if ($row === null || !password_verify($password, (string)$row['password_hash'])) {
            Audit::log('admin', 0, 'admin_login_failed', 'admin_users', ['username' => $username]);
            return [false, 'Tên đăng nhập hoặc mật khẩu không đúng.'];
        }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$row['id'];
        Database::run('UPDATE admin_users SET last_login_at = ?, last_login_ip = ? WHERE id = ?', [now(), client_ip(), $row['id']]);
        Audit::log('admin', (int)$row['id'], 'admin_login_ok', 'admin_users', []);
        return [true, null];
    }

    public static function logout(): void
    {
        $id = self::id();
        unset($_SESSION['admin_id']);
        if ($id !== null) {
            Audit::log('admin', $id, 'admin_logout', 'admin_users', []);
        }
    }
}
