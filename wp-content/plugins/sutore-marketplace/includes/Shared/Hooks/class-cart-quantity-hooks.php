<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Shared\Settings\Settings;

final class CartQuantityHooks
{
    public function register(): void
    {
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateCartQuantity'], 10, 3);
    }

    public function validateCartQuantity(bool $valid, int $productId, int $quantity): bool
    {
        if (!$valid || !function_exists('WC') || !WC()->cart) {
            return $valid;
        }

        $max = Settings::cartMaxQuantity();
        $current = (int) WC()->cart->get_cart_contents_count();

        if ($current >= $max) {
            wc_add_notice(
                sprintf(
                    __('You can purchase a maximum of %d items in a single order.', 'sutore-marketplace'),
                    $max
                ),
                'error'
            );

            return false;
        }

        return $valid;
    }
}
