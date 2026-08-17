<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class FulfillmentPipelineTest
{
    public function testPaymentConfirmThenMerchantShipThenHub(): void
    {
        $catalog = Fixtures::catalog('sale1');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        $id = (int) $listing->variationId;
        $fs = new FulfillmentService();

        Fixtures::withOrderSettings(['require_admin_payment_confirm' => true], static function () use ($listing, $id, $seller, $fs): void {
            Fixtures::paidOrder(Fixtures::customer(), $id);
            Fixtures::assertStatus($id, ListingStatus::PAYMENT, 'admin confirm required');

            wp_set_current_user(Fixtures::adminId());
            Harness::assertNotWpError($fs->adminConfirmPayment($id));
            Fixtures::assertStatus($id, ListingStatus::SOLD);

            wp_set_current_user($seller);
            Harness::assertNotWpError($fs->merchantConfirmSale($id, $seller));
            Fixtures::assertStatus($id, ListingStatus::CONFIRMED);

            Harness::assertWpError($fs->merchantSubmitShipment($id, $seller, 'bad'), 'invalid tracking');
            Harness::assertNotWpError($fs->merchantSubmitShipment($id, $seller, Fixtures::TRACK_SELLER));
            Fixtures::assertStatus($id, ListingStatus::SHIPPED_TO_SUTORE);

            wp_set_current_user(Fixtures::adminId());
            Harness::assertNotWpError($fs->markArrivedAtSutore($id));
            Harness::assertNotWpError($fs->markVerified($id));
            Harness::assertNotWpError($fs->markReadyToShip($id));
            Harness::assertNotWpError($fs->markShippedToCustomer($id, [
                'sutore_shipment_code' => Fixtures::TRACK_SUTORE,
            ]));
            Harness::assertNotWpError($fs->markDeliveredToCustomer($id));
            Fixtures::assertStatus($id, ListingStatus::DELIVERED_TO_CUSTOMER);

            Harness::assertNotWpError($fs->markMerchantPayout($id, 'TEST-EFT-001'));
        });
    }

    public function testCannotSkipSoldToArrived(): void
    {
        $catalog = Fixtures::catalog('sale2');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        Fixtures::soldFromPublish($listing, Fixtures::customer());
        $fs = new FulfillmentService();
        wp_set_current_user(Fixtures::adminId());
        Harness::assertWpError($fs->markArrivedAtSutore((int) $listing->variationId));
        Harness::assertWpError($fs->markMerchantPayout((int) $listing->variationId, 'EARLY'));
    }
}
