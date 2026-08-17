<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class CatalogProductRequestStatus
{
    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::FULFILLED,
            self::REJECTED,
            self::CANCELLED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::PENDING => __('Pending', 'sutore-marketplace'),
            self::FULFILLED => __('Added to catalog', 'sutore-marketplace'),
            self::REJECTED => __('Declined', 'sutore-marketplace'),
            self::CANCELLED => __('Cancelled', 'sutore-marketplace'),
        ];
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }
}
