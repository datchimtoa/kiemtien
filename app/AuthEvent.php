<?php
declare(strict_types=1);

namespace App;

/** Auth event logging helper (own file so the autoloader can find it). */
final class AuthEvent
{
    public static function log(?int $userId, string $phone, string $event, string $result, string $detail = ''): void
    {
        Database::run(
            'INSERT INTO auth_events(user_id, phone, event, result, ip, ua, device_id, detail, created_at) VALUES(?,?,?,?,?,?,?,?,?)',
            [$userId, $phone, $event, $result, client_ip(), user_agent(), (string)Fingerprint::sessionDeviceId(), $detail, now()]
        );
    }
}
