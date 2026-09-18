<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Csrf;
use App\Database;
use App\Risk;
use App\Session;
use App\View;
use App\Withdrawals;

final class WithdrawController
{
    /** GET /withdraw */
    public static function index(): void
    {
        $user = require_user();
        $history = Database::all(
            'SELECT * FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 20',
            [(int)$user['id']]
        );
        $sumToday = (int)(Database::value(
            "SELECT COALESCE(SUM(amount_vnd),0) FROM withdrawals WHERE user_id = ? AND status IN ('pending','processing','completed') AND created_at >= ?",
            [(int)$user['id'], date('Y-m-d 00:00:00')]
        ) ?? 0);
        $banks = array_values(array_filter(explode("\n", (string)\App\Settings::get('withdraw_bank_list', '')), static fn($s) => trim($s) !== ''));
        View::show('withdraw/index', [
            'user'       => $user,
            'history'    => $history,
            'sumToday'   => $sumToday,
            'banks'      => $banks,
            'min'        => (int)\App\Settings::get('min_withdraw_vnd', '50000'),
            'max'        => (int)\App\Settings::get('max_withdraw_vnd', '5000000'),
            'dailyLimit' => (int)\App\Settings::get('daily_withdraw_limit_vnd', '5000000'),
            'dailyCount' => (int)\App\Settings::get('withdraw_daily_count_limit', '3'),
            'feePct'     => (float)\App\Settings::get('withdraw_fee_percent', '0'),
            'riskError'  => Risk::checkWithdrawal((int)$user['id']),
        ], 'member');
    }

    /** POST /withdraw */
    public static function create(): void
    {
        $user = require_user();
        Csrf::check();
        $amount = (int)str_replace([',', '.', ' '], '', (string)input('amount', '0'));
        $method = (string)input('method', 'bank');
        $accountName = mb_substr(trim((string)input('account_name', '')), 0, 120);
        $accountNumber = mb_substr(trim((string)input('account_number', '')), 0, 64);
        $bankName = mb_substr(trim((string)input('bank_name', '')), 0, 120);

        [$ok, $err] = Withdrawals::request((int)$user['id'], $amount, $method, $accountName, $accountNumber, $bankName);
        if (!$ok) {
            Session::flash('error', (string)$err);
            redirect('/withdraw');
        }
        Session::flash('success', 'Đã tạo yêu cầu rút tiền ' . vnd($amount) . '. Vui lòng chờ quản trị viên xử lý.');
        redirect('/withdraw');
    }
}
