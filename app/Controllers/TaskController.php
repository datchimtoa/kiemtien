<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Csrf;
use App\Database;
use App\PubCryptoClient;
use App\RateLimiter;
use App\Risk;
use App\Session;
use App\View;

final class TaskController
{
    /** GET /tasks — list PubCrypto Link tasks for the current member. */
    public static function index(): void
    {
        $user = require_user();
        $res = PubCryptoClient::links((int)$user['id']);
        View::show('tasks/index', [
            'user'   => $user,
            'res'    => $res,
            'clickCooldown' => (int)\App\Settings::get('task_click_cooldown_seconds', '20'),
        ], 'member');
    }

    /**
     * POST /tasks/start — user picks a task; we validate locally (cooldown, velocity,
     * available, per-task cycle) and record the click, then redirect the browser to
     * the one-time PubCrypto link (link_dotask).
     */
    public static function start(): void
    {
        $user = require_user();
        Csrf::checkOrJson();
        $json = ['ok' => false, 'error' => null, 'redirect' => null];

        if (!\App\Settings::getInt('anticheat_enabled', 1)) {
            // anticheat off: still record, minimal checks
        } else {
            // Rate: clicks per hour
            if (!RateLimiter::attempt('task_click_u' . $user['id'], (string)$user['id'], 30, 3600)) {
                $json['error'] = 'Bạn đã làm quá nhiều nhiệm vụ trong giờ. Vui lòng chờ một chút.';
                json_response($json, 429);
            }
            // Per-click cooldown (last recorded click time)
            $last = Database::one(
                'SELECT created_at FROM task_clicks WHERE user_id = ? ORDER BY id DESC LIMIT 1',
                [(int)$user['id']]
            );
            $cooldown = (int)\App\Settings::get('task_click_cooldown_seconds', '20');
            if ($last !== null && (time() - strtotime((string)$last['created_at'])) < $cooldown) {
                $wait = $cooldown - (time() - strtotime((string)$last['created_at']));
                $json['error'] = "Vui lòng chờ {$wait} giây trước khi làm nhiệm vụ tiếp theo.";
                json_response($json, 429);
            }
        }

        $taskId = (int)input('task_id', 0);
        $res = PubCryptoClient::links((int)$user['id']);
        if (!$res['ok']) {
            $json['error'] = (string)$res['error'];
            json_response($json, 502);
        }
        $task = null;
        foreach ($res['tasks'] as $t) {
            if ((int)$t['id'] === $taskId) {
                $task = $t;
                break;
            }
        }
        if ($task === null || !$task['available'] || $task['link_dotask'] === '') {
            $json['error'] = 'Nhiệm vụ không khả dụng hoặc đã hết lượt trong chu kỳ. Vui lòng thử nhiệm vụ khác.';
            json_response($json, 400);
        }

        // Record click and hand out the one-time link.
        Database::run(
            'INSERT INTO task_clicks(user_id, task_id, task_name, ip, created_at) VALUES(?,?,?,?,?)',
            [(int)$user['id'], $task['id'], (string)$task['task_name'], client_ip(), now()]
        );
        $json['ok'] = true;
        $json['redirect'] = (string)$task['link_dotask'];
        json_response($json);
    }
}
