<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons;

use SutoreMarketplace\Modules\Coupons\Admin\CouponMetaFields;
use SutoreMarketplace\Modules\Coupons\Hooks\CartNoticeHooks;
use SutoreMarketplace\Modules\Coupons\Hooks\CheckoutUiHooks;
use SutoreMarketplace\Modules\Coupons\Hooks\CouponValidationHooks;
use SutoreMarketplace\Modules\Coupons\Rest\AdminCouponsController;

final class Module
{
    public static function boot(): void
    {
        (new CouponValidationHooks())->register();
        (new CartNoticeHooks())->register();
        (new CheckoutUiHooks())->register();
        (new AdminCouponsController())->register();

        if (is_admin()) {
            (new CouponMetaFields())->register();
        }
    }

    public static function registerCouponStyle(): void
    {
        wp_register_style(
            'sutore-marketplace-coupons',
            SUTORE_MARKETPLACE_URL . 'assets/css/checkout-coupon.css',
            [],
            SUTORE_MARKETPLACE_VERSION
        );
    }

    public static function enqueueCouponStyle(): void
    {
        self::registerCouponStyle();
        wp_enqueue_style('sutore-marketplace-coupons');
    }
}
