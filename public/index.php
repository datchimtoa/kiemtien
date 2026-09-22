<?php
/**
 * earnmoney.vip — front controller + router.
 *
 * Dev:      php -S 127.0.0.1:8080 -t public public/index.php
 * Nginx:    root public/; try_files $uri /index.php?$query_string;
 */
declare(strict_types=1);

define('APP_DEV', in_array(getenv('APP_ENV'), ['dev', 'local', ''], true));

require dirname(__DIR__) . '/app/bootstrap.php';

// Serve static assets directly under the built-in dev server.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
        return false;
    }
}

use App\Database;
use App\PostbackProcessor;
use App\Session;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\TaskController;
use App\Controllers\WithdrawController;

// Force HTTPS in production.
if ((bool)(config('force_https') ?? false)
    && empty($_SERVER['HTTPS'])
    && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https'
    && PHP_SAPI !== 'cli'
    && !preg_match('/^(localhost|127\.0\.0\.1)/', $_SERVER['HTTP_HOST'] ?? '')) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

// Security headers.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'self'; base-uri 'self'");

Session::start();
try {
    Database::migrate();
} catch (\Throwable $e) {
    // Lỗi migrate không được làm chết cả site: các bảng chính đã có sẵn, ghi log để xử lý sau.
    error_log('[migrate] failed: ' . $e->getMessage());
}
\App\RateUpdater::refresh();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

// ---------------------------------------------------------------- postback (no session, no CSRF)
if ($path === '/postback/pubcrypto' || str_starts_with($path, '/postback/pubcrypto/')) {
    $token = (string)(config('postback_token') ?? '');
    if ($token !== '' && $path !== '/postback/pubcrypto/' . $token) {
        http_response_code(404);
        exit('not found');
    }
    if ($method === 'GET') {
        // PubCrypto redirect TRÌNH DUYỆT người dùng về URL postback sau khi claim
        // (trong dashboard PubCrypto nhiều publisher dán chung 1 URL cho cả S2S và Return).
        // GET không phải postback thật → không xử lý tiền, đưa user về trang nhiệm vụ.
        if (Session::userId() !== null) {
            Session::flash('success', '✅ Nhiệm vụ đã hoàn tất — phần thưởng sẽ tự cộng vào ví trong ít phút.');
            redirect('/tasks');
        }
        redirect('/');
    }
    if ($method !== 'POST') {
        http_response_code(405);
        exit('method not allowed');
    }
    PostbackProcessor::handle($_REQUEST);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    exit;
}

// ---------------------------------------------------------------- fingerprint beacon
if ($path === '/api/fingerprint' && $method === 'POST') {
    \App\Controllers\FingerprintController::store();
    exit;
}

// ---------------------------------------------------------------- telegram webhook (no session/CSRF)
if ($path === '/api/telegram/webhook' && $method === 'POST') {
    \App\Controllers\TelegramController::webhook();
    exit;
}

// ---------------------------------------------------------------- admin bootstrap (no session/CSRF)
if ($path === '/admin/bootstrap' && $method === 'POST') {
    $setupKey = (string)(getenv('SETUP_KEY') ?: (string)(config('setup_key') ?? ''));
    $provided = (string)($_POST['setup_key'] ?? $_SERVER['HTTP_X_SETUP_KEY'] ?? '');
    if ($setupKey === '' || !hash_equals($setupKey, $provided)) {
        http_response_code(404);
        exit('not found');
    }
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || strlen($password) < 10) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('username + password(min 10 ky tu) bat buoc');
    }
    Database::run(
        'INSERT INTO admin_users(username, password_hash, status, created_at) VALUES(?,?,?,?) '
        . (Database::isPgsql() || Database::isSqlite()
            ? 'ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash, status = excluded.status'
            : 'ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = VALUES(status)'),
        [$username, hash_password($password), 'active', now()]
    );
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok — admin "' . $username . '" da tao/cap nhat. XOA SETUP_KEY ngay va login tai /admin/login';
    exit;
}

// ---------------------------------------------------------------- static route table
$handled = match (true) {
    $path === '/'                              && $method === 'GET'  => HomeController::landing(),
    $path === '/register'                      && $method === 'GET'  => AuthController::showRegister(),
    $path === '/register/step2'                && $method === 'GET'  => AuthController::showRegisterStep2(),
    $path === '/register/phone'                && $method === 'POST' => AuthController::postPhone(),
    $path === '/register/telegram'             && $method === 'POST' => AuthController::postRegisterTelegram(),
    $path === '/register/telegram-status'      && $method === 'POST' => AuthController::telegramStatus(),
    $path === '/register/verify'               && $method === 'POST' => AuthController::postVerify(),
    $path === '/login'                         && $method === 'GET'  => AuthController::showLogin(),
    $path === '/login'                         && $method === 'POST' => AuthController::postLogin(),
    $path === '/login/otp'                     && $method === 'POST' => AuthController::postLoginOtp(),
    $path === '/logout'                        && $method === 'POST' => AuthController::logout(),
    $path === '/dashboard'                     && $method === 'GET'  => DashboardController::index(),
    $path === '/tasks'                         && $method === 'GET'  => TaskController::index(),
    $path === '/tasks/start'                   && $method === 'POST' => TaskController::start(),
    $path === '/withdraw'                      && $method === 'GET'  => WithdrawController::index(),
    $path === '/withdraw'                      && $method === 'POST' => WithdrawController::create(),
    $path === '/profile'                       && $method === 'GET'  => DashboardController::profile(),
    $path === '/admin/login'                   && $method === 'GET'  => AdminController::showLogin(),
    $path === '/admin/login'                   && $method === 'POST' => AdminController::postLogin(),
    $path === '/admin/logout'                  && $method === 'POST' => AdminController::logout(),
    $path === '/admin'                         && $method === 'GET'  => AdminController::dashboard(),
    $path === '/admin/users'                   && $method === 'GET'  => AdminController::users(),
    $path === '/admin/withdrawals'             && $method === 'GET'  => AdminController::withdrawals(),
    $path === '/admin/transactions'            && $method === 'GET'  => AdminController::transactions(),
    $path === '/admin/postbacks'               && $method === 'GET'  => AdminController::postbacks(),
    $path === '/admin/risk'                    && $method === 'GET'  => AdminController::risk(),
    $path === '/admin/settings'                && $method === 'GET'  => AdminController::settings(),
    $path === '/admin/settings'                && $method === 'POST' => AdminController::saveSettings(),
    $path === '/admin/audit'                   && $method === 'GET'  => AdminController::audit(),
    $path === '/admin/diag'                    && $method === 'GET'  => AdminController::diag(),
    $path === '/admin/diag/telegram-webhook'   && $method === 'POST' => AdminController::setTelegramWebhook(),
    default => null,
};
if ($handled !== null) {
    exit;
}

// ---------------------------------------------------------------- dynamic admin actions
if (preg_match('#^/admin/users/(\d+)/(\w+)$#', $path, $m) && $method === 'POST') {
    AdminController::userAction((int)$m[1], $m[2]);
}
if (preg_match('#^/admin/users/(\d+)$#', $path, $m) && $method === 'GET') {
    AdminController::userView((int)$m[1]);
}
if (preg_match('#^/admin/withdrawals/(\d+)/(\w+)$#', $path, $m) && $method === 'POST') {
    AdminController::withdrawAction((int)$m[1], $m[2]);
}

http_response_code(404);
\App\View::show('errors/404', [], 'base');

/** Member-area guard helper. */
function require_user(): array
{
    $u = Session::user();
    if ($u === null) {
        Session::set('_intended', $_SERVER['REQUEST_URI'] ?? '/');
        redirect('/login');
    }
    return $u;
}
