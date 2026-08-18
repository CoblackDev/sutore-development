<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class QueueCompetitionTest
{
    public function testCheapestAskingWinsEvenIfDamaged(): void
    {
        Fixtures::withMarketplaceSettings(['listing_price_step' => 25], static function (): void {
            $catalog = Fixtures::catalog('queue1');
            $cheap = Fixtures::listing(
                Fixtures::sellerPremium(),
                $catalog['parent_id'],
                $catalog['size_term_id'],
                100,
                ['damaged' => 1]
            );
            $expensive = Fixtures::listing(
                Fixtures::sellerVerified(),
                $catalog['parent_id'],
                $catalog['size_term_id'],
                250
            );

            Fixtures::assertStatus((int) $cheap->variationId, ListingStatus::PUBLISH, 'damaged cheapest must win');
            Fixtures::assertStatus((int) $expensive->variationId, ListingStatus::QUEUED, 'flawless expensive waits');
            Harness::assertTrue(Fixtures::reloadListing((int) $cheap->variationId)->isWinner);
            Harness::assertFalse(Fixtures::reloadListing((int) $expensive->variationId)->isWinner);
        });
    }

    public function testRemovingWinnerPromotesQueue(): void
    {
        Fixtures::withMarketplaceSettings(['listing_price_step' => 25], static function (): void {
            $catalog = Fixtures::catalog('queue2');
            $winner = Fixtures::listing(Fixtures::sellerPremium(), $catalog['parent_id'], $catalog['size_term_id'], 100);
            $queued = Fixtures::listing(Fixtures::sellerVerified(), $catalog['parent_id'], $catalog['size_term_id'], 200);
            Fixtures::assertStatus((int) $queued->variationId, ListingStatus::QUEUED);

            wp_set_current_user(Fixtures::sellerPremium());
            $removed = (new \SutoreMarketplace\Modules\Listings\Services\ListingService())
                ->removeFromSale((int) $winner->variationId, Fixtures::sellerPremium());
            Harness::assertNotWpError($removed);
            Fixtures::assertStatus((int) $queued->variationId, ListingStatus::PUBLISH, 'queued listing should win after remove');
        });
    }
}
