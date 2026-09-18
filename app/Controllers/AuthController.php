<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\AuthEvent;
use App\Csrf;
use App\Database;
use App\Fingerprint;
use App\Otp;
use App\Session;
use App\View;

final class AuthController
{
    public static function showRegister(): void
    {
        if (Session::userId() !== null) {
            redirect('/dashboard');
        }
        View::show('auth/register', ['step' => 'phone', 'error' => null, 'phone' => '', 'devCode' => null], 'base');
    }

    /** Step 1: phone + send OTP (SMS channel, disabled by default). */
    public static function postPhone(): void
    {
        if (App\Settings::get('register_channel_sms', '0') !== '1') {
            Session::flash('info', 'Hiện tại đăng ký chỉ hỗ trợ xác thực qua Telegram (miễn phí).');
            redirect('/register');
        }
        Csrf::check();
        $honeypot = (string)input('website', '');
        if ($honeypot !== '') {
            // Bot filled the honeypot — pretend success without doing anything.
            Session::flash('info', 'Vui lòng kiểm tra SMS để nhận mã xác thực.');
            redirect('/register');
        }
        $started = (int)input('form_started', 0);
        $minSeconds = (int)(\App\Settings::get('min_form_seconds', '3') ?: 3);
        if ($started > 0 && time() - $started < $minSeconds) {
            AuthEvent::log(null, (string)input('phone', ''), 'register', 'blocked', 'form submitted too fast');
            Session::flash('error', 'Gửi quá nhanh. Vui lòng thử lại.');
            redirect('/register');
        }
        $phone = normalize_phone((string)input('phone', ''));
        if ($phone === null) {
            View::show('auth/register', ['step' => 'phone', 'error' => 'Số điện thoại không hợp lệ (VD: 0912345678).', 'phone' => (string)input('phone', ''), 'devCode' => null], 'base');
        }
        [$ok, $err, $otpId, $devCode] = Auth::sendRegisterOtp($phone);
        if (!$ok) {
            View::show('auth/register', ['step' => 'phone', 'error' => (string)$err, 'phone' => display_phone($phone), 'devCode' => null], 'base');
        }
        Session::set('reg_phone', $phone);
        Session::set('reg_otp_id', $otpId);
        Session::set('reg_otp_sent_at', time());
        if ($devCode !== null && APP_DEV) {
            Session::set('reg_dev_code', $devCode);
        }
        redirect('/register/step2');
    }

    /** Intermediate step-2 page (GET /register/step2). */
    public static function showRegisterStep2(): void
    {
        $phone = Session::get('reg_phone');
        if ($phone === null) {
            redirect('/register');
        }
        View::show('auth/register', [
            'step' => 'verify', 'error' => null, 'phone' => display_phone($phone),
            'devCode' => Session::get('reg_dev_code'),
            'tgVerified' => (bool)Session::get('reg_tg_verified'),
        ], 'base');
    }

    /** Step 2: verify OTP + set password + collect name. */
    public static function postVerify(): void
    {
        Csrf::check();
        $phone = Session::get('reg_phone');
        $otpId = Session::get('reg_otp_id');
        $tgVerified = (bool)Session::get('reg_tg_verified');
        if ($phone === null || ($otpId === null && !$tgVerified)) {
            redirect('/register');
        }
        $viewErr = static function (string $err) use ($phone, $tgVerified): never {
            View::show('auth/register', ['step' => 'verify', 'error' => $err, 'phone' => display_phone($phone), 'devCode' => null, 'tgVerified' => $tgVerified], 'base');
        };
        $password = (string)input('password', '');
        $password2 = (string)input('password_confirm', '');
        $fullName = mb_substr((string)input('full_name', ''), 0, 120);

        if (!$tgVerified) {
            $code = (string)input('otp_code', '');
            if (!preg_match('/^\d{6}$/', $code)) {
                $viewErr('Mã OTP gồm 6 chữ số.');
            }
            [$ok, $err] = Otp::verify((int)$otpId, $phone, Otp::PURPOSE_REGISTER, $code);
            if (!$ok) {
                $viewErr((string)$err);
            }
        }
        if (strlen($password) < 8) {
            $viewErr('Mật khẩu tối thiểu 8 ký tự.');
        }
        if ($password !== $password2) {
            $viewErr('Mật khẩu nhập lại không khớp.');
        }
        $tg = null;
        if ($tgVerified) {
            $tgUserId = (string)Session::get('reg_tg_user_id');
            // HARD RULE: 1 Telegram = 1 account (double-check at registration).
            if ($tgUserId !== '' && Database::one('SELECT id FROM users WHERE telegram_user_id = ?', [$tgUserId]) !== null) {
                Risk::event(null, 'telegram_duplicate_register', 'high', 'tg_user=' . $tgUserId . ' phone=' . $phone);
                $viewErr('Tài khoản Telegram này đã được liên kết với một tài khoản khác. Mỗi Telegram chỉ dùng được cho 1 tài khoản.');
            }
            $tg = [
                'user_id'  => $tgUserId,
                'username' => (string)Session::get('reg_tg_username'),
            ];
        }
        [$ok, $err, $user] = Auth::register($phone, $password, $fullName !== '' ? $fullName : null, Fingerprint::sessionHash(), Fingerprint::sessionDeviceId(), $tg);
        if (!$ok) {
            $viewErr((string)$err);
        }
        Session::forget('reg_phone');
        Session::forget('reg_otp_id');
        Session::forget('reg_otp_sent_at');
        Session::forget('reg_dev_code');
        Session::forget('reg_tg_token');
        Session::forget('reg_tg_verified');
        Session::forget('reg_tg_user_id');
        Session::forget('reg_tg_username');
        Session::flash('success', 'Đăng ký thành công! Chào mừng bạn đến với EarnMoney.VIP.');
        redirect('/dashboard');
    }

    public static function showLogin(): void
    {
        if (Session::userId() !== null) {
            redirect('/dashboard');
        }
        View::show('auth/login', ['error' => null, 'otpStep' => false, 'phone' => '', 'devCode' => null], 'base');
    }

    public static function postLogin(): void
    {
        Csrf::check();
        $phone = normalize_phone((string)input('phone', ''));
        $password = (string)input('password', '');
        if ($phone === null || $password === '') {
            View::show('auth/login', ['error' => 'Vui lòng nhập số điện thoại và mật khẩu.', 'otpStep' => false, 'phone' => (string)input('phone', ''), 'devCode' => null], 'base');
        }
        $res = Auth::login($phone, $password, Fingerprint::sessionHash(), Fingerprint::sessionDeviceId());
        if ($res['status'] === 'ok') {
            $intended = Session::get('_intended');
            Session::forget('_intended');
            redirect(is_string($intended) && str_starts_with($intended, '/') ? $intended : '/dashboard');
        }
        if ($res['status'] === 'otp_required') {
            Session::set('login_user_id', $res['user_id']);
            Session::set('login_otp_id', $res['otp_id']);
            View::show('auth/login', ['error' => null, 'otpStep' => true, 'phone' => display_phone($phone), 'devCode' => APP_DEV ? $res['dev_code'] : null], 'base');
        }
        View::show('auth/login', ['error' => (string)$res['error'], 'otpStep' => false, 'phone' => display_phone($phone), 'devCode' => null], 'base');
    }

    public static function postLoginOtp(): void
    {
        Csrf::check();
        $userId = Session::get('login_user_id');
        $otpId = Session::get('login_otp_id');
        if ($userId === null || $otpId === null) {
            redirect('/login');
        }
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            redirect('/login');
        }
        [$ok, $err] = Otp::verify((int)$otpId, (string)$user['phone'], Otp::PURPOSE_LOGIN, (string)input('otp_code', ''));
        if (!$ok) {
            View::show('auth/login', ['error' => (string)$err, 'otpStep' => true, 'phone' => display_phone((string)$user['phone']), 'devCode' => null], 'base');
        }
        Session::forget('login_user_id');
        Session::forget('login_otp_id');
        Auth::finishLogin($user, Fingerprint::sessionDeviceId(), Fingerprint::sessionHash());
        Session::flash('success', 'Đăng nhập thành công!');
        $intended = Session::get('_intended');
        Session::forget('_intended');
        redirect(is_string($intended) && str_starts_with($intended, '/') ? $intended : '/dashboard');
    }

    /** Choose Telegram as the free verification channel. */
    public static function postRegisterTelegram(): void
    {
        Csrf::check();
        $phone = Session::get('reg_phone');
        if ($phone === null) {
            redirect('/register');
        }
        if (!\App\Telegram::enabled()) {
            Session::flash('error', 'Xác thực Telegram chưa được bật. Vui lòng dùng SMS.');
            redirect('/register');
        }
        $res = \App\Telegram::createVerify($phone);
        if ($res === null) {
            Session::flash('error', 'Bạn tạo link Telegram quá nhiều lần. Vui lòng thử lại sau 1 giờ.');
            redirect('/register');
        }
        Session::set('reg_tg_token', $res['token']);
        View::show('auth/telegram', [
            'bot'    => \App\Telegram::botUsername(),
            'token'  => $res['token'],
            'link'   => 'https://t.me/' . \App\Telegram::botUsername() . '?start=' . $res['token'],
        ], 'base');
    }

    /** Poll endpoint: has the user pressed START in the bot? */
    public static function telegramStatus(): void
    {
        Csrf::checkOrJson();
        $token = Session::get('reg_tg_token');
        $phone = Session::get('reg_phone');
        if ($token === null || $phone === null) {
            json_response(['ok' => false, 'error' => 'not_started'], 400);
        }
        $row = \App\Telegram::status((string)$token);
        $verified = $row !== null && $row['status'] === 'verified' && $row['phone'] === $phone;
        if ($verified) {
            Session::set('reg_tg_verified', true);
            // Bind telegram identity to the (not yet created) user at register time.
            Session::set('reg_tg_user_id', (string)($row['telegram_user_id'] ?? ''));
            Session::set('reg_tg_username', (string)($row['telegram_username'] ?? ''));
        }
        json_response(['ok' => true, 'verified' => $verified]);
    }

    public static function logout(): void
    {
        Csrf::check();
        AuthEvent::log(Session::userId(), '', 'logout', 'ok', '');
        Session::logout();
        redirect('/');
    }
}
