#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Http;
use App\Providers\Link4mAntiCheat;
use App\Providers\Tasks;
use App\Settings;

config('db');
$GLOBALS['__config']['db'] = ['driver' => 'sqlite', 'sqlite' => ':memory:'];
$GLOBALS['__config']['base_url'] = 'https://example.test';
$GLOBALS['__config']['link4m'] = [
    'hmac_secret' => str_repeat('h', 40),
    'turnstile_site_key' => 'site-key',
    'turnstile_secret' => 'turnstile-secret',
    'min_seconds' => 20,
    'soft_seconds' => 30,
    'token_ttl' => 3600,
];
Database::migrate();
Database::run('INSERT INTO users(phone,password_hash,created_at,updated_at) VALUES(?,?,?,?)', ['84911111111', 'test', now(), now()]);
$userId = Database::lastId();
Database::run('INSERT INTO provider_attempts(token,user_id,provider,service,status,reward_vnd,share_percent,min_seconds,ip,device_hash,destination,created_at,expires_at,remote_id,short_url) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
    str_repeat('a', 64), $userId, 'link4m', 'default', 'pending', 400, 100, 20, '203.0.113.10', hash('sha256', 'device'), 'https://example.test/task/link4m/callback', date('Y-m-d H:i:s', time() - 40), date('Y-m-d H:i:s', time() + 3600), 'link4m-test', 'https://link4m.co/test',
]);
$token = str_repeat('a', 64);
Link4mAntiCheat::initialize($token, $userId, '203.0.113.10', 'test-agent', hash('sha256', 'device'));
Database::run('UPDATE link4m_sessions SET created_at = ? WHERE token = ?', [date('Y-m-d H:i:s', time() - 40), $token]);
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_USER_AGENT'] = 'test-agent';
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'VN';
$_SERVER['HTTP_REFERER'] = 'https://link4m.co/test';
$_COOKIE['ts'] = $token;
$signature = hash_hmac('sha256', $token, str_repeat('h', 40));
$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $fail += $ok ? 0 : 1;
};
$check('Link4m callback URL is HMAC-bound', Link4mAntiCheat::validSignature($token, $signature)
    && str_contains(Link4mAntiCheat::callbackUrl($token), 'sig=' . $signature));
$check('Link4m callback records first-party arrival', Link4mAntiCheat::markArrival($token) !== null
    && Database::value('SELECT arrived_at FROM link4m_sessions WHERE token = ?', [$token]) !== null);
Http::fake(fn() => ['body' => json_encode(['success' => true])]);
$completed = Link4mAntiCheat::complete($token, $signature, 'turnstile-token', 1);
$check('Link4m verification never credits automatically', $completed['status'] === 'verified'
    && Database::value('SELECT status FROM provider_attempts WHERE token = ?', [$token]) === 'pending');
$check('Link4m verified result is stored as countable', Database::value('SELECT link4m_countable FROM link4m_sessions WHERE token = ?', [$token]) === 1);
$check('Link4m safe link accepts documented domains', App\Providers\Client::safeLink('link4m', 'https://link4m.com/test') !== '');
exit($fail ? 1 : 0);
