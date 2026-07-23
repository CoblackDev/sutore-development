<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping;

use SutoreMarketplace\Modules\Shipping\Hooks\CheckoutHooks;
use SutoreMarketplace\Modules\Shipping\Methods\SutoreShippingMethod;
use SutoreMarketplace\Modules\Shipping\Services\ShippingZoneSetup;

final class Module
{
    public static function boot(): void
    {
        add_filter('woocommerce_shipping_methods', [self::class, 'registerShippingMethod']);
        add_action('woocommerce_init', [ShippingZoneSetup::class, 'ensure']);

        (new CheckoutHooks())->register();
    }

    /** @param array<string, string> $methods */
    public static function registerShippingMethod(array $methods): array
    {
        $methods['sutore_marketplace'] = SutoreShippingMethod::class;

        return $methods;
    }
}
