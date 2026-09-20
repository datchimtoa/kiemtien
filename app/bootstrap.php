<?php
declare(strict_types=1);

/**
 * Global helpers + environment bootstrap for earnmoney.vip.
 */

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_PATH', APP_ROOT . '/storage');

// Load config.
$GLOBALS['__config'] = null;
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        if ($GLOBALS['__config'] === null) {
            $file = APP_ROOT . '/config.php';
            if (is_file($file)) {
                $GLOBALS['__config'] = require $file;
            } else {
                // Fall back to sample so the app still boots (with warnings).
                $GLOBALS['__config'] = is_file(APP_ROOT . '/config.sample.php') ? require APP_ROOT . '/config.sample.php' : [];
                if (PHP_SAPI === 'cli' || (defined('APP_DEV') && APP_DEV)) {
                    error_log('[config] config.php missing — using config.sample.php defaults. Copy config.sample.php to config.php!');
                }
            }
        }
        $parts = explode('.', $key);
        $cur = $GLOBALS['__config'];
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return $default;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }
}

if (!function_exists('now')) {
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('e')) {
    /** HTML escape helper. */
    function e(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('vnd')) {
    function vnd(int|string|float $amount): string
    {
        return number_format((float)$amount, 0, ',', '.') . ' ₫';
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        // Behind Cloudflare/tunnel: trust the edge-provided client IP.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $first = trim(explode(',', (string)$_SERVER[$h])[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return substr($first, 0, 64);
                }
            }
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return $ip !== '' ? substr($ip, 0, 64) : 'unknown';
    }
}

if (!function_exists('is_valid_public_ip')) {
    function is_valid_public_ip(string $ip): bool
    {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}

if (!function_exists('ip_version')) {
    /**
     * Identify IP version for admin's legitimacy check (IPv4 / IPv6 / Unknown).
     */
    function ip_version(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'IPv4';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'IPv6';
        }
        return 'Unknown';
    }
}

if (!function_exists('ip_geo_flag')) {
    /**
     * Country risk flags per IP. Returns empty string when not set / not configured.
     * Admin can set ip_risk_countries = 'CN,RU,KP,...' in settings to block these.
     */
    function ip_geo_flag(string $ip): string
    {
        return '';
    }
}

if (!function_exists('user_agent')) {
    function user_agent(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
    }
}

if (!function_exists('json_response')) {
    function json_response(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $to): never
    {
        header('Location: ' . $to, true, 302);
        exit;
    }
}

if (!function_exists('input')) {
    function input(string $key, mixed $default = null): mixed
    {
        $v = $_REQUEST[$key] ?? null;
        if (is_string($v)) {
            return trim($v);
        }
        return $v ?? $default;
    }
}

if (!function_exists('normalize_phone')) {
    /**
     * Normalize Vietnamese phone numbers: 09x/03x/07x/08x/05x, +84, 84 prefixes.
     * Returns E.164-ish "84xxxxxxxxx" or null on invalid.
     */
    function normalize_phone(?string $raw): ?string
    {
        $p = preg_replace('/\D+/', '', (string)$raw);
        if ($p === '') {
            return null;
        }
        if (str_starts_with($p, '84') && strlen($p) >= 11) {
            // 84 + 9/10 digits
            $national = substr($p, 2);
        } elseif (str_starts_with($p, '0')) {
            $national = substr($p, 1);
        } else {
            $national = $p;
        }
        if (strlen($national) < 9 || strlen($national) > 10) {
            return null;
        }
        if (!preg_match('/^(3|5|7|8|9)\d{8,9}$/', $national)) {
            return null;
        }
        return '84' . $national;
    }
}

if (!function_exists('display_phone')) {
    function display_phone(?string $e164): string
    {
        if ($e164 === null || $e164 === '') {
            return '';
        }
        if (str_starts_with($e164, '84')) {
            return '0' . substr($e164, 2);
        }
        return $e164;
    }
}

if (!function_exists('hash_password')) {
    function hash_password(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($plain, $algo);
    }
}

if (!function_exists('random_code')) {
    function random_code(int $digits = 6): string
    {
        $code = '';
        for ($i = 0; $i < $digits; $i++) {
            $code .= (string)random_int(0, 9);
        }
        return $code;
    }
}

if (!function_exists('ensure_storage')) {
    function ensure_storage(): void
    {
        foreach (['', '/logs', '/sessions', '/backups'] as $sub) {
            $dir = STORAGE_PATH . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
        }
    }
}

// Composer-free autoloader for the App\ namespace.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

date_default_timezone_set((string)(config('timezone') ?: 'Asia/Ho_Chi_Minh'));
ensure_storage();

/**
 * Xử lý lỗi toàn cục: không bao giờ để người dùng thấy "Fatal error: Uncaught PDOException"
 * (lộ đường dẫn + chi tiết DB). Ghi log đầy đủ kèm mã lỗi và hiển thị trang thân thiện.
 */
set_exception_handler(static function (\Throwable $e): void {
    $ref = strtoupper(bin2hex(random_bytes(4)));
    error_log('[fatal] ref=' . $ref . ' ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $wantsJson = str_starts_with($path, '/api/')
        || str_starts_with($path, '/postback/')
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json');
    if (!headers_sent()) {
        http_response_code(500);
    }
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'internal_error', 'ref' => $ref], JSON_UNESCAPED_UNICODE);
        return;
    }
    $detail = (defined('APP_DEV') && APP_DEV)
        ? '<pre style="white-space:pre-wrap;font-size:12px;opacity:.8">' . htmlspecialchars((string)$e, ENT_QUOTES) . '</pre>'
        : '<p>Mã lỗi: <code>' . $ref . '</code> — gửi mã này cho hỗ trợ để được kiểm tra.</p>';
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Lỗi hệ thống</title></head>'
        . '<body style="margin:0;padding:40px;background:#0f1115;color:#e8e8e8;font-family:system-ui,sans-serif">'
        . '<h1 style="font-size:20px">⚠️ Hệ thống đang bận</h1>'
        . '<p>Vui lòng thử lại sau vài giây.</p>' . $detail
        . '<p><a style="color:#6ee7b7" href="/">← Về trang chủ</a></p></body></html>';
});

/** Warning/notice → log, không in ra HTML (tránh làm hỏng header/JSON). */
set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    error_log('[php] ' . $msg . ' @ ' . $file . ':' . $line);
    return true;
});
