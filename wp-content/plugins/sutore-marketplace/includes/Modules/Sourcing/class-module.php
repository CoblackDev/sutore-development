<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Sourcing;

use SutoreMarketplace\Modules\Sourcing\Hooks\SourcingDigestCron;
use SutoreMarketplace\Modules\Sourcing\Rest\SourcingController;

final class Module
{
    public static function boot(): void
    {
        (new SourcingController())->register();
        (new SourcingDigestCron())->register();
    }
}
