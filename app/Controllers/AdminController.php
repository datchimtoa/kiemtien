<?php
declare(strict_types=1);

namespace App\Controllers;

use App\AdminAuth;
use App\Audit;
use App\Csrf;
use App\Database;
use App\RateLimiter;
use App\RateUpdater;
use App\Risk;
use App\Session;
use App\Telegram;
use App\View;
use App\Wallet;
use App\Withdrawals;

final class AdminController
{
    public static function showLogin(): void
    {
        View::show('admin/login', ['error' => null], 'admin');
    }

    public static function postLogin(): void
    {
        Csrf::check();
        [$ok, $err] = AdminAuth::attempt((string)input('username', ''), (string)input('password', ''));
        if (!$ok) {
            View::show('admin/login', ['error' => (string)$err], 'admin');
        }
        redirect('/admin');
    }

    public static function logout(): void
    {
        Csrf::check();
        AdminAuth::logout();
        redirect('/admin/login');
    }

    public static function dashboard(): void
    {
        AdminAuth::require();
        $stats = [
            'users'          => (int)Database::value('SELECT COUNT(*) FROM users'),
            'usersToday'     => (int)Database::value('SELECT COUNT(*) FROM users WHERE created_at >= ?', [date('Y-m-d 00:00:00')]),
            'balanceSum'     => (int)Database::value('SELECT COALESCE(SUM(balance_vnd),0) FROM users'),
            'taskRewards'    => (int)Database::value("SELECT COALESCE(SUM(amount_vnd),0) FROM transactions WHERE type = 'task_reward'"),
            'tasksToday'     => (int)Database::value("SELECT COUNT(*) FROM transactions WHERE type = 'task_reward' AND created_at >= ?", [date('Y-m-d 00:00:00')]),
            'wdPending'      => (int)Database::value("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'"),
            'wdPaid'         => (int)Database::value("SELECT COALESCE(SUM(amount_vnd),0) FROM withdrawals WHERE status = 'completed'"),
            'postbacksToday' => (int)Database::value('SELECT COUNT(*) FROM postback_logs WHERE created_at >= ?', [date('Y-m-d 00:00:00')]),
        ];
        $recentPostbacks = Database::all('SELECT * FROM postback_logs ORDER BY id DESC LIMIT 8');
        $pendingWd = Database::all("SELECT w.*, u.phone FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status = 'pending' ORDER BY w.id ASC LIMIT 10");
        View::show('admin/dashboard', ['stats' => $stats, 'recentPostbacks' => $recentPostbacks, 'pendingWd' => $pendingWd], 'admin');
    }

    public static function users(): void
    {
        AdminAuth::require();
        $q = trim((string)input('q', ''));
        $page = max(1, (int)input('page', 1));
        $perPage = 30;
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where = '(phone LIKE ? OR full_name LIKE ? OR id = ?)';
            $params = ["%{$q}%", "%{$q}%", ctype_digit($q) ? (int)$q : 0];
        }
        $total = (int)Database::value("SELECT COUNT(*) FROM users WHERE {$where}", $params);
        $rows = Database::all(
            "SELECT u.*, COALESCE((SELECT COUNT(*) FROM transactions WHERE user_id = u.id AND type = 'task_reward' AND amount_vnd > 0),0) AS task_count,
                COALESCE((SELECT SUM(amount_vnd) FROM transactions WHERE user_id = u.id AND type = 'task_reward' AND amount_vnd > 0),0) AS total_earned,
                COALESCE((SELECT SUM(amount_vnd) FROM transactions WHERE user_id = u.id AND type IN ('task_chargeback','withdraw_hold','withdraw_refund') AND amount_vnd < 0),0) AS total_deducted,
                COALESCE((SELECT COUNT(*) FROM withdrawals WHERE user_id = u.id AND status = 'completed'),0) AS wd_count,
                COALESCE((SELECT SUM(amount_vnd) FROM withdrawals WHERE user_id = u.id AND status = 'completed'),0) AS wd_total
             FROM users u WHERE {$where} ORDER BY u.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );
        foreach ($rows as &$r) {
            $r['trust_score'] = (int)Risk::trustScore((int)$r['id']);
            $r['high_score'] = (int)Risk::highScore((int)$r['id']);
        }
        View::show('admin/users', ['rows' => $rows, 'total' => $total, 'q' => $q, 'page' => $page, 'perPage' => $perPage], 'admin');
    }

    public static function userAction(int $userId, string $action): void
    {
        AdminAuth::require();
        Csrf::check();
        if (Database::one('SELECT id FROM users WHERE id = ?', [$userId]) === null) {
            Session::flash('error', 'Không tìm thấy người dùng.');
            redirect('/admin/users');
        }
        switch ($action) {
            case 'block':
            case 'unblock':
                Database::run('UPDATE users SET status = ? WHERE id = ?', [$action === 'block' ? 'blocked' : 'active', $userId]);
                Audit::log('admin', AdminAuth::id() ?? 0, 'user_' . $action, 'users/' . $userId, []);
                Session::flash('success', 'Đã ' . ($action === 'block' ? 'khoá' : 'mở khoá') . ' tài khoản.');
                break;
            case 'clear_risk':
                Database::run('UPDATE users SET risk_flag = 0 WHERE id = ?', [$userId]);
                Audit::log('admin', AdminAuth::id() ?? 0, 'user_clear_risk', 'users/' . $userId, []);
                Session::flash('success', 'Đã bỏ cờ rủi ro.');
                break;
            case 'adjust':
                $amount = (int)input('amount', 0);
                if ($amount !== 0) {
                    Wallet::post($userId, Wallet::TYPE_ADMIN_ADJUST, $amount, ['note' => (string)input('note', '') ?: 'Admin adjust']);
                    Audit::log('admin', AdminAuth::id() ?? 0, 'user_adjust', 'users/' . $userId, ['amount' => $amount]);
                    Session::flash('success', 'Đã điều chỉnh số dư.');
                }
                break;
                        case 'hard_delete':
                if (Risk::deleteUser($userId)) {
                    Audit::log('admin', AdminAuth::id() ?? 0, 'user_hard_deleted', 'users/' . $userId, []);
                    Session::flash('success', 'Đã xóa vĩnh viễn tài khoản và toàn bộ dữ liệu liên quan.');
                } else {
                    Session::flash('error', 'Xóa tài khoản thất bại. Vui lòng thử lại.');
                }
                break;
            default:
                Session::flash('error', 'Hành động không hợp lệ.');
        }
        redirect('/admin/users');
    }

    /** User detail page: full profile with IPs, devices, risk events, telegram, transactions */
    public static function userView(int $userId): void
    {
        AdminAuth::require();
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            Session::flash('error', 'Không tìm thấy người dùng.');
            redirect('/admin/users');
        }
        $stats = Risk::userStats($userId);
                View::show('admin/user_detail', ['user' => $user, 'stats' => $stats], 'admin');
    }

    public static function withdrawals(): void
    {
        AdminAuth::require();
        $status = (string)input('status', '');
        $where = $status !== '' ? 'WHERE w.status = ?' : '';
        $params = $status !== '' ? [$status] : [];
        $rows = Database::all(
            "SELECT w.*, u.phone, u.balance_vnd, u.register_ip, u.register_device_id,
                    u.last_login_ip, u.telegram_user_id, u.telegram_username, u.created_at AS user_created,
                    u.points_total,
                    COALESCE((SELECT SUM(amount_vnd) FROM transactions WHERE user_id = u.id AND type = 'task_reward' AND amount_vnd > 0),0) AS total_earned,
                    COALESCE((SELECT COUNT(*) FROM transactions WHERE user_id = u.id AND type = 'task_reward' AND amount_vnd > 0),0) AS tasks_done,
                    COALESCE((SELECT COUNT(*) FROM withdrawals WHERE user_id = u.id AND status = 'completed'),0) AS wd_count
             FROM withdrawals w JOIN users u ON u.id = w.user_id {$where} ORDER BY (w.status = 'pending') DESC, w.id DESC LIMIT 100",
            $params
        );
        foreach ($rows as &$r) {
            $r['trust_score'] = Risk::trustScore((int)$r['user_id']);
            $r['high_score'] = Risk::highScore((int)$r['user_id']);
            $r['user_ip_version'] = ip_version((string)$r['register_ip']);
            $r['account_age_days'] = (int)((time() - strtotime((string)$r['user_created'])) / 86400);
        }
        View::show('admin/withdrawals', ['rows' => $rows, 'status' => $status], 'admin');
    }

    public static function withdrawAction(int $withdrawId, string $action): void
    {
        AdminAuth::require();
        Csrf::check();
        $adminId = AdminAuth::id() ?? 0;
        $note = trim((string)input('note', ''));
        if ($action === 'complete') {
            [$ok, $err] = Withdrawals::complete($withdrawId, $adminId, $note);
            Session::flash($ok ? 'success' : 'error', $ok ? 'Đã đánh dấu COMPLETED.' : (string)$err);
        } elseif ($action === 'reject') {
            [$ok, $err] = Withdrawals::reject($withdrawId, $adminId, $note);
            Session::flash($ok ? 'success' : 'error', $ok ? 'Đã từ chối và hoàn tiền.' : (string)$err);
        } else {
            Session::flash('error', 'Hành động không hợp lệ.');
        }
        redirect('/admin/withdrawals');
    }

    public static function transactions(): void
    {
        AdminAuth::require();
        $type = (string)input('type', '');
        $where = $type !== '' ? 'WHERE t.type = ?' : '';
        $params = $type !== '' ? [$type] : [];
        $rows = Database::all(
            "SELECT t.*, u.phone FROM transactions t JOIN users u ON u.id = t.user_id {$where} ORDER BY t.id DESC LIMIT 100",
            $params
        );
        View::show('admin/transactions', ['rows' => $rows, 'type' => $type], 'admin');
    }

    public static function postbacks(): void
    {
        AdminAuth::require();
        $rows = Database::all('SELECT * FROM postback_logs ORDER BY id DESC LIMIT 200');
        View::show('admin/postbacks', ['rows' => $rows, 'postbackUrl' => \App\PostbackProcessor::url()], 'admin');
    }

    public static function risk(): void
    {
        AdminAuth::require();
        $rows = Database::all(
            'SELECT r.*, u.phone FROM risk_events r LEFT JOIN users u ON u.id = r.user_id ORDER BY r.id DESC LIMIT 200'
        );
        View::show('admin/risk', ['rows' => $rows], 'admin');
    }

    public static function audit(): void
    {
        AdminAuth::require();
        $rows = Database::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 200');
        View::show('admin/audit', ['rows' => $rows], 'admin');
    }

        /** 🩺 Chẩn đoán DB từ xa (không cần shell) — dùng khi login/tiền báo lỗi. */
    public static function diag(): void
    {
        AdminAuth::require();
        $ok = [];
        $err = [];
        $run = static function (string $label, callable $fn) use (&$ok, &$err): void {
            try {
                $ok[$label] = (string)$fn();
            } catch (\Throwable $e) {
                $state = $e instanceof \PDOException ? (string)($e->errorInfo[0] ?? '?') : '-';
                $err[$label] = get_class($e) . ' [SQLSTATE ' . $state . ']: ' . $e->getMessage();
            }
        };

        $run('Môi trường', static fn(): string => 'PHP ' . PHP_VERSION
            . ' · PDO: ' . implode(', ', \PDO::getAvailableDrivers()));
        $run('Driver DB', static fn(): string => (string)(config('db')['driver'] ?? 'sqlite')
            . (Database::isPgsql() ? ' (postgres)' : (Database::isSqlite() ? ' (sqlite)' : '')));
        $run('Phiên bản DB', static function (): string {
            if (Database::isSqlite()) {
                return 'SQLite ' . (string)Database::value('SELECT sqlite_version()');
            }
            if (Database::isPgsql()) {
                return (string)Database::value('SHOW server_version');
            }
            return (string)Database::value('SELECT VERSION()');
        });
        $run('schema_version', static fn(): string => (string)(Database::value(
            "SELECT svalue FROM settings WHERE skey = 'schema_version'"
        ) ?? '(chưa ghi — sẽ migrate ở request này)'));
        $run('Index của settings (cần cho upsert)', static function (): string {
            if (Database::isPgsql()) {
                return implode(' | ', array_map(
                    static fn(array $r): string => (string)$r['indexdef'],
                    Database::all("SELECT indexdef FROM pg_indexes WHERE tablename = 'settings'")
                ));
            }
            if (Database::isSqlite()) {
                return implode(' | ', array_map(
                    static fn(array $r): string => json_encode($r, JSON_UNESCAPED_UNICODE) ?: '',
                    Database::all('PRAGMA index_list(settings)')
                ));
            }
            return implode(' | ', array_map(
                static fn(array $r): string => (string)$r['d'],
                Database::all(
                    'SELECT CONCAT(INDEX_NAME, " (", COLUMN_NAME, ")") AS d FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    ['settings']
                )
            ));
        });
        $run('Cột của rate_buckets', static function (): string {
            if (Database::isSqlite()) {
                return implode(', ', array_map(
                    static fn(array $c): string => (string)$c['name'] . ':' . (string)$c['type'],
                    Database::all('PRAGMA table_info(rate_buckets)')
                ));
            }
            if (Database::isPgsql()) {
                return implode(', ', array_map(
                    static fn(array $c): string => (string)$c['column_name'] . ':' . (string)$c['data_type'],
                    Database::all(
                        'SELECT column_name, data_type FROM information_schema.columns
                         WHERE table_name = ? ORDER BY ordinal_position',
                        ['rate_buckets']
                    )
                ));
            }
            return implode(', ', array_map(
                static fn(array $c): string => (string)$c['COLUMN_NAME'] . ':' . (string)$c['DATA_TYPE'],
                Database::all(
                    'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    ['rate_buckets']
                )
            ));
        });
        $run('Test transaction (BEGIN/INSERT/SELECT/COMMIT)', static function (): string {
            $key = 'diag:' . bin2hex(random_bytes(4));
            Database::begin();
            Database::run('INSERT INTO rate_buckets(bucket, hits, window_start) VALUES(?, 1, ?)', [$key, time()]);
            $row = Database::one('SELECT hits, window_start FROM rate_buckets WHERE bucket = ?', [$key]);
            Database::run('DELETE FROM rate_buckets WHERE bucket = ?', [$key]);
            Database::commit();
            return 'OK — dữ liệu đọc lại: ' . json_encode($row, JSON_UNESCAPED_UNICODE);
        });
        $run('RateLimiter', static fn(): string => RateLimiter::attempt('diag', client_ip(), 3, 60)
            ? 'OK — cho phép (DB lỗi thì tự fail-open, xem log Render)'
            : 'Đang bị chặn (đã dùng hết 3 lượt/60s)');
        $run('Số bản ghi', static fn(): string => 'users=' . (int)Database::value('SELECT COUNT(*) FROM users')
            . ', telegram_verify=' . (int)Database::value('SELECT COUNT(*) FROM telegram_verify')
            . ', rate_buckets=' . (int)Database::value('SELECT COUNT(*) FROM rate_buckets')
            . ', settings=' . (int)Database::value('SELECT COUNT(*) FROM settings'));
        $run('Telegram', static fn(): string => (Telegram::enabled() ? 'đã bật' : 'CHƯA BẬT (thiếu TELEGRAM_BOT_TOKEN / TELEGRAM_BOT_USERNAME)')
            . ' · bot=@' . (Telegram::botUsername() ?: '?'));
        $run('Nhật ký lỗi gần nhất (không cần Render Logs)', static function (): string {
            $f = STORAGE_PATH . '/logs/app.log';
            if (!is_file($f)) {
                return 'chưa có file log (chưa ghi lỗi nào kể từ khi deploy bản này)';
            }
            $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $tail = array_slice($lines, -15);
            return count($lines) . ' dòng — 15 dòng cuối: ' . implode(' ⏎ ', $tail);
        });
        $run('Telegram webhook (bot có nhận update?)', static function (): string {
            $i = Telegram::webhookInfo();
            if ($i === null) {
                return 'chưa bật bot → không kiểm tra được';
            }
            if (isset($i['error'])) {
                return (string)$i['error'];
            }
            $s = 'url=' . ($i['url'] !== '' ? $i['url'] : '(CHƯA ĐẶT — bot sẽ không trả lời)')
                . ' · đang chờ=' . $i['pending'];
            if ($i['last_error'] !== '') {
                $s .= ' · ⚠️ LỖI GẦN NHẤT: ' . $i['last_error'];
            }
            return $s;
        });

        View::show('admin/diag', [
            'ok'      => $ok,
            'err'     => $err,
            'baseUrl' => (string)(config('base_url') ?? ''),
        ], 'admin');
    }

    /** Đặt lại webhook Telegram về BASE_URL hiện tại (1 click, không cần curl/Render Shell). */
    public static function setTelegramWebhook(): void
    {
        AdminAuth::require();
        Csrf::check();
        if (!Telegram::enabled()) {
            Session::flash('error', 'Bot Telegram chưa được cấu hình (thiếu TELEGRAM_BOT_TOKEN / TELEGRAM_BOT_USERNAME).');
            redirect('/admin/diag');
        }
        $base = rtrim((string)(config('base_url') ?: getenv('RENDER_EXTERNAL_URL') ?: ''), '/');
        if (!str_starts_with($base, 'https://')) {
            Session::flash('error', 'BASE_URL chưa hợp lệ (cần bắt đầu bằng https://). Hiện tại: ' . ($base !== '' ? $base : '(trống)'));
            redirect('/admin/diag');
        }
        $url = $base . '/api/telegram/webhook';
        // Chốt an toàn: thử POST vào chính URL đó trước. Nếu bị redirect (apex htxg.pro → www)
        // hoặc lỗi thì KHÔNG đặt, vì Telegram không đi theo redirect → bot sẽ im lặng.
        $probe = self::probeWebhookUrl($url);
        if ($probe['code'] !== 200) {
            Session::flash('error', 'URL ' . $url . ' không dùng được: HTTP ' . $probe['code']
                . ($probe['location'] !== '' ? ' → ' . $probe['location'] : '')
                . '. Hãy dùng https://www.htxg.pro (apex htxg.pro bị redirect 307) rồi thử lại.');
            redirect('/admin/diag');
        }
        $res = Telegram::setWebhook($url);
        $json = json_decode((string)$res['resp'], true);
        if ($res['http'] === 200 && is_array($json) && !empty($json['ok'])) {
            Session::flash('success', 'Đã đặt webhook Telegram → ' . $url . ' (thử bấm START trong bot để kiểm tra)');
        } else {
            Session::flash('error', 'Đặt webhook thất bại (HTTP ' . $res['http'] . '): ' . (string)$res['resp']);
        }
        redirect('/admin/diag');
    }

    /** POST thử vào URL webhook: phát hiện redirect/domain lỗi trước khi đặt webhook thật. */
    private static function probeWebhookUrl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        unset($ch);
        return ['code' => $code, 'location' => $loc];
    }

        public static function settings(): void
    {
        AdminAuth::require();
        $rate = RateUpdater::currentRate();
        View::show('admin/settings', [
            'settings'     => Settings::all(),
            'postbackUrl'  => \App\PostbackProcessor::url(),
            'rateInfo'     => $rate,
        ], 'admin');
    }

    private const EDITABLE_SETTINGS = [
        'site_name', 'maintenance',
        'pubcrypto_site_key', 'pubcrypto_api_key', 'pubcrypto_forward_secret', 'pubcrypto_api_base', 'pubcrypto_task_cache_ttl',
        'usd_to_vnd_rate', 'site_member_share_percent',
        'min_withdraw_vnd', 'max_withdraw_vnd', 'daily_withdraw_limit_vnd', 'withdraw_fee_percent',
        'withdraw_min_account_age_days', 'withdraw_daily_count_limit', 'withdraw_bank_list',
        'withdraw_review_days', 'usd_rate_auto',
        'sms_http_url', 'sms_http_method', 'sms_http_headers', 'sms_http_body_template', 'sms_sender',
        'anticheat_enabled', 'max_accounts_per_ip', 'max_accounts_per_fp',
        'task_click_cooldown_seconds', 'task_max_completions_per_hour', 'postback_max_reward_vnd',
        'login_new_device_otp', 'min_form_seconds',
    ];

    public static function saveSettings(): void
    {
        AdminAuth::require();
        Csrf::check();
        foreach ($_POST as $key => $value) {
            if (!in_array($key, self::EDITABLE_SETTINGS, true) || !is_string($value)) {
                continue;
            }
            // Secrets may be left blank to keep the existing value (masked in the form).
            if ($value === '' && in_array($key, ['pubcrypto_api_key', 'pubcrypto_forward_secret'], true)) {
                continue;
            }
            Settings::set($key, trim($value));
        }
        // Checkboxes not posted = off.
        foreach (['maintenance', 'anticheat_enabled', 'login_new_device_otp'] as $flag) {
            if (!isset($_POST[$flag])) {
                Settings::set($flag, '0');
            }
        }
        Settings::reload();
        Audit::log('admin', AdminAuth::id() ?? 0, 'settings_saved', 'settings', []);
        Session::flash('success', 'Đã lưu cài đặt.');
        redirect('/admin/settings');
    }
}
