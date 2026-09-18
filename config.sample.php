<?php
/**
 * earnmoney.vip — Configuration loader.
 * Reads from environment variables (for Render/Vercel deployment) with fallback
 * to hardcoded values (for local dev). NEVER exposes secrets to frontend.
 */

// --- Database ---
$db_driver = getenv('DB_DRIVER') ?: 'sqlite';
$db_config = [];

if ($db_driver === 'mysql') {
    $db_config = [
        'driver'   => 'mysql',
        'mysql'    => [
            'host'     => getenv('DB_HOST') ?: '127.0.0.1',
            'port'     => (int)(getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_NAME') ?: 'earnmoney',
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset'  => 'utf8mb4',
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

// --- Base URL (for links + webhook setup) ---
$base_url = getenv('RENDER_EXTERNAL_URL') ?: getenv('BASE_URL') ?: 'https://earnmoney.vip';

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
        'site_key' => getenv('PUBCRYPTO_SITE_KEY') ?: '',
        'api_key'  => getenv('PUBCRYPTO_API_KEY') ?: '',
        'member_share_percent' => (int)(getenv('PUBCRYPTO_MEMBER_PERCENT') ?: 100),
    ],
    // Telegram API (for SMS verification via bot)
    'telegram' => [
        'bot_token'    => getenv('TELEGRAM_BOT_TOKEN') ?: '',
        'bot_username' => getenv('TELEGRAM_BOT_USERNAME') ?: '',
        'chat_id'      => getenv('TELEGRAM_CHAT_ID') ?: '',
    ],
];

