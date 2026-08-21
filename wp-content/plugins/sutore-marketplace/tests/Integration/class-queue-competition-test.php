<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
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

    public function testPendingNormalDoesNotLockVitrineFromVerified(): void
    {
        Fixtures::withMarketplaceSettings(['listing_price_step' => 25], static function (): void {
            $catalog = Fixtures::catalog('queue3');
            $normalCheap = Fixtures::listing(
                Fixtures::sellerNormal(),
                $catalog['parent_id'],
                $catalog['size_term_id'],
                100,
                ['duration_days' => 30]
            );
            $verifiedExpensive = Fixtures::listing(
                Fixtures::sellerVerified(),
                $catalog['parent_id'],
                $catalog['size_term_id'],
                200
            );

            Fixtures::assertStatus(
                (int) $normalCheap->variationId,
                ListingStatus::PENDING,
                'normal cheapest awaits approval without locking vitrine'
            );
            Harness::assertFalse(
                Fixtures::reloadListing((int) $normalCheap->variationId)->isWinner,
                'pending approval candidate is not the vitrine winner'
            );
            Fixtures::assertStatus(
                (int) $verifiedExpensive->variationId,
                ListingStatus::PUBLISH,
                'verified occupies vitrine while cheaper normal is pending'
            );
            Harness::assertTrue(Fixtures::reloadListing((int) $verifiedExpensive->variationId)->isWinner);

            $approved = (new ListingSelector())->approvePendingWinner((int) $normalCheap->variationId);
            Harness::assertNotWpError($approved);
            Fixtures::assertStatus((int) $normalCheap->variationId, ListingStatus::PUBLISH);
            Harness::assertTrue(Fixtures::reloadListing((int) $normalCheap->variationId)->isWinner);
            Fixtures::assertStatus((int) $verifiedExpensive->variationId, ListingStatus::QUEUED);

            $rival = Fixtures::listing(
                Fixtures::sellerPremium(),
                $catalog['parent_id'],
                $catalog['size_term_id'],
                150
            );
            Fixtures::assertStatus(
                (int) $normalCheap->variationId,
                ListingStatus::PUBLISH,
                'approved listing stays on vitrine when a more expensive rival appears'
            );
            Harness::assertTrue(Fixtures::reloadListing((int) $normalCheap->variationId)->isWinner);
            Harness::assertTrue(
                Fixtures::reloadListing((int) $normalCheap->variationId)->approvedAt !== null
                && Fixtures::reloadListing((int) $normalCheap->variationId)->approvedAt !== ''
            );
            Fixtures::assertStatus((int) $rival->variationId, ListingStatus::QUEUED);
            Fixtures::assertStatus((int) $verifiedExpensive->variationId, ListingStatus::QUEUED);
        });
    }
}
