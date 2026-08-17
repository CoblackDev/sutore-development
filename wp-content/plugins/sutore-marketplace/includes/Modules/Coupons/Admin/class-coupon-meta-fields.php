<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Admin;

use SutoreMarketplace\Modules\Coupons\Services\BrandCampaignRegistry;
use SutoreMarketplace\Modules\Coupons\Support\CouponMeta;

final class CouponMetaFields
{
    public function register(): void
    {
        add_action('woocommerce_coupon_options_usage_restriction', [$this, 'render'], 20);
        add_action('woocommerce_coupon_options_save', [$this, 'save'], 10, 2);
        add_action('save_post_shop_coupon', static function (): void {
            BrandCampaignRegistry::flush();
        });
    }

    public function render(): void
    {
        global $post;

        if (!$post instanceof \WP_Post) {
            return;
        }

        $coupon = new \WC_Coupon((int) $post->ID);

        echo '<div class="options_group sutore-mp-coupon-brand-campaign">';
        echo '<p class="form-field"><strong>' . esc_html__('Sutore brand campaign', 'sutore-marketplace') . '</strong></p>';
        echo '<p class="description">' . esc_html__(
            'Select the brand restriction from the “Product brands” field above. Discount type must be percentage. Cart notices and the minimum quantity rule are managed in this section.',
            'sutore-marketplace'
        ) . '</p>';

        woocommerce_wp_checkbox([
            'id' => CouponMeta::BRAND_CAMPAIGN,
            'label' => __('Brand campaign coupon', 'sutore-marketplace'),
            'description' => __('When enabled, cart progress notification and the minimum brand quantity rule are applied.', 'sutore-marketplace'),
            'value' => CouponMeta::isBrandCampaign($coupon) ? 'yes' : 'no',
        ]);

        woocommerce_wp_text_input([
            'id' => CouponMeta::MIN_BRAND_QTY,
            'label' => __('Minimum brand product quantity', 'sutore-marketplace'),
            'description' => __('Minimum total quantity of selected brands required in the cart.', 'sutore-marketplace'),
            'desc_tip' => true,
            'type' => 'number',
            'value' => (string) max(1, CouponMeta::minBrandQty($coupon)),
            'custom_attributes' => [
                'min' => '1',
                'step' => '1',
            ],
        ]);

        woocommerce_wp_text_input([
            'id' => CouponMeta::NOTICE_PRIORITY,
            'label' => __('Cart notice priority', 'sutore-marketplace'),
            'description' => __('Lower numbers are shown first.', 'sutore-marketplace'),
            'desc_tip' => true,
            'type' => 'number',
            'value' => (string) CouponMeta::noticePriority($coupon),
            'custom_attributes' => [
                'min' => '0',
                'step' => '1',
            ],
        ]);

        woocommerce_wp_text_input([
            'id' => CouponMeta::NOTICE_COLOR,
            'label' => __('Cart notice color', 'sutore-marketplace'),
            'description' => __('Progress bar and left border color (hex).', 'sutore-marketplace'),
            'desc_tip' => true,
            'type' => 'text',
            'value' => CouponMeta::noticeColor($coupon),
            'placeholder' => '#4caf50',
        ]);

        echo '</div>';
    }

    public function save(int $postId, \WC_Coupon $coupon): void
    {
        unset($coupon);

        $enabled = isset($_POST[CouponMeta::BRAND_CAMPAIGN]) ? 'yes' : 'no';
        update_post_meta($postId, CouponMeta::BRAND_CAMPAIGN, $enabled);

        $minQty = max(1, (int) ($_POST[CouponMeta::MIN_BRAND_QTY] ?? 1));
        update_post_meta($postId, CouponMeta::MIN_BRAND_QTY, $minQty);

        $priority = max(0, (int) ($_POST[CouponMeta::NOTICE_PRIORITY] ?? 0));
        update_post_meta($postId, CouponMeta::NOTICE_PRIORITY, $priority);

        $color = sanitize_hex_color((string) ($_POST[CouponMeta::NOTICE_COLOR] ?? ''));
        update_post_meta($postId, CouponMeta::NOTICE_COLOR, $color !== null && $color !== '' ? $color : '#222222');

        BrandCampaignRegistry::flush();
    }
}
