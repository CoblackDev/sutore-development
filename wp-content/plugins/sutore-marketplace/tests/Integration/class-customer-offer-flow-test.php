<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Services\CustomerOfferService;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class CustomerOfferFlowTest
{
    public function testAcceptCreatesCouponWithoutChangingVitrine(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer1');
            $seller = Fixtures::sellerVerified();
            $customer = Fixtures::customer();
            $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
            Fixtures::assertStatus((int) $listing->variationId, ListingStatus::PUBLISH);
            $askingBefore = MarketplacePricing::activeAsking($listing);

            $service = new CustomerOfferService();
            $created = $service->create($customer, (int) $listing->variationId, 150);
            Harness::assertNotWpError($created);
            /** @var array{offer_id:int} $created */

            wp_set_current_user($seller);
            $accepted = $service->accept((int) $created['offer_id'], $seller);
            Harness::assertNotWpError($accepted);
            $fresh = Fixtures::reloadListing((int) $listing->variationId);
            Harness::assertEqualsFloat($askingBefore, MarketplacePricing::activeAsking($fresh), 'vitrine asking must stay');
            Harness::assertSame('none', $fresh->campaignStatus);

            $own = $service->create($seller, (int) $listing->variationId, 150);
            Harness::assertWpError($own, 'cannot bid on own listing');
        });
    }

    public function testDeclineForwardsToQueuedSeller(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer2');
            $winner = Fixtures::listing(Fixtures::sellerVerified(), $catalog['parent_id'], $catalog['size_term_id'], 200);
            $queued = Fixtures::listing(Fixtures::sellerQueued(), $catalog['parent_id'], $catalog['size_term_id'], 250);
            Fixtures::assertStatus((int) $queued->variationId, ListingStatus::QUEUED);

            $service = new CustomerOfferService();
            $created = $service->create(Fixtures::customer(), (int) $winner->variationId, 150);
            Harness::assertNotWpError($created);
            /** @var array{offer_id:int} $created */
            Harness::assertNotWpError($service->decline((int) $created['offer_id'], Fixtures::sellerVerified()));

            $rows = (new \SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository())
                ->findForMerchant(Fixtures::sellerQueued(), CustomerOfferStatus::PENDING);
            Harness::assertTrue($rows !== [], 'decline should forward the bid to the queued seller');
        });
    }

    public function testBidBelowMinimumRejected(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer3');
            $listing = Fixtures::listing(Fixtures::sellerVerified(), $catalog['parent_id'], $catalog['size_term_id'], 200);
            $result = (new CustomerOfferService())->create(Fixtures::customer(), (int) $listing->variationId, 50);
            Harness::assertWpError($result, '50 TL is below 70% of 200');
        });
    }
}
