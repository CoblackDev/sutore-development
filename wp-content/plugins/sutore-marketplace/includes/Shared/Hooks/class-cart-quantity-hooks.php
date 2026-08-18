<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Shared\Settings\Settings;

final class CartQuantityHooks
{
    public function register(): void
    {
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateCartQuantity'], 10, 3);
        add_filter('woocommerce_quantity_input_args', [$this, 'filterProductPageQuantity'], 20, 2);
    }

    /**
     * Product pages always add quantity 1 — the stepper is hidden in CSS.
     *
     * @param array<string, mixed> $args
     * @param \WC_Product $product
     * @return array<string, mixed>
     */
    public function filterProductPageQuantity(array $args, $product): array
    {
        unset($product);
        if (!is_product()) {
            return $args;
        }

        $args['min_value'] = 1;
        $args['max_value'] = 1;
        $args['input_value'] = 1;

        return $args;
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
