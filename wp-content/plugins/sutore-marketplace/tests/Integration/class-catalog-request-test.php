<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Modules\Listings\Services\CatalogProductRequestService;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class CatalogRequestTest
{
    public function testConfirmedSellerCanRequestAndStaffCanFulfill(): void
    {
        $sku = 'NOT-IN-CATALOG-' . wp_generate_password(6, false);
        $service = new CatalogProductRequestService();
        wp_set_current_user(Fixtures::sellerVerified());
        $created = $service->create(Fixtures::sellerVerified(), [
            'sku_or_link' => $sku,
            'size_note' => '42',
            'note' => 'automated test',
        ]);
        Harness::assertNotWpError($created);
        /** @var array{id?:int,request?:array} $created */
        $id = (int) ($created['item']['id'] ?? 0);
        Harness::assertGreaterThan(0, $id, 'request id: ' . wp_json_encode($created));

        wp_set_current_user(Fixtures::adminId());
        $done = $service->fulfill($id, Fixtures::adminId(), []);
        Harness::assertNotWpError($done);
    }

    public function testNewSellerCannotRequest(): void
    {
        wp_set_current_user(Fixtures::sellerNormal());
        $result = (new CatalogProductRequestService())->create(Fixtures::sellerNormal(), [
            'sku_or_link' => 'ZZZ-MISSING',
            'size_note' => '42',
        ]);
        Harness::assertWpError($result, 'New level cannot request catalog products');
    }

    public function testMerchantCanCancelPending(): void
    {
        $service = new CatalogProductRequestService();
        $created = $service->create(Fixtures::sellerPremium(), [
            'sku_or_link' => 'CANCEL-ME-' . wp_generate_password(4, false),
            'size_note' => '43',
        ]);
        Harness::assertNotWpError($created);
        $id = (int) ($created['item']['id'] ?? 0);
        Harness::assertGreaterThan(0, $id);
        $cancel = $service->cancel($id, Fixtures::sellerPremium());
        Harness::assertNotWpError($cancel);
        unset($cancel);
        Harness::assertTrue(CatalogProductRequestStatus::isValid(CatalogProductRequestStatus::CANCELLED));
    }
}
