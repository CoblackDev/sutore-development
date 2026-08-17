<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Merchants\Services\RestrictionService;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class ListingLifecycleTest
{
    public function testVerifiedSellerListingGoesOnSale(): void
    {
        $catalog = Fixtures::catalog('life1');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        $fresh = Fixtures::reloadListing((int) $listing->variationId);
        Harness::assertSame(ListingStatus::PUBLISH, $fresh->listingStatus);
        Harness::assertTrue($fresh->isWinner);
        Harness::assertEqualsFloat(200.0, $fresh->asking);
    }

    public function testNormalSellerStaysPending(): void
    {
        $catalog = Fixtures::catalog('life2');
        $seller = Fixtures::sellerNormal();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200, [
            'duration_days' => 30,
        ]);
        Fixtures::assertStatus((int) $listing->variationId, ListingStatus::PENDING);
    }

    public function testRemoveFromSaleAndPutBack(): void
    {
        $catalog = Fixtures::catalog('life3');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        $service = new ListingService();
        wp_set_current_user($seller);
        $removed = $service->removeFromSale((int) $listing->variationId, $seller);
        Harness::assertNotWpError($removed);
        Fixtures::assertStatus((int) $listing->variationId, ListingStatus::NOT_SALE);

        $back = $service->putOnSale((int) $listing->variationId, $seller);
        Harness::assertNotWpError($back);
        $status = Fixtures::listingStatus((int) $listing->variationId);
        Harness::assertTrue(
            in_array($status, [ListingStatus::PUBLISH, ListingStatus::PENDING, ListingStatus::QUEUED], true),
            'put on sale status: ' . $status
        );
    }

    public function testInvalidPriceRejected(): void
    {
        Fixtures::withMarketplaceSettings(['listing_price_step' => 25], static function (): void {
            $catalog = Fixtures::catalog('life4');
            $seller = Fixtures::sellerVerified();
            wp_set_current_user($seller);
            $result = (new ListingService())->create([
                'parent_product_id' => $catalog['parent_id'],
                'size_term_id' => $catalog['size_term_id'],
                'asking' => 10,
            ], $seller);
            Harness::assertWpError($result, 'step 25 should reject 10 TL');
        });
    }

    public function testListingCreateBanBlocksSeller(): void
    {
        $catalog = Fixtures::catalog('life5');
        $seller = Fixtures::user('st_seller_banned_tmp', 'merchant');
        $ban = (new RestrictionService())->create([
            'merchant_id' => $seller,
            'restriction_key' => 'listing_create_ban',
            'reason' => 'automated test',
        ], Fixtures::adminId());
        Harness::assertNotWpError($ban, 'restriction create');
        wp_set_current_user($seller);
        $result = (new ListingService())->create([
            'parent_product_id' => $catalog['parent_id'],
            'size_term_id' => $catalog['size_term_id'],
            'asking' => 200,
        ], $seller);
        Harness::assertWpError($result, 'banned seller must not create');
    }
}
