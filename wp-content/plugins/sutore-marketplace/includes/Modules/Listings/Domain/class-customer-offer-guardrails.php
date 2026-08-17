<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

use SutoreMarketplace\Shared\Settings\Settings;

final class CustomerOfferGuardrails
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('customer_offer_enabled', true);
    }

    public static function ttlHours(): int
    {
        return max(1, min(168, (int) Settings::get('customer_offer_ttl_hours', 48)));
    }

    public static function minPercent(): int
    {
        return max(1, min(99, (int) Settings::get('customer_offer_min_percent', 70)));
    }

    public static function maxPerDay(): int
    {
        return max(1, min(50, (int) Settings::get('customer_offer_max_per_day', 10)));
    }

    public static function minBidForAsking(float $asking): int
    {
        $step = Settings::listingPriceStep();
        $raw = $asking * (self::minPercent() / 100);

        return max($step, ListingPriceValidator::roundDownToStep($raw, $step));
    }
}
