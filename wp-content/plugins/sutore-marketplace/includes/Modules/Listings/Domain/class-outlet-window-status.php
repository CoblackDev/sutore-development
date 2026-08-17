<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class OutletWindowStatus
{
    public const DRAFT = 'draft';
    public const SCHEDULED = 'scheduled';
    public const ACTIVE = 'active';
    public const ENDED = 'ended';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::SCHEDULED, self::ACTIVE, self::ENDED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::DRAFT => __('Draft', 'sutore-marketplace'),
            self::SCHEDULED => __('Scheduled', 'sutore-marketplace'),
            self::ACTIVE => __('Open', 'sutore-marketplace'),
            self::ENDED => __('Ended', 'sutore-marketplace'),
        ];
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }

    /** Merchants can see catalog items and opt in. */
    public static function isOpenForOptIn(string $status): bool
    {
        return in_array($status, [self::SCHEDULED, self::ACTIVE], true);
    }
}
