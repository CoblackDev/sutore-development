<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Settings;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationChannel;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Orders\Settings\Settings as FulfillmentSettings;

final class NotificationSettings
{
    public static function enabled(): bool
    {
        return (bool) FulfillmentSettings::get('merchant_notifications_enabled', true);
    }

    public static function channelEnabled(string $type, string $channel): bool
    {
        if (!NotificationChannel::isValid($channel)) {
            return false;
        }

        if ($channel === NotificationChannel::PANEL && !self::enabled()) {
            return false;
        }

        if ($channel === NotificationChannel::SMS && !FulfillmentSettings::smsEnabled()) {
            return false;
        }

        $row = self::channelsFor($type);

        return !empty($row[$channel]);
    }

    /**
     * @return array{panel: bool, sms: bool, push: bool}
     */
    public static function channelsFor(string $type): array
    {
        $defaults = NotificationType::defaultChannels();
        $fallback = $defaults[$type] ?? [
            NotificationChannel::PANEL => true,
            NotificationChannel::SMS => false,
            NotificationChannel::PUSH => false,
        ];
        $all = (array) FulfillmentSettings::get('merchant_notification_channels', $defaults);
        $row = $all[$type] ?? $fallback;
        if (!is_array($row)) {
            return $fallback;
        }

        return [
            NotificationChannel::PANEL => !empty($row[NotificationChannel::PANEL]),
            NotificationChannel::SMS => !empty($row[NotificationChannel::SMS]),
            NotificationChannel::PUSH => !empty($row[NotificationChannel::PUSH]),
        ];
    }
}
