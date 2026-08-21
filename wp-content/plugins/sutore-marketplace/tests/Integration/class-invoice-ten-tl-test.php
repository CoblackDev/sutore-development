<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind;
use SutoreMarketplace\Modules\Invoices\Domain\InvoiceStatus;
use SutoreMarketplace\Modules\Invoices\Repositories\InvoiceRepository;
use SutoreMarketplace\Modules\Invoices\Services\InvoiceIssuer;
use SutoreMarketplace\Modules\Invoices\Services\YouthInvoiceAllocator;
use SutoreMarketplace\Modules\Invoices\Settings\InvoiceSettings;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Merchants\Services\PayoutLineService;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Shared\Services\YouthDiscount;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class InvoiceTenTlTest
{
    public function testCustomerInvoiceHasHizmetAndGuvenceLines(): void
    {
        $invoices = InvoiceSettings::all();
        $invoices['enabled'] = true;
        $invoices['vat_rate'] = 20;

        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 10,
            'service_fee_amount' => 1,
            'assurance_fee_percent' => 10,
            'invoices' => $invoices,
        ], static function (): void {
            $catalog = Fixtures::catalog('inv1');
            $listing = Fixtures::listing(
                Fixtures::sellerVerified(),
                $catalog['parent_id'],
                $catalog['size_term_id'],
                10
            );
            $order = Fixtures::soldFromPublish($listing, Fixtures::customer());
            Fixtures::assertStatus((int) $listing->variationId, ListingStatus::SOLD);
            $fresh = Fixtures::reloadListing((int) $listing->variationId);

            $allocated = (new YouthInvoiceAllocator())->forListing($fresh, $order);
            Harness::assertEqualsFloat(1.0, $allocated['hizmet'], 'hizmet is 1 TL');
            Harness::assertEqualsFloat(1.0, $allocated['guvence'], 'guvence is 10% of 10 TL asking');

            $repo = new InvoiceRepository();
            $customer = $repo->findByKindAndOrder(InvoiceKind::CUSTOMER_FEES, (int) $order->get_id());
            Harness::assertTrue($customer === null, 'customer_fees is not queued on sold');

            Fixtures::advanceSoldToVerified((int) $listing->variationId, (int) $listing->merchantId);
            $fresh = Fixtures::reloadListing((int) $listing->variationId);

            $customer = $repo->findByKindAndOrder(InvoiceKind::CUSTOMER_FEES, (int) $order->get_id());
            $seller = $repo->findByKindAndVariation(InvoiceKind::SELLER_COMMISSION, (int) $listing->variationId);
            Harness::assertTrue($customer !== null, 'customer_fees invoice queued when remaining items are verified');
            Harness::assertSame(InvoiceKind::ORDER_SCOPE_VARIATION, (int) $customer->variation_id, 'customer invoice is order-scoped');
            Harness::assertTrue($seller === null, 'seller commission is not queued until payout is paid');
            Harness::assertEqualsFloat(1.0, (float) $customer->hizmet_amount);
            Harness::assertEqualsFloat(1.0, (float) $customer->guvence_amount);
            Harness::assertEqualsFloat(0.0, (float) $customer->commission_amount);
            Harness::assertEqualsFloat(2.0, (float) $customer->total_amount);
            $customerLines = InvoiceRepository::decodeLines($customer);
            Harness::assertSame(2, count($customerLines), 'customer invoice has hizmet + guvence lines');
            Harness::assertSame('hizmet', $customerLines[0]['code']);
            Harness::assertSame('guvence', $customerLines[1]['code']);

            $paidPayout = new PayoutLineService();
            $saleRow = (new FulfillmentRepository())->find((int) $listing->variationId);
            Harness::assertTrue($saleRow !== null, 'sale row for payout');
            $paidPayout->createForListing($saleRow, $fresh);
            $paid = $paidPayout->markPaid((int) $listing->variationId, Fixtures::adminId(), 'test-1tl');
            Harness::assertNotWpError($paid, 'mark payout paid');
            $seller = $repo->findByKindAndVariation(InvoiceKind::SELLER_COMMISSION, (int) $listing->variationId);
            Harness::assertTrue($seller !== null, 'seller commission queued when payout is marked paid');
            Harness::assertEqualsFloat(0.0, (float) $seller->hizmet_amount);
            Harness::assertEqualsFloat(0.0, (float) $seller->guvence_amount);
            Harness::assertGreaterThan(0.0, (float) $seller->commission_amount, 'seller invoice is commission only');

            if (!InvoiceSettings::enabled()) {
                Harness::skip('Paraşüt credentials are not configured; fee split passed without issuing');
            }

            $apiBase = \SutoreMarketplace\Modules\Invoices\Services\ParasutClient::apiBase();
            if (str_contains($apiBase, 'sutore-parasut-test.invalid')) {
                Harness::skip('Paraşüt live issue skipped — test suite blocks production API host');
            }

            $customer = self::issueUntilPdf((int) $customer->id);
            Harness::assertGreaterThan(0, strlen((string) $customer->parasut_invoice_id), 'customer Parasut invoice');
            Harness::assertTrue((string) $customer->pdf_path !== '' && is_readable((string) $customer->pdf_path), 'customer PDF');

            $seller = self::issueUntilPdf((int) $seller->id);
            Harness::assertGreaterThan(0, strlen((string) $seller->parasut_invoice_id), 'seller Parasut invoice');
            Harness::assertTrue((string) $seller->pdf_path !== '' && is_readable((string) $seller->pdf_path), 'seller PDF');
        });
    }

    public function testYouthDiscountHitsHizmetFirstOnOneTlFees(): void
    {
        $invoices = InvoiceSettings::all();
        $invoices['enabled'] = false;

        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 10,
            'service_fee_amount' => 1,
            'assurance_fee_percent' => 10,
            'invoices' => $invoices,
        ], static function (): void {
            $catalog = Fixtures::catalog('invy1');
            $listing = Fixtures::listing(Fixtures::sellerVerified(), $catalog['parent_id'], $catalog['size_term_id'], 10);
            $order = Fixtures::soldFromPublish($listing, Fixtures::customer());
            $order->update_meta_data(YouthDiscount::ORDER_META_AMOUNT, '0.40');
            $order->save();
            $fresh = Fixtures::reloadListing((int) $listing->variationId);
            $allocated = (new YouthInvoiceAllocator())->forListing($fresh, wc_get_order($order->get_id()));
            Harness::assertEqualsFloat(0.6, $allocated['hizmet'], 'youth 0.40 TL cuts 1 TL hizmet first');
            Harness::assertEqualsFloat(1.0, $allocated['guvence'], 'guvence untouched after 0.40 youth');
        });
    }

    public function testMultiListingOrderSharesOneCustomerInvoice(): void
    {
        $invoices = InvoiceSettings::all();
        $invoices['enabled'] = true;
        $invoices['vat_rate'] = 20;

        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 10,
            'service_fee_amount' => 1,
            'assurance_fee_percent' => 10,
            'invoices' => $invoices,
        ], static function (): void {
            $seller = Fixtures::sellerVerified();
            $catalogA = Fixtures::catalog('inv2a');
            $catalogB = Fixtures::catalog('inv2b');
            $listingA = Fixtures::listing($seller, $catalogA['parent_id'], $catalogA['size_term_id'], 10);
            $listingB = Fixtures::listing($seller, $catalogB['parent_id'], $catalogB['size_term_id'], 10);

            $order = Fixtures::withOrderSettings(['require_admin_payment_confirm' => false], static function () use ($listingA, $listingB) {
                return Fixtures::paidOrderWithVariations(
                    Fixtures::customer(),
                    [(int) $listingA->variationId, (int) $listingB->variationId]
                );
            });

            $repo = new InvoiceRepository();
            $customer = $repo->findByKindAndOrder(InvoiceKind::CUSTOMER_FEES, (int) $order->get_id());
            Harness::assertTrue($customer === null, 'no customer invoice while items are still sold');

            Fixtures::advanceSoldToVerified((int) $listingA->variationId, (int) $listingA->merchantId);
            $customer = $repo->findByKindAndOrder(InvoiceKind::CUSTOMER_FEES, (int) $order->get_id());
            Harness::assertTrue($customer === null, 'no customer invoice while a sibling item is still open');

            Fixtures::advanceSoldToVerified((int) $listingB->variationId, (int) $listingB->merchantId);
            $customer = $repo->findByKindAndOrder(InvoiceKind::CUSTOMER_FEES, (int) $order->get_id());
            Harness::assertTrue($customer !== null, 'one customer invoice per order');
            Harness::assertSame(InvoiceKind::ORDER_SCOPE_VARIATION, (int) $customer->variation_id);
            Harness::assertEqualsFloat(2.0, (float) $customer->hizmet_amount, '1 TL hizmet x 2 products');
            Harness::assertEqualsFloat(2.0, (float) $customer->guvence_amount, '1 TL guvence x 2 products');
            Harness::assertEqualsFloat(4.0, (float) $customer->total_amount);
            $lines = InvoiceRepository::decodeLines($customer);
            Harness::assertSame(4, count($lines), 'two products x hizmet + guvence');
            $perListing = $repo->findByKindAndVariation(InvoiceKind::CUSTOMER_FEES, (int) $listingA->variationId);
            Harness::assertTrue(
                $perListing === null || (int) $perListing->id === (int) $customer->id,
                'no extra per-listing customer invoice'
            );
        });
    }

    public function testDroppedSiblingIsOmittedFromCustomerInvoice(): void
    {
        $invoices = InvoiceSettings::all();
        $invoices['enabled'] = true;
        $invoices['vat_rate'] = 20;

        Fixtures::withMarketplaceSettings([
            'listing_price_step' => 10,
            'service_fee_amount' => 1,
            'assurance_fee_percent' => 10,
            'invoices' => $invoices,
        ], static function (): void {
            $seller = Fixtures::sellerVerified();
            $catalogA = Fixtures::catalog('inv3a');
            $catalogB = Fixtures::catalog('inv3b');
            $listingA = Fixtures::listing($seller, $catalogA['parent_id'], $catalogA['size_term_id'], 10);
            $listingB = Fixtures::listing($seller, $catalogB['parent_id'], $catalogB['size_term_id'], 10);

            $order = Fixtures::withOrderSettings(['require_admin_payment_confirm' => false], static function () use ($listingA, $listingB) {
                return Fixtures::paidOrderWithVariations(
                    Fixtures::customer(),
                    [(int) $listingA->variationId, (int) $listingB->variationId]
                );
            });

            $repo = new InvoiceRepository();
            Fixtures::advanceSoldToVerified((int) $listingA->variationId, (int) $listingA->merchantId);
            Harness::assertTrue(
                $repo->findByKindAndOrder(InvoiceKind::CUSTOMER_FEES, (int) $order->get_id()) === null,
                'still waiting on the unsourced sibling'
            );

            wp_set_current_user(Fixtures::adminId());
            $dropped = (new \SutoreMarketplace\Modules\Orders\Services\FulfillmentService())->markNotForSale(
                (int) $listingB->variationId,
                ['staff_note' => 'test drop before invoice']
            );
            Harness::assertNotWpError($dropped, 'drop open sibling');

            $customer = $repo->findByKindAndOrder(InvoiceKind::CUSTOMER_FEES, (int) $order->get_id());
            Harness::assertTrue($customer !== null, 'customer invoice after remaining items settled');
            Harness::assertEqualsFloat(1.0, (float) $customer->hizmet_amount, 'only the verified product is billed');
            Harness::assertEqualsFloat(1.0, (float) $customer->guvence_amount);
            Harness::assertEqualsFloat(2.0, (float) $customer->total_amount);
            $lines = InvoiceRepository::decodeLines($customer);
            Harness::assertSame(2, count($lines), 'dropped product is omitted');
            foreach ($lines as $line) {
                Harness::assertSame((int) $listingA->variationId, (int) $line['variation_id']);
            }
        });
    }

    private static function issueUntilPdf(int $id): object
    {
        $issuer = new InvoiceIssuer();
        $repo = new InvoiceRepository();
        $deadline = time() + 180;
        $last = $repo->find($id);
        while (time() < $deadline) {
            $issuer->processOne($id);
            $last = $repo->find($id);
            if ($last && (string) ($last->pdf_path ?? '') !== '' && is_readable((string) $last->pdf_path)) {
                return $last;
            }
            if ($last && (string) $last->status === InvoiceStatus::ERROR && (string) ($last->parasut_invoice_id ?? '') === '') {
                break;
            }
            sleep(3);
        }

        Harness::assertTrue($last !== null);
        if ((string) ($last->parasut_invoice_id ?? '') === '') {
            throw new \SutoreMarketplace\Tests\Support\Failed(
                'Paraşüt invoice was not created. status=' . ($last->status ?? '')
                . ' error=' . ($last->last_error ?? '')
            );
        }
        if ((string) ($last->pdf_path ?? '') === '' || !is_readable((string) $last->pdf_path)) {
            throw new \SutoreMarketplace\Tests\Support\Failed(
                'Paraşüt PDF was not stored. status=' . ($last->status ?? '')
                . ' error=' . ($last->last_error ?? '')
            );
        }

        return $last;
    }
}
