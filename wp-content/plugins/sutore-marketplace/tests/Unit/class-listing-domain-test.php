<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingCampaignPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingConditionRank;
use SutoreMarketplace\Modules\Listings\Domain\ListingDuration;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Tests\Support\Harness;

final class ListingDomainTest
{
    public function testCheapestAskingWinsRegardlessOfCondition(): void
    {
        $damaged = $this->listing(100, '2026-01-01 00:00:00', ['damaged' => '1']);
        $flawless = $this->listing(150, '2026-01-01 00:00:00', []);
        $ranked = ListingConditionRank::sortForSale([$flawless, $damaged]);
        Harness::assertSame(100.0, $ranked[0]->asking);
        Harness::assertTrue(ListingConditionRank::hasDefect($damaged));
        Harness::assertTrue(ListingConditionRank::isFlawless($flawless));
    }

    public function testOlderListingWinsAskingTie(): void
    {
        $older = $this->listing(200, '2026-01-01 00:00:00');
        $newer = $this->listing(200, '2026-06-01 00:00:00');
        $ranked = ListingConditionRank::sortForSale([$newer, $older]);
        Harness::assertSame('2026-01-01 00:00:00', $ranked[0]->createdAt);
    }

    public function testAllowedDurations(): void
    {
        Harness::assertTrue(ListingDuration::isAllowed(45));
        Harness::assertFalse(ListingDuration::isAllowed(3));
        Harness::assertSame(7, ListingDuration::clampToAllowed(40, [7, 30, 45]));
    }

    public function testCampaignOfferLocksUpdates(): void
    {
        $offer = $this->listing(200, '2026-01-01 00:00:00', [], 'offer');
        $active = $this->listing(200, '2026-01-01 00:00:00', [], 'active');
        $none = $this->listing(200, '2026-01-01 00:00:00', [], 'none');
        Harness::assertTrue(ListingCampaignPolicy::blocksAllUpdates($offer));
        Harness::assertFalse(ListingCampaignPolicy::allowsAskingIncrease($active));
        Harness::assertTrue(ListingCampaignPolicy::allowsAskingIncrease($none));
        Harness::assertFalse(ListingCampaignPolicy::blocksAllUpdates($none));
    }

    /**
     * @param array<string, string> $conditions
     */
    private function listing(float $asking, string $created, array $conditions = [], string $campaign = 'none'): Listing
    {
        return new Listing(
            variationId: random_int(100000, 999999),
            parentProductId: 1,
            sizeTermId: 1,
            merchantId: 1,
            listingStatus: ListingStatus::PUBLISH,
            asking: $asking,
            conditionFingerprint: $conditions === [] ? '' : 'x',
            campaignStatus: $campaign,
            createdAt: $created,
            conditions: $conditions,
        );
    }
}
