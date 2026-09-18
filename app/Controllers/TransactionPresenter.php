<?php
declare(strict_types=1);

namespace App\Controllers;

/**
 * Presentation helpers for transaction rows (views).
 */
final class TransactionPresenter
{
    public static function typeLabel(array $tx): string
    {
        return match ($tx['type']) {
            'task_reward'    => 'Thưởng nhiệm vụ',
            'task_pending'   => 'Nhiệm vụ chờ duyệt',
            'task_chargeback' => 'Hoàn thưởng (chargeback)',
            'withdraw_hold'  => 'Rút tiền',
            'withdraw_refund' => 'Hoàn tiền rút',
            'admin_adjust'   => 'Điều chỉnh',
            default          => (string)$tx['type'],
        };
    }

    public static function icon(array $tx): string
    {
        return match ($tx['type']) {
            'task_reward'    => '＋',
            'withdraw_hold'  => '－',
            'task_chargeback' => '－',
            default          => '•',
        };
    }
}
