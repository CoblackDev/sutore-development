<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants;

use SutoreMarketplace\Modules\Merchants\Rest\AdminMerchantsController;
use SutoreMarketplace\Modules\Merchants\Rest\AdminBehaviorController;
use SutoreMarketplace\Modules\Merchants\Rest\MerchantsController;
use SutoreMarketplace\Modules\Merchants\Hooks\BehaviorCronHooks;

final class Module
{
    public static function boot(): void
    {
        (new MerchantsController())->register();
        (new AdminMerchantsController())->register();
        (new AdminBehaviorController())->register();
        (new BehaviorCronHooks())->register();
        BehaviorCronHooks::schedule();
    }

    public static function activate(): void
    {
        BehaviorCronHooks::schedule();
    }

    public static function deactivate(): void
    {
        BehaviorCronHooks::unschedule();
    }
}
