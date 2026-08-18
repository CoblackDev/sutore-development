<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Modules\Listings\Services\CatalogProductRequestService;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class CatalogRequestTest
{
    public function testConfirmedSellerCanRequestAndStaffCanFulfill(): void
    {
        $sku = 'NOT-IN-CATALOG-' . wp_generate_password(6, false);
        $service = new CatalogProductRequestService();
        $sellerId = Fixtures::sellerVerified();
        wp_set_current_user($sellerId);
        $created = $service->create($sellerId, [
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

        $item = self::latestNotificationMatching($sellerId, 'catalog_request');
        Harness::assertTrue($item !== null, 'seller must get a catalog-added panel notification');
        Harness::assertTrue(
            str_contains((string) ($item['type'] ?? ''), 'fulfilled'),
            'fulfill notification type: ' . wp_json_encode($item)
        );
        Harness::assertTrue(
            str_contains((string) ($item['body'] ?? ''), $sku) || str_contains((string) ($item['title'] ?? ''), 'catalog'),
            'fulfill body should mention the product: ' . wp_json_encode($item)
        );
    }

    public function testStaffRejectNotifiesSeller(): void
    {
        $sku = 'REJECT-ME-' . wp_generate_password(6, false);
        $service = new CatalogProductRequestService();
        $sellerId = Fixtures::sellerPremium();
        wp_set_current_user($sellerId);
        $created = $service->create($sellerId, [
            'sku_or_link' => $sku,
            'size_note' => '43',
        ]);
        Harness::assertNotWpError($created);
        $id = (int) ($created['item']['id'] ?? 0);
        Harness::assertGreaterThan(0, $id);

        $note = 'Not a catalog fit.';
        wp_set_current_user(Fixtures::adminId());
        $done = $service->reject($id, Fixtures::adminId(), ['staff_note' => $note]);
        Harness::assertNotWpError($done);

        $item = self::latestNotificationMatching($sellerId, 'catalog_request');
        Harness::assertTrue($item !== null, 'seller must get a catalog-declined panel notification');
        Harness::assertTrue(
            str_contains((string) ($item['type'] ?? ''), 'rejected'),
            'reject notification type: ' . wp_json_encode($item)
        );
        Harness::assertSame($note, (string) ($item['body'] ?? ''));
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

    /** @return array<string, mixed>|null */
    private static function latestNotificationMatching(int $userId, string $needle): ?array
    {
        $feed = (new NotificationService())->feedForUser($userId, 1, 50);
        foreach ($feed['items'] as $item) {
            $type = (string) ($item['type'] ?? '');
            if (str_contains($type, $needle)) {
                return $item;
            }
        }

        return null;
    }
}
