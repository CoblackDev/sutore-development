<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Invoices\Domain\InvoiceLineName;
use SutoreMarketplace\Tests\Support\Harness;

final class InvoiceLineNameTest
{
    public function testLegalLineNamesAreTurkish(): void
    {
        Harness::assertSame('Hizmet Bedeli', InvoiceLineName::forCode('hizmet'));
        Harness::assertSame('Güvence Bedeli', InvoiceLineName::forCode('guvence'));
        Harness::assertSame('Komisyon Bedeli', InvoiceLineName::forCode('commission'));
        Harness::assertSame(
            'Hizmet Bedeli — Dunk Low',
            InvoiceLineName::description('hizmet', 'Dunk Low')
        );
    }
}
