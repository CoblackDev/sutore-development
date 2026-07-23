<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Hooks;

use SutoreMarketplace\Modules\Coupons\Services\BrandCampaignRegistry;
use SutoreMarketplace\Modules\Coupons\Services\CouponLockout;
use SutoreMarketplace\Modules\Coupons\Support\CouponMeta;

final class CouponValidationHooks
{
    public function register(): void
    {
        add_filter('woocommerce_coupon_is_valid', [$this, 'enforceLockout'], 1, 3);
        add_filter('woocommerce_coupon_is_valid', [$this, 'validateBrandCampaign'], 20, 3);
        add_filter('woocommerce_coupon_error', [$this, 'trackCouponError'], 10, 3);
        add_action('woocommerce_applied_coupon', [$this, 'resetLockout']);
    }

    public function enforceLockout(bool $isValid, \WC_Coupon $coupon, $discounts): bool
    {
        unset($coupon, $discounts);

        if (CouponLockout::isLocked()) {
            throw new \Exception(CouponLockout::lockoutMessage());
        }

        return $isValid;
    }

    public function validateBrandCampaign(bool $isValid, \WC_Coupon $coupon, $discounts): bool
    {
        if (!$isValid || !CouponMeta::isBrandCampaign($coupon)) {
            return $isValid;
        }

        $brandIds = CouponMeta::includedBrandTermIds($coupon);
        if ($brandIds === []) {
            throw new \Exception(__('At least one brand must be selected for this coupon.', 'sutore-marketplace'));
        }

        $items = $this->resolveItems($discounts);
        if ($items === []) {
            return $isValid;
        }

        $brandCount = 0;
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }

            $terms = wp_get_post_terms($productId, 'product_brand', ['fields' => 'ids']);
            if (is_wp_error($terms) || $terms === []) {
                continue;
            }

            if (array_intersect($brandIds, array_map('intval', $terms)) !== []) {
                $brandCount += $qty;
            }
        }

        $required = CouponMeta::minBrandQty($coupon);
        if ($brandCount < $required) {
            $brandName = $this->brandLabel($brandIds);
            throw new \Exception(
                sprintf(
                    /* translators: 1: coupon code, 2: required quantity, 3: brand name */
                    __('To use the %1$s coupon, your cart must contain at least %2$d of %3$s product.', 'sutore-marketplace'),
                    '<strong>' . esc_html(strtoupper((string) $coupon->get_code())) . '</strong>',
                    $required,
                    '<strong>' . esc_html($brandName) . '</strong>'
                )
            );
        }

        return $isValid;
    }

    /** @return string|int */
    public function trackCouponError($error, int $errorCode, \WC_Coupon $coupon)
    {
        unset($errorCode, $coupon);

        if (wp_doing_ajax() && sanitize_key((string) ($_POST['action'] ?? '')) === 'apply_coupon') {
            CouponLockout::recordFailure();
        }

        return $error;
    }

    public function resetLockout(string $couponCode): void
    {
        unset($couponCode);
        CouponLockout::reset();
        BrandCampaignRegistry::flush();
    }

    /** @return list<array{product_id:int,quantity:int}> */
    private function resolveItems($discounts): array
    {
        if ($discounts && method_exists($discounts, 'get_object')) {
            $object = $discounts->get_object();
            if ($object instanceof \WC_Order) {
                $items = [];
                foreach ($object->get_items() as $item) {
                    $items[] = [
                        'product_id' => (int) $item->get_product_id(),
                        'quantity' => (int) $item->get_quantity(),
                    ];
                }

                return $items;
            }
        }

        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return [];
        }

        $items = [];
        foreach (WC()->cart->get_cart() as $cartItem) {
            $items[] = [
                'product_id' => (int) ($cartItem['product_id'] ?? 0),
                'quantity' => (int) ($cartItem['quantity'] ?? 0),
            ];
        }

        return $items;
    }

    /** @param list<int> $brandIds */
    private function brandLabel(array $brandIds): string
    {
        $names = [];
        foreach ($brandIds as $termId) {
            $term = get_term((int) $termId, 'product_brand');
            if ($term instanceof \WP_Term) {
                $names[] = $term->name;
            }
        }

        return $names !== [] ? implode(', ', $names) : __('selected brand', 'sutore-marketplace');
    }
}
