<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Listings\Domain\ListingCapabilities;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Shared\Security\SecretBox;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class SecurityHardeningTest
{
    public function testSecretBoxRoundTripAndTamperReject(): void
    {
        $sealed = SecretBox::seal('netgsm-secret-value');
        Harness::assertTrue(str_starts_with($sealed, 'enc:v2:'), 'v2 prefix');
        Harness::assertSame('netgsm-secret-value', SecretBox::open($sealed));

        $tampered = substr($sealed, 0, -4) . 'XXXX';
        Harness::assertSame('', SecretBox::open($tampered), 'tampered ciphertext must fail open');

        $kept = SecretBox::resolveForSave('', $sealed);
        Harness::assertSame($sealed, $kept, 'empty submit keeps existing');
    }

    public function testMerchantRoleLosesNativeProductCaps(): void
    {
        ListingCapabilities::reconcileMerchantRole();
        $role = get_role('merchant');
        Harness::assertTrue($role !== null);
        Harness::assertTrue($role->has_cap(ListingCapabilities::MANAGE_OWN));
        Harness::assertTrue(!$role->has_cap('edit_products'));
        Harness::assertTrue(!$role->has_cap('upload_files'));
    }

    public function testStaffCapabilitiesSplitSettingsFromOps(): void
    {
        \SutoreMarketplace\Admin\StaffCapabilities::reconcile();
        $admin = get_role('administrator');
        $shop = get_role('shop_manager');
        Harness::assertTrue($admin !== null && $admin->has_cap(\SutoreMarketplace\Admin\StaffCapabilities::MANAGE_SETTINGS));
        Harness::assertTrue($admin !== null && $admin->has_cap(\SutoreMarketplace\Admin\StaffCapabilities::MANAGE_PAYOUTS));
        if ($shop) {
            Harness::assertTrue($shop->has_cap(\SutoreMarketplace\Admin\StaffCapabilities::MANAGE_OPS));
            Harness::assertTrue(!$shop->has_cap(\SutoreMarketplace\Admin\StaffCapabilities::MANAGE_SETTINGS));
        }
    }

    public function testInvoiceStoragePrefersPrivateContentDir(): void
    {
        $storage = new \SutoreMarketplace\Modules\Invoices\Services\InvoiceStorage();
        $dir = $storage->directory();
        Harness::assertTrue(!is_wp_error($dir), 'invoice dir created');
        Harness::assertTrue(str_contains((string) $dir, 'sutore-private'), 'private path');
        Harness::assertTrue(!str_contains((string) $dir, 'uploads'), 'not under uploads');
    }

    public function testOutboundEffectSmsCompletesInSimulation(): void
    {
        Fixtures::withMarketplaceSettings([
            'sms_simulation_mode' => true,
        ], static function (): void {
            $id = (new \SutoreMarketplace\Shared\Effects\OutboundEffectService())->enqueue(
                \SutoreMarketplace\Shared\Effects\OutboundEffectType::SMS,
                ['phone' => '5551112233', 'message' => 'Hello effect'],
                'test-sms-' . wp_generate_uuid4()
            );
            Harness::assertTrue($id > 0);
            $row = (new \SutoreMarketplace\Shared\Effects\OutboundEffectRepository())->find($id);
            Harness::assertTrue($row !== null);
            Harness::assertSame(
                \SutoreMarketplace\Shared\Effects\OutboundEffectStatus::DONE,
                (string) $row->status
            );
        });
    }

    public function testSchemaVersionIsProductionBaseline(): void
    {
        Harness::assertTrue(\SutoreMarketplace\Shared\Database\Schema::VERSION >= 101);
        Harness::assertTrue(in_array('outbound_effects', \SutoreMarketplace\Shared\Database\Schema::tableSuffixes(), true));
        Harness::assertTrue(in_array('customer_offer_daily_counters', \SutoreMarketplace\Shared\Database\Schema::tableSuffixes(), true));
    }

    public function testOfferDailySlotIsAtomic(): void
    {
        \SutoreMarketplace\Shared\Database\Schema::install();
        $repo = new \SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository();
        $customer = Fixtures::user('st_offer_cap_' . wp_generate_password(6, false), 'customer');
        Harness::assertTrue($repo->tryConsumeDailySlot($customer, 2));
        Harness::assertTrue($repo->tryConsumeDailySlot($customer, 2));
        Harness::assertTrue(!$repo->tryConsumeDailySlot($customer, 2), 'third slot must fail at cap 2');
        $repo->releaseDailySlot($customer);
        Harness::assertTrue($repo->tryConsumeDailySlot($customer, 2), 'release frees a slot');
    }

    public function testMerchantProfileSealsIbanAndTckno(): void
    {
        $seller = Fixtures::sellerVerified();
        $repo = new \SutoreMarketplace\Modules\Merchants\Repositories\MerchantProfileRepository();
        $profile = $repo->readProfile($seller);
        $profile[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_IBAN] = Fixtures::VALID_IBAN;
        $profile[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_TCKNO] = Fixtures::VALID_TC;
        $repo->upsert($seller, $profile);

        global $wpdb;
        $raw = $wpdb->get_row($wpdb->prepare(
            'SELECT account_iban, account_tckno FROM ' . $repo->table() . ' WHERE user_id = %d',
            $seller
        ), ARRAY_A);
        Harness::assertTrue(is_array($raw));
        Harness::assertTrue(
            \SutoreMarketplace\Shared\Security\SecretBox::isSealed((string) $raw['account_iban']),
            'iban sealed at rest'
        );
        Harness::assertTrue(
            \SutoreMarketplace\Shared\Security\SecretBox::isSealed((string) $raw['account_tckno']),
            'tckno sealed at rest'
        );
        $opened = $repo->readProfile($seller);
        Harness::assertSame(Fixtures::VALID_IBAN, $opened[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_IBAN]);
        Harness::assertSame(Fixtures::VALID_TC, $opened[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_TCKNO]);
    }

    public function testFulfillmentAdvanceStatusIsCas(): void
    {
        $catalog = Fixtures::catalog('ffcas');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        $id = (int) $listing->variationId;
        (new ListingRepository())->update($id, [
            'listing_status' => ListingStatus::CONFIRMED,
            'order_id' => 910001,
            'order_item_id' => 1,
        ]);

        $repo = new \SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository();
        $ok = $repo->updateIfStatus($id, ListingStatus::CONFIRMED, [
            'fulfillment_status' => ListingStatus::SHIPPED_TO_SUTORE,
        ]);
        Harness::assertTrue($ok);
        $lose = $repo->updateIfStatus($id, ListingStatus::CONFIRMED, [
            'fulfillment_status' => ListingStatus::SHIPPED_TO_SUTORE,
        ]);
        Harness::assertTrue(!$lose, 'second advance from same status must lose');
        Fixtures::assertStatus($id, ListingStatus::SHIPPED_TO_SUTORE);
    }

    public function testListingClaimIsAtomicWinnerTakesAll(): void
    {
        $catalog = Fixtures::catalog('claim1');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        $id = (int) $listing->variationId;

        // Force publish so claimable.
        (new ListingRepository())->update($id, ['listing_status' => ListingStatus::PUBLISH]);

        $bridge = new ListingOrderBridge();
        $a = $bridge->markSold($id, 900001, 1);
        Harness::assertNotWpError($a);
        Fixtures::assertStatus($id, ListingStatus::SOLD);

        $b = $bridge->markSold($id, 900002, 2);
        Harness::assertWpError($b, 'second order must lose claim');
        $fresh = (new ListingRepository())->find($id);
        Harness::assertTrue($fresh !== null && (int) $fresh->orderId === 900001);
    }

    public function testCsvFormulaPrefixNeutralized(): void
    {
        $svc = new \SutoreMarketplace\Modules\Merchants\Services\PayoutExportService();
        $ref = new \ReflectionClass($svc);
        $method = $ref->getMethod('csvCell');
        $method->setAccessible(true);
        Harness::assertSame("'=HYPERLINK(\"http://x\")", $method->invoke($svc, '=HYPERLINK("http://x")'));
        Harness::assertSame("'+cmd", $method->invoke($svc, '+cmd'));
        Harness::assertSame('safe', $method->invoke($svc, 'safe'));
    }
}
