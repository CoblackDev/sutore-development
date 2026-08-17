<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Services;

use SutoreMarketplace\Modules\Coupons\Domain\BrandCampaignConfig;
use SutoreMarketplace\Modules\Coupons\Support\CouponMeta;

final class BrandCampaignRegistry
{
    private const TRANSIENT_KEY = 'sutore_mp_brand_campaigns';
    private const TTL = 300;

    /** @var list<BrandCampaignConfig>|null */
    private static ?array $cache = null;

    /** @return list<BrandCampaignConfig> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            self::$cache = self::hydrate($cached);

            return self::$cache;
        }

        if (!function_exists('WC')) {
            self::$cache = [];
            set_transient(self::TRANSIENT_KEY, [], self::TTL);

            return self::$cache;
        }

        $configs = [];
        $couponIds = get_posts([
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => CouponMeta::BRAND_CAMPAIGN,
                    'value' => 'yes',
                ],
            ],
        ]);

        if (!is_array($couponIds)) {
            self::$cache = [];
            set_transient(self::TRANSIENT_KEY, [], self::TTL);

            return self::$cache;
        }

        foreach ($couponIds as $couponId) {
            $coupon = new \WC_Coupon((int) $couponId);

            $brandIds = CouponMeta::includedBrandTermIds($coupon);
            if ($brandIds === []) {
                continue;
            }

            if ($coupon->get_discount_type() !== 'percent') {
                continue;
            }

            $configs[] = new BrandCampaignConfig(
                couponId: (int) $coupon->get_id(),
                code: (string) $coupon->get_code(),
                discountPercent: (float) $coupon->get_amount(),
                brandTermIds: $brandIds,
                minBrandQty: CouponMeta::minBrandQty($coupon),
                noticePriority: CouponMeta::noticePriority($coupon),
                noticeColor: CouponMeta::noticeColor($coupon),
            );
        }

        usort($configs, static fn (BrandCampaignConfig $a, BrandCampaignConfig $b): int => $a->noticePriority <=> $b->noticePriority);

        set_transient(self::TRANSIENT_KEY, self::serialize($configs), self::TTL);
        self::$cache = $configs;

        return self::$cache;
    }

    public static function findByCode(string $code): ?BrandCampaignConfig
    {
        $code = strtolower(wc_format_coupon_code($code));
        foreach (self::all() as $config) {
            if (strtolower($config->code) === $code) {
                return $config;
            }
        }

        return null;
    }

    public static function flush(): void
    {
        self::$cache = null;
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * @param list<BrandCampaignConfig> $configs
     * @return list<array<string, mixed>>
     */
    private static function serialize(array $configs): array
    {
        $out = [];
        foreach ($configs as $config) {
            $out[] = [
                'coupon_id' => $config->couponId,
                'code' => $config->code,
                'discount_percent' => $config->discountPercent,
                'brand_term_ids' => $config->brandTermIds,
                'min_brand_qty' => $config->minBrandQty,
                'notice_priority' => $config->noticePriority,
                'notice_color' => $config->noticeColor,
            ];
        }

        return $out;
    }

    /**
     * @param list<mixed> $rows
     * @return list<BrandCampaignConfig>
     */
    private static function hydrate(array $rows): array
    {
        $configs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $brandIds = array_values(array_filter(array_map('intval', (array) ($row['brand_term_ids'] ?? []))));
            if ($brandIds === []) {
                continue;
            }
            $configs[] = new BrandCampaignConfig(
                couponId: (int) ($row['coupon_id'] ?? 0),
                code: (string) ($row['code'] ?? ''),
                discountPercent: (float) ($row['discount_percent'] ?? 0),
                brandTermIds: $brandIds,
                minBrandQty: (int) ($row['min_brand_qty'] ?? 0),
                noticePriority: (int) ($row['notice_priority'] ?? 0),
                noticeColor: (string) ($row['notice_color'] ?? ''),
            );
        }

        return $configs;
    }
}
