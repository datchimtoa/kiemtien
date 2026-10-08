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
$GLOBALS['__config']['trusted_proxy_ips'] = ['127.0.0.1', '::1'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 203.0.113.20';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.1.1.1';
check('trusted ingress selects nearest untrusted IP, not spoofed prefix or CF header', client_ip() === '203.0.113.20');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.21, ::1';
check('trusted proxy chain supports IPv6 loopback', client_ip() === '203.0.113.21');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'invalid, 203.0.113.20';
check('malformed forwarded chain fails closed to peer', client_ip() === '127.0.0.1');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
check('missing forwarded chain does not trust CF header', client_ip() === '127.0.0.1');
$GLOBALS['__config']['trusted_proxy_ips'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
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

Settings::set('provider_yeujob_service_friend_reward_vnd', '0');
rejects('V2 rejects legacy zero reward before remote call', fn() => Tasks::start($uid, 'yeujob', 'friend'));
Settings::set('provider_yeujob_service_friend_reward_vnd', '3500');
Http::fake(function ($method, $url, $opt) {
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    check('YeuJob V2 uses st without V1 list or accept', $method === 'GET'
        && parse_url($url, PHP_URL_PATH) === '/st' && ($query['api'] ?? '') === 'test-key'
        && str_starts_with($query['url'] ?? '', 'https://example.test/task/return/'));
    check('V2 uses standalone request profile without body or custom user agent', !isset($opt['body']) && ($opt['user_agent'] ?? null) === '');
    return ['body' => json_encode(['success' => true, 'shortenedUrl' => 'https://yeujob.com/q/job-456'])];
});
$job = Tasks::start($uid, 'yeujob', 'friend'); age($job['token']);
Tasks::returned($uid, $job['token']);
check('V2 return records review marker and keeps pending', Database::value('SELECT returned_at FROM provider_attempts WHERE token = ?', [$job['token']]) !== null
    && Database::value('SELECT status FROM provider_attempts WHERE token = ?', [$job['token']]) === 'pending');
Http::fake(function () { throw new RuntimeException('V2 must not poll V1'); });
check('V2 return and poll never pay', !Tasks::poll($uid, $job['token']) && Wallet::balance($uid) === 550);
check('V2 rejects API and callback credit', !Tasks::credit($job['token'], 'provider_api') && !Tasks::credit($job['token'], 'signed_callback', 'yeujob'));
Settings::set('provider_yeujob_callback_secret', str_repeat('v', 40));
$v2Body = json_encode(['token' => $job['token'], 'remote_id' => 'v2:job-456', 'status' => 'paid']);
$v2Time = (string)time();
check('authenticated callback cannot pay manual V2 task', !Callback::process('yeujob', $v2Time, hash_hmac('sha256', $v2Time . '.' . $v2Body, str_repeat('v', 40)), $v2Body) && Wallet::balance($uid) === 550);
Database::begin();
check('V2 admin approves frozen reward', Tasks::credit($job['token'], 'admin_review'));
Audit::log('admin', 1, 'provider_approve', $job['token'], ['note' => 'Provider-side V2 evidence checked']);
Database::commit();
check('V2 approval paid once', Wallet::balance($uid) === 4050 && !Tasks::credit($job['token'], 'admin_review'));
// Simulate an existing V1 attempt; retain authenticated legacy polling.
Database::run("UPDATE provider_attempts SET remote_id = 'legacy-456', status = 'pending' WHERE token = ?", [$job['token']]);
Http::fake(fn() => ['body' => json_encode(['success' => true, 'data' => ['application_id' => 'legacy-456', 'status' => 'approved', 'reward_status' => 'paid', 'reward' => 5000]])]);
check('legacy V1 polling retained without duplicate wallet credit', Tasks::poll($uid, $job['token']) && Wallet::balance($uid) === 4050);

// Signed callbacks must bind provider, token and remote ID; amounts are ignored.
// A failed cleanup must never replace the first error when creating a YeuJob task.
age($job['token']);
Settings::set('provider_yeujob_daily_limit', '100');
Settings::set('provider_yeujob_ip_daily_limit', '100');
Database::run("CREATE TRIGGER fail_provider_cleanup BEFORE UPDATE OF status ON provider_attempts WHEN NEW.status IN ('failed', 'creation_failed') BEGIN SELECT RAISE(ABORT, 'injected cleanup failure'); END");
Http::fake(fn() => ['body' => json_encode(['success' => false])]);
try {
    Tasks::start($uid, 'yeujob', 'friend');
    check('YeuJob creation failure propagates', false);
} catch (Throwable $e) {
    check('YeuJob cleanup preserves original provider error', $e->getPrevious() !== null
        && str_contains($e->getPrevious()->getMessage(), 'success không hợp lệ'));
}
check('failed YeuJob creation never pays', Wallet::balance($uid) === 4050);
Database::run('DROP TRIGGER fail_provider_cleanup');
Database::run('UPDATE provider_attempts SET created_at = ? WHERE user_id = ?', [date('Y-m-d H:i:s', time() - 120), $uid]);
Database::run("CREATE TRIGGER fail_provider_pending BEFORE UPDATE OF status ON provider_attempts WHEN NEW.status = 'pending' BEGIN SELECT RAISE(ABORT, 'injected pending failure'); END");
Http::fake(fn() => ['body' => json_encode(['success' => true, 'shortenedUrl' => 'https://yeujob.com/q/sql-failure-job'])]);
try {
    Tasks::start($uid, 'yeujob', 'friend');
    check('YeuJob pending SQL failure propagates', false);
} catch (Throwable $e) {
    check('YeuJob preserves first SQL failure', $e->getPrevious() instanceof PDOException
        && str_contains($e->getPrevious()->getMessage(), 'injected pending failure'));
}
check('YeuJob SQL failure leaves database usable and wallet unchanged', !Database::inTransaction() && Wallet::balance($uid) === 4050);
check('failure after remote acceptance retains daily reservation', Database::value('SELECT status FROM provider_attempts WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]) === 'failed');
Database::run('DROP TRIGGER fail_provider_pending');

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
        if ($providerId === 'yeujob') {
            $mapped = $mapped && $method === 'GET' && str_contains($url, '/st?') && str_contains($url, 'api=test-key');
            $body = ['success' => true, 'shortenedUrl' => 'https://yeujob.com/q/test'];
        } elseif ($providerId === 'xtask') {
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

// Definite pre-accept failures release daily slots, but retain anti-spam history.
Database::run('INSERT INTO users(phone,password_hash,created_at,updated_at) VALUES(?,?,?,?)', ['84933333333', 'test', now(), now()]);
$quotaUser = Database::lastId();
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
Settings::set('provider_yeujob_daily_limit', '1');
Settings::set('provider_yeujob_ip_daily_limit', '1');
Settings::set('provider_yeujob_api_key', '');
rejects('missing key rejects creation', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'));
$failedToken = Database::value('SELECT token FROM provider_attempts WHERE user_id = ?', [$quotaUser]);
check('missing key releases daily reservation', Database::value('SELECT status FROM provider_attempts WHERE token = ?', [$failedToken]) === 'creation_failed');
rejects('released reservation still enforces cooldown', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'));
age($failedToken);
Settings::set('provider_yeujob_api_key', 'test-key');
Http::fake(fn() => ['body' => json_encode(['success' => true, 'shortenedUrl' => 'https://yeujob.com/q/quota-retry-job'])]);
$quotaJob = Tasks::start($quotaUser, 'yeujob', 'friend');
check('released user and IP slot allows successful retry', Database::value('SELECT status FROM provider_attempts WHERE token = ?', [$quotaJob['token']]) === 'pending');
age($quotaJob['token']);
rejects('successful reservation still consumes daily slot', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'));
check('released and pending attempts do not pay without proof', Wallet::balance($quotaUser) === 0);
function rejectsWith(string $name, callable $fn, string $message): void {
    try { $fn(); check($name, false); } catch (RuntimeException $e) { check($name, str_contains($e->getMessage(), $message)); }
}
rejectsWith('daily block identifies account usage', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'), 'Tài khoản đã dùng 1/1');
Settings::set('provider_yeujob_daily_limit', '100');
rejectsWith('daily block identifies shared IP usage', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'), 'IP hiện tại đã dùng 1/1');
Settings::set('provider_yeujob_ip_daily_limit', '100');
Settings::set('task_max_completions_per_hour', '2');
rejectsWith('hourly block includes failed attempts', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'), '2/2 lần bắt đầu trong 60 phút');
Settings::set('task_max_completions_per_hour', '30');
Database::run('UPDATE provider_attempts SET created_at = ? WHERE token = ?', [now(), $quotaJob['token']]);
rejectsWith('cooldown block reports remaining seconds', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'), 'giây trước khi bắt đầu');
check('diagnostic blocks do not reserve additional attempts', (int)Database::value('SELECT COUNT(*) FROM provider_attempts WHERE user_id = ?', [$quotaUser]) === 2);
age($quotaJob['token']);
Settings::set('provider_yeujob_api_key', '');
rejectsWith('missing key has actionable pre-submission message', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'), 'Chưa gửi yêu cầu nhận job: Chưa cấu hình API key');
Settings::set('provider_yeujob_api_key', 'test-key');
Database::run('UPDATE provider_attempts SET created_at = ? WHERE user_id = ?', [date('Y-m-d H:i:s', time() - 120), $quotaUser]);
Http::fake(fn() => ['body' => json_encode(['success' => true, 'data' => []])]);
rejectsWith('missing V2 shortenedUrl is actionable', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'), 'API nhà cung cấp không trả link hợp lệ');
check('invalid V2 response keeps submitted reservation', Database::value('SELECT status FROM provider_attempts WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$quotaUser]) === 'failed');
Database::run('UPDATE provider_attempts SET created_at = ? WHERE user_id = ?', [date('Y-m-d H:i:s', time() - 120), $quotaUser]);
Http::fake(fn() => ['ok' => false, 'http' => 403, 'body' => 'test-key private body']);
rejectsWith('submitted V2 HTTP failure keeps safe diagnostic', fn() => Tasks::start($quotaUser, 'yeujob', 'friend'), 'HTTP 403');
Database::run('UPDATE provider_attempts SET created_at = ? WHERE user_id = ?', [date('Y-m-d H:i:s', time() - 120), $quotaUser]);
Http::fake(function () { throw new RuntimeException('test-key private unexpected error'); });
try {
    Tasks::start($quotaUser, 'yeujob', 'friend');
    check('unexpected error remains private with reference', false);
} catch (RuntimeException $e) {
    check('unexpected error remains private with reference', !str_contains($e->getMessage(), 'test-key') && str_contains($e->getMessage(), 'Mã lỗi:'));
}
foreach ([0, 401, 403, 429, 500, 503] as $status) {
    Http::fake(fn() => ['ok' => false, 'http' => $status, 'body' => json_encode(['message' => 'test-key private body'])]);
    try {
        Client::call('yeujob', Catalog::get('yeujob')['job']['list']);
        check('HTTP failure rejected ' . $status, false);
    } catch (RuntimeException $e) {
        check('HTTP diagnostic safe and actionable ' . $status, !str_contains($e->getMessage(), 'test-key')
            && !str_contains($e->getMessage(), 'private body')
            && str_contains($e->getMessage(), $status === 0 ? 'Không kết nối' : 'HTTP ' . $status));
    }
}
foreach ([
    'json' => '{"message":"test-key private body"}',
    'empty' => '',
    'html' => '<html><title>test-key private body</title></html>',
    'challenge_marker' => '<html><script src="/cdn-cgi/challenge-platform/test-key"></script></html>',
    'non_json' => 'test-key private body',
] as $kind => $body) {
    $requests = 0;
    Http::fake(function () use ($body, &$requests) {
        $requests++;
        return ['ok' => false, 'http' => 503, 'body' => $body];
    });
    try {
        Client::call('yeujob', Catalog::get('yeujob')['shorten'], ['url' => 'https://example.test/return']);
        check('503 classified safely ' . $kind, false);
    } catch (RuntimeException $e) {
        check('503 classified safely ' . $kind, str_contains($e->getMessage(), 'HTTP 503')
            && str_contains($e->getMessage(), 'response_kind=' . $kind)
            && !str_contains($e->getMessage(), 'test-key') && !str_contains($e->getMessage(), 'private body')
            && $requests === 1);
    }
}
Http::fake(null);
if (!function_exists('curl_init')) {
    rejectsWith('missing PHP curl reports deployment requirement', fn() => Http::request('GET', 'https://example.test'), 'thiếu PHP extension cURL');
}
exit($failures === 0 ? 0 : 1);