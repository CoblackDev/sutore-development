<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Services;

/**
 * Persists checkout shipment choice via WooCommerce native chosen_shipping_methods.
 */
final class ChosenShipment
{
    private const METHOD_ID = 'sutore_marketplace';

    public static function get(): string
    {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }

        $chosen = (array) WC()->session->get('chosen_shipping_methods', []);
        $rateId = (string) ($chosen[0] ?? '');
        if ($rateId === '') {
            return '';
        }

        $type = self::parseShipmentType($rateId);
        if ($type === '') {
            return '';
        }

        foreach (RateResolver::checkoutRates() as $rate) {
            if ($rate['id'] === $type) {
                return $type;
            }
        }

        return '';
    }

    public static function choose(string $method): string
    {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }

        $resolved = RateResolver::resolveCheckoutMethod($method);
        $rateId = self::buildRateId($resolved['id']);
        $chosen = (array) WC()->session->get('chosen_shipping_methods', []);
        $chosen[0] = $rateId;
        WC()->session->set('chosen_shipping_methods', $chosen);

        return $resolved['id'];
    }

    public static function ensureDefault(): string
    {
        $current = self::get();
        if ($current !== '') {
            return self::choose($current);
        }

        $rates = RateResolver::checkoutRates();
        if ($rates === []) {
            return '';
        }

        return self::choose($rates[0]['id']);
    }

    public static function buildRateId(string $shipmentType): string
    {
        return self::METHOD_ID . ':' . self::instanceId() . ':' . sanitize_key($shipmentType);
    }

    public static function parseShipmentType(string $rateId): string
    {
        if (!str_starts_with($rateId, self::METHOD_ID . ':')) {
            return '';
        }

        $parts = explode(':', $rateId);
        if (count($parts) < 3) {
            return '';
        }

        return sanitize_key((string) end($parts));
    }

    private static function instanceId(): int
    {
        if (!class_exists('WC_Shipping_Zones') || !class_exists('WC_Shipping_Zone')) {
            return 0;
        }

        $package = [
            'destination' => [
                'country' => RateResolver::destinationCountry(),
                'state' => RateResolver::destinationState(),
                'postcode' => function_exists('WC') && WC()->customer
                    ? (WC()->customer->get_shipping_postcode() ?: WC()->customer->get_billing_postcode())
                    : '',
                'city' => function_exists('WC') && WC()->customer
                    ? (WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city())
                    : '',
            ],
        ];
        $zone = \WC_Shipping_Zones::get_zone_matching_package($package);
        $instance = self::instanceOnZone($zone);
        if ($instance > 0) {
            return $instance;
        }

        foreach (\WC_Shipping_Zones::get_zones() as $zoneData) {
            $zoneId = (int) ($zoneData['id'] ?? $zoneData['zone_id'] ?? 0);
            if ($zoneId <= 0) {
                continue;
            }
            $instance = self::instanceOnZone(new \WC_Shipping_Zone($zoneId));
            if ($instance > 0) {
                return $instance;
            }
        }

        return self::instanceOnZone(new \WC_Shipping_Zone(0));
    }

    private static function instanceOnZone(\WC_Shipping_Zone $zone): int
    {
        foreach ($zone->get_shipping_methods(true) as $method) {
            if ($method->id === self::METHOD_ID && $method->is_enabled()) {
                return (int) $method->instance_id;
            }
        }

        return 0;
    }
}
