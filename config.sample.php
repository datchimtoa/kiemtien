<?php
/**
 * earnmoney.vip — Configuration loader.
 * Reads from environment variables (for Render/Vercel deployment) with fallback
 * to hardcoded values (for local dev). NEVER exposes secrets to frontend.
 */

// --- Database ---
// DB_DRIVER: sqlite (dev) | mysql (legacy) | pgsql (Neon cloud — KHUYÊN DÙNG production)
// Neon: copy connection string từ dashboard → set DATABASE_URL, hoặc điền rời DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD.
$db_driver = strtolower((string)(getenv('DB_DRIVER') ?: 'sqlite'));
$db_config = [];

if ($db_driver === 'pgsql') {
    $db_config = [
        'driver' => 'pgsql',
        'pgsql'  => [
            'url'      => getenv('DATABASE_URL') ?: '',
            'host'     => getenv('DB_HOST') ?: '',
            'port'     => (int)(getenv('DB_PORT') ?: 5432),
            'database' => getenv('DB_NAME') ?: 'neondb',
            'username' => getenv('DB_USER') ?: '',
            'password' => getenv('DB_PASSWORD') ?: '',
            'sslmode'  => getenv('DB_SSLMODE') ?: 'require',
        ],
    ];
} elseif ($db_driver === 'mysql') {
    $db_config = [
        'driver'   => 'mysql',
        'mysql'    => [
            'host'     => getenv('DB_HOST') ?: '127.0.0.1',
            'port'     => (int)(getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_NAME') ?: 'earnmoney',
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset'  => 'utf8mb4',
            // Cloud MySQL bắt buộc TLS: set DB_SSL=1 (+ DB_SSL_CA nếu nhà cung cấp cấp CA).
            'ssl'      => filter_var(getenv('DB_SSL') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'ssl_ca'   => getenv('DB_SSL_CA') ?: '',
        ],
    ];
} else {
    $db_config = [
        'driver' => 'sqlite',
        'sqlite' => __DIR__ . '/storage/earnmoney.sqlite3',
    ];
}

// --- SMS Driver ---
$sms_driver = getenv('SMS_DRIVER') ?: 'telegram'; // telegram | log | http
$sms_config = [];

if ($sms_driver === 'telegram') {
    $sms_config = [
        'driver' => 'telegram',
        'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
        'chat_id'   => getenv('TELEGRAM_CHAT_ID') ?: '',
    ];
} elseif ($sms_driver === 'http') {
    $sms_config = [
        'driver' => 'http',
        'url' => getenv('SMS_HTTP_URL') ?: 'https://sms_gateway.local/api',
        'headers' => getenv('SMS_HTTP_HEADERS') ?: 'Content-Type: application/json',
        'body_template' => getenv('SMS_HTTP_BODY') ?: '{"phone":"{phone}","message":"{message}","sender":"{sender}"}',
    ];
} else {
    $sms_config = ['driver' => 'log'];
}

// Custom domain: BASE_URL override > RENDER_EXTERNAL_URL (onrender.com) > htxg.pro
$base_url = getenv('BASE_URL') ?: getenv('RENDER_EXTERNAL_URL') ?: 'https://htxg.pro';

// --- Postback token ---
$postback_token = getenv('POSTBACK_TOKEN') ?: bin2hex(random_bytes(8));

// --- Rate updater settings ---
$rate_updater = [
    'auto_update'      => filter_var(getenv('RATE_AUTO_UPDATE') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'max_uptime_minutes' => (int)(getenv('RATE_MAX_UPTIME') ?: 5),
    'check_interval_seconds' => (int)(getenv('RATE_CHECK_INTERVAL') ?: 3600),
    'api_url' => 'https://open.er-api.com/v6/latest/USD',
];

return [
    'base_url' => rtrim($base_url, '/'),
    'force_https' => true,
    'timezone' => 'Asia/Ho_Chi_Minh',
    'db' => $db_config,
    'admin' => [
        'username' => getenv('ADMIN_USERNAME') ?: 'admin',
        'password' => getenv('ADMIN_PASSWORD') ?: 'SecureAdmin@2024',
    ],
    'session' => [
        'name' => 'emvsid',
        'lifetime' => 60 * 60 * 24 * 7,
        'secure' => true,
    ],
    'postback_token' => $postback_token,
    'sms' => $sms_config,
    'rate_updater' => $rate_updater,
    // Crypto API configs (set via environment)
    'pubcrypto' => [
        'api_base' => getenv('PUBCRYPTO_API_BASE') ?: 'https://pub.cryptolinkforearn.com',
        // Site key + API key: ưu tiên env (Render), fallback DB settings (Admin → Cài đặt).
        'site_key' => getenv('PUBCRYPTO_SITE_KEY') ?: '',
        'api_key'  => getenv('PUBCRYPTO_API_KEY') ?: '',
        // Forward secret: BẮT BUỘC để verify postback. Ưu tiên env, fallback DB.
        'forward_secret' => getenv('PUBCRYPTO_FORWARD_SECRET') ?: '',
        'member_share_percent' => (int)(getenv('PUBCRYPTO_MEMBER_PERCENT') ?: 100),
    ],
    // Telegram API (for SMS verification via bot)
    'telegram' => [
        'bot_token'    => getenv('TELEGRAM_BOT_TOKEN') ?: '',
        'bot_username' => getenv('TELEGRAM_BOT_USERNAME') ?: '',
        'chat_id'      => getenv('TELEGRAM_CHAT_ID') ?: '',
    ],
];

