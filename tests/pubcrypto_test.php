#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Http;
use App\PubCryptoClient;
use App\Settings;

config('db');
$GLOBALS['__config']['db'] = ['driver' => 'sqlite', 'sqlite' => ':memory:'];
$GLOBALS['__config']['pubcrypto'] = ['api_base' => 'https://example.test', 'site_key' => 'mock-site', 'api_key' => 'mock-key'];
Database::migrate();
// Exercise the real exiting controller in isolated child processes (no HTTP/production).
if (($argv[1] ?? '') === '--start') {
    function require_user(): array { return ['id' => 1]; }
    $scenario = $argv[2] ?? 'ready';
    Settings::set('anticheat_enabled', '0');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.12';
    $_SESSION['_csrf'] = str_repeat('a', 64);
    $_REQUEST = ['task_id' => '12', '_csrf' => $_SESSION['_csrf']];
    $cachedTask = ['id' => 12, 'available' => true, 'link_dotask' => 'https://example.test/already-used'];
    Database::run('INSERT INTO task_cache(site_key,payload,fetched_at) VALUES(?,?,?)',
        ['mock-site|1', json_encode(['tasks' => [$cachedTask]]), now()]);
    $requests = 0;
    Http::fake(static function () use ($scenario, &$requests): array {
        $requests++;
        if ($scenario === 'failure') {
            return ['ok' => false, 'http' => 503, 'body' => 'private-upstream'];
        }
        return ['body' => json_encode(['tasks' => [[
            'id' => 12, 'task_name' => 'Mock task', 'available' => $scenario === 'ready',
            'link_dotask' => 'https://example.test/fresh-token',
            'cooldown_remaining' => $scenario === 'cooldown' ? 15 : 0,
            'pending_in_cycle' => $scenario === 'pending' ? 1 : 0,
        ]]])];
    });
    ob_start();
    register_shutdown_function(static function () use (&$requests): void {
        $result = json_decode((string)ob_get_clean(), true);
        echo json_encode(['response' => $result, 'http' => http_response_code(), 'requests' => $requests,
            'clicks' => (int)Database::value('SELECT COUNT(*) FROM task_clicks'),
            'cache_rows' => (int)Database::value('SELECT COUNT(*) FROM task_cache'),
            'ledger_rows' => (int)Database::value('SELECT COUNT(*) FROM transactions')]);
    });
    App\Controllers\TaskController::start();
}
$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $fail += $ok ? 0 : 1;
};
$calls = 0;
$cooldown = 0;
$available = true;
$expiry = 600;
Http::fake(static function ($method, $url, $opt) use (&$calls, &$cooldown, &$available, &$expiry, $check): array {
    $calls++;
    $check('list request uses authenticated bodyless GET ' . $calls, $method === 'GET'
        && !isset($opt['body']) && in_array('Authorization: Bearer mock-key', $opt['headers'], true));
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    return ['body' => json_encode(['link_expires_in' => $expiry, 'tasks' => [[
        'id' => 12, 'task_name' => 'Mock task', 'link_dotask' => 'https://example.test/one-time/' . $query['sub_id'] . '/' . $calls,
        'available' => $available, 'total_max' => 10, 'remaining_in_cycle' => $available ? 10 : 0,
        'cooldown_seconds' => 300, 'cooldown_remaining' => $cooldown, 'pending_in_cycle' => $available ? 0 : 1,
    ]]])];
});
$first = PubCryptoClient::links(1);
$second = PubCryptoClient::links(1);
$check('list caches per-member snapshot', $calls === 1 && $second['meta']['from_cache']);
$fresh = PubCryptoClient::links(1, true);
$check('start mode ignores cached single-use token', $calls === 2
    && $fresh['tasks'][0]['link_dotask'] !== $first['tasks'][0]['link_dotask']);
PubCryptoClient::links(2);
$check('members do not share tokens or quota snapshots', $calls === 3);
PubCryptoClient::forgetLinks(1);
$check('consumed token cache invalidated for just its member',
    Database::one('SELECT site_key FROM task_cache WHERE site_key = ?', ['mock-site|1']) === null
    && Database::one('SELECT site_key FROM task_cache WHERE site_key = ?', ['mock-site|2']) !== null);

$cooldown = 10;
$available = false;
PubCryptoClient::links(1, true);
Database::run('UPDATE task_cache SET fetched_at = ? WHERE site_key = ?', [date('Y-m-d H:i:s', time() - 4), 'mock-site|1']);
$callsBefore = $calls;
$cached = PubCryptoClient::links(1);
$check('cached countdown decreases without unlocking task', $calls === $callsBefore
    && $cached['tasks'][0]['cooldown_remaining'] <= 6 && $cached['tasks'][0]['cooldown_remaining'] >= 5
    && !$cached['tasks'][0]['available']);
$check('pending state preserved for UI', $cached['tasks'][0]['pending_in_cycle'] === 1);
Database::run('UPDATE task_cache SET fetched_at = ? WHERE site_key = ?', [date('Y-m-d H:i:s', time() - 10), 'mock-site|1']);
$cooldown = 0;
$available = true;
$reset = PubCryptoClient::links(1);
$check('cooldown boundary fetches authoritative reopened cycle before normal TTL', $calls === $callsBefore + 1
    && $reset['tasks'][0]['available'] && !$reset['meta']['from_cache']);

$available = false;
$blocked = PubCryptoClient::links(1, true);
$check('quota unavailable without cooldown is not locally reset', !$blocked['tasks'][0]['available']
    && $blocked['tasks'][0]['remaining_in_cycle'] === 0);
$expiry = 3;
PubCryptoClient::links(1, true);
$callsBefore = $calls;
Database::run('UPDATE task_cache SET fetched_at = ? WHERE site_key = ?', [date('Y-m-d H:i:s', time() - 3), 'mock-site|1']);
PubCryptoClient::links(1);
$check('expired link bypasses normal snapshot TTL', $calls === $callsBefore + 1);

Http::fake(static fn() => ['ok' => false, 'http' => 503, 'body' => 'mock-key private upstream response']);
$failure = PubCryptoClient::links(1, true);
$check('fresh API error never falls back to cached token or exposes body', !$failure['ok'] && $failure['tasks'] === []
    && !str_contains($failure['error'], 'mock-key') && !str_contains($failure['error'], 'private upstream'));
Http::fake(null);
$embedBody = [];
Http::fake(static function ($method, $url, $opt) use (&$embedBody): array {
    $embedBody = ['method' => $method, 'json' => $opt['json'] ?? false, 'body' => $opt['body'] ?? []];
    return ['body' => '{"iframe_url":"https://example.test/embed"}'];
});
$embed = PubCryptoClient::embedToken(1, 'https://example.test');
$check('shared HTTP client preserves signed embed JSON POST', $embed['ok'] && $embedBody['method'] === 'POST'
    && $embedBody['json'] && $embedBody['body']['sub_id'] === '1'
    && $embedBody['body']['origin'] === 'https://example.test');
Http::fake(null);
foreach (['ready', 'cooldown', 'pending', 'exhausted', 'failure'] as $scenario) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--start', $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $check('controller process ' . $scenario, false);
        continue;
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $result = json_decode($output, true);
    $check('real start controller revalidates quota ' . $scenario, $code === 0 && is_array($result)
        && $result['requests'] === 1 && $result['ledger_rows'] === 0
        && $result['clicks'] === ($scenario === 'ready' ? 1 : 0)
        && ($result['response']['ok'] ?? false) === ($scenario === 'ready'));
    if ($scenario === 'ready') {
        $check('controller issues fresh token and removes used cache', ($result['response']['redirect'] ?? '') === 'https://example.test/fresh-token'
            && $result['cache_rows'] === 0);
    } elseif ($scenario === 'cooldown') {
        $check('controller reports remaining wait instead of permanent exhaustion', ($result['http'] ?? 0) === 429
            && str_contains($result['response']['error'] ?? '', '15 giây'));
    } elseif ($scenario === 'pending') {
        $check('controller explains pending review', str_contains($result['response']['error'] ?? '', 'chờ nhà cung cấp xác nhận'));
    }
}
exit($fail ? 1 : 0);