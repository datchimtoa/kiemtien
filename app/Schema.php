<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Idempotent schema migrations.
 * All money amounts are INTEGER VND (đồng). Points/rewards are REAL (fractional coins).
 */
final class Schema
{
    /**
     * Tăng số này MỖI KHI thêm/bớt DDL, cột, index hoặc setting mặc định mới,
     * nếu không thay đổi sẽ không được áp dụng cho DB đã tồn tại.
     */
    private const VERSION = '2026-09-20.2';

    /** Cờ đánh dấu rate_buckets đã đúng cấu trúc (tránh kiểm tra mỗi request). */
    private const FLAG_RATE_BUCKETS = 'rate_buckets_ok';

    public static function migrate(PDO $pdo): void
    {
        // Fast-path: schema đã đúng version → bỏ qua ~25 câu DDL mỗi request
        // (nhanh hơn rõ rệt với Postgres remote như Neon và tránh DDL lặp lại vô ích).
        try {
            if (Database::value("SELECT svalue FROM settings WHERE skey = 'schema_version'") === self::VERSION) {
                // Chỉ kiểm tra rate_buckets khi cờ chưa được ghi (thường chỉ 1 request đầu).
                if (Database::value('SELECT svalue FROM settings WHERE skey = ?', [self::FLAG_RATE_BUCKETS]) !== '1') {
                    self::ensureRateBuckets($pdo);
                }
                return;
            }
        } catch (\Throwable $e) {
            // Bảng settings chưa tồn tại (lần chạy đầu) → chạy migrate đầy đủ bên dưới.
        }
        $sqlite = Database::isSqlite();
        $pgsql = Database::isPgsql();
        // Postgres: BIGSERIAL PK, TEXT thay TEXT(n), VARCHAR thay TEXT(n).
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : ($pgsql ? 'BIGSERIAL PRIMARY KEY'
            : 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT');
        $text = static fn(int $n = 255) => $sqlite ? "TEXT($n)" : "VARCHAR($n)";
        // MySQL/Postgres: CREATE INDEX IF NOT EXISTS không tồn tại → tạo index có kiểm tra trùng.
        // $idx('name ON table(cols[, UNIQUE])') tương thích cả 2 driver.
        $idx = static function (string $name, string $table, string $cols, bool $unique = false) use ($pdo, $sqlite, $pgsql): void {
            if ($sqlite) {
                $pdo->exec(($unique ? 'CREATE UNIQUE INDEX IF NOT EXISTS ' : 'CREATE INDEX IF NOT EXISTS ') . "{$name} ON {$table}({$cols})");
                return;
            }
            if ($pgsql) {
                // Postgres: pg_indexes, CREATE INDEX IF NOT EXISTS ĐÃ có sẵn từ v9.5.
                $pdo->exec(($unique ? 'CREATE UNIQUE INDEX IF NOT EXISTS ' : 'CREATE INDEX IF NOT EXISTS ') . "{$name} ON {$table}({$cols})");
                return;
            }
            // MySQL: kiểm tra index đã tồn tại chưa rồi mới tạo (tránh lỗi 1061 khi migrate lại).
            try {
                $exists = Database::value(
                    'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$table, $name]
                );
                if ((int)$exists === 0) {
                    $pdo->exec(($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . "{$name} ON {$table}({$cols})");
                }
            } catch (\Throwable $e) {
                // Index có thể đã tồn tại do race → bỏ qua (ghi log để còn chẩn đoán).
                error_log('[schema] index ' . $name . ' on ' . $table . ' skipped: ' . $e->getMessage());
            }
        };

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            skey {$text(100)} PRIMARY KEY,
            svalue TEXT NOT NULL DEFAULT '',
            updated_at {$text(32)}
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id {$pk},
            phone {$text(20)} NOT NULL UNIQUE,
            password_hash {$text(255)} NOT NULL,
            full_name {$text(120)} DEFAULT '',
            status {$text(20)} NOT NULL DEFAULT 'active',
            balance_vnd INTEGER NOT NULL DEFAULT 0,
            points_total REAL NOT NULL DEFAULT 0,
            withdrawn_total_vnd INTEGER NOT NULL DEFAULT 0,
            referral_code {$text(16)} DEFAULT '',
            register_ip {$text(64)} DEFAULT '',
            register_ua {$text(300)} DEFAULT '',
            register_fp {$text(128)} DEFAULT '',
            register_device_id {$text(128)} DEFAULT '',
            last_login_at {$text(32)},
            last_login_ip {$text(64)} DEFAULT '',
            risk_flag INTEGER NOT NULL DEFAULT 0,
            created_at {$text(32)} NOT NULL,
            updated_at {$text(32)}
        )");
        $idx('idx_users_status', 'users', 'status');
        $idx('idx_users_fp', 'users', 'register_fp');

        $pdo->exec("CREATE TABLE IF NOT EXISTS otp_codes (
            id {$pk},
            phone {$text(20)} NOT NULL,
            code_hash {$text(128)} NOT NULL,
            purpose {$text(32)} NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            ip {$text(64)} DEFAULT '',
            expires_at {$text(32)} NOT NULL,
            consumed_at {$text(32)},
            created_at {$text(32)} NOT NULL
        )");
        $idx('idx_otp_phone', 'otp_codes', 'phone, purpose');

        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_events (
            id {$pk},
            user_id INTEGER,
            phone {$text(20)} DEFAULT '',
            event {$text(40)} NOT NULL,
            result {$text(20)} NOT NULL,
            ip {$text(64)} DEFAULT '',
            ua {$text(300)} DEFAULT '',
            device_id {$text(128)} DEFAULT '',
            detail {$text(500)} DEFAULT '',
            created_at {$text(32)} NOT NULL
        )");
        $idx('idx_auth_events_user', 'auth_events', 'user_id');

        $pdo->exec("CREATE TABLE IF NOT EXISTS devices (
            id {$pk},
            user_id INTEGER NOT NULL,
            device_id {$text(128)} DEFAULT '',
            fp_hash {$text(128)} DEFAULT '',
            ua {$text(300)} DEFAULT '',
            platform {$text(60)} DEFAULT '',
            ip {$text(64)} DEFAULT '',
            first_seen {$text(32)} NOT NULL,
            last_seen {$text(32)} NOT NULL,
            trusted INTEGER NOT NULL DEFAULT 0
        )");
        $idx('idx_devices_user', 'devices', 'user_id');
        $idx('idx_devices_fp', 'devices', 'fp_hash');
        $idx('idx_devices_ip', 'devices', 'ip');

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_ips (
            id {$pk},
            user_id INTEGER NOT NULL,
            ip {$text(64)} NOT NULL,
            hits INTEGER NOT NULL DEFAULT 1,
            first_seen {$text(32)} NOT NULL,
            last_seen {$text(32)} NOT NULL
        )");
        $idx('uq_user_ip', 'user_ips', 'user_id, ip', true);
        $idx('idx_user_ips_ip', 'user_ips', 'ip');
        self::migratePart2($pdo, $pk, $text);
    }

    private static function migratePart2(PDO $pdo, string $pk, \Closure $text): void
    {
        $sqlite = Database::isSqlite();
        $pgsql = Database::isPgsql();
        $idx = static function (string $name, string $table, string $cols, bool $unique = false) use ($pdo, $sqlite, $pgsql): void {
            if ($sqlite || $pgsql) {
                $pdo->exec(($unique ? 'CREATE UNIQUE INDEX IF NOT EXISTS ' : 'CREATE INDEX IF NOT EXISTS ') . "{$name} ON {$table}({$cols})");
                return;
            }
            try {
                $exists = Database::value(
                    'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$table, $name]
                );
                if ((int)$exists === 0) {
                    $pdo->exec(($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . "{$name} ON {$table}({$cols})");
                }
            } catch (\Throwable $e) {
                error_log('[schema] index ' . $name . ' on ' . $table . ' skipped: ' . $e->getMessage());
            }
        };
        $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
            id {$pk},
            user_id INTEGER NOT NULL,
            type {$text(32)} NOT NULL,
            amount_vnd INTEGER NOT NULL DEFAULT 0,
            points REAL NOT NULL DEFAULT 0,
            balance_after INTEGER NOT NULL DEFAULT 0,
            trans_id {$text(64)} DEFAULT '',
            source {$text(32)} DEFAULT '',
            status_raw INTEGER,
            offer_name {$text(200)} DEFAULT '',
            offer_type {$text(60)} DEFAULT '',
            payout_usd REAL,
            reward_pb REAL,
            country {$text(4)} DEFAULT '',
            ip {$text(64)} DEFAULT '',
            note {$text(300)} DEFAULT '',
            raw_json TEXT,
            created_at {$text(32)} NOT NULL
        )");
        $idx('idx_tx_user', 'transactions', 'user_id');
        $idx('idx_tx_trans', 'transactions', 'trans_id');
        // Chống trùng transId trên cả 2 driver. SQLite hỗ trợ partial index (WHERE ...);
        // MySQL KHÔNG hỗ trợ → đổi chiến lược: trans_id rỗng lưu NULL, UNIQUE(trans_id)
        // (MySQL cho phép nhiều NULL trong UNIQUE; SQLite cũng vậy). Xem Wallet::post().
        if ($sqlite) {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_tx_trans_id ON transactions(trans_id) WHERE trans_id IS NOT NULL");
        } else {
            $idx('uq_tx_trans_id', 'transactions', 'trans_id', true);
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawals (
            id {$pk},
            user_id INTEGER NOT NULL,
            amount_vnd INTEGER NOT NULL,
            fee_vnd INTEGER NOT NULL DEFAULT 0,
            method {$text(20)} NOT NULL,
            account_name {$text(120)} NOT NULL,
            account_number {$text(64)} NOT NULL,
            bank_name {$text(120)} DEFAULT '',
            status {$text(20)} NOT NULL DEFAULT 'pending',
            admin_note {$text(300)} DEFAULT '',
            requested_ip {$text(64)} DEFAULT '',
            requested_device {$text(128)} DEFAULT '',
            processed_by INTEGER,
            processed_at {$text(32)},
            created_at {$text(32)} NOT NULL
        )");
        $idx('idx_wd_user', 'withdrawals', 'user_id');
        $idx('idx_wd_status', 'withdrawals', 'status');

        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
            id {$pk},
            username {$text(60)} NOT NULL UNIQUE,
            password_hash {$text(255)} NOT NULL,
            status {$text(20)} NOT NULL DEFAULT 'active',
            last_login_at {$text(32)},
            last_login_ip {$text(64)} DEFAULT '',
            created_at {$text(32)} NOT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id {$pk},
            actor_type {$text(20)} NOT NULL,
            actor_id INTEGER NOT NULL DEFAULT 0,
            action {$text(60)} NOT NULL,
            target {$text(60)} DEFAULT '',
            detail TEXT,
            ip {$text(64)} DEFAULT '',
            created_at {$text(32)} NOT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS risk_events (
            id {$pk},
            user_id INTEGER,
            type {$text(60)} NOT NULL,
            severity {$text(20)} NOT NULL DEFAULT 'low',
            detail {$text(500)} DEFAULT '',
            ip {$text(64)} DEFAULT '',
            created_at {$text(32)} NOT NULL
        )");
        $idx('idx_risk_user', 'risk_events', 'user_id');

        $pdo->exec("CREATE TABLE IF NOT EXISTS postback_logs (
            id {$pk},
            trans_id {$text(64)} DEFAULT '',
            sub_id {$text(64)} DEFAULT '',
            status_raw INTEGER,
            reward_pb REAL,
            payout_pb REAL,
            ip {$text(64)} DEFAULT '',
            signature_valid INTEGER NOT NULL DEFAULT 0,
            result {$text(40)} NOT NULL DEFAULT '',
            raw TEXT,
            created_at {$text(32)} NOT NULL
        )");
        $idx('idx_pbl_trans', 'postback_logs', 'trans_id');

        $pdo->exec("CREATE TABLE IF NOT EXISTS task_cache (
            site_key {$text(128)} PRIMARY KEY,
            payload TEXT NOT NULL,
            fetched_at {$text(32)} NOT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS task_clicks (
            id {$pk},
            user_id INTEGER NOT NULL,
            task_id INTEGER NOT NULL,
            task_name {$text(200)} DEFAULT '',
            ip {$text(64)} DEFAULT '',
            created_at {$text(32)} NOT NULL
        )");
        $idx('idx_clicks_user_time', 'task_clicks', 'user_id, created_at');

        // window_start lưu UNIX timestamp (số). Postgres/MySQL dùng kiểu số để so sánh
        // và ràng buộc kiểu đúng; SQLite giữ TEXT(32) để tương thích DB cũ (TEXT affinity).
        $windowStartType = $sqlite ? 'TEXT(32)' : 'BIGINT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_buckets (
            bucket {$text(160)} PRIMARY KEY,
            hits INTEGER NOT NULL DEFAULT 0,
            window_start {$windowStartType} NOT NULL
        )");
        self::ensureRateBuckets($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS fingerprints (
            id {$pk},
            user_id INTEGER,
            session_key {$text(128)} DEFAULT '',
            device_id {$text(128)} DEFAULT '',
            fp_hash {$text(128)} NOT NULL,
            components {$text(500)} DEFAULT '',
            ip {$text(64)} DEFAULT '',
            ua {$text(300)} DEFAULT '',
            created_at {$text(32)} NOT NULL
        )");
        $idx('idx_fp_hash', 'fingerprints', 'fp_hash');

        $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_verify (
            id {$pk},
            token {$text(64)} NOT NULL UNIQUE,
            phone {$text(20)} NOT NULL,
            telegram_user_id {$text(32)},
            telegram_username {$text(64)},
            status {$text(20)} NOT NULL DEFAULT 'pending',
            purpose {$text(20)} NOT NULL DEFAULT 'register',
            ip {$text(64)} DEFAULT '',
            created_at {$text(32)} NOT NULL,
            expires_at {$text(32)} NOT NULL,
            verified_at {$text(32)}
        )");
        $idx('idx_tgv_phone', 'telegram_verify', 'phone');

        // Partial unique cho dedupe transId: SQLite + Postgres hỗ trợ WHERE,
        // MySQL không → dùng UNIQUE thường (Wallet::post lưu NULL khi rỗng).
        if ($sqlite || $pgsql) {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_tx_trans_id ON transactions(trans_id) WHERE trans_id IS NOT NULL');
        } else {
            $idx('uq_tx_trans_id', 'transactions', 'trans_id', true);
        }

        self::migrateColumns($pdo);
        self::migrateDefaults($pdo);
        // Ghi nhớ version để các request sau bỏ qua bước migrate.
        try {
            Database::upsert(
                'settings',
                ['skey' => 'schema_version', 'svalue' => self::VERSION, 'updated_at' => now()],
                'skey',
                ['svalue', 'updated_at']
            );
        } catch (\Throwable $e) {
            error_log('[schema] cannot record schema_version: ' . $e->getMessage());
        }
    }

    /** Add columns that were introduced after the first release. */
    private static function migrateColumns(PDO $pdo): void
    {
        $sqlite = Database::isSqlite();
        $pgsql = Database::isPgsql();
        // Kiểu cột mới cho Postgres: TEXT(n) → TEXT, TEXT(32) → VARCHAR(32).
        $pgdef = static fn(string $def): string => preg_replace('/\bTEXT\((\d+)\)/i', 'VARCHAR($1)', $def) ?? $def;
        $add = static function (string $table, string $column, string $definition) use ($pdo, $sqlite, $pgsql, $pgdef): void {
            if ($sqlite) {
                $cols = Database::all("PRAGMA table_info({$table})");
                foreach ($cols as $c) {
                    if (($c['name'] ?? '') === $column) {
                        return;
                    }
                }
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                return;
            }
            if ($pgsql) {
                $exists = Database::value(
                    'SELECT COUNT(*) FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                    [$table, $column]
                );
                if ((int)$exists === 0) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} " . $pgdef($definition));
                }
                return;
            }
            // MySQL: kiểm tra qua information_schema (TABLE_SCHEMA = DATABASE()).
            $exists = Database::value(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );
            if ((int)$exists === 0) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        };
        $add('users', 'telegram_user_id', "TEXT(32) DEFAULT ''");
        $add('users', 'telegram_username', "TEXT(64) DEFAULT ''");
        $add('users', 'telegram_verified_at', 'TEXT(32)');
    }

    /**
     * rate_buckets là bảng tạm của rate limiter. DB tạo từ phiên bản schema cũ có thể lệch:
     *  - window_start từng khai báo TEXT(32)/VARCHAR(32) nhưng code ghi số (time()).
     *    Postgres so kiểu rất chặt → câu lệnh lỗi trong transaction → transaction "aborted"
     *    → mọi câu lệnh sau trả SQLSTATE 25P02 (chính là lỗi làm hỏng trang đăng nhập).
     *  - thiếu UNIQUE(bucket) → limiter đếm sai.
     * Sửa 1 lần, sau đó ghi cờ vào settings để không phải kiểm tra lại mỗi request.
     */
    private static function ensureRateBuckets(PDO $pdo): void
    {
        if (Database::isSqlite()) {
            // SQLite: TEXT affinity vẫn lưu + so sánh số được → không cần sửa, chỉ ghi cờ.
            self::markRateBucketsOk();
            return;
        }
        try {
            $bad = [];
            if (Database::isPgsql()) {
                $cols = [];
                foreach (Database::all(
                    'SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ?',
                    ['rate_buckets']
                ) as $r) {
                    $cols[strtolower((string)$r['column_name'])] = strtolower((string)$r['data_type']);
                }
                $type = $cols['window_start'] ?? '';
                if (!in_array($type, ['bigint', 'integer'], true)) {
                    $bad[] = 'window_start=' . ($type !== '' ? $type : 'missing');
                    if ($type !== '') {
                        $pdo->exec(
                            'ALTER TABLE rate_buckets ALTER COLUMN window_start TYPE BIGINT '
                            . "USING COALESCE(NULLIF(regexp_replace(window_start::text, '[^0-9]', '', 'g'), ''), '0')::bigint"
                        );
                    }
                }
                $uniq = (int)Database::value(
                    "SELECT COUNT(*) FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE '%unique%' AND indexdef ILIKE '%bucket%'",
                    ['rate_buckets']
                );
                if ($uniq === 0) {
                    $bad[] = 'missing unique(bucket)';
                    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_rate_buckets_bucket ON rate_buckets(bucket)');
                }
            } else {
                $cols = [];
                foreach (Database::all(
                    'SELECT COLUMN_NAME AS c, DATA_TYPE AS t FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    ['rate_buckets']
                ) as $r) {
                    $cols[strtolower((string)$r['c'])] = strtolower((string)$r['t']);
                }
                $type = $cols['window_start'] ?? '';
                if (!in_array($type, ['bigint', 'int', 'integer'], true)) {
                    $bad[] = 'window_start=' . ($type !== '' ? $type : 'missing');
                    if ($type !== '') {
                        $pdo->exec('ALTER TABLE rate_buckets MODIFY window_start BIGINT NOT NULL');
                    }
                }
            }
            if ($bad) {
                error_log('[schema] rate_buckets repaired → ' . implode('; ', $bad));
            }
            self::markRateBucketsOk();
        } catch (\Throwable $e) {
            // Không chặn app: RateLimiter đã có fail-open + tự phục hồi 25P02.
            error_log('[schema] ensureRateBuckets failed: ' . $e->getMessage());
        }
    }

    /** Ghi cờ "rate_buckets đã đúng" để bỏ qua bước kiểm tra ở các request sau. */
    private static function markRateBucketsOk(): void
    {
        try {
            Database::upsert(
                'settings',
                ['skey' => self::FLAG_RATE_BUCKETS, 'svalue' => '1', 'updated_at' => now()],
                'skey',
                ['svalue', 'updated_at']
            );
        } catch (\Throwable $e) {
            error_log('[schema] cannot set ' . self::FLAG_RATE_BUCKETS . ': ' . $e->getMessage());
        }
    }

    private static function migrateDefaults(PDO $pdo): void
    {
        $defaults = [
            'site_name'            => 'HTXG.PRO',
            'currency_name'        => 'VND',
            'maintenance'          => '0',
            // PubCrypto integration
            'pubcrypto_site_key'   => '',
            'pubcrypto_api_key'    => '',
            'pubcrypto_forward_secret' => '',
            'pubcrypto_api_base'   => 'https://pub.cryptolinkforearn.com',
            'pubcrypto_task_cache_ttl' => '45',
                        // Economy
            'usd_to_vnd_rate'      => '26000',
            'usd_rate_auto'        => '1',
            'usd_rate_updated_at'  => '',
            'site_member_share_percent' => '100',
            'min_withdraw_vnd'     => '10000',
            'max_withdraw_vnd'     => '5000000',
            'daily_withdraw_limit_vnd' => '5000000',
            'withdraw_fee_percent' => '0',
            'withdraw_min_account_age_days' => '0',
            'withdraw_daily_count_limit' => '3',
            // Chỉ duyệt rút vào các ngày 7, 14, 21, 28
            'withdraw_review_days' => '7,14,21,28',
            'withdraw_bank_list'   => "Vietcombank\nTechcombank\nMB Bank\nVPBank\nBIDV\nAgribank\nACB\nTPBank\nSacombank\nVietinBank\nMomo\nZaloPay",
            // SMS gateway (http driver)
            'sms_http_url'         => '',
            'sms_http_method'      => 'POST',
            'sms_http_headers'     => "Content-Type: application/json",
            'sms_http_body_template' => '',
            'sms_sender'           => 'HTXG',
            // SpeedSMS.vn driver
            'speedsms_token'       => '',
            'speedsms_zns_template_id' => '',
            'speedsms_sender'      => '',
            // Anticheat
            'anticheat_enabled'    => '1',
            'max_accounts_per_ip'  => '3',
            'max_accounts_per_fp'  => '2',
            'task_click_cooldown_seconds' => '20',
            'task_max_completions_per_hour' => '30',
            'postback_max_reward_vnd' => '2000000',
            'login_new_device_otp' => '1',
            'min_form_seconds'     => '3',
            // Registration channels
            'register_channel_sms' => '0', // 0 = chỉ Telegram (miễn phí); bật 1 khi có SMS gateway
            'register_channel_telegram' => '1',
            // Auto USD/VND rate
            'usd_rate_auto'        => '1',
            'usd_rate_updated_at'  => '',
        ];
        // "INSERT OR IGNORE" là cú pháp SQLite. MySQL: INSERT IGNORE; Postgres: ON CONFLICT DO NOTHING.
        $now = now();
        $isPgsql = Database::isPgsql();
        foreach ($defaults as $k => $v) {
            try {
                if (Database::isSqlite()) {
                    $pdo->prepare('INSERT OR IGNORE INTO settings(skey, svalue, updated_at) VALUES(?, ?, ?)')
                        ->execute([$k, $v, $now]);
                } elseif ($isPgsql) {
                    $pdo->prepare('INSERT INTO settings(skey, svalue, updated_at) VALUES(?, ?, ?) ON CONFLICT(skey) DO NOTHING')
                        ->execute([$k, $v, $now]);
                } else {
                    $pdo->prepare('INSERT IGNORE INTO settings(skey, svalue, updated_at) VALUES(?, ?, ?)')
                        ->execute([$k, $v, $now]);
                }
            } catch (\Throwable $e) {
                // Đã tồn tại hoặc lỗi seed → bỏ qua, giữ giá trị hiện có.
                error_log('[schema] seed setting ' . $k . ' skipped: ' . $e->getMessage());
            }
        }

        // Self-heal: DB đã tồn tại từ thời brand cũ → cập nhật sang brand hiện tại.
        try {
            $pdo->prepare("UPDATE settings SET svalue = ?, updated_at = ? WHERE skey = 'site_name' AND svalue = ?")
                ->execute(['HTXG.PRO', $now, 'EarnMoney.VIP']);
        } catch (\Throwable $e) {
            // Bỏ qua nếu bảng chưa sẵn sàng.
            error_log('[schema] site_name self-heal skipped: ' . $e->getMessage());
        }
    }
}
