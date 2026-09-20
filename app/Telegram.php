<?php
declare(strict_types=1);

namespace App;

/**
 * Telegram-based OTP alternative (100% free).
 *
 * Flow:
 *  1) User enters phone on the site → site creates a telegram_verify row
 *     with a one-time token + deep-link t.me/<bot>?start=<token>
 *  2) User presses START in the bot → Telegram sends an update to our webhook
 *  3) Webhook marks the row verified (one-time, TTL enforced) and replies in chat
 *  4) Website polls /register/telegram-status until status=verified → continues
 *
 * Bot token is set in config.php: 'telegram' => ['bot_token' => '...', 'bot_username' => 'BotName']
 */
final class Telegram
{
    /** Link xác thực dùng 1 lần, hết hạn sau 30 phút. */
    public const TTL_SECONDS = 1800;

    // Mã lý do trả về của /start — dùng để bot trả lời đúng vấn đề cho người dùng.
    public const R_OK = 'ok';
    public const R_ALREADY = 'already';
    public const R_NO_PAYLOAD = 'no_payload';
    public const R_BAD_TOKEN = 'bad_token';
    public const R_TOKEN_USED = 'token_used';
    public const R_EXPIRED = 'expired';
    public const R_HAS_ACCOUNT = 'has_account';
    public const R_TOO_MANY = 'too_many';
    public const R_NO_SENDER = 'no_sender';

    public static function botToken(): string
    {
        // Ưu tiên env (Render: TELEGRAM_BOT_TOKEN), fallback config.php.
        return (string)(getenv('TELEGRAM_BOT_TOKEN') ?: (config('telegram.bot_token') ?? ''));
    }

    public static function botUsername(): string
    {
        return (string)(getenv('TELEGRAM_BOT_USERNAME') ?: (config('telegram.bot_username') ?? ''));
    }

    public static function enabled(): bool
    {
        return self::botToken() !== '' && self::botUsername() !== '';
    }

    /** Create a pending verify row; returns [token, expiresAt] or null. */
    public static function createVerify(string $phone, string $purpose = 'register'): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        // Throttle: max 5 pending links per phone per hour.
        $recent = (int)Database::value(
            'SELECT COUNT(*) FROM telegram_verify WHERE phone = ? AND created_at >= ?',
            [$phone, date('Y-m-d H:i:s', time() - 3600)]
        );
        if ($recent >= 5) {
            return null;
        }
        $token = bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        Database::run(
            'INSERT INTO telegram_verify(token, phone, purpose, ip, created_at, expires_at) VALUES(?,?,?,?,?,?)',
            [$token, $phone, $purpose, client_ip(), now(), $expires]
        );
        return ['token' => $token, 'expires_at' => $expires];
    }

    /** Called by webhook when the bot receives /start with our payload. */
    public static function handleStart(string $payload, array $from): bool
    {
        return self::verifyStart($payload, $from) === self::R_OK;
    }

    /**
     * Xác thực payload của /start. Trả về MÃ LÝ DO (R_*) để webhook trả lời đúng
     * vấn đề cho người dùng (trước đây mọi lỗi đều nói "link không hợp lệ" nên rất khó hiểu).
     */
    public static function verifyStart(string $payload, array $from): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            // Người dùng tự mở bot / bấm START không qua link t?start=<token>.
            return self::R_NO_PAYLOAD;
        }
        if (strlen($payload) > 64) {
            return self::R_BAD_TOKEN;
        }
        $tgId = (string)($from['id'] ?? '');
        if ($tgId === '') {
            return self::R_NO_SENDER;
        }
        $row = Database::one('SELECT * FROM telegram_verify WHERE token = ?', [$payload]);
        if ($row === null) {
            return self::R_BAD_TOKEN;
        }
        if ((string)$row['status'] === 'verified') {
            // Idempotent: đã xác thực bởi đúng Telegram này.
            return (string)($row['telegram_user_id'] ?? '') === $tgId ? self::R_ALREADY : self::R_TOKEN_USED;
        }
        // HARD RULE: 1 Telegram account = 1 website account.
        $bound = Database::one(
            'SELECT id, phone, status FROM users WHERE telegram_user_id = ? LIMIT 1',
            [$tgId]
        );
        if ($bound !== null && (string)$bound['phone'] !== (string)$row['phone']) {
            Risk::event(
                (int)$bound['id'],
                'telegram_duplicate_bind',
                'high',
                'tg_user=' . $tgId . ' tried phone=' . $row['phone'] . ' but owns phone=' . $bound['phone']
            );
            return self::R_HAS_ACCOUNT;
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            return self::R_EXPIRED;
        }
        // Anti-abuse: one Telegram account verifies at most 3 different phones per day.
        $abuse = (int)Database::value(
            'SELECT COUNT(DISTINCT phone) FROM telegram_verify WHERE telegram_user_id = ? AND status = ? AND verified_at >= ?',
            [$tgId, 'verified', date('Y-m-d H:i:s', time() - 86400)]
        );
        if ($abuse >= 3) {
            Risk::event(null, 'telegram_verify_abuse', 'medium', 'tg_user=' . $tgId . ' phones_today=' . $abuse);
            return self::R_TOO_MANY;
        }
        // Excessive attempts → risk-flag the TG user (considered spam; user is banned site-wide).
        $attemptsToday = (int)Database::value(
            'SELECT COUNT(DISTINCT phone) FROM telegram_verify WHERE telegram_user_id = ? AND created_at >= ?',
            [$tgId, date('Y-m-d H:i:s', time() - 86400)]
        );
        if ($attemptsToday >= 10) {
            Database::run("UPDATE users SET risk_flag = 1 WHERE telegram_user_id = ? AND risk_flag = 0", [$tgId]);
        }
        Database::run(
            'UPDATE telegram_verify SET status = ?, telegram_user_id = ?, telegram_username = ?, verified_at = ? WHERE id = ?',
            ['verified', $tgId, (string)($from['username'] ?? ''), now(), $row['id']]
        );
        return self::R_OK;
    }

    /** Câu trả lời trong chat theo mã lý do (tiếng Việt, có hướng dẫn cụ thể). */
    public static function reasonMessage(string $reason): string
    {
        $mins = (int)round(self::TTL_SECONDS / 60);
        return match ($reason) {
            self::R_OK => "✅ Xác thực thành công!\n\nQuay lại trang web và bấm \"Tôi đã bấm START — kiểm tra ngay\" (hoặc chờ trang tự chuyển).",
            self::R_ALREADY => "✅ Bạn đã xác thực rồi.\n\nQuay lại trang web để tiếp tục đăng ký.",
            self::R_NO_PAYLOAD => "👋 Chào bạn!\n\nĐể xác thực, hãy QUAY LẠI TRANG WEB → Đăng ký → nhập số điện thoại → bấm \"Xác thực qua Telegram (miễn phí)\", rồi bấm nút \"🚀 Mở bot\" ở đó. Link đó có mã riêng cho từng lần đăng ký.",
            self::R_EXPIRED => "⏰ Link đã hết hạn ({$mins} phút).\nQuay lại trang web và tạo link mới.",
            self::R_HAS_ACCOUNT => "🚫 Telegram này đã gắn với một tài khoản khác.\nMỗi Telegram chỉ dùng cho 1 tài khoản — hãy dùng Telegram khác hoặc đăng nhập tài khoản cũ.",
            self::R_TOO_MANY => "🚫 Telegram này đã xác thực cho quá nhiều số điện thoại trong 24 giờ.\nVui lòng thử lại sau.",
            self::R_NO_SENDER => "❌ Không đọc được thông tin người gửi. Vui lòng thử lại.",
            default => "❌ Link xác thực không hợp lệ hoặc đã được dùng.\nQuay lại trang web tạo link mới.",
        };
    }

    /** Poll helper: status of a token for the website loop. */
    public static function status(string $token): ?array
    {
        return Database::one('SELECT status, phone, purpose, telegram_user_id FROM telegram_verify WHERE token = ?', [$token]);
    }

    /** Send a plain message to a chat (used to confirm verification). */
    public static function sendMessage(string $chatId, string $text): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $ch = curl_init('https://api.telegram.org/bot' . self::botToken() . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['chat_id' => $chatId, 'text' => $text]),
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        return $code === 200;
    }

    /** Trạng thái webhook hiện tại (Telegram getWebhookInfo) hoặc null nếu chưa bật bot. */
    public static function webhookInfo(): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $ch = curl_init('https://api.telegram.org/bot' . self::botToken() . '/getWebhookInfo');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        $json = json_decode($resp, true);
        if ($code !== 200 || !is_array($json) || empty($json['ok'])) {
            return ['error' => 'getWebhookInfo failed (HTTP ' . $code . ')'];
        }
        $r = is_array($json['result'] ?? null) ? $json['result'] : [];
        return [
            'url'      => (string)($r['url'] ?? ''),
            'pending'  => (int)($r['pending_update_count'] ?? 0),
            'last_error' => (string)($r['last_error_message'] ?? ''),
        ];
    }

    /** Register the webhook (run after tunnel/domain change). */
    public static function setWebhook(string $url): array
    {
        $ch = curl_init('https://api.telegram.org/bot' . self::botToken() . '/setWebhook');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['url' => $url]),
        ]);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        return ['http' => $code, 'resp' => $resp];
    }
}
