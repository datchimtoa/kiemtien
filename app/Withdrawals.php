<?php
declare(strict_types=1);

namespace App;

/**
 * Withdrawals: request (balance hold), admin approve/reject (refund on reject),
 * daily limits, per-user and global checks.
 */
final class Withdrawals
{
    public static function methodLabel(string $m): string
    {
        return match ($m) {
            'bank'  => 'Chuyển khoản ngân hàng',
            'momo'  => 'MoMo',
            'zalopay' => 'ZaloPay',
            default => $m,
        };
    }

    /** TRUE if today is a review day (default 7,14,21,28). */
    public static function isReviewDay(?int $ts = null): bool
    {
        $days = array_map('intval', array_filter(explode(',', (string)Settings::get('withdraw_review_days', '7,14,21,28'))));
        if ($days === []) {
            return true;
        }
        return in_array((int)date('j', $ts ?? time()), $days, true);
    }

    /** Next review day timestamp (for countdown display). */
    public static function nextReviewDay(): int
    {
        $days = array_map('intval', array_filter(explode(',', (string)Settings::get('withdraw_review_days', '7,14,21,28'))));
        sort($days);
        $now = time();
        for ($i = 0; $i < 40; $i++) {
            $ts = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now) + $i, (int)date('Y', $now));
            if (in_array((int)date('j', $ts), $days, true) && $ts > $now) {
                return $ts;
            }
        }
        return $now + 86400;
    }

    /**
     * Request a withdrawal. Debits balance immediately (hold).
     * @return array [ok, error]
     */
    public static function request(int $userId, int $amount, string $method, string $accountName, string $accountNumber, string $bankName = ''): array
    {
        $min = Settings::getInt('min_withdraw_vnd', 10000);
        $max = Settings::getInt('max_withdraw_vnd', 5000000);
        $dailyLimit = Settings::getInt('daily_withdraw_limit_vnd', 5000000);
        $dailyCount = Settings::getInt('withdraw_daily_count_limit', 3);
        $feePct = Settings::getFloat('withdraw_fee_percent', 0.0);

        if ($amount <= 0 || $amount < $min) {
            return [false, 'Số tiền rút tối thiểu là ' . vnd($min) . '.'];
        }
        if ($amount > $max) {
            return [false, 'Số tiền rút tối đa mỗi giao dịch là ' . vnd($max) . '.'];
        }
        if (!in_array($method, ['bank', 'momo', 'zalopay'], true)) {
            return [false, 'Phương thức rút không hợp lệ.'];
        }
        if ($method === 'bank' && $bankName === '') {
            return [false, 'Vui lòng chọn ngân hàng.'];
        }
        if (mb_strlen($accountName) < 3 || mb_strlen($accountNumber) < 6) {
            return [false, 'Thông tin tài khoản nhận tiền không hợp lệ.'];
        }

        $riskErr = Risk::checkWithdrawal($userId);
        if ($riskErr !== null) {
            return [false, $riskErr];
        }

        // Withdrawal date gate: only allow requests on review days (7,14,21,28 by default).
        if (!self::isReviewDay()) {
            return [false, 'Chỉ được đăng ký rút tiền vào ngày duyệt: ' . date('d/m/Y', self::nextReviewDay()) . ' (' . implode(', ', array_map('intval', array_filter(explode(',', (string)Settings::get('withdraw_review_days', '7,14,21,28'))))) . ' hàng tháng).'];
        }

        Database::begin();
        try {
            $lock = Database::isSqlite() ? '' : ' FOR UPDATE';
            $user = Database::one('SELECT balance_vnd FROM users WHERE id = ?' . $lock, [$userId]);
            if ($user === null || (int)$user['balance_vnd'] < $amount) {
                Database::rollback();
                return [false, 'Số dư không đủ.'];
            }
            $todayStart = date('Y-m-d 00:00:00');
            $sumToday = (int)(Database::value(
                "SELECT COALESCE(SUM(amount_vnd),0) FROM withdrawals WHERE user_id = ? AND status IN ('pending','processing','completed') AND created_at >= ?",
                [$userId, $todayStart]
            ) ?? 0);
            if ($sumToday + $amount > $dailyLimit) {
                Database::rollback();
                return [false, 'Bạn đã đạt hạn mức rút tiền trong ngày (' . vnd($dailyLimit) . ').'];
            }
            $countToday = (int)(Database::value(
                "SELECT COUNT(*) FROM withdrawals WHERE user_id = ? AND status IN ('pending','processing','completed') AND created_at >= ?",
                [$userId, $todayStart]
            ) ?? 0);
            if ($countToday >= $dailyCount) {
                Database::rollback();
                return [false, "Bạn chỉ được tạo tối đa {$dailyCount} yêu cầu rút mỗi ngày."];
            }
            $pending = Database::one("SELECT id FROM withdrawals WHERE user_id = ? AND status IN ('pending','processing')", [$userId]);
            if ($pending !== null) {
                Database::rollback();
                return [false, 'Bạn còn một yêu cầu rút tiền đang chờ xử lý.'];
            }

            $fee = (int)floor($amount * $feePct / 100);
            Wallet::post($userId, Wallet::TYPE_WITHDRAW_HOLD, -$amount, [
                'source' => 'withdrawal',
                'note' => 'Yêu cầu rút tiền #' . $method,
            ]);

            Database::run(
                'INSERT INTO withdrawals(user_id, amount_vnd, fee_vnd, method, account_name, account_number, bank_name, status, requested_ip, requested_device, created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [$userId, $amount, $fee, $method, $accountName, $accountNumber, $bankName, 'pending', client_ip(), (string)Fingerprint::sessionDeviceId(), now()]
            );
            Audit::log('user', $userId, 'withdraw_requested', 'withdrawals', ['amount' => $amount, 'method' => $method]);
            Database::commit();
            return [true, null];
        } catch (\Throwable $e) {
            Database::rollback();
            error_log('[withdraw] request failed: ' . $e->getMessage());
            return [false, 'Không thể tạo yêu cầu rút tiền. Vui lòng thử lại.'];
        }
    }

    /** Admin: mark completed (money already debited). Only on review days (7,14,21,28). */
    public static function complete(int $withdrawId, int $adminId, string $note = ''): array
    {
        Database::begin();
        try {
            $lock = Database::isSqlite() ? '' : ' FOR UPDATE';
            $wd = Database::one("SELECT * FROM withdrawals WHERE id = ? AND status IN ('pending','processing')" . $lock, [$withdrawId]);
            if ($wd === null) {
                Database::rollback();
                return [false, 'Không tìm thấy yêu cầu rút tiền hợp lệ.'];
            }
            if (!self::isReviewDay()) {
                Database::rollback();
                return [false, 'Chỉ được duyệt rút tiền vào ngày 7, 14, 21, 28 hàng tháng. Kỳ duyệt gần nhất: ' . date('d/m/Y', self::nextReviewDay()) . '.'];
            }
            Database::run(
                "UPDATE withdrawals SET status = 'completed', admin_note = ?, processed_by = ?, processed_at = ? WHERE id = ?",
                [$note, $adminId, now(), $withdrawId]
            );
            Database::run('UPDATE users SET withdrawn_total_vnd = withdrawn_total_vnd + ? WHERE id = ?', [(int)$wd['amount_vnd'], (int)$wd['user_id']]);
            Audit::log('admin', $adminId, 'withdraw_completed', 'withdrawals/' . $withdrawId, ['amount' => (int)$wd['amount_vnd']]);
            Database::commit();
            return [true, null];
        } catch (\Throwable $e) {
            Database::rollback();
            error_log('[withdraw] complete failed: ' . $e->getMessage());
            return [false, 'Không thể duyệt yêu cầu. Vui lòng thử lại.'];
        }
    }

    /** Admin: reject → refund the held amount. */
    public static function reject(int $withdrawId, int $adminId, string $note): array
    {
        Database::begin();
        try {
            $lock = Database::isSqlite() ? '' : ' FOR UPDATE';
            $wd = Database::one("SELECT * FROM withdrawals WHERE id = ? AND status IN ('pending','processing')" . $lock, [$withdrawId]);
            if ($wd === null) {
                Database::rollback();
                return [false, 'Không tìm thấy yêu cầu rút tiền hợp lệ.'];
            }
            if (trim($note) === '') {
                Database::rollback();
                return [false, 'Vui lòng nhập lý do từ chối.'];
            }
            Database::run(
                "UPDATE withdrawals SET status = 'rejected', admin_note = ?, processed_by = ?, processed_at = ? WHERE id = ?",
                [$note, $adminId, now(), $withdrawId]
            );
            Wallet::post((int)$wd['user_id'], Wallet::TYPE_WITHDRAW_REF, (int)$wd['amount_vnd'], [
                'source' => 'withdrawal',
                'note' => 'Hoàn tiền: từ chối rút #' . $withdrawId . ' — ' . $note,
            ]);
            Audit::log('admin', $adminId, 'withdraw_rejected', 'withdrawals/' . $withdrawId, ['amount' => (int)$wd['amount_vnd'], 'note' => $note]);
            Database::commit();
            return [true, null];
        } catch (\Throwable $e) {
            Database::rollback();
            error_log('[withdraw] reject failed: ' . $e->getMessage());
            return [false, 'Không thể từ chối yêu cầu. Vui lòng thử lại.'];
        }
    }
}
