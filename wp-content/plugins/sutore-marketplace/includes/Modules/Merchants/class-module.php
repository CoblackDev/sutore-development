<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants;

use SutoreMarketplace\Modules\Merchants\Rest\AdminMerchantsController;
use SutoreMarketplace\Modules\Merchants\Rest\MerchantsController;

final class Module
{
    public static function boot(): void
    {
        (new MerchantsController())->register();
        (new AdminMerchantsController())->register();
    }
}
