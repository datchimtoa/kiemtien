#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Risk;
use App\Settings;

// Only disposable data. Restore environment after exercising legacy config fallback.
$savedProxy = getenv('TRUSTED_PROXY_IPS');
$savedRender = getenv('RENDER');
config('db');
$GLOBALS['__config']['db'] = ['driver' => 'sqlite', 'sqlite' => ':memory:'];
Database::migrate();
Settings::set('max_accounts_per_ip', '1');
$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $fail += $ok ? 0 : 1;
};
try {
    putenv('TRUSTED_PROXY_IPS');
    putenv('RENDER=true');
    unset($GLOBALS['__config']['trusted_proxy_ips']);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 203.0.113.11';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '9.9.9.9';
    $check('Render legacy config uses nearest untrusted client, not spoofed headers', client_ip() === '203.0.113.11');
    foreach (['127.0.0.1', '203.0.113.11'] as $i => $ip) {
        Database::run('INSERT INTO users(phone,password_hash,register_ip,created_at,updated_at) VALUES(?,?,?,?,?)',
            ['8491000000' . $i, 'test', $ip, now(), now()]);
    }
    $check('same real IP still hits registration cap', Risk::checkRegistration('84920000001', null, null) !== []);
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.12';
    $check('different client behind same ingress can register despite old loopback records',
        client_ip() === '203.0.113.12' && Risk::checkRegistration('84920000002', null, null) === []);
    Database::run('UPDATE users SET register_fp = ?', ['same-device']);
    $check('fingerprint limit remains enforced across client IPs', Risk::checkRegistration('84920000003', 'same-device', null) !== []);

    $GLOBALS['__config']['trusted_proxy_ips'] = [];
    putenv('TRUSTED_PROXY_IPS=127.0.0.1,::1');
    $check('explicit env works even when config has empty proxy list', client_ip() === '203.0.113.12');
    $_SERVER['REMOTE_ADDR'] = '::1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::12';
    $check('IPv6 ingress supports different IPv6 clients', client_ip() === '2001:db8::12');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
    $check('direct untrusted clients cannot spoof IP', client_ip() === '203.0.113.99');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = 'bad-value, 203.0.113.12';
    $check('malformed forwarding fails closed', client_ip() === '127.0.0.1');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.12';
    putenv('TRUSTED_PROXY_IPS=');
    $check('explicit empty env disables proxy trust on Render', client_ip() === '127.0.0.1');
    putenv('TRUSTED_PROXY_IPS');
    putenv('RENDER');
    unset($GLOBALS['__config']['trusted_proxy_ips']);
    $check('non-Render legacy config does not trust loopback by default', client_ip() === '127.0.0.1');
} finally {
    putenv($savedProxy === false ? 'TRUSTED_PROXY_IPS' : 'TRUSTED_PROXY_IPS=' . $savedProxy);
    putenv($savedRender === false ? 'RENDER' : 'RENDER=' . $savedRender);
}
exit($fail ? 1 : 0);