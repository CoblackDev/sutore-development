<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Services\CustomerOfferService;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
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
            'customer_offer_max_per_day' => 50,
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
            'customer_offer_max_per_day' => 50,
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
            'customer_offer_max_per_day' => 50,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer3');
            $listing = Fixtures::listing(Fixtures::sellerVerified(), $catalog['parent_id'], $catalog['size_term_id'], 200);
            $result = (new CustomerOfferService())->create(Fixtures::customer(), (int) $listing->variationId, 50);
            Harness::assertWpError($result, '50 TL is below 70% of 200');
        });
    }

    public function testBidAtOrAboveAskingRejected(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
            'customer_offer_max_per_day' => 50,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer4');
            $listing = Fixtures::listing(Fixtures::sellerVerified(), $catalog['parent_id'], $catalog['size_term_id'], 200);
            $service = new CustomerOfferService();
            Harness::assertWpError(
                $service->create(Fixtures::customer(), (int) $listing->variationId, 200),
                'bid equal to asking must be rejected'
            );
            Harness::assertWpError(
                $service->create(Fixtures::customer(), (int) $listing->variationId, 225),
                'bid above asking must be rejected'
            );
        });
    }

    public function testDuplicatePendingOfferRejected(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
            'customer_offer_max_per_day' => 50,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer5');
            $listing = Fixtures::listing(Fixtures::sellerVerified(), $catalog['parent_id'], $catalog['size_term_id'], 200);
            $customer = Fixtures::customer();
            $service = new CustomerOfferService();
            $created = $service->create($customer, (int) $listing->variationId, 150);
            Harness::assertNotWpError($created);
            $again = $service->create($customer, (int) $listing->variationId, 175);
            Harness::assertWpError($again, 'second pending offer on the same size must be rejected');
        });
    }

    public function testAcceptNotifiesCustomer(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
            'customer_offer_max_per_day' => 50,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer6');
            $seller = Fixtures::sellerVerified();
            $customer = Fixtures::customer();
            $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
            $service = new CustomerOfferService();
            $created = $service->create($customer, (int) $listing->variationId, 150);
            Harness::assertNotWpError($created);
            /** @var array{offer_id:int} $created */
            wp_set_current_user($seller);
            Harness::assertNotWpError($service->accept((int) $created['offer_id'], $seller));

            $feed = (new NotificationService())->feedForUser($customer, 1, 50);
            $found = false;
            foreach ($feed['items'] as $item) {
                if (str_contains((string) ($item['type'] ?? ''), 'offer_accepted')) {
                    $found = true;
                    break;
                }
            }
            Harness::assertTrue($found, 'customer must get an accepted-offer panel notification');
        });
    }

    public function testDeclineWithoutQueueNotifiesCustomer(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
            'customer_offer_max_per_day' => 50,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer7');
            $seller = Fixtures::sellerVerified();
            $customer = Fixtures::customer();
            $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
            $service = new CustomerOfferService();
            $created = $service->create($customer, (int) $listing->variationId, 150);
            Harness::assertNotWpError($created);
            /** @var array{offer_id:int} $created */
            Harness::assertNotWpError($service->decline((int) $created['offer_id'], $seller));

            $feed = (new NotificationService())->feedForUser($customer, 1, 50);
            $found = false;
            foreach ($feed['items'] as $item) {
                if (str_contains((string) ($item['type'] ?? ''), 'offer_declined')) {
                    $found = true;
                    break;
                }
            }
            Harness::assertTrue($found, 'customer must get a declined-offer panel notification when no next seller exists');
        });
    }

    public function testDeclineForwardNotifiesCustomer(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
            'customer_offer_max_per_day' => 50,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer8');
            $winner = Fixtures::listing(Fixtures::sellerVerified(), $catalog['parent_id'], $catalog['size_term_id'], 200);
            Fixtures::listing(Fixtures::sellerQueued(), $catalog['parent_id'], $catalog['size_term_id'], 250);
            $customer = Fixtures::customer();
            $service = new CustomerOfferService();
            $created = $service->create($customer, (int) $winner->variationId, 150);
            Harness::assertNotWpError($created);
            /** @var array{offer_id:int} $created */
            Harness::assertNotWpError($service->decline((int) $created['offer_id'], Fixtures::sellerVerified()));

            $feed = (new NotificationService())->feedForUser($customer, 1, 50);
            $found = false;
            foreach ($feed['items'] as $item) {
                if (str_contains((string) ($item['type'] ?? ''), 'offer_forwarded')) {
                    $found = true;
                    break;
                }
            }
            Harness::assertTrue($found, 'customer must be told the offer was forwarded, not dead');
        });
    }

    public function testCancelledOfferCannotBeAccepted(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
            'customer_offer_max_per_day' => 50,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer9');
            $seller = Fixtures::sellerVerified();
            $customer = Fixtures::customer();
            $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
            $service = new CustomerOfferService();
            $created = $service->create($customer, (int) $listing->variationId, 150);
            Harness::assertNotWpError($created);
            /** @var array{offer_id:int} $created */
            Harness::assertNotWpError($service->cancel((int) $created['offer_id'], $customer));

            $row = (new \SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository())
                ->find((int) $created['offer_id']);
            Harness::assertTrue($row !== null);
            Harness::assertSame(CustomerOfferStatus::CANCELLED, (string) $row->status);

            wp_set_current_user($seller);
            Harness::assertWpError(
                $service->accept((int) $created['offer_id'], $seller),
                'cancelled offers must not be accepted'
            );

            $fresh = (new \SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository())
                ->find((int) $created['offer_id']);
            Harness::assertSame(CustomerOfferStatus::CANCELLED, (string) $fresh->status);
        });
    }

    public function testUnansweredOfferAutoDeclines(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
            'customer_offer_max_per_day' => 50,
            'customer_offer_auto_decline_hours' => 1,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer10');
            $seller = Fixtures::sellerVerified();
            $customer = Fixtures::customer();
            $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
            $service = new CustomerOfferService();
            $created = $service->create($customer, (int) $listing->variationId, 150);
            Harness::assertNotWpError($created);
            /** @var array{offer_id:int} $created */

            $repo = new \SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository();
            $repo->update((int) $created['offer_id'], ['expires_at' => '2000-01-01 00:00:00']);

            $n = $service->runExpiryPass(50);
            Harness::assertTrue($n >= 1, 'expiry pass should close the unanswered offer');

            $row = $repo->find((int) $created['offer_id']);
            Harness::assertSame(CustomerOfferStatus::DECLINED, (string) $row->status);

            wp_set_current_user($seller);
            Harness::assertWpError(
                $service->accept((int) $created['offer_id'], $seller),
                'auto-declined offers must not be accepted'
            );
        });
    }

    public function testUnansweredOfferAutoDeclineForwards(): void
    {
        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 25,
            'customer_offer_enabled' => true,
            'customer_offer_min_percent' => 70,
            'customer_offer_max_per_day' => 50,
            'customer_offer_auto_decline_hours' => 1,
        ], static function (): void {
            $catalog = Fixtures::catalog('offer11');
            $winner = Fixtures::listing(Fixtures::sellerVerified(), $catalog['parent_id'], $catalog['size_term_id'], 200);
            Fixtures::listing(Fixtures::sellerQueued(), $catalog['parent_id'], $catalog['size_term_id'], 250);
            $customer = Fixtures::customer();
            $service = new CustomerOfferService();
            $created = $service->create($customer, (int) $winner->variationId, 150);
            Harness::assertNotWpError($created);
            /** @var array{offer_id:int} $created */

            $repo = new \SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository();
            $repo->update((int) $created['offer_id'], ['expires_at' => '2000-01-01 00:00:00']);
            $service->runExpiryPass(50);

            $origin = $repo->find((int) $created['offer_id']);
            Harness::assertSame(CustomerOfferStatus::DECLINED, (string) $origin->status);

            $rows = $repo->findForMerchant(Fixtures::sellerQueued(), CustomerOfferStatus::PENDING);
            $forwarded = false;
            foreach ($rows as $row) {
                if ((int) $row->origin_offer_id === (int) $created['offer_id']) {
                    $forwarded = true;
                    break;
                }
            }
            Harness::assertTrue($forwarded, 'timeout should forward the bid to the queued seller');
        });
    }
}
