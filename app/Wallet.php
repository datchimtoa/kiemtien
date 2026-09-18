<?php
declare(strict_types=1);

namespace App;

/**
 * Wallet: transactional ledger. The users.balance_vnd column is the fast cache;
 * every mutation writes a transactions row in the same DB transaction.
 */
final class Wallet
{
    public const TYPE_TASK_REWARD   = 'task_reward';    // status=1 postback credit
    public const TYPE_TASK_PENDING  = 'task_pending';   // status=0 postback (record only, no credit)
    public const TYPE_TASK_CHARGEBK = 'task_chargeback';// status=2 postback (subtract)
    public const TYPE_WITHDRAW_HOLD = 'withdraw_hold';
    public const TYPE_WITHDRAW_REF  = 'withdraw_refund';
    public const TYPE_ADMIN_ADJUST  = 'admin_adjust';

    /** Convert postback USD value to member VND credit. */
    public static function usdToVnd(float $usd): int
    {
        $rate = Settings::getFloat('usd_to_vnd_rate', 26000.0);
        $share = max(0, min(100, Settings::getInt('site_member_share_percent', 100)));
        return (int)floor($usd * $rate * $share / 100);
    }

    /**
     * Member reward = reward_pb từ postback PubCrypto (giá cuối cho member).
     * reward_pb ĐÃ bao gồm member share của phía PubCrypto → không nhân share lần 2.
     */
    public static function memberRewardVnd(float $rewardPb): int
    {
        return (int)floor($rewardPb);
    }

    /** Add/subtract balance + write ledger row. Returns new balance. */
    public static function post(
        int $userId,
        string $type,
        int $amountVnd,
        array $meta = []
    ): int {
        $db = Database::pdo();
        $db->beginTransaction();
        try {
            $lock = Database::isSqlite() ? '' : ' FOR UPDATE';
            $row = Database::one('SELECT balance_vnd, points_total FROM users WHERE id = ?' . $lock, [$userId]);
            if ($row === null) {
                $db->rollBack();
                throw new \RuntimeException('user not found: ' . $userId);
            }
            $newBalance = (int)$row['balance_vnd'] + $amountVnd;
            if ($newBalance < 0) {
                // Clamp at zero for chargebacks that exceed current balance.
                $amountVnd = -1 * (int)$row['balance_vnd'];
                $newBalance = 0;
            }
            $pointsDelta = (float)($meta['points'] ?? 0);
            $newPoints = (float)$row['points_total'] + max(0, $pointsDelta);

            $transId = (string)($meta['trans_id'] ?? '');
            // trans_id: NULL khi rỗng → UNIQUE(trans_id) chỉ chặn trùng transId thật
            // (tương thích cả SQLite partial index lẫn MySQL UNIQUE cho phép nhiều NULL).
            $transIdDb = $transId !== '' ? $transId : null;
            if ($transId !== '') {
                $dupe = Database::one('SELECT id FROM transactions WHERE trans_id = ?', [$transId]);
                if ($dupe !== null) {
                    $db->rollBack();
                    // Duplicate — treat as no-op, return current balance.
                    return (int)$row['balance_vnd'];
                }
            }

            Database::run(
                'UPDATE users SET balance_vnd = ?, points_total = points_total + ?, updated_at = ? WHERE id = ?',
                [$newBalance, max(0, $pointsDelta), now(), $userId]
            );
            Database::run(
                'INSERT INTO transactions(user_id, type, amount_vnd, points, balance_after, trans_id, source, status_raw, offer_name, offer_type, payout_usd, reward_pb, country, ip, note, raw_json, created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $userId, $type, $amountVnd, $pointsDelta, $newBalance, $transIdDb,
                    (string)($meta['source'] ?? ''),
                    isset($meta['status_raw']) ? (int)$meta['status_raw'] : null,
                    (string)($meta['offer_name'] ?? ''), (string)($meta['offer_type'] ?? ''),
                    isset($meta['payout_usd']) ? (float)$meta['payout_usd'] : null,
                    isset($meta['reward_pb']) ? (float)$meta['reward_pb'] : null,
                    (string)($meta['country'] ?? ''), client_ip(), (string)($meta['note'] ?? ''),
                    isset($meta['raw']) ? json_encode($meta['raw'], JSON_UNESCAPED_UNICODE) : null,
                    now(),
                ]
            );
            $db->commit();
            return $newBalance;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function balance(int $userId): int
    {
        return (int)(Database::value('SELECT balance_vnd FROM users WHERE id = ?', [$userId]) ?? 0);
    }
}
