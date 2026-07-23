<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Support;

final class CouponMeta
{
    public const BRAND_CAMPAIGN = '_sutore_mp_brand_campaign';
    public const MIN_BRAND_QTY = '_sutore_mp_min_brand_qty';
    public const NOTICE_PRIORITY = '_sutore_mp_notice_priority';
    public const NOTICE_COLOR = '_sutore_mp_notice_color';

    public static function isBrandCampaign(\WC_Coupon $coupon): bool
    {
        return $coupon->get_meta(self::BRAND_CAMPAIGN, true) === 'yes';
    }

    public static function minBrandQty(\WC_Coupon $coupon): int
    {
        return max(1, (int) $coupon->get_meta(self::MIN_BRAND_QTY, true));
    }

    public static function noticePriority(\WC_Coupon $coupon): int
    {
        return max(0, (int) $coupon->get_meta(self::NOTICE_PRIORITY, true));
    }

    public static function noticeColor(\WC_Coupon $coupon): string
    {
        $color = sanitize_hex_color((string) $coupon->get_meta(self::NOTICE_COLOR, true));

        return $color !== null && $color !== '' ? $color : '#222222';
    }

    /** @return list<int> */
    public static function includedBrandTermIds(\WC_Coupon $coupon): array
    {
        $raw = $coupon->get_meta('product_brands', true);
        if (!is_array($raw)) {
            if (is_string($raw) && $raw !== '') {
                $raw = array_map('trim', explode(',', $raw));
            } else {
                return [];
            }
        }

        return array_values(array_filter(array_map('intval', $raw)));
    }
}
