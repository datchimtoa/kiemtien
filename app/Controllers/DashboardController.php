<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Risk;
use App\Session;
use App\Settings;
use App\TransactionPresenter;
use App\View;
use App\Wallet;
use App\Withdrawals;

final class DashboardController
{
    public static function index(): void
    {
        $user = require_user();
        $tx = Database::all(
            'SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 12',
            [(int)$user['id']]
        );
        $sumTasks = (int)(Database::value(
            "SELECT COALESCE(SUM(amount_vnd),0) FROM transactions WHERE user_id = ? AND type = 'task_reward'",
            [(int)$user['id']]
        ) ?? 0);
        $countTasks = (int)(Database::value(
            "SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = 'task_reward'",
            [(int)$user['id']]
        ) ?? 0);
        View::show('dashboard/index', [
            'user'        => $user,
            'tx'          => $tx,
            'sumTasks'    => $sumTasks,
            'countTasks'  => $countTasks,
            'pendingWd'   => Database::one(
                "SELECT id, amount_vnd, created_at FROM withdrawals WHERE user_id = ? AND status IN ('pending','processing') ORDER BY id DESC LIMIT 1",
                [(int)$user['id']]
            ),
        ], 'member');
    }

    public static function profile(): void
    {
        $user = require_user();
        $devices = Database::all('SELECT * FROM devices WHERE user_id = ? ORDER BY last_seen DESC LIMIT 10', [(int)$user['id']]);
        $ips = Database::all('SELECT * FROM user_ips WHERE user_id = ? ORDER BY last_seen DESC LIMIT 10', [(int)$user['id']]);
        View::show('dashboard/profile', ['user' => $user, 'devices' => $devices, 'ips' => $ips], 'member');
    }
}
