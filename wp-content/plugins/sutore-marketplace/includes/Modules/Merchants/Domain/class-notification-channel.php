<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Domain;

final class NotificationChannel
{
    public const PANEL = 'panel';
    public const SMS = 'sms';
    public const PUSH = 'push';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PANEL, self::SMS, self::PUSH];
    }

    /** @return list<string> Channels exposed in admin until the mobile app ships. */
    public static function configurable(): array
    {
        return [self::PANEL, self::SMS];
    }

    public static function isValid(string $channel): bool
    {
        return in_array($channel, self::all(), true);
    }
}
