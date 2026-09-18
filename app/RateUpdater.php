<?php
declare(strict_types=1);

namespace App;

/**
 * Auto USD→VND rate refresher.
 * Lazy background update: called on every request; refreshes the rate from a
 * free exchange API when the last update is older than 6 hours.
 * Admin can force manual mode by setting usd_rate_auto = 0 and editing the rate.
 */
final class RateUpdater
{
    private const SOURCE = 'https://open.er-api.com/v6/latest/USD';

    /**
     * Current rate + metadata for display in admin settings.
     * Shows the rate, when it was last updated, and member share.
     */
    public static function currentRate(): array
    {
        return [
            'rate'         => (int)Settings::get('usd_to_vnd_rate', '26000'),
            'updated_at'   => Settings::get('usd_rate_updated_at', ''),
            'auto'         => Settings::getInt('usd_rate_auto', 1) === 1,
            'member_share' => Settings::getInt('site_member_share_percent', 100),
        ];
    }


    public static function refresh(): void
    {
        if (Settings::getInt('usd_rate_auto', 1) !== 1) {
            return;
        }
        $last = (string)Settings::get('usd_rate_updated_at', '');
        // First run: initialize immediately; afterwards refresh every 6 hours.
        if ($last !== '' && (time() - strtotime($last)) < 6 * 3600) {
            return;
        }
        $ch = curl_init(self::SOURCE);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        if ($code !== 200 || !is_string($resp)) {
            return; // keep the old rate; retry on a later request
        }
        $data = json_decode($resp, true);
        $vnd = $data['rates']['VND'] ?? null;
        if (is_numeric($vnd) && $vnd > 1000) {
            Settings::set('usd_to_vnd_rate', (string)(int)round((float)$vnd));
            Settings::set('usd_rate_updated_at', now());
            error_log('[rate] USD/VND auto-updated to ' . (int)round((float)$vnd));
        }
    }
}
