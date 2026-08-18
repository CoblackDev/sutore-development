<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferGuardrails;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class CustomerOfferGuardrailsTest
{
    public function testMinBidIsSeventyPercentRoundedToStep(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_min_percent' => 70,
        ], static function (): void {
            $min = CustomerOfferGuardrails::minBidForAsking(200);
            Harness::assertSame(125, $min);
            Harness::assertTrue($min % 25 === 0);
            Harness::assertTrue($min < 200);
        });
    }

    public function testMaxBidIsAskingMinusOneStep(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
        ], static function (): void {
            $max = CustomerOfferGuardrails::maxBidForAsking(200);
            Harness::assertSame(175, $max);
            Harness::assertTrue($max < 200);
            Harness::assertTrue($max % 25 === 0);
        });
    }

    public function testTtlAndDailyCapBounds(): void
    {
        Fixtures::withMarketplaceSettings([
            'customer_offer_ttl_hours' => 48,
            'customer_offer_auto_decline_hours' => 24,
            'customer_offer_max_per_day' => 10,
        ], static function (): void {
            Harness::assertSame(48, CustomerOfferGuardrails::ttlHours());
            Harness::assertSame(24, CustomerOfferGuardrails::autoDeclineHours());
            Harness::assertSame(10, CustomerOfferGuardrails::maxPerDay());
        });
    }
}
