<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Tests\Support\Harness;

final class ListingStatusTest
{
    public function testMarketAndSaleSetsAreDisjoint(): void
    {
        $overlap = array_intersect(ListingStatus::market(), ListingStatus::saleActive());
        Harness::assertSame([], array_values($overlap), 'market vs saleActive overlap');
    }

    public function testPublishIsForSale(): void
    {
        Harness::assertSame(
            __('For sale', 'sutore-marketplace'),
            ListingStatus::label(ListingStatus::PUBLISH)
        );
        Harness::assertTrue(in_array(ListingStatus::PUBLISH, ListingStatus::removableFromSale(), true));
    }

    public function testSoldIsSaleActiveAndOrderLocked(): void
    {
        Harness::assertTrue(ListingStatus::isSaleActive(ListingStatus::SOLD));
        Harness::assertTrue(in_array(ListingStatus::SOLD, ListingStatus::orderLocked(), true));
        Harness::assertFalse(ListingStatus::allowsPayout(ListingStatus::SOLD));
    }

    public function testPayoutOnlyAfterVerified(): void
    {
        Harness::assertFalse(ListingStatus::allowsPayout(ListingStatus::CONFIRMED));
        Harness::assertTrue(ListingStatus::allowsPayout(ListingStatus::VERIFIED));
        Harness::assertTrue(ListingStatus::allowsPayout(ListingStatus::DELIVERED_TO_CUSTOMER));
    }

    public function testCustomerInvoiceWaitsWhileSaleIsOpen(): void
    {
        Harness::assertTrue(ListingStatus::invoiceOpen(ListingStatus::SOLD));
        Harness::assertTrue(ListingStatus::invoiceOpen(ListingStatus::PRE_ORDER));
        Harness::assertFalse(ListingStatus::invoiceBillable(ListingStatus::SOLD));
        Harness::assertTrue(ListingStatus::invoiceBillable(ListingStatus::VERIFIED));
        Harness::assertFalse(ListingStatus::invoiceOpen(ListingStatus::CHARGEBACK));
        Harness::assertFalse(ListingStatus::invoiceBillable(ListingStatus::CHARGEBACK));
        Harness::assertFalse(ListingStatus::invoiceOpen(ListingStatus::ORDER_DETACHED));
        Harness::assertFalse(ListingStatus::invoiceBillable(ListingStatus::ORDER_DETACHED));
    }

    public function testDetachOnlyBeforeCarrierHandoff(): void
    {
        Harness::assertTrue(ListingStatus::allowsDetach(ListingStatus::PAYMENT));
        Harness::assertTrue(ListingStatus::allowsDetach(ListingStatus::SOLD));
        Harness::assertTrue(ListingStatus::allowsDetach(ListingStatus::CONFIRMED));
        Harness::assertFalse(ListingStatus::allowsDetach(ListingStatus::SHIPPED_TO_SUTORE));
    }

    public function testStaffCannotSkipSoldToArrived(): void
    {
        $caps = ListingStatus::staffCapabilities(ListingStatus::SOLD);
        Harness::assertFalse($caps['mark_arrived']);
        Harness::assertTrue($caps['mark_pre_order']);
    }

    public function testChargebackIsTerminal(): void
    {
        Harness::assertTrue(ListingStatus::isSaleTerminal(ListingStatus::CHARGEBACK));
        Harness::assertTrue(in_array(ListingStatus::CHARGEBACK, ListingStatus::relistable(), true));
    }

    public function testCustomerLabelCollapsesEarlySale(): void
    {
        $label = ListingStatus::customerLabel(ListingStatus::SOLD);
        Harness::assertSame($label, ListingStatus::customerLabel(ListingStatus::PAYMENT));
        Harness::assertSame($label, ListingStatus::customerLabel(ListingStatus::PRE_ORDER));
    }
}
