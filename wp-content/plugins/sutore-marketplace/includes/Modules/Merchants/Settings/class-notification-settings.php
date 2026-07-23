<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Settings;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Orders\Settings\Settings as FulfillmentSettings;

final class NotificationSettings
{
    public static function enabled(): bool
    {
        return (bool) FulfillmentSettings::get('merchant_notifications_enabled', true);
    }

    public static function eventEnabled(string $type): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $events = (array) FulfillmentSettings::get('merchant_notification_events', NotificationType::defaultEventFlags());

        return !isset($events[$type]) || !empty($events[$type]);
    }
}
