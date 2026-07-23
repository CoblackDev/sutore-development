<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp;

use SutoreMarketplace\Modules\Otp\Hooks\AccountFormHooks;
use SutoreMarketplace\Modules\Otp\Rest\OtpController;

final class Module
{
    public static function boot(): void
    {
        (new OtpController())->register();
        (new AccountFormHooks())->register();
    }
}
