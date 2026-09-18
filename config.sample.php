<?php
/**
 * earnmoney.vip — Sample configuration.
 * Copy to config.php and adjust values. NEVER commit config.php to VCS.
 */
return [
    // Base URL of the site (no trailing slash). Used for links + postback hints.
    'base_url'        => 'https://earnmoney.vip',

    // Force HTTPS redirect (enable in production).
    'force_https'     => true,

    // App timezone.
    'timezone'        => 'Asia/Ho_Chi_Minh',

    // Database: sqlite (default, zero config) or mysql.
    'db' => [
        'driver'   => 'sqlite',
        'sqlite'   => __DIR__ . '/storage/earnmoney.sqlite3',
        'mysql'    => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'earnmoney', 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4'],
    ],

    // Bootstrap admin (used by scripts/create_admin.php).
    'admin' => [
        'username' => 'admin',
        'password' => 'CHANGE_ME_STRONG_PASSWORD',
    ],

    // SMS OTP driver: "log" (writes code to storage/logs/otp.log — DEV ONLY)
    // or "http" (generic gateway, configurable in Admin → Settings).
    'sms' => [
        'driver' => 'log',
    ],

    // Session cookie hardening.
    'session' => [
        'name'      => 'emvsid',
        'lifetime'  => 60 * 60 * 24 * 7, // 7 days
        'secure'    => true,             // send cookie over HTTPS only
    ],

    // Postback endpoint secret path segment: /postback/pubcrypto/{token}
    // Generate e.g.: php -r "echo bin2hex(random_bytes(16));"
    // Leave empty to disable the extra segment (path is still secret-ish).
    'postback_token'  => 'CHANGE_ME_RANDOM_HEX',
];
