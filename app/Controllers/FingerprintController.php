<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Fingerprint;
use App\Session;

final class FingerprintController
{
    /** POST /api/fingerprint — beacon from app.js. JSON in/out. */
    public static function store(): void
    {
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) {
            \App\json_response(['ok' => false, 'error' => 'bad json'], 400);
        }
        $deviceId = substr((string)($in['device_id'] ?? ''), 0, 128);
        $fpHash = substr((string)($in['fp'] ?? ''), 0, 128);
        $components = (string)($in['components'] ?? '');
        if ($deviceId === '' || $fpHash === '') {
            \App\json_response(['ok' => false, 'error' => 'missing fields'], 400);
        }
        Session::set('_fp_hash', $fpHash);
        Session::set('_device_id', $deviceId);
        Fingerprint::store(Session::userId(), $deviceId, $fpHash, $components);
        \App\json_response(['ok' => true]);
    }
}
