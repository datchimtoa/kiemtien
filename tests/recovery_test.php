#!/usr/bin/env php
<?php
/**
 * Test nhanh các nhánh phục hồi lỗi DB (chạy được cả khi DB là sqlite):
 *  - isRecoverable(): nhận đúng SQLSTATE 25P02/40001/40P01, bỏ qua lỗi khác.
 *  - commit() có bảo vệ: không "no active transaction" khi transaction đã bị mất.
 *  - rollbackQuietly(): không ném lỗi khi không có transaction.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;

$fail = 0;
$check = function (string $name, bool $ok, string $extra = '') use (&$fail): void {
    echo ($ok ? "  ✅ " : "  ❌ ") . $name . ($extra !== '' ? "  ($extra)" : '') . "\n";
    if (!$ok) {
        $fail++;
    }
};

$recoverable = new ReflectionMethod(Database::class, 'isRecoverable');

$mk = static function (string $state): PDOException {
    $e = new PDOException('test ' . $state);
    $e->errorInfo = [$state, 7, 'driver message'];
    return $e;
};
$mkPlain = static function (): PDOException {
    $e = new PDOException('relation does not exist');
    $e->errorInfo = ['42P01', 7, 'driver message'];
    return $e;
};

echo "1) isRecoverable()\n";
$check('25P02 (transaction aborted) → phục hồi', $recoverable->invoke(null, $mk('25P02')) === true);
$check('40001 (serialization) → phục hồi', $recoverable->invoke(null, $mk('40001')) === true);
$check('40P01 (deadlock) → phục hồi', $recoverable->invoke(null, $mk('40P01')) === true);
$check('42P01 (thiếu bảng) → KHÔNG phục hồi', $recoverable->invoke(null, $mkPlain()) === false);

echo "2) transaction hygiene\n";
$check('rollbackQuietly() khi không có transaction', (static function (): bool {
    Database::rollbackQuietly();
    return true;
})());
Database::begin();
$check('inTransaction() sau begin()', Database::inTransaction() === true);
Database::rollback();
$check('inTransaction() sau rollback()', Database::inTransaction() === false);
$check('rollbackQuietly() khi transaction đang mở', (static function (): bool {
    Database::begin();
    Database::rollbackQuietly();
    return Database::inTransaction() === false;
})());
$check('begin() dọn transaction treo trước đó', (static function (): bool {
    Database::begin();      // transaction 1
    Database::begin();      // phải tự dọn rồi mở lại, không được ném lỗi
    $ok = Database::inTransaction();
    Database::rollback();
    return $ok;
})());

echo "3) RateLimiter fail-open + ghi hit\n";
$bucketId = 'unit:' . bin2hex(random_bytes(3));
$allowed = [];
for ($i = 0; $i < 5; $i++) {
    $allowed[] = App\RateLimiter::attempt($bucketId, 'test', 3, 60);
}
$check('3 lượt đầu được phép', $allowed[0] && $allowed[1] && $allowed[2]);
$check('lượt 4-5 bị chặn', $allowed[3] === false && $allowed[4] === false);
App\RateLimiter::clear($bucketId, 'test');
$check('clear() mở lại lượt', App\RateLimiter::attempt($bucketId, 'test', 3, 60) === true);
App\RateLimiter::clear($bucketId, 'test');

echo "4) Lỗi 1 câu lệnh KHÔNG được giết các câu lệnh sau\n";
$check('lỗi không-phục-hồi vẫn được ném ra (không nuốt)', (static function (): bool {
    Database::begin();
    try {
        Database::run('SELECT * FROM bang_khong_ton_tai_zzz');
        Database::rollback();
        return false; // đáng lẽ phải ném lỗi
    } catch (\Throwable $e) {
        Database::rollbackQuietly();
        return true;
    }
})());
$check('DB vẫn dùng được ngay sau lỗi (request không chết)', (static function (): bool {
    Database::begin();
    try {
        Database::run('SELECT * FROM bang_khong_ton_tai_zzz');
    } catch (\Throwable $e) {
        Database::rollbackQuietly();
    }
    return (int)Database::value('SELECT COUNT(*) FROM settings') > 0;
})());

echo $fail === 0 ? "\nTẤT CẢ PASS\n" : "\n{$fail} TEST FAIL\n";
exit($fail === 0 ? 0 : 1);
