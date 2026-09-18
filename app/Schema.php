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
    public static function migrate(PDO $pdo): void
    {
        $sqlite = Database::isSqlite();
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT';
        $text = static fn(int $n = 255) => $sqlite ? "TEXT($n)" : "VARCHAR($n)";

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_status ON users(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_fp ON users(register_fp)');

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_otp_phone ON otp_codes(phone, purpose)');

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_events_user ON auth_events(user_id)');

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_user ON devices(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_fp ON devices(fp_hash)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_ip ON devices(ip)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_ips (
            id {$pk},
            user_id INTEGER NOT NULL,
            ip {$text(64)} NOT NULL,
            hits INTEGER NOT NULL DEFAULT 1,
            first_seen {$text(32)} NOT NULL,
            last_seen {$text(32)} NOT NULL
        )");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_user_ip ON user_ips(user_id, ip)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_ips_ip ON user_ips(ip)');
        self::migratePart2($pdo, $pk, $text);
    }

    private static function migratePart2(PDO $pdo, string $pk, \Closure $text): void
    {
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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tx_user ON transactions(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tx_trans ON transactions(trans_id)');
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_tx_trans_id ON transactions(trans_id) WHERE trans_id <> ''");

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wd_user ON withdrawals(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wd_status ON withdrawals(status)');

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_risk_user ON risk_events(user_id)');

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pbl_trans ON postback_logs(trans_id)');

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clicks_user_time ON task_clicks(user_id, created_at)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_buckets (
            bucket {$text(160)} PRIMARY KEY,
            hits INTEGER NOT NULL DEFAULT 0,
            window_start {$text(32)} NOT NULL
        )");

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fp_hash ON fingerprints(fp_hash)');

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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tgv_phone ON telegram_verify(phone)');

        self::migrateColumns($pdo);
        self::migrateDefaults($pdo);
    }

    /** Add columns that were introduced after the first release. */
    private static function migrateColumns(PDO $pdo): void
    {
        $add = static function (string $table, string $column, string $definition) use ($pdo): void {
            $cols = Database::all("PRAGMA table_info({$table})");
            foreach ($cols as $c) {
                if (($c['name'] ?? '') === $column) {
                    return;
                }
            }
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        };
        $add('users', 'telegram_user_id', "TEXT(32) DEFAULT ''");
        $add('users', 'telegram_username', "TEXT(64) DEFAULT ''");
        $add('users', 'telegram_verified_at', 'TEXT(32)');
    }

    private static function migrateDefaults(PDO $pdo): void
    {
        $defaults = [
            'site_name'            => 'EarnMoney.VIP',
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
            'sms_sender'           => 'EARNVIP',
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
        $st = $pdo->prepare("INSERT OR IGNORE INTO settings(skey, svalue, updated_at) VALUES(?, ?, ?)");
        $now = now();
        foreach ($defaults as $k => $v) {
            $st->execute([$k, $v, $now]);
        }
    }
}
