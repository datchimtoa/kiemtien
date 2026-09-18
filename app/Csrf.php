<?php
declare(strict_types=1);

namespace App;

/**
 * CSRF token generation + verification (per-session, timing-safe).
 */
final class Csrf
{
    public static function token(): string
    {
        $token = $_SESSION['_csrf'] ?? null;
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf'] = $token;
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function check(): void
    {
        $sent = (string)input('_csrf', '');
        $known = (string)($_SESSION['_csrf'] ?? '');
        if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
            http_response_code(419);
            echo 'Phiên không hợp lệ (CSRF). Vui lòng tải lại trang.';
            exit;
        }
    }

    /** Verify for JSON/AJAX endpoints (header or body). */
    public static function checkOrJson(): void
    {
        $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? input('_csrf', ''));
        $known = (string)($_SESSION['_csrf'] ?? '');
        if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
            json_response(['ok' => false, 'error' => 'CSRF token không hợp lệ'], 419);
        }
    }
}
