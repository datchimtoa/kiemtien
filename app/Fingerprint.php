<?php
declare(strict_types=1);

namespace App;

/**
 * Device fingerprint collection + storage.
 * JS side (public/assets/app.js) computes a stable hash from screen/tz/lang/hardware
 * plus a random device_id persisted in localStorage; server stores + links to users.
 */
final class Fingerprint
{
    public static function store(?int $userId, string $deviceId, string $fpHash, string $componentsJson): string
    {
        $deviceId = substr($deviceId, 0, 128);
        $fpHash = substr($fpHash, 0, 128);
        Database::run(
            'INSERT INTO fingerprints(user_id, session_key, device_id, fp_hash, components, ip, ua, created_at) VALUES(?,?,?,?,?,?,?,?)',
            [$userId, session_id(), $deviceId, $fpHash, substr($componentsJson, 0, 500), client_ip(), user_agent(), now()]
        );
        if ($userId !== null) {
            // upsert manually: update last_seen when device exists, else insert.
            $row = Database::one(
                'SELECT id FROM devices WHERE user_id = ? AND device_id = ?',
                [$userId, $deviceId]
            );
            if ($row !== null) {
                Database::run('UPDATE devices SET last_seen = ?, ip = ?, fp_hash = COALESCE(NULLIF(?, \'\'), fp_hash) WHERE id = ?',
                    [now(), client_ip(), $fpHash, $row['id']]);
            } else {
                Database::run(
                    'INSERT INTO devices(user_id, device_id, fp_hash, ua, platform, ip, first_seen, last_seen, trusted) VALUES(?,?,?,?,?,?,?,?,0)',
                    [$userId, $deviceId, $fpHash, user_agent(), '', client_ip(), now(), now()]
                );
            }
        }
        return $fpHash;
    }

    /** Latest fingerprint hash for the current session (or null). */
    public static function sessionHash(): ?string
    {
        $h = Session::get('_fp_hash');
        return is_string($h) && $h !== '' ? $h : null;
    }

    /** Current device id for the session. */
    public static function sessionDeviceId(): ?string
    {
        $d = Session::get('_device_id');
        return is_string($d) && $d !== '' ? $d : null;
    }

    /** TRUE if this user has seen this device_id (or ip) before login flow. */
    public static function isKnownDevice(?int $userId, ?string $deviceId, ?string $fpHash, string $ip): bool
    {
        if ($userId === null) {
            return false;
        }
        if ($deviceId !== null && $deviceId !== '') {
            $row = Database::one('SELECT id FROM devices WHERE user_id = ? AND device_id = ? LIMIT 1', [$userId, $deviceId]);
            if ($row !== null) {
                return true;
            }
        }
        if ($fpHash !== null && $fpHash !== '') {
            $row = Database::one('SELECT id FROM devices WHERE user_id = ? AND fp_hash = ? LIMIT 1', [$userId, $fpHash]);
            if ($row !== null) {
                return true;
            }
        }
        $row = Database::one('SELECT id FROM user_ips WHERE user_id = ? AND ip = ? LIMIT 1', [$userId, $ip]);
        return $row !== null;
    }
}
