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
        Database::run(
            'INSERT INTO telegram_verify(token, phone, purpose, ip, created_at, expires_at) VALUES(?,?,?,?,?,?)',
            [$token, $phone, $purpose, client_ip(), now(), date('Y-m-d H:i:s', time() + 900)]
        );
        return ['token' => $token, 'expires_at' => date('Y-m-d H:i:s', time() + 900)];
    }

    /** Called by webhook when the bot receives /start with our payload. */
    public static function handleStart(string $payload, array $from): bool
    {
        $payload = trim($payload);
        if ($payload === '' || strlen($payload) > 64) {
            return false;
        }
        $tgId = (string)($from['id'] ?? '');
        if ($tgId === '') {
            return false;
        }
        // HARD RULE: 1 Telegram account = 1 website account.
        $bound = Database::one(
            'SELECT id, phone, status FROM users WHERE telegram_user_id = ? LIMIT 1',
            [$tgId]
        );
        $row = Database::one('SELECT * FROM telegram_verify WHERE token = ?', [$payload]);
        if ($row === null) {
            return false;
        }
        if ($bound !== null && (string)$bound['phone'] !== (string)$row['phone']) {
            // This Telegram already owns a different account → reject + risk flag.
            Risk::event(
                (int)$bound['id'],
                'telegram_duplicate_bind',
                'high',
                'tg_user=' . $tgId . ' tried phone=' . $row['phone'] . ' but owns phone=' . $bound['phone']
            );
            return false;
        }
        if ($row['status'] === 'verified') {
            return true; // idempotent
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            return false;
        }
        // Anti-abuse: one Telegram account verifies at most 3 different phones per day.
        $abuse = (int)Database::value(
            'SELECT COUNT(DISTINCT phone) FROM telegram_verify WHERE telegram_user_id = ? AND status = ? AND verified_at >= ?',
            [(string)$from['id'], 'verified', date('Y-m-d H:i:s', time() - 86400)]
        );
        if ($abuse >= 1) {
            Risk::event(null, 'telegram_verify_abuse', 'medium', 'tg_user=' . $from['id'] . ' phones_today=' . $abuse);
            return false;
        }
        // Excessive attempts → risk-flag the TG user (considered spam; user is banned site-wide).
        $attemptsToday = (int)Database::value(
            'SELECT COUNT(DISTINCT phone) FROM telegram_verify WHERE telegram_user_id = ? AND created_at >= ?',
            [(string)$from['id'], date('Y-m-d H:i:s', time() - 86400)]
        );
        if ($attemptsToday >= 10) {
            Database::run("UPDATE users SET risk_flag = 1 WHERE telegram_user_id = ? AND risk_flag = 0", [(string)$from['id']]);
        }
        Database::run(
            'UPDATE telegram_verify SET status = ?, telegram_user_id = ?, telegram_username = ?, verified_at = ? WHERE id = ?',
            ['verified', (string)$from['id'], (string)($from['username'] ?? ''), now(), $row['id']]
        );
        return true;
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
