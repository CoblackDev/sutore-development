<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Services;

use SutoreMarketplace\Modules\Coupons\Settings\CouponSettings;

final class CouponLockout
{
    private const ATTEMPTS_KEY = 'sutore_mp_coupon_failed_attempts';
    private const LOCKOUT_KEY = 'sutore_mp_coupon_lockout_until';

    public static function isLocked(): bool
    {
        if (!function_exists('WC') || !WC()->session) {
            return false;
        }

        $until = (int) WC()->session->get(self::LOCKOUT_KEY, 0);

        return $until > 0 && time() < $until;
    }

    public static function lockoutMessage(): string
    {
        return __(
            'Your coupon action cannot be completed at this time. If you believe this is an error, please contact customer service.',
            'sutore-marketplace'
        );
    }

    public static function recordFailure(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        $attempts = (int) WC()->session->get(self::ATTEMPTS_KEY, 0) + 1;
        WC()->session->set(self::ATTEMPTS_KEY, $attempts);

        if ($attempts >= CouponSettings::lockoutMaxAttempts()) {
            WC()->session->set(self::ATTEMPTS_KEY, 0);
            WC()->session->set(self::LOCKOUT_KEY, time() + (CouponSettings::lockoutMinutes() * MINUTE_IN_SECONDS));
        }
    }

    public static function reset(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        WC()->session->set(self::ATTEMPTS_KEY, 0);
        WC()->session->set(self::LOCKOUT_KEY, 0);
    }
}
