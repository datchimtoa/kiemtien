<?php
declare(strict_types=1);

namespace App;

/**
 * PubCrypto postback processing (S2S callback from pub.cryptolinkforearn.com).
 *
 * Spec compliance:
 *  - POST application/x-www-form-urlencoded
 *  - signature = md5(subId + transId + reward_pb + secretKey)  (secretKey = Forward Secret)
 *  - status: 0 = pending (record only), 1 = credit (+reward_pb), 2 = chargeback (subtract)
 *  - MUST respond exactly "ok" (2xx) within 60s or platform retries (8x backoff)
 *  - dedupe transId — duplicates still respond "ok"
 */
final class PostbackProcessor
{
    /** @param array $p parsed postback parameters */
    public static function handle(array $p): string
    {
        $subId     = (string)($p['subId'] ?? '');
        $transId   = (string)($p['transId'] ?? '');
        $rewardPb  = (string)($p['reward_pb'] ?? '');
        $payoutPb  = (string)($p['payout_pb'] ?? '');
        $status    = (int)($p['status'] ?? -1);
        $signature = (string)($p['signature'] ?? '');
        $ip        = client_ip();

        $secret = (string)Settings::get('pubcrypto_forward_secret', '');
        $sigOk = false;
        if ($secret !== '' && $subId !== '' && $transId !== '') {
            $sigOk = hash_equals(strtolower($signature), strtolower(md5($subId . $transId . $rewardPb . $secret)));
        }

        $result = 'ignored';
        $userId = null;

        if (!$sigOk) {
            $result = 'signature_invalid';
        } elseif (!ctype_digit($subId) || (int)$subId <= 0) {
            $result = 'unknown_sub';
        } else {
            $userId = (int)$subId;
            $result = self::process($userId, $transId, $rewardPb, $payoutPb, $status, $p);
        }

        Database::run(
            'INSERT INTO postback_logs(trans_id, sub_id, status_raw, reward_pb, payout_pb, ip, signature_valid, result, raw, created_at)
             VALUES(?,?,?,?,?,?,?,?,?,?)',
            [$transId, $subId, $status, (float)$rewardPb, (float)$payoutPb, $ip, $sigOk ? 1 : 0, $result,
             json_encode($p, JSON_UNESCAPED_UNICODE), now()]
        );
        return $result;
    }

    private static function process(int $userId, string $transId, string $rewardPb, string $payoutPb, int $status, array $p): string
    {
        $user = Database::one('SELECT id, status FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return 'unknown_sub';
        }
        if ($user['status'] !== 'active') {
            return 'user_inactive';
        }
        $common = [
            'trans_id' => $transId,
            'source'   => 'pubcrypto',
            'status_raw' => $status,
            'offer_name' => (string)($p['offer_name'] ?? ''),
            'offer_type' => (string)($p['offer_type'] ?? ''),
            'payout_usd' => (float)$payoutPb,
            'reward_pb'  => (float)$rewardPb,
            'country'    => (string)($p['country'] ?? ''),
            'raw'        => $p,
        ];
        switch ($status) {
            case 0: // Pending: record only, do not credit.
                $common['note'] = 'pending';
                Wallet::post($userId, Wallet::TYPE_TASK_PENDING, 0, $common);
                return 'pending_recorded';

            case 1: // Credit. Ledger dedupes transId inside the same DB transaction.
                // Bypass cheat: reward without any task click in 7 days → ban, no credit.
                if (!Risk::checkBypassCheat($userId, $transId)) {
                    return 'banned_bypass_cheat';
                }
                // reward_pb = số VND CUỐI cho member (đã bao gồm member share phía PubCrypto).
                $amountVnd = Wallet::memberRewardVnd((float)$rewardPb);
                $common['points'] = (float)$rewardPb;
                $balance = Wallet::post($userId, Wallet::TYPE_TASK_REWARD, $amountVnd, $common);
                Risk::checkPostback($userId, $amountVnd, $transId, (string)($p['country'] ?? ''));
                Risk::autoBanCheck($userId);
                return 'credited';

            case 2: // Chargeback: subtract what the original transaction credited.
                $orig = Database::one(
                    "SELECT amount_vnd FROM transactions WHERE trans_id = ? AND type = 'task_reward'",
                    [$transId]
                );
                $sub = $orig !== null ? (int)$orig['amount_vnd'] : Wallet::memberRewardVnd((float)$rewardPb);
                if ($sub > 0) {
                    $common['trans_id'] = $transId . ':cb';
                    $common['note'] = 'chargeback of ' . $transId;
                    Wallet::post($userId, Wallet::TYPE_TASK_CHARGEBK, -$sub, $common);
                }
                Risk::event($userId, 'postback_chargeback', 'medium', 'transId=' . $transId);
                return 'chargeback_processed';

            default:
                return 'bad_status';
        }
    }

    /** Canonical postback URL for this install (shown in Admin). */
    public static function url(): string
    {
        $token = (string)(config('postback_token') ?? '');
        $base = rtrim((string)config('base_url', ''), '/');
        return $base . '/postback/pubcrypto' . ($token !== '' ? '/' . $token : '');
    }
}
