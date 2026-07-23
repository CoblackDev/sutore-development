<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Admin;

use SutoreMarketplace\Modules\Coupons\Services\BrandCampaignRegistry;
use SutoreMarketplace\Modules\Coupons\Support\CouponMeta;

final class CouponSeeder
{
    /**
     * @return array{created:int,skipped:int}|\WP_Error
     */
    public function seed(): array|\WP_Error
    {
        if (!function_exists('WC')) {
            return new \WP_Error(
                'sutore_marketplace_wc_inactive',
                __('WooCommerce is not active.', 'sutore-marketplace'),
                ['status' => 503]
            );
        }

        $created = 0;
        $skipped = 0;

        foreach ($this->seedDefinitions() as $seed) {
            $code = (string) $seed['code'];
            if (wc_get_coupon_id_by_code($code)) {
                $skipped++;
                continue;
            }

            $term = get_term_by('slug', (string) $seed['brand_slug'], 'product_brand');
            if (!$term instanceof \WP_Term) {
                $skipped++;
                continue;
            }

            $coupon = new \WC_Coupon();
            $coupon->set_code($code);
            $coupon->set_discount_type('percent');
            $coupon->set_amount((string) (float) $seed['percent']);
            $coupon->set_individual_use(true);

            // WC Brands supported coupon brand restriction meta.
            $coupon->update_meta_data('product_brands', [(int) $term->term_id]);

            // Sutore brand-campaign meta.
            $coupon->update_meta_data(CouponMeta::BRAND_CAMPAIGN, 'yes');
            $coupon->update_meta_data(CouponMeta::MIN_BRAND_QTY, (int) $seed['min_qty']);
            $coupon->update_meta_data(CouponMeta::NOTICE_PRIORITY, (int) $seed['priority']);
            $coupon->update_meta_data(CouponMeta::NOTICE_COLOR, (string) $seed['color']);

            $coupon->save();
            $created++;
        }

        BrandCampaignRegistry::flush();

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /** @return list<array{code:string,brand_slug:string,percent:float,min_qty:int,priority:int,color:string}> */
    private function seedDefinitions(): array
    {
        return [
            ['code' => 'KUJTEN10', 'brand_slug' => 'kujten', 'percent' => 10.0, 'min_qty' => 2, 'priority' => 10, 'color' => '#4caf50'],
            ['code' => 'RHODE10', 'brand_slug' => 'rhode', 'percent' => 10.0, 'min_qty' => 3, 'priority' => 20, 'color' => '#4caf50'],
            ['code' => 'GLOSSIER10', 'brand_slug' => 'glossier', 'percent' => 10.0, 'min_qty' => 3, 'priority' => 40, 'color' => '#4caf50'],
        ];
    }
}
