<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Settings;

use SutoreMarketplace\Shared\Settings\Settings;

final class CouponSettings
{
    public static function lockoutMaxAttempts(): int
    {
        return max(1, (int) Settings::get('coupon_lockout_max_attempts', 5));
    }

    public static function lockoutMinutes(): int
    {
        return max(1, (int) Settings::get('coupon_lockout_minutes', 15));
    }

    public static function cartNoticeLimit(): int
    {
        return max(1, (int) Settings::get('coupon_cart_notice_limit', 2));
    }
}
