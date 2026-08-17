<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Services;

final class AddressFormatter
{
    public static function billing(\WC_Order $order): string
    {
        return self::fromParts(
            (string) $order->get_billing_address_1(),
            (string) $order->get_billing_address_2(),
            (string) $order->get_billing_city(),
            (string) $order->get_billing_state(),
            (string) $order->get_billing_postcode(),
            (string) $order->get_billing_country()
        );
    }

    public static function fromParts(
        string $address1,
        string $address2,
        string $city,
        string $stateCode,
        string $postcode,
        string $country = 'TR'
    ): string {
        $states = function_exists('WC') && WC()->countries
            ? WC()->countries->get_states($country !== '' ? $country : 'TR')
            : [];
        $state = (is_array($states) && isset($states[$stateCode])) ? (string) $states[$stateCode] : $stateCode;

        return implode(', ', array_filter([
            $address1,
            $address2,
            $city,
            $state,
            $postcode,
        ], static fn (string $part): bool => trim($part) !== ''));
    }
}
