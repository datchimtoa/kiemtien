#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Providers\Catalog;
use App\Settings;
use App\View;

// In-memory fixtures only; never connect to production or call a provider.
config('db');
$GLOBALS['__config']['db'] = ['driver' => 'sqlite', 'sqlite' => ':memory:'];
$GLOBALS['__config']['telegram'] = ['bot_token' => 'fixture-only', 'bot_username' => 'example'];
$GLOBALS['__config']['sms'] = ['driver' => 'log'];
$GLOBALS['__config']['postback_token'] = 'fixture-only';
Database::migrate();
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, (string)$e . PHP_EOL);
    exit(1);
});
$_SESSION = [];
$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $fail += $ok ? 0 : 1;
};
$render = static function (string $template, array $data, string $layout, string $path) use ($check): string {
    $_SERVER['REQUEST_URI'] = $path;
    $html = View::render($template, $data, $layout);
    $dom = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    $xpath = new DOMXPath($dom);
    $check($template . ' has a main landmark and CSRF metadata', $xpath->query('//main[@id="main"]')->length === 1
        && $xpath->query('//meta[@name="csrf-token"]')->length === 1);
    $check($template . ' keeps CSRF on every POST form', $xpath->query('//form[translate(@method,"POST","post")="post" and not(.//input[@name="_csrf"])]')->length === 0);
    $check($template . ' has no nested tables or duplicate IDs', $xpath->query('//table//table')->length === 0
        && $xpath->query('//*[@id = preceding::*/@id]')->length === 0);
    $preview = getenv('UI_PREVIEW_DIR');
    if ($preview) {
        if (!is_dir($preview)) {
            mkdir($preview, 0770, true);
        }
        file_put_contents($preview . '/' . str_replace('/', '-', $template) . '.html', $html);
    }
    return $html;
};
$landing = $render('home/landing', ['minWithdraw' => 10000], 'base', '/');
$check('landing uses configured minimum and does not fabricate task metrics', str_contains($landing, '10.000')
    && str_contains($landing, 'href="#how-it-works"') && !str_contains($landing, '100% thanh toán'));
$render('auth/login', ['error' => null, 'otpStep' => false, 'phone' => '', 'devCode' => null], 'base', '/login');
$render('auth/register', ['step' => 'phone', 'error' => null, 'phone' => '', 'devCode' => null], 'base', '/register');
$render('auth/telegram', ['link' => 'https://t.me/example?start=mock', 'bot' => 'example'], 'base', '/register/telegram');
$render('admin/login', ['error' => null], 'admin', '/admin/login');
Database::run('INSERT INTO users(phone,password_hash,full_name,balance_vnd,points_total,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',
    ['84912345678', 'fixture-only', '<script>Member</script>', 15600, 120, now(), now()]);
$user = Database::one('SELECT * FROM users WHERE id = ?', [Database::lastId()]);
$_SESSION['user_id'] = (int)$user['id'];
// Guest pages above loaded the session cache; start a fresh member context.
(new ReflectionProperty(App\Session::class, 'loaded'))->setValue(null, false);
$dashboard = ['user' => $user, 'sumTasks' => 25600, 'countTasks' => 64, 'pendingWd' => null,
    'trustScore' => 100, 'highScore' => 25600, 'rateInfo' => ['rate' => 24000, 'member_share' => 100], 'tx' => [
        ['id' => 2, 'created_at' => '2026-10-17 10:00:00', 'type' => 'withdraw_hold', 'offer_name' => '', 'amount_vnd' => -10000, 'balance_after' => 15600],
        ['id' => 1, 'created_at' => '2026-10-16 10:00:00', 'type' => 'task_reward', 'offer_name' => '<script>Task</script>', 'amount_vnd' => 400, 'balance_after' => 25600],
    ]];
$html = $render('dashboard/index', $dashboard, 'member', '/dashboard');
$check('dashboard escapes labels and shows debit signs', str_contains($html, '&lt;script&gt;Member&lt;/script&gt;')
    && str_contains($html, '&lt;script&gt;Task&lt;/script&gt;') && str_contains($html, '−10.000'));
$check('balance chart uses the actual ledger balances in time order', str_contains($html, 'points="12,26 708,136"'));
$check('member navigation preserves fixed conversion and mobile access', str_contains($html, '24.000')
    && str_contains($html, 'class="mobile-menu"') && str_contains($html, 'aria-current="page"'));
$emptyDashboard = $dashboard;
$emptyDashboard['tx'] = [];
$empty = View::render('dashboard/index', $emptyDashboard, null);
$check('empty dashboard has no invented chart', !str_contains($empty, '<polyline') && str_contains($empty, 'Chưa có giao dịch nào'));

$providers = Catalog::definitions();
foreach (['link4m', 'traffic24h'] as $id) {
    Settings::set('provider_' . $id . '_enabled', '1');
}
$tasks = ['providers' => $providers, 'providerCounts' => [], 'providerLastStart' => null, 'clickCooldown' => 20,
    'res' => ['ok' => true, 'meta' => ['refresh_after' => 45], 'tasks' => [
        ['id' => 10, 'task_name' => 'Liên kết chính thức', 'member_reward' => 400, 'cooldown_remaining' => 0, 'available' => true, 'remaining_in_cycle' => 2, 'total_max' => 2, 'cooldown_seconds' => 30],
        ['id' => 11, 'task_name' => 'Nhiệm vụ đang nghỉ', 'member_reward' => 600, 'cooldown_remaining' => 12, 'available' => false, 'remaining_in_cycle' => 0, 'total_max' => 2, 'cooldown_seconds' => 30],
    ]]];
$html = $render('tasks/index', $tasks, 'member', '/tasks');
$check('member topbar reads the existing member balance', str_contains($html, '<small>Số dư khả dụng</small>15.600'));
$check('task groups are ordered and keep existing endpoints', strpos($html, 'id="task1"') < strpos($html, 'id="task2"')
    && str_contains($html, '/tasks/start') && str_contains($html, '/tasks/provider/start') && str_contains($html, 'data-task-cooldown="12"'));
$history = ['providers' => $providers, 'taskClicks' => [['task_name' => 'Liên kết chính thức', 'created_at' => now()]], 'providerAttempts' => [[
    'id' => 1, 'provider' => 'link4m', 'service' => 'default', 'remote_id' => 'mock', 'status' => 'pending',
    'created_at' => now(), 'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'reward_vnd' => 400,
    'returned_at' => now(), 'token' => str_repeat('a', 64), 'short_url' => 'https://link4m.co/mock',
]]];
$render('tasks/history', $history, 'member', '/tasks/history');
$withdraw = ['user' => $user, 'sumToday' => 0, 'dailyLimit' => 5000000, 'min' => 10000, 'max' => 5000000,
    'dailyCount' => 3, 'feePct' => 0, 'riskError' => null, 'banks' => ['Vietcombank', 'MB Bank'], 'history' => [[
        'created_at' => now(), 'amount_vnd' => 10000, 'method' => 'bank', 'status' => 'rejected', 'admin_note' => '<script>Incorrect account</script>',
    ]]];
$html = $render('withdraw/index', $withdraw, 'member', '/withdraw');
$check('withdrawal retains inputs, methods and escaped rejection reason', str_contains($html, 'name="amount"')
    && str_contains($html, 'value="momo"') && str_contains($html, 'value="zalopay"')
    && str_contains($html, '&lt;script&gt;Incorrect account&lt;/script&gt;'));
$render('dashboard/profile', ['user' => $user, 'devices' => [['device_id' => 'fixture-device', 'ip' => '203.0.113.10', 'first_seen' => now(), 'last_seen' => now()]]], 'member', '/profile');
$render('tasks/link4m_callback', ['result' => null, 'error' => null, 'row' => ['id' => 1], 'token' => str_repeat('a', 64),
    'signature' => str_repeat('b', 64), 'siteKey' => 'fixture-only'], 'base', '/task/link4m/callback');
$_SESSION['admin_id'] = 1;
$render('admin/dashboard', ['stats' => array_fill_keys(['users', 'usersToday', 'balanceSum', 'taskRewards', 'tasksToday', 'wdPending', 'wdPaid', 'postbacksToday'], 0), 'pendingWd' => [], 'recentPostbacks' => []], 'admin', '/admin');
$render('admin/users', ['q' => '', 'total' => 0, 'page' => 1, 'perPage' => 30, 'rows' => []], 'admin', '/admin/users');
$render('admin/withdrawals', ['status' => '', 'rows' => []], 'admin', '/admin/withdrawals');
$render('admin/transactions', ['type' => '', 'rows' => []], 'admin', '/admin/transactions');
$render('admin/postbacks', ['postbackUrl' => 'https://example.test/postback/mock', 'rows' => []], 'admin', '/admin/postbacks');
$render('admin/risk', ['rows' => []], 'admin', '/admin/risk');
$render('admin/audit', ['rows' => []], 'admin', '/admin/audit');
$render('admin/settings', ['rateInfo' => ['rate' => 24000, 'updated_at' => now(), 'member_share' => 100], 'settings' => [],
    'postbackUrl' => 'https://example.test/postback/mock'], 'admin', '/admin/settings');
$render('admin/providers', ['attempts' => [array_merge($history['providerAttempts'][0], ['user_id' => (int)$user['id'],
    'user_phone' => $user['phone'], 'user_full_name' => $user['full_name'], 'ip' => '203.0.113.10'])]], 'admin', '/admin/providers');
$render('admin/diag', ['ok' => ['Database' => 'Mock SQLite'], 'err' => [], 'baseUrl' => 'https://example.test'], 'admin', '/admin/diag');
$memberRow = array_merge($user, ['trust_score' => 100, 'high_score' => 25600, 'task_count' => 64,
    'total_earned' => 25600, 'wd_total' => 10000, 'wd_count' => 1]);
$render('admin/users', ['q' => '', 'total' => 1, 'page' => 1, 'perPage' => 30, 'rows' => [$memberRow]], 'admin', '/admin/users');
$render('admin/user_detail', ['user' => $user, 'stats' => App\Risk::userStats((int)$user['id'])], 'admin', '/admin/users/' . $user['id']);
$payout = array_merge($user, ['id' => 1, 'user_id' => (int)$user['id'], 'amount_vnd' => 10000, 'method' => 'bank',
    'account_name' => 'Nguyen Van A', 'account_number' => '123456789', 'trust_score' => 100, 'total_earned' => 25600,
    'tasks_done' => 64, 'high_score' => 25600, 'account_age_days' => 30, 'user_ip_version' => 'IPv4', 'status' => 'pending']);
$render('admin/withdrawals', ['status' => '', 'rows' => [$payout]], 'admin', '/admin/withdrawals');
$transaction = array_merge($dashboard['tx'][0], ['id' => 1, 'phone' => $user['phone'], 'points' => 0, 'trans_id' => 'fixture-transaction', 'ip' => '203.0.113.10']);
$render('admin/transactions', ['type' => '', 'rows' => [$transaction]], 'admin', '/admin/transactions');
$singleDashboard = $dashboard;
$singleDashboard['tx'] = [$dashboard['tx'][0]];
$otp = View::render('auth/login', ['error' => null, 'otpStep' => true, 'phone' => '84912345678', 'devCode' => null], null);
$check('device OTP form is retained', str_contains($otp, 'action="/login/otp"') && str_contains($otp, 'name="otp_code"'));
$verifiedRegistration = View::render('auth/register', ['step' => 'verify', 'error' => null, 'phone' => '84912345678', 'tgVerified' => true, 'devCode' => null], null);
$check('Telegram registration keeps the password step', str_contains($verifiedRegistration, 'name="password_confirm"')
    && str_contains($verifiedRegistration, 'action="/register/verify"') && !str_contains($verifiedRegistration, 'name="otp_code"'));
$check('single transaction does not fabricate a trend', !str_contains(View::render('dashboard/index', $singleDashboard, null), '<polyline'));
$css = file_get_contents(APP_ROOT . '/public/assets/app.css');
preg_match_all('/--([a-zA-Z0-9-]+)\s*:/', $css, $definedTokens);
preg_match_all('/var\(--([a-zA-Z0-9-]+)\)/', $css, $usedTokens);
$check('every CSS token has a definition', array_diff($usedTokens[1], $definedTokens[1]) === []);
$check('design retains flat backgrounds and requested colors', !str_contains($css, 'gradient(')
    && str_contains($css, '--bg:#020202') && str_contains($css, '--accent:#0f2bf0'));
$check('self-hosted font licenses are present', is_file(APP_ROOT . '/public/assets/fonts/inter-LICENSE.txt')
    && is_file(APP_ROOT . '/public/assets/fonts/jetbrains-LICENSE.txt'));
exit($fail ? 1 : 0);
