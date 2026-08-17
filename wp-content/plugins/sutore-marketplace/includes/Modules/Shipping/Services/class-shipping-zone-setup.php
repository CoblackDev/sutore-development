<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Services;

use SutoreMarketplace\Modules\Shipping\Methods\SutoreShippingMethod;

/**
 * Ensures the full native WC shipping zone layout the marketplace expects.
 *
 * Zone layout (native WC):
 * - Turkey (TR) → free / fast / express
 * - Cyprus (CY) → cyprus only
 * - Rest of the World (0) → international / fallback
 *
 * Every dedicated zone plus Rest of the World carries the Sutore shipping method;
 * competing WC Free Shipping is disabled so the native UI stays single-source.
 */
final class ShippingZoneSetup
{
    /** Bump when zone installation logic changes so ensure() re-runs. */
    private const OPTION_KEY = 'sutore_marketplace_shipping_zone_v4';

    public static function ensure(): void
    {
        if (!class_exists('WooCommerce') || !class_exists('WC_Shipping_Zone') || !class_exists('WC_Shipping_Zones')) {
            return;
        }

        if (get_option(self::OPTION_KEY) === 'done') {
            return;
        }

        self::ensureCountryZone('TR', __('Turkey', 'sutore-marketplace'), 0);
        self::ensureCountryZone('CY', __('Cyprus', 'sutore-marketplace'), 1);
        self::ensureMethodOnZone(new \WC_Shipping_Zone(0));

        foreach (\WC_Shipping_Zones::get_zones() as $zoneData) {
            $zoneId = (int) ($zoneData['id'] ?? $zoneData['zone_id'] ?? 0);
            if ($zoneId <= 0) {
                continue;
            }
            $zone = new \WC_Shipping_Zone($zoneId);
            self::ensureMethodOnZone($zone);
            self::disableCompetingFreeShipping($zone);
        }

        self::disableCompetingFreeShipping(new \WC_Shipping_Zone(0));

        update_option(self::OPTION_KEY, 'done', false);
    }

    /**
     * Reuse an existing zone that already targets the country, otherwise create it.
     */
    private static function ensureCountryZone(string $countryCode, string $zoneName, int $zoneOrder): void
    {
        $countryCode = strtoupper($countryCode);

        foreach (\WC_Shipping_Zones::get_zones() as $zoneData) {
            foreach ($zoneData['zone_locations'] ?? [] as $location) {
                $code = strtoupper((string) ($location->code ?? $location['code'] ?? ''));
                $type = (string) ($location->type ?? $location['type'] ?? '');
                if ($type === 'country' && $code === $countryCode) {
                    $zoneId = (int) ($zoneData['id'] ?? $zoneData['zone_id'] ?? 0);
                    if ($zoneId > 0) {
                        self::ensureMethodOnZone(new \WC_Shipping_Zone($zoneId));
                    }

                    return;
                }
            }
        }

        $zone = new \WC_Shipping_Zone();
        $zone->set_zone_name($zoneName);
        $zone->set_zone_order($zoneOrder);
        $zone->save();
        $zone->add_location($countryCode, 'country');
        $zone->save();
        self::ensureMethodOnZone($zone);
    }

    private static function ensureMethodOnZone(\WC_Shipping_Zone $zone): void
    {
        foreach ($zone->get_shipping_methods(true) as $method) {
            if ($method instanceof SutoreShippingMethod || $method->id === 'sutore_marketplace') {
                return;
            }
        }

        $zone->add_shipping_method('sutore_marketplace');
    }

    /**
     * Hide WC Free Shipping when Sutore method is present so native UI stays single-source.
     */
    private static function disableCompetingFreeShipping(\WC_Shipping_Zone $zone): void
    {
        $hasSutore = false;
        foreach ($zone->get_shipping_methods(true) as $method) {
            if ($method->id === 'sutore_marketplace') {
                $hasSutore = true;
                break;
            }
        }
        if (!$hasSutore) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}woocommerce_shipping_zone_methods",
            ['is_enabled' => 0],
            [
                'zone_id' => (int) $zone->get_id(),
                'method_id' => 'free_shipping',
            ],
            ['%d'],
            ['%d', '%s']
        );
    }
}
