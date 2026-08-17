<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;
use SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind;
use SutoreMarketplace\Modules\Invoices\Domain\InvoiceStatus;
use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Modules\Otp\Domain\OtpPurpose;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentType;
use SutoreMarketplace\Tests\Support\Harness;

final class EnumSmokeTest
{
    public function testShipmentTypes(): void
    {
        Harness::assertTrue(ShipmentType::isValid(ShipmentType::FAST));
        Harness::assertFalse(ShipmentType::isValid('standard'));
        Harness::assertTrue(count(ShipmentType::all()) >= 5);
    }

    public function testInvoiceEnums(): void
    {
        Harness::assertSame('customer_fees', InvoiceKind::CUSTOMER_FEES);
        Harness::assertTrue(in_array(InvoiceStatus::QUEUED, InvoiceStatus::dueForCron(), true));
        Harness::assertTrue(in_array(InvoiceStatus::ERROR, InvoiceStatus::dueForCron(), true));
        Harness::assertFalse(in_array(InvoiceStatus::SENT, InvoiceStatus::dueForCron(), true));
    }

    public function testOtpAndCatalogAndContracts(): void
    {
        Harness::assertTrue(OtpPurpose::isValid(OtpPurpose::ACCOUNT_DELETE));
        Harness::assertTrue(CatalogProductRequestStatus::isValid(CatalogProductRequestStatus::PENDING));
        Harness::assertTrue(ContractSettings::CHECKOUT_FIELD !== '');
    }
}
