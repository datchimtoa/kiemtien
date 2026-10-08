#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Audit;
use App\Http;
use App\Settings;
use App\Wallet;
use App\Providers\Callback;
use App\Providers\Catalog;
use App\Providers\Client;
use App\Providers\Configuration;
use App\Providers\Tasks;

config('db');
$GLOBALS['__config']['db'] = ['driver' => 'sqlite', 'sqlite' => ':memory:'];
$GLOBALS['__config']['base_url'] = 'https://example.test';
$GLOBALS['__config']['trusted_proxy_ips'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
$_SERVER['HTTP_USER_AGENT'] = 'provider-test';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
Database::migrate();
$failures = 0;
function check(string $name, bool $ok): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failures += $ok ? 0 : 1;
}
function rejects(string $name, callable $fn): void {
    try { $fn(); check($name, false); } catch (Throwable $e) { check($name, true); }
}
function age(string $token): void {
    Database::run('UPDATE provider_attempts SET created_at = ? WHERE token = ?', [date('Y-m-d H:i:s', time() - 120), $token]);
}
Database::run('INSERT INTO users(phone,password_hash,created_at,updated_at) VALUES(?,?,?,?)', ['84911111111', 'test', now(), now()]);
$uid = Database::lastId();
Database::run('INSERT INTO users(phone,password_hash,created_at,updated_at) VALUES(?,?,?,?)', ['84922222222', 'test', now(), now()]);
$other = Database::lastId();
check('ten provider definitions', count(Catalog::definitions()) === 10);
check('spoofed forwarded IP ignored', client_ip() === '203.0.113.20');
check('missing success fails closed', !Http::isOk([], true, ['ok_path' => 'success']));
check('false never equals numeric zero', !Http::isOk(['success' => false], true, ['ok_path' => 'success', 'ok_values' => [0]]));
check('HTTP error cannot claim success', !Http::isOk(['success' => true], false, ['ok_path' => 'success']));
rejects('negative reward rejected', fn() => Configuration::validate(['provider_yeujob_reward_vnd' => '-1']));
rejects('share above 100 rejected', fn() => Configuration::validate(['provider_yeujob_share_percent' => '101']));
rejects('short callback secret rejected', fn() => Configuration::validate(['provider_yeujob_callback_secret' => 'tiny']));
rejects('foreign redirect rejected', fn() => Client::safeLink('traffic24h', 'https://evil.test/abc'));
foreach (Catalog::ids() as $id) {
    Settings::set('provider_' . $id . '_enabled', '1');
    Settings::set('provider_' . $id . '_api_key', 'test-key');
}
Settings::set('provider_traffic24h_service_default_reward_vnd', '550');
$viewCount = 0;
$destination = '';
$slug = '';
Http::fake(function ($method, $url, $opt) use (&$destination, &$slug, &$viewCount) {
    if (str_contains($url, '/quicklink/api')) {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $destination = $q['url']; $slug = $q['alias'];
        return ['body' => json_encode(['status' => 'success', 'shortenedUrl' => 'https://traffic24h.top/' . $slug, 'slug' => $slug, 'destination' => $destination, 'reused' => false])];
    }
    return ['body' => json_encode(['slug' => $slug, 'target_url' => $destination, 'views' => 50, 'held_views' => 50, 'views_valid' => $viewCount])];
});
$attempt = Tasks::start($uid, 'traffic24h', 'default');
$token = $attempt['token'];
check('start does not pay', Wallet::balance($uid) === 0);
rejects('too-fast return rejected', fn() => Tasks::returned($uid, $token));
rejects('other user cannot return', fn() => Tasks::returned($other, $token));
rejects('same-IP quota enforced across accounts', fn() => Tasks::start($other, 'traffic24h', 'default'));
age($token);
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
rejects('changed IP cannot record return', fn() => Tasks::returned($uid, $token));
$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
$_SERVER['HTTP_USER_AGENT'] = 'different-device';
rejects('changed device cannot record return', fn() => Tasks::returned($uid, $token));
$_SERVER['HTTP_USER_AGENT'] = 'provider-test';
Tasks::returned($uid, $token);
check('browser return never pays', Wallet::balance($uid) === 0);
check('held/raw views never pay', !Tasks::poll($uid, $token) && Wallet::balance($uid) === 0);
Settings::set('provider_traffic24h_service_default_reward_vnd', '900');
$viewCount = 1;
check('validated view pays frozen reward', Tasks::poll($uid, $token) && Wallet::balance($uid) === 550);
check('duplicate verification does not pay twice', !Tasks::poll($uid, $token) && Wallet::balance($uid) === 550);

$remote = 'job-456'; $paid = false;
Http::fake(function ($method, $url, $opt) use (&$paid, &$remote) {
    if (str_contains($url, 'friend_jobs')) return ['body' => json_encode(['success' => true, 'data' => [['id' => 123, 'slots_left' => 1]]])];
    if ($method === 'POST') return ['body' => json_encode(['success' => true, 'data' => ['application_id' => $remote, 'friend_url' => 'https://yeujob.com/friend-review.php?t=test', 'reward' => 5000]])];
    return ['body' => json_encode(['success' => true, 'data' => ['application_id' => $remote, 'status' => 'approved', 'reward_status' => $paid ? 'paid' : 'pending', 'reward' => 5000]])];
});
$job = Tasks::start($uid, 'yeujob', 'friend'); age($job['token']);
check('approved but unpaid job waits', !Tasks::poll($uid, $job['token']));
$paid = true;
check('approved paid job shares VND once', Tasks::poll($uid, $job['token']) && Wallet::balance($uid) === 4050);

// Signed callbacks must bind provider, token and remote ID; amounts are ignored.
$_SERVER['REMOTE_ADDR'] = '203.0.113.21';
Http::fake(fn() => ['body' => json_encode(['status' => 'success', 'shortenedUrl' => 'https://link999.app/unique-test'])]);
$link = Tasks::start($other, 'link999', 'default'); age($link['token']);
Tasks::returned($other, $link['token']);
check('return-only provider never auto-pays', !Tasks::poll($other, $link['token']) && Wallet::balance($other) === 0);
$secret = str_repeat('s', 40);
Settings::set('provider_link999_callback_secret', $secret);
$body = json_encode(['token' => $link['token'], 'remote_id' => 'unique-test', 'status' => 'paid', 'amount' => 99999999]);
$timestamp = (string)time();
$signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
rejects('unsigned callback rejected', fn() => Callback::process('link999', $timestamp, '', $body));
rejects('stale callback rejected', fn() => Callback::process('link999', (string)(time() - 600), $signature, $body));
rejects('cross-provider callback rejected', fn() => Callback::process('site2s', $timestamp, $signature, $body));
$wrongRemote = json_encode(['token' => $link['token'], 'remote_id' => 'another-link', 'status' => 'paid']);
rejects('signed wrong remote ID rejected', fn() => Callback::process('link999', $timestamp, hash_hmac('sha256', $timestamp . '.' . $wrongRemote, $secret), $wrongRemote));
$tampered = str_replace('99999999', '123', $body);
rejects('modified signed body rejected', fn() => Callback::process('link999', $timestamp, $signature, $tampered));
Database::run('UPDATE users SET risk_flag = 1 WHERE id = ?', [$other]);
check('flagged account cannot receive callback reward', !Callback::process('link999', $timestamp, $signature, $body) && Wallet::balance($other) === 0);
Database::run('UPDATE users SET risk_flag = 0 WHERE id = ?', [$other]);
Database::run('UPDATE provider_attempts SET expires_at = ? WHERE token = ?', [date('Y-m-d H:i:s', time() - 1), $link['token']]);
check('expired attempt cannot receive callback reward', !Callback::process('link999', $timestamp, $signature, $body) && Wallet::balance($other) === 0);
Database::run('UPDATE provider_attempts SET expires_at = ? WHERE token = ?', [date('Y-m-d H:i:s', time() + 3600), $link['token']]);
check('signed callback uses server reward only', Callback::process('link999', $timestamp, $signature, $body) && Wallet::balance($other) === 400);
check('callback retry acknowledged once', Callback::process('link999', $timestamp, $signature, $body) && Wallet::balance($other) === 400);

// Failure after wallet mutation must roll back state + ledger + balance.
Database::run("UPDATE provider_attempts SET status = 'pending' WHERE token = ?", [$link['token']]);
Database::run('DELETE FROM transactions WHERE user_id = ?', [$other]);
Database::run('UPDATE users SET balance_vnd = 0 WHERE id = ?', [$other]);
Database::run("CREATE TRIGGER fail_provider BEFORE UPDATE OF credited_at ON provider_attempts BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
rejects('credit failure propagates', fn() => Tasks::credit($link['token'], 'test'));
check('credit failure rolls back wallet and state', Wallet::balance($other) === 0 && Database::value('SELECT status FROM provider_attempts WHERE token = ?', [$link['token']]) === 'pending' && (int)Database::value('SELECT COUNT(*) FROM transactions WHERE user_id = ?', [$other]) === 0);
Database::run('DROP TRIGGER fail_provider');
Database::begin();
Tasks::credit($link['token'], 'admin_review');
Database::rollback();
check('credit joins admin transaction', Wallet::balance($other) === 0 && Database::value('SELECT status FROM provider_attempts WHERE token = ?', [$link['token']]) === 'pending');
Database::run("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
Database::begin();
try {
    Tasks::credit($link['token'], 'admin_review');
    Audit::log('admin', 1, 'provider_approve', $link['token'], ['note' => 'Provider-side evidence checked']);
    Database::commit();
    check('audit failure rejects approval', false);
} catch (Throwable $e) {
    Database::rollback();
    check('audit failure rejects approval', true);
}
Database::run('DROP TRIGGER fail_audit');
check('audit failure rolls back reward and attempt', Wallet::balance($other) === 0 && Database::value('SELECT status FROM provider_attempts WHERE token = ?', [$link['token']]) === 'pending');
Database::begin();
check('admin credit succeeds inside transaction', Tasks::credit($link['token'], 'admin_review'));
Audit::log('admin', 1, 'provider_approve', $link['token'], ['note' => 'Provider-side evidence checked']);
Database::commit();
check('admin credit commits with audit', Wallet::balance($other) === 400 && (int)Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'provider_approve' AND target = ?", [$link['token']]) === 1);
check('duplicate admin credit rejected', !Tasks::credit($link['token'], 'admin_review') && Wallet::balance($other) === 400);
Http::fake(null);

// Exercise every shortener mapping offline (including JSON, bearer and token APIs).
foreach (Catalog::definitions() as $providerId => $definition) {
    if ($definition['kind'] !== 'shortlink') continue;
    Http::fake(function ($method, $url, $opt) use ($providerId, $definition) {
        $mapped = str_contains($url, (string)parse_url($definition['shorten']['url'], PHP_URL_HOST));
        if ($providerId === 'xtask') {
            $mapped = $mapped && $method === 'POST' && ($opt['json'] ?? false)
                && ($opt['body']['type'] ?? '') === 'traffic'
                && in_array('Authorization: Bearer test-key', $opt['headers'], true);
            $body = ['success' => true, 'data' => ['shortUrl' => 'https://xtask.top/task/test', 'shortCode' => 'test']];
        } elseif ($providerId === 'bbmkts') {
            $mapped = $mapped && str_contains($url, 'token=test-key') && str_contains($url, 'longurl=');
            $body = ['status' => 'success', 'bbmktsUrl' => 'https://bbmkts.com/go/test'];
        } elseif ($providerId === 'trafficuserr') {
            $mapped = $mapped && str_contains($url, 'api_key=test-key');
            $body = ['status' => true, 'message' => 'https://www.trafficuserr.com/v/test'];
        } else {
            $body = ['status' => 'success', 'shortenedUrl' => $definition['base'] . '/test'];
        }
        check('request mapping ' . $providerId, $mapped);
        return ['body' => json_encode($body)];
    });
    $service = $definition['services'][0];
    $data = Client::call($providerId, $definition['shorten'], ['url' => 'https://example.test/task/return/test', 'service' => $service['id'], 'service_type' => $service['type'] ?? '']);
    check('response mapping ' . $providerId, Client::safeLink($providerId, Http::any($data, $definition['shorten']['result'])) !== '');
}
Http::fake(null);
exit($failures === 0 ? 0 : 1);