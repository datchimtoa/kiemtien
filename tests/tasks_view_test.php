#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Providers\Catalog;
use App\Settings;
use App\View;

config('db');
$GLOBALS['__config']['db'] = ['driver' => 'sqlite', 'sqlite' => ':memory:'];
Database::migrate();
$providers = Catalog::definitions();
foreach ($providers as $id => $definition) {
    Settings::set('provider_' . $id . '_enabled', '1');
}
$data = [
    'providers' => $providers, 'providerCounts' => [], 'providerLastStart' => null,
    'clickCooldown' => 20, 'providerAttempts' => [],
    'res' => ['ok' => false, 'error' => 'Nguồn cũ tạm thời không khả dụng.'],
];
$html = View::render('tasks/index', $data, null);
$fail = 0;
$check = function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $fail += $ok ? 0 : 1;
};
foreach ($providers as $id => $definition) {
    $check('provider card ' . $id, str_contains($html, e($definition['label'])) && str_contains($html, 'name="provider" value="' . $id . '"'));
}
$check('cards reuse existing task layout', str_contains($html, 'card task-card state-ok'));
$check('no paid no-ad service', !str_contains($html, 'name="service" value="no_ads"'));
$check('new providers visible when old provider fails', str_contains($html, '/tasks/provider/start'));
$check('YeuJob V2 card explains manual review', str_contains($html, 'YeuJob V2: admin duyệt thưởng thủ công'));
$data['providerAttempts'] = [[
    'id' => 1, 'provider' => 'yeujob', 'service' => 'friend', 'remote_id' => 'v2:test',
    'status' => 'pending', 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
    'created_at' => now(), 'reward_vnd' => 400, 'token' => str_repeat('a', 64),
    'short_url' => 'https://yeujob.com/q/test',
]];
$html = View::render('tasks/index', $data, null);
$check('V2 pending shows manual review without poll button', str_contains($html, 'Chờ admin duyệt') && !str_contains($html, 'Kiểm tra xác nhận'));
$data['providerAttempts'] = [];
$data['providerLastStart'] = now();
$html = View::render('tasks/index', $data, null);
$check('cooldown disables start buttons', str_contains($html, 'disabled') && !str_contains($html, 'state-ok'));
foreach ($providers as $id => $definition) {
    Settings::set('provider_' . $id . '_enabled', '0');
}
$html = View::render('tasks/index', $data, null);
$check('disabled providers not offered', !str_contains($html, '/tasks/provider/start'));
$check('empty state explained', str_contains($html, 'Chưa có nguồn đối tác được bật'));

// Local visual preview with mock data only; no provider requests or persistent DB.
if (getenv('TASKS_PREVIEW_FILE')) {
    foreach ($providers as $id => $definition) {
        Settings::set('provider_' . $id . '_enabled', '1');
    }
    $data['providerLastStart'] = null;
    $html = View::render('tasks/index', $data, null);
    file_put_contents(getenv('TASKS_PREVIEW_FILE'), '<!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Local task preview</title><link rel="stylesheet" href="/assets/app.css"><body><main class="container">' . $html . '</main></body></html>');
}
exit($fail ? 1 : 0);