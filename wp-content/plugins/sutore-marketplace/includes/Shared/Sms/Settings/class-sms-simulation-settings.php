<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms\Settings;

use SutoreMarketplace\Shared\Settings\Settings;

final class SmsSimulationSettings
{
    public static function isEnabled(): bool
    {
        return (bool) Settings::get('sms_simulation_mode', false);
    }
}
