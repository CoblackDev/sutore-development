<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Modules\Sourcing\Services\SourcingService;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class SourcingFlowTest
{
    public function testPreOrderAcceptSwapsOrderOntoReplacement(): void
    {
        $catalog = Fixtures::catalog('src1');
        $originalSeller = Fixtures::sellerVerified();
        $acceptor = Fixtures::sellerPremium();
        $original = Fixtures::listing($originalSeller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        $order = Fixtures::soldFromPublish($original, Fixtures::customer());
        $id = (int) $original->variationId;

        $fs = new FulfillmentService();
        wp_set_current_user(Fixtures::adminId());
        Harness::assertNotWpError($fs->markAsPreOrder($id, 'staff'));
        Fixtures::assertStatus($id, ListingStatus::PRE_ORDER);

        $replacement = Fixtures::listing($acceptor, $catalog['parent_id'], $catalog['size_term_id'], 200);
        wp_set_current_user($acceptor);
        $accepted = (new SourcingService())->accept($id, $acceptor, (int) $replacement->variationId, false);
        Harness::assertNotWpError($accepted);
        /** @var array{variation_id:int} $accepted */

        $fresh = Fixtures::reloadListing((int) $accepted['variation_id']);
        Harness::assertSame((int) $order->get_id(), (int) $fresh->orderId);
        Harness::assertTrue(ListingStatus::isSaleActive($fresh->listingStatus) || $fresh->listingStatus === ListingStatus::SOLD);
        Fixtures::assertStatus($id, ListingStatus::ORDER_DETACHED);
    }

    public function testConfirmedSellerSeesBackdatedStaffPreOrderOnly(): void
    {
        $catalog = Fixtures::catalog('src2');
        $originSeller = Fixtures::sellerPremium();
        $viewer = Fixtures::sellerVerified();
        $original = Fixtures::listing($originSeller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        Fixtures::listing($viewer, $catalog['parent_id'], $catalog['size_term_id'], 300);
        Fixtures::soldFromPublish($original, Fixtures::customer());
        $id = (int) $original->variationId;

        wp_set_current_user(Fixtures::adminId());
        Harness::assertNotWpError((new FulfillmentService())->markAsPreOrder($id, 'staff'));
        Fixtures::assertStatus($id, ListingStatus::PRE_ORDER);

        $presenter = new \SutoreMarketplace\Modules\Sourcing\Services\SourcingFeedPresenter();
        $freshFeed = $presenter->presentForMerchant($viewer, 1, 30);
        $freshIds = array_map(static fn (array $row): int => (int) $row['id'], $freshFeed['items']);
        Harness::assertTrue(!in_array($id, $freshIds, true), 'Confirmed seller must wait the early-access window');

        $created = function_exists('current_datetime')
            ? current_datetime()
            : new \DateTimeImmutable('now', wp_timezone());
        $backdated = $created->modify('-36 hours')->format('Y-m-d H:i:s');
        (new \SutoreMarketplace\Modules\Listings\Repositories\ListingRepository())->update($id, [
            'created_at' => $backdated,
        ]);

        $readyFeed = $presenter->presentForMerchant($viewer, 1, 30);
        $readyIds = array_map(static fn (array $row): int => (int) $row['id'], $readyFeed['items']);
        Harness::assertTrue(in_array($id, $readyIds, true), 'Backdated staff pre-order must appear for Confirmed');
    }
}
