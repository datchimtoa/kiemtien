#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\DatabaseSessionHandler;

config('db');
$GLOBALS['__config']['db'] = ['driver' => 'sqlite', 'sqlite' => ':memory:'];
Database::migrate();
class_exists(App\Session::class);
$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $fail += $ok ? 0 : 1;
};

$handler = new DatabaseSessionHandler(604800);
$id = 'session-regression-test';
$data = '_csrf|s:64:"token";';
$handler->destroy($id);
$check('database session round-trip persists CSRF state', $handler->write($id, $data) && $handler->read($id) === $data);
Database::run('UPDATE app_sessions SET last_activity = ? WHERE id = ?', [time() - 604801, $id]);
$check('expired database session is rejected', $handler->read($id) === '');
$check('expired database session is removed', Database::one('SELECT id FROM app_sessions WHERE id = ?', [$id]) === null);

exit($fail ? 1 : 0);
