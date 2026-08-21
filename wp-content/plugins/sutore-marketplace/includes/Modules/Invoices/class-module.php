<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices;

use SutoreMarketplace\Modules\Invoices\Hooks\InvoiceCronHooks;
use SutoreMarketplace\Modules\Invoices\Rest\InvoicesController;
use SutoreMarketplace\Modules\Invoices\Services\InvoiceStorage;

final class Module
{
    public static function boot(): void
    {
        (new InvoiceCronHooks())->register();
        (new InvoicesController())->register();
        (new InvoiceStorage())->directory();
    }
}
