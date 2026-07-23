<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Settings;

use SutoreMarketplace\Shared\Settings\Settings;

final class ShippingSettings
{
    public static function fastShippingFee(): float
    {
        return max(0.0, (float) Settings::get('checkout_fast_shipping_fee', 0));
    }

    public static function expressBaseFee(): float
    {
        return max(0.0, (float) Settings::get('checkout_express_base_fee', 0));
    }

    public static function expressPerItemSurcharge(): float
    {
        return max(0.0, (float) Settings::get('checkout_express_per_item_surcharge', 200));
    }

    public static function internationalFee(): float
    {
        return max(0.0, (float) Settings::get('checkout_international_fee', 1500));
    }

    public static function cyprusFee(): float
    {
        return max(0.0, (float) Settings::get('checkout_cyprus_fee', 600));
    }

    public static function fastCampaignPrice(): float
    {
        return max(0.0, (float) Settings::get('checkout_fast_campaign_price', 395));
    }

    public static function fastCampaignActive(): bool
    {
        return (bool) Settings::get('checkout_fast_campaign_active', false);
    }

    public static function expressEverywhereEnabled(): bool
    {
        return (bool) Settings::get('checkout_express_everywhere_enabled', false);
    }

    public static function freeFastCartThreshold(): int
    {
        return max(1, (int) Settings::get('checkout_free_fast_cart_threshold', 4));
    }

    public static function etaDays(string $method): int
    {
        $map = (array) Settings::get('checkout_eta_days', self::defaultEtaDays());

        return max(0, (int) ($map[$method] ?? self::defaultEtaDays()[$method] ?? 0));
    }

    /** @return array<string, int> */
    public static function defaultEtaDays(): array
    {
        return [
            'free' => 8,
            'fast' => 6,
            'express' => 1,
            'international' => 12,
            'cyprus' => 10,
            'imported_free' => 17,
        ];
    }
}
