#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Settings;
use App\Wallet;
use App\Withdrawals;

config('db');
$GLOBALS['__config']['db'] = ['driver' => 'sqlite', 'sqlite' => ':memory:'];
Database::migrate();
Settings::set('anticheat_enabled', '0');
Settings::set('withdraw_review_days', '');
Database::run('INSERT INTO users(phone,password_hash,created_at,updated_at) VALUES(?,?,?,?)',
    ['84912345678', 'test-only', now(), now()]);
$id = Database::lastId();
$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

check('fixed rate migrated', Settings::getInt('usd_to_vnd_rate') === 24000 && Settings::getInt('usd_rate_auto') === 0);
Settings::set('usd_to_vnd_rate', '99999');
check('conversion cannot be changed by stale settings', Wallet::usdToVnd(1) === 24000);
Wallet::post($id, Wallet::TYPE_ADMIN_ADJUST, 20000);
try {
    Wallet::post($id, Wallet::TYPE_WITHDRAW_HOLD, -30000);
    check('insufficient hold throws', false);
} catch (RuntimeException $e) {
    check('insufficient hold leaves balance intact', Wallet::balance($id) === 20000);
}
Database::begin();
Wallet::post($id, Wallet::TYPE_ADMIN_ADJUST, 1000);
Database::rollback();
check('wallet joins caller transaction', Wallet::balance($id) === 20000);
[$ok] = Withdrawals::request($id, 30000, 'momo', 'Test User', '0912345678');
check('overdraft withdrawal rejected', !$ok && Wallet::balance($id) === 20000);
[$ok] = Withdrawals::request($id, 10000, 'momo', 'Test User', '0912345678');
$wd = (int)Database::value('SELECT id FROM withdrawals LIMIT 1');
check('valid withdrawal holds exact amount', $ok && $wd > 0 && Wallet::balance($id) === 10000);
[$ok] = Withdrawals::request($id, 10000, 'momo', 'Test User', '0912345678');
check('second pending request blocked', !$ok && Wallet::balance($id) === 10000);
[$ok] = Withdrawals::reject($wd, 1, 'Test refund');
check('reject refunds exactly once', $ok && Wallet::balance($id) === 20000);
[$ok] = Withdrawals::reject($wd, 1, 'Duplicate');
check('duplicate rejection cannot refund', !$ok && Wallet::balance($id) === 20000);
[$ok] = Withdrawals::request($id, 10000, 'momo', 'Test User', '0912345678');
$wd = (int)Database::value('SELECT MAX(id) FROM withdrawals');
[$done] = Withdrawals::complete($wd, 1);
[$again] = Withdrawals::complete($wd, 1);
check('completion counted once', $ok && $done && !$again && (int)Database::value('SELECT withdrawn_total_vnd FROM users WHERE id = ?', [$id]) === 10000);

// Simulate failure after the hold; balance and ledger must roll back together.
Database::run("CREATE TRIGGER fail_withdraw BEFORE INSERT ON withdrawals BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
$balance = Wallet::balance($id);
$count = (int)Database::value('SELECT COUNT(*) FROM transactions');
[$ok] = Withdrawals::request($id, 10000, 'momo', 'Test User', '0912345678');
check('insert failure rolls back hold and ledger', !$ok && Wallet::balance($id) === $balance && (int)Database::value('SELECT COUNT(*) FROM transactions') === $count);
check('user deletion uses existing schema columns', App\Risk::deleteUser($id) && Database::one('SELECT id FROM users WHERE id = ?', [$id]) === null);
exit($failures === 0 ? 0 : 1);