<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Hooks;

use SutoreMarketplace\Modules\Coupons\Module;

final class CheckoutUiHooks
{
    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(): void
    {
        if (!function_exists('is_cart') || (!is_cart() && !is_checkout())) {
            return;
        }

        Module::enqueueCouponStyle();
    }
}
