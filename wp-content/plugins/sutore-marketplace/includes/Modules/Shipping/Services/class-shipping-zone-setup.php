<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Services;

use SutoreMarketplace\Modules\Shipping\Methods\SutoreShippingMethod;

/**
 * Ensures Sutore shipping method exists on relevant zones, including a dedicated Cyprus zone.
 *
 * Zone layout (native WC):
 * - Türkiye (TR) → free / fast / express
 * - Kıbrıs (CY) → cyprus only
 * - Rest of the World (0) → international / fallback (no cyprus)
 */
final class ShippingZoneSetup
{
    /** Bump when zone installation logic changes so ensure() re-runs. */
    private const OPTION_KEY = 'sutore_marketplace_shipping_zone_v3';

    public static function ensure(): void
    {
        if (!class_exists('WooCommerce') || !class_exists('WC_Shipping_Zone')) {
            return;
        }

        if (get_option(self::OPTION_KEY) === 'done') {
            return;
        }

        self::ensureMethodOnZone(new \WC_Shipping_Zone(0));
        self::ensureCyprusZone();

        if (class_exists('WC_Shipping_Zones')) {
            foreach (\WC_Shipping_Zones::get_zones() as $zoneData) {
                $zoneId = (int) ($zoneData['id'] ?? $zoneData['zone_id'] ?? 0);
                if ($zoneId <= 0) {
                    continue;
                }
                self::ensureMethodOnZone(new \WC_Shipping_Zone($zoneId));
                self::disableCompetingFreeShipping(new \WC_Shipping_Zone($zoneId));
            }
        }

        self::disableCompetingFreeShipping(new \WC_Shipping_Zone(0));

        update_option(self::OPTION_KEY, 'done', false);
    }

    private static function ensureCyprusZone(): void
    {
        if (!class_exists('WC_Shipping_Zones')) {
            return;
        }

        foreach (\WC_Shipping_Zones::get_zones() as $zoneData) {
            $locations = $zoneData['zone_locations'] ?? [];
            foreach ($locations as $location) {
                $code = strtoupper((string) ($location->code ?? $location['code'] ?? ''));
                $type = (string) ($location->type ?? $location['type'] ?? '');
                if ($type === 'country' && $code === 'CY') {
                    $zoneId = (int) ($zoneData['id'] ?? $zoneData['zone_id'] ?? 0);
                    if ($zoneId > 0) {
                        self::ensureMethodOnZone(new \WC_Shipping_Zone($zoneId));
                    }

                    return;
                }
            }
        }

        $zone = new \WC_Shipping_Zone();
        $zone->set_zone_name(__('Cyprus', 'sutore-marketplace'));
        $zone->set_zone_order(1);
        $zone->save();
        $zone->add_location('CY', 'country');
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
