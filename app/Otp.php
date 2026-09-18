<?php
declare(strict_types=1);

namespace App;

/**
 * OTP issue/verify with hashed codes, attempt caps and expiry.
 */
final class Otp
{
    public const PURPOSE_REGISTER = 'register';
    public const PURPOSE_LOGIN    = 'login';

    public static function issue(string $phone, string $purpose, int $ttlSeconds = 300): array
    {
        // Invalidate previous unconsumed codes for same phone+purpose.
        Database::run(
            'UPDATE otp_codes SET consumed_at = ? WHERE phone = ? AND purpose = ? AND consumed_at IS NULL',
            [now(), $phone, $purpose]
        );
        $code = random_code(6);
        $id = Database::run(
            'INSERT INTO otp_codes(phone, code_hash, purpose, attempts, ip, expires_at, created_at) VALUES(?,?,?,?,?,?,?)',
            [$phone, hash('sha256', $code . $phone), $purpose, 0, client_ip(), date('Y-m-d H:i:s', time() + $ttlSeconds), now()]
        );
        return ['id' => Database::lastId(), 'code' => $code, 'expires_in' => $ttlSeconds];
    }

    /**
     * Verify and consume. Returns [ok, error].
     */
    public static function verify(int $otpId, string $phone, string $purpose, string $code): array
    {
        $row = Database::one(
            'SELECT * FROM otp_codes WHERE id = ? AND phone = ? AND purpose = ? AND consumed_at IS NULL',
            [$otpId, $phone, $purpose]
        );
        if ($row === null) {
            return [false, 'Mã OTP không tồn tại hoặc đã được sử dụng.'];
        }
        if (strtotime($row['expires_at']) < time()) {
            return [false, 'Mã OTP đã hết hạn. Vui lòng yêu cầu mã mới.'];
        }
        if ((int)$row['attempts'] >= 5) {
            Database::run('UPDATE otp_codes SET consumed_at = ? WHERE id = ?', [now(), $row['id']]);
            return [false, 'Bạn đã nhập sai quá nhiều lần. Vui lòng yêu cầu mã mới.'];
        }
        Database::run('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        if (!hash_equals($row['code_hash'], hash('sha256', $code . $phone))) {
            return [false, 'Mã OTP không đúng.'];
        }
        Database::run('UPDATE otp_codes SET consumed_at = ? WHERE id = ?', [now(), $row['id']]);
        return [true, null];
    }
}
