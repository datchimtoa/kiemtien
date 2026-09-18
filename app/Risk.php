<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Anticheat / risk engine.
 * Central place for multi-account, velocity and sanity checks. Every decision
 * is persisted to risk_events for admin review.
 */
final class Risk
{
    public static function enabled(): bool
    {
        return Settings::getInt('anticheat_enabled', 1) === 1;
    }

    public static function event(?int $userId, string $type, string $severity = 'low', string $detail = ''): void
    {
        Database::run(
            'INSERT INTO risk_events(user_id, type, severity, detail, ip, created_at) VALUES(?,?,?,?,?,?)',
            [$userId, $type, $severity, substr($detail, 0, 500), client_ip(), now()]
        );
        error_log("[risk] u={$userId} {$type} ({$severity}) {$detail} ip=" . client_ip());
    }

    /**
     * Registration-time checks.
     * @return string[] list of human messages (empty = allowed)
     */
    public static function checkRegistration(string $phone, ?string $fpHash, ?string $deviceId): array
    {
        $errs = [];
        if (!self::enabled()) {
            return $errs;
        }
        $ip = client_ip();
        $maxPerIp = Settings::getInt('max_accounts_per_ip', 3);
        $countIp = (int)Database::value(
            'SELECT COUNT(*) FROM users WHERE register_ip = ? OR id IN (SELECT user_id FROM user_ips WHERE ip = ?)',
            [$ip, $ip]
        );
        if ($countIp >= $maxPerIp) {
            $errs[] = 'Địa chỉ IP này đã được dùng để tạo quá nhiều tài khoản.';
            self::event(null, 'register_multi_account_ip', 'high', "ip={$ip} count={$countIp} phone={$phone}");
        }
        if ($fpHash !== null && $fpHash !== '') {
            $maxPerFp = Settings::getInt('max_accounts_per_fp', 2);
            $countFp = (int)Database::value('SELECT COUNT(*) FROM users WHERE register_fp = ?', [$fpHash]);
            if ($countFp >= $maxPerFp) {
                $errs[] = 'Thiết bị này đã được dùng để tạo quá nhiều tài khoản.';
                self::event(null, 'register_multi_account_fp', 'high', "fp={$fpHash} count={$countFp}");
            }
        }
        return $errs;
    }

    /** Postback sanity: amount vs configured ceiling + user velocity. */
    public static function checkPostback(int $userId, float $amountVnd, string $transId, string $country): void
    {
        $max = (float)Settings::get('postback_max_reward_vnd', '2000000');
        if ($amountVnd > $max) {
            self::event($userId, 'postback_amount_over_ceiling', 'high', "transId={$transId} amount={$amountVnd} ceiling={$max}");
        }
        // Velocity: completions per hour
        $maxPerHour = Settings::getInt('task_max_completions_per_hour', 30);
        $count = (int)Database::value(
            "SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = 'task_reward' AND created_at >= ?",
            [$userId, date('Y-m-d H:i:s', time() - 3600)]
        );
        if ($count > $maxPerHour) {
            self::event($userId, 'postback_velocity', 'medium', "transId={$transId} completions_last_hour={$count} cap={$maxPerHour}");
        }
        $ip = client_ip();
        $knownIps = (int)Database::value('SELECT COUNT(*) FROM user_ips WHERE user_id = ? AND ip = ?', [$userId, $ip]);
        if ($knownIps === 0 && $ip !== 'unknown') {
            self::event($userId, 'postback_from_unknown_ip', 'low', "transId={$transId} ip={$ip}");
        }
    }

    /** Withdrawal-time checks (extra to hard limits). Returns error message or null. */
    public static function checkWithdrawal(int $userId): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null || $user['status'] !== 'active') {
            return 'Tài khoản không khả dụng.';
        }
        if ((int)$user['risk_flag'] === 1) {
            return 'Tài khoản của bạn đang bị tạm khoá rút tiền. Vui lòng liên hệ hỗ trợ.';
        }
        // Account age
        $minAge = Settings::getInt('withdraw_min_account_age_days', 0);
        if ($minAge > 0) {
            $ageDays = (int)((time() - strtotime((string)$user['created_at'])) / 86400);
            if ($ageDays < $minAge) {
                return "Tài khoản cần hoạt động tối thiểu {$minAge} ngày trước khi rút tiền.";
            }
        }
        // Sharing IP with many other users -> flag
        $ip = client_ip();
        $othersOnIp = (int)Database::value(
            'SELECT COUNT(DISTINCT user_id) FROM user_ips WHERE ip = ? AND user_id != ?',
            [$ip, $userId]
        );
        if ($othersOnIp >= 5) {
            self::event($userId, 'withdraw_shared_ip', 'medium', "ip={$ip} other_accounts={$othersOnIp}");
        }
        return null;
    }

    /** Trust score 0-100: higher = more trustworthy. */
    public static function trustScore(int $userId): int
    {
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return 0;
        }
        $score = 70;
        // Telegram verified = strong positive signal
        if (!empty($user['telegram_user_id'])) {
            $score += 15;
        }
        // Account age: up to +10
        $ageDays = (int)((time() - strtotime((string)$user['created_at'])) / 86400);
        $score += min(10, $ageDays);
        // Chargebacks: -20 each (max -40)
        $chargebacks = (int)Database::value("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = 'task_chargeback'", [$userId]);
        $score -= min(40, $chargebacks * 20);
        // High-severity risk events: -15 each (max -45)
        $high = (int)Database::value("SELECT COUNT(*) FROM risk_events WHERE user_id = ? AND severity = 'high'", [$userId]);
        $score -= min(45, $high * 15);
        // Multi-account signals: shared IP / shared fingerprint
        $ip = (string)$user['last_login_ip'];
        if ($ip !== '') {
            $othersIp = (int)Database::value('SELECT COUNT(DISTINCT user_id) FROM user_ips WHERE ip = ? AND user_id != ?', [$ip, $userId]);
            if ($othersIp > 0) {
                $score -= min(20, $othersIp * 5);
            }
        }
        if (!empty($user['register_fp'])) {
            $othersFp = (int)Database::value('SELECT COUNT(DISTINCT user_id) FROM users WHERE register_fp = ? AND register_fp != "" AND id != ?', [$user['register_fp'], $userId]);
            if ($othersFp > 0) {
                $score -= min(30, $othersFp * 15);
            }
        }
        return max(0, min(100, $score));
    }

    /** High score = lifetime task earnings in VND. */
    public static function highScore(int $userId): int
    {
        return (int)(Database::value(
            "SELECT COALESCE(SUM(amount_vnd),0) FROM transactions WHERE user_id = ? AND type = 'task_reward' AND amount_vnd > 0",
            [$userId]
        ) ?? 0);
    }

    /** Auto-ban: >=3 high-severity violations → ban the account. */
    public static function autoBanCheck(int $userId): void
    {
        if (!self::enabled()) {
            return;
        }
        $high = (int)Database::value("SELECT COUNT(*) FROM risk_events WHERE user_id = ? AND severity = 'high'", [$userId]);
        if ($high >= 3) {
            $user = Database::one('SELECT status FROM users WHERE id = ?', [$userId]);
            if ($user !== null && $user['status'] === 'active') {
                Database::run("UPDATE users SET status = 'banned', risk_flag = 1, updated_at = ? WHERE id = ?", [now(), $userId]);
                self::event($userId, 'auto_ban_violations', 'high', "banned after {$high} high-severity violations");
            }
        }
    }

    /** Bypass cheat: reward postback without any task click in 7 days → ban + delete balance? (admin decides deletion) */
    public static function checkBypassCheat(int $userId, string $transId): bool
    {
        $hasClick = Database::one(
            'SELECT id FROM task_clicks WHERE user_id = ? AND created_at >= ? LIMIT 1',
            [$userId, date('Y-m-d H:i:s', time() - 7 * 86400)]
        );
        if ($hasClick === null) {
            self::event($userId, 'postback_without_click', 'high', "transId={$transId} — reward with no task click in 7 days (bypass cheat)");
            // Bypass cheat = ban immediately.
            Database::run("UPDATE users SET status = 'banned', risk_flag = 1, updated_at = ? WHERE id = ?", [now(), $userId]);
            self::event($userId, 'auto_ban_bypass', 'high', "banned for link bypass");
            return false;
        }
        return true;
    }
}
