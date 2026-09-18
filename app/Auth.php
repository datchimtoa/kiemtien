<?php
declare(strict_types=1);

namespace App;

/**
 * Member authentication: registration (phone+OTP), password login,
 * OTP challenge on unknown devices, throttling, audit.
 */
final class Auth
{
    /** Send an OTP for registration. Returns [ok, error, otpId, devCode?] */
    public static function sendRegisterOtp(string $phone): array
    {
        if (!RateLimiter::attempt('otp_send_phone', $phone, 3, 600)) {
            return [false, 'Bạn đã yêu cầu quá nhiều mã OTP. Vui lòng thử lại sau 10 phút.', null, null];
        }
        if (!RateLimiter::attempt('otp_send_ip', client_ip(), 10, 3600)) {
            return [false, 'Quá nhiều yêu cầu OTP từ mạng của bạn. Vui lòng thử lại sau.', null, null];
        }
        if (Database::one('SELECT id FROM users WHERE phone = ?', [$phone]) !== null) {
            return [false, 'Số điện thoại này đã được đăng ký. Vui lòng đăng nhập.', null, null];
        }
        $otp = Otp::issue($phone, Otp::PURPOSE_REGISTER);
        $msg = 'Ma xac thuc EarnMoney.VIP cua ban la: ' . $otp['code'] . ' (hieu luc 5 phut). Khong chia se ma nay.';
        $sent = Sms::send($phone, $msg);
        if (!$sent['ok']) {
            return [false, 'Không gửi được SMS. Vui lòng thử lại sau.', null, null];
        }
        AuthEvent::log(null, $phone, 'otp_sent', 'ok', 'purpose=register');
        return [true, null, $otp['id'], $sent['driver'] === 'log' ? $otp['code'] : null];
    }

    /** Send a login OTP — prefers Telegram (free) when the user linked one, falls back to SMS. */
    public static function sendLoginOtp(string $phone, ?string $telegramChatId = null): array
    {
        if (!RateLimiter::attempt('otp_send_phone_login', $phone, 5, 600)) {
            return [false, 'Bạn đã yêu cầu quá nhiều mã OTP. Vui lòng thử lại sau.'];
        }
        $otp = Otp::issue($phone, Otp::PURPOSE_LOGIN);
        $msg = 'Ma dang nhap EarnMoney.VIP: ' . $otp['code'] . ' (hieu luc 5 phut).';

        // Telegram channel first (free).
        if ($telegramChatId !== null && $telegramChatId !== '') {
            $ok = Telegram::sendMessage($telegramChatId, '🔑 Mã đăng nhập EarnMoney.VIP: ' . $otp['code'] . "\n(hiệu lực 5 phút. Không chia sẻ mã này.)");
            if ($ok) {
                return [true, null, $otp['id'], null, 'telegram'];
            }
        }
        $sent = Sms::send($phone, $msg);
        return [$sent['ok'], $sent['ok'] ? null : 'Không gửi được SMS. Vui lòng thử lại sau.', $otp['id'], $sent['driver'] === 'log' ? $otp['code'] : null, 'sms'];
    }

    /** Create account (phone verified via SMS-OTP or Telegram). Returns [ok, error, user?] */
    public static function register(string $phone, string $password, ?string $fullName, ?string $fpHash, ?string $deviceId, ?array $telegram = null): array
    {
        $errs = Risk::checkRegistration($phone, $fpHash, $deviceId);
        if ($errs) {
            return [false, $errs[0], null];
        }
        $hash = hash_password($password);
        $ref = 'EM' . strtoupper(bin2hex(random_bytes(4)));
        try {
            Database::run(
                'INSERT INTO users(phone, password_hash, full_name, status, referral_code, register_ip, register_ua, register_fp, register_device_id, telegram_user_id, telegram_username, telegram_verified_at, created_at, updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$phone, $hash, (string)$fullName, 'active', $ref, client_ip(), user_agent(), (string)$fpHash, (string)$deviceId,
                 (string)($telegram['user_id'] ?? ''), (string)($telegram['username'] ?? ''),
                 $telegram !== null ? now() : null, now(), now()]
            );
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return [false, 'Số điện thoại này đã được đăng ký.', null];
            }
            throw $e;
        }
        $userId = Database::lastId();
        Database::run('INSERT INTO user_ips(user_id, ip, hits, first_seen, last_seen) VALUES(?,?,1,?,?)', [$userId, client_ip(), now(), now()]);
        AuthEvent::log($userId, $phone, 'register', 'ok', '');
        Session::regenerate();
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        Session::login($user);
        return [true, null, $user];
    }

    /**
     * Password login. Returns one of:
     *  ['status' => 'ok'], ['status' => 'otp_required', ...], ['status' => 'error', 'error' => msg]
     */
    public static function login(string $phone, string $password, ?string $fpHash, ?string $deviceId): array
    {
        $ip = client_ip();
        if (!RateLimiter::attempt('login_phone', $phone, 5, 900)) {
            AuthEvent::log(null, $phone, 'login', 'throttled', 'too many attempts');
            return ['status' => 'error', 'error' => 'Bạn đã nhập sai quá nhiều lần. Thử lại sau 15 phút.'];
        }
        if (!RateLimiter::attempt('login_ip', $ip, 20, 900)) {
            return ['status' => 'error', 'error' => 'Quá nhiều lần thử đăng nhập từ mạng của bạn.'];
        }
        $user = Database::one('SELECT * FROM users WHERE phone = ?', [$phone]);
        if ($user === null || !password_verify($password, (string)$user['password_hash'])) {
            AuthEvent::log($user === null ? null : (int)$user['id'], $phone, 'login', 'failed', 'bad credentials');
            return ['status' => 'error', 'error' => 'Số điện thoại hoặc mật khẩu không đúng.'];
        }
        if ($user['status'] !== 'active') {
            AuthEvent::log((int)$user['id'], $phone, 'login', 'blocked', 'status=' . $user['status']);
            return ['status' => 'error', 'error' => 'Tài khoản đã bị khoá. Vui lòng liên hệ hỗ trợ.'];
        }

        $needOtp = Settings::getInt('login_new_device_otp', 1) === 1
            && !Fingerprint::isKnownDevice((int)$user['id'], $deviceId, $fpHash, $ip);
        if ($needOtp) {
            [$ok, $err, $otpId, $devCode] = self::sendLoginOtp($phone, (string)($user['telegram_user_id'] ?? ''));
            if (!$ok) {
                return ['status' => 'error', 'error' => (string)$err];
            }
            AuthEvent::log((int)$user['id'], $phone, 'login', 'otp_challenge', 'new device');
            return ['status' => 'otp_required', 'otp_id' => $otpId, 'dev_code' => $devCode, 'user_id' => (int)$user['id']];
        }

        self::finishLogin($user, $deviceId, $fpHash);
        return ['status' => 'ok'];
    }

    /** Record IP visit — tương thích SQLite + MySQL + Postgres (upsert hits+1). */
    public static function recordIpVisit(int $userId, string $ip): void
    {
        $now = now();
        if (Database::isSqlite() || Database::isPgsql()) {
            Database::run(
                'INSERT INTO user_ips(user_id, ip, hits, first_seen, last_seen) VALUES(?,?,1,?,?)
                 ON CONFLICT(user_id, ip) DO UPDATE SET hits = user_ips.hits + 1, last_seen = excluded.last_seen',
                [$userId, $ip, $now, $now]
            );
        } else {
            // MySQL: uq_user_ip(user_id, ip) → ON DUPLICATE KEY UPDATE.
            Database::run(
                'INSERT INTO user_ips(user_id, ip, hits, first_seen, last_seen) VALUES(?,?,1,?,?)
                 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)',
                [$userId, $ip, $now, $now]
            );
        }
    }

    /** Complete login after (optional) OTP verification. */
    public static function finishLogin(array $user, ?string $deviceId, ?string $fpHash): void
    {
        $userId = (int)$user['id'];
        $ip = client_ip();
        Database::run('UPDATE users SET last_login_at = ?, last_login_ip = ? WHERE id = ?', [now(), $ip, $userId]);
        self::recordIpVisit($userId, $ip);
        if ($deviceId !== null && $deviceId !== '') {
            $row = Database::one('SELECT id FROM devices WHERE user_id = ? AND device_id = ?', [$userId, $deviceId]);
            if ($row === null) {
                Database::run(
                    'INSERT INTO devices(user_id, device_id, fp_hash, ua, platform, ip, first_seen, last_seen) VALUES(?,?,?,?,?,?,?,?)',
                    [$userId, $deviceId, (string)$fpHash, user_agent(), '', $ip, now(), now()]
                );
            } else {
                Database::run('UPDATE devices SET last_seen = ?, ip = ?, ua = ? WHERE id = ?', [now(), $ip, user_agent(), $row['id']]);
            }
        }
        AuthEvent::log($userId, (string)$user['phone'], 'login', 'ok', '');
        Session::regenerate();
        Session::login($user);
    }
}
