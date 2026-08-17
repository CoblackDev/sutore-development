<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class OutletOptinStatus
{
    public const PENDING = 'pending';
    public const LIVE = 'live';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::LIVE, self::CANCELLED, self::EXPIRED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::PENDING => __('Waiting for window', 'sutore-marketplace'),
            self::LIVE => __('On sale', 'sutore-marketplace'),
            self::CANCELLED => __('Cancelled', 'sutore-marketplace'),
            self::EXPIRED => __('Ended', 'sutore-marketplace'),
        ];
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }
}
