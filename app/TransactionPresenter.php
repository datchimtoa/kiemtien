<?php
declare(strict_types=1);

namespace App;

/**
 * Presentation helpers for transaction rows.
 */
final class TransactionPresenter
{
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            Wallet::TYPE_TASK_REWARD   => 'Thưởng nhiệm vụ',
            Wallet::TYPE_TASK_PENDING  => 'Nhiệm vụ (chờ duyệt)',
            Wallet::TYPE_TASK_CHARGEBK => 'Hoàn thuồng (chargeback)',
            Wallet::TYPE_WITHDRAW_HOLD => 'Rút tiền (tạm giữ)',
            Wallet::TYPE_WITHDRAW_REF  => 'Hoàn tiền rút bị từ chối',
            Wallet::TYPE_ADMIN_ADJUST  => 'Điều chỉnh bởi admin',
            default => $type,
        };
    }

    public static function amountHtml(array $tx): string
    {
        $amt = (int)$tx['amount_vnd'];
        if ($amt > 0) {
            return '<span class="pos">+' . e(vnd($amt)) . '</span>';
        }
        if ($amt < 0) {
            return '<span class="neg">-' . e(vnd(-$amt)) . '</span>';
        }
        return '<span class="muted">0 ₫</span>';
    }
}
