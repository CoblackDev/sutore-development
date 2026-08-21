<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\OutletOptinStatus;
use SutoreMarketplace\Modules\Listings\Repositories\OutletOptinRepository;
use SutoreMarketplace\Modules\Listings\Services\OutletService;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class OutletFlowTest
{
    public function testWindowPublishOptInCreatesLockedListing(): void
    {
        $catalog = Fixtures::catalog('out1');
        $seller = Fixtures::sellerVerified();
        $service = new OutletService();
        $windowId = $service->createWindow([
            'name' => 'Test outlet ' . wp_generate_password(4, false),
            'starts_at' => wp_date('Y-m-d H:i:s', time() - 60),
            'ends_at' => wp_date('Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS),
        ]);
        Harness::assertNotWpError($windowId);
        /** @var int $windowId */

        $itemId = $service->addItem($windowId, [
            'parent_product_id' => $catalog['parent_id'],
            'size_term_id' => $catalog['size_term_id'],
            'customer_sale' => 400,
            'seller_net' => 300,
        ]);
        Harness::assertNotWpError($itemId);
        /** @var int $itemId */

        Harness::assertNotWpError($service->publish($windowId));
        wp_set_current_user($seller);
        $opt = $service->optIn($itemId, $seller);
        Harness::assertNotWpError($opt);
        /** @var array{variation_id:?int,listing_created:bool} $opt */
        Harness::assertTrue(!empty($opt['variation_id']), 'outlet listing should be created on live window');

        $listing = Fixtures::reloadListing((int) $opt['variation_id']);
        Harness::assertEqualsFloat(300.0, $listing->asking);
        Harness::assertEqualsFloat(0.0, (float) ($listing->commissionPercent ?? -1), 'outlet listing commission must be 0');
        $net = \SutoreMarketplace\Shared\Domain\MarketplacePricing::netFromAsking(
            (float) $listing->asking,
            (float) ($listing->commissionPercent ?? 0)
        );
        Harness::assertEqualsFloat(300.0, $net, 'seller net must equal asking when commission is 0');
        Harness::assertTrue(in_array($listing->listingStatus, [ListingStatus::PUBLISH, ListingStatus::PENDING], true));

        $optin = (new OutletOptinRepository())->findForItemMerchant($itemId, $seller);
        Harness::assertTrue($optin instanceof \SutoreMarketplace\Modules\Listings\Domain\OutletOptin);
        Harness::assertTrue(in_array($optin->status, [OutletOptinStatus::LIVE, OutletOptinStatus::PENDING], true));

        Harness::assertNotWpError($service->endWindow($windowId));
    }
}
