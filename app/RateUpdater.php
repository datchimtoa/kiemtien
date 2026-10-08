<?php
declare(strict_types=1);

namespace App;

/**
 * Fixed site conversion policy. No external market-rate updates.
 */
final class RateUpdater
{
    public const FIXED_RATE = 24000;

    /**
     * Current rate + metadata for display in admin settings.
     * Shows the rate, when it was last updated, and member share.
     */
    public static function currentRate(): array
    {
        return [
            'rate'         => self::FIXED_RATE,
            'updated_at'   => Settings::get('usd_rate_updated_at', ''),
            'auto'         => false,
            'member_share' => Settings::getInt('site_member_share_percent', 100),
        ];
    }


    public static function refresh(): void
    {
        // Fixed conversion policy: never fetch market rates on web requests.
    }

}
