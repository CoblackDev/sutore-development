<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Settings\Settings;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class MarketplacePricingTest
{
    public function testGuvenceIsPercentOfAsking(): void
    {
        Fixtures::withMarketplaceSettings([
            'assurance_fee_percent' => 10,
            'service_fee_amount' => 50,
        ], static function (): void {
            Harness::assertEqualsFloat(20.0, MarketplacePricing::guvenceFeeForAsking(200));
            Harness::assertEqualsFloat(0.0, MarketplacePricing::guvenceFeeForAsking(0));
            Harness::assertEqualsFloat(270.0, MarketplacePricing::listingComparePrice(200));
        });
    }

    public function testNetFromAskingSubtractsCommissionOnly(): void
    {
        Harness::assertEqualsFloat(88.0, MarketplacePricing::netFromAsking(100, 12));
        Harness::assertEqualsFloat(10.0, MarketplacePricing::netFromAsking(10, 0));
    }

    public function testCampaignAcceptMathCapsPlatformWaiverByFees(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'service_fee_amount' => 40,
            'assurance_fee_percent' => 0,
        ], static function (): void {
            $math = MarketplacePricing::campaignAcceptMath(200, 25, 999);
            Harness::assertEqualsFloat(175.0, $math['asking_effective']);
            Harness::assertEqualsFloat(40.0, $math['platform_waiver']);
            Harness::assertEqualsFloat(175.0, $math['customer_sale']);
        });
    }

    public function testResolvePercentCampaign(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'service_fee_amount' => 100,
            'assurance_fee_percent' => 0,
            'campaign_discount_min_percent' => 10,
            'campaign_discount_max_percent' => 40,
        ], static function (): void {
            $math = MarketplacePricing::resolveCampaignOfferMath(
                200,
                CampaignDiscountType::PERCENT,
                10,
                CampaignDiscountType::PERCENT,
                50
            );
            Harness::assertEqualsFloat(175.0, $math['asking_effective']);
            Harness::assertTrue($math['platform_waiver'] <= 100.0);
        });
    }
}
