<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Domain;

/**
 * Checkout / order shipment type keys persisted on listings.order_shipment_type.
 */
final class ShipmentType
{
    public const FREE = 'free';
    public const FAST = 'fast';
    public const EXPRESS = 'express';
    public const INTERNATIONAL = 'international';
    public const CYPRUS = 'cyprus';
    public const IMPORTED_FREE = 'imported_free';

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(string $type): bool
    {
        return isset(self::labels()[$type]);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::FREE => __('Free shipping', 'sutore-marketplace'),
            self::FAST => __('Fast shipping', 'sutore-marketplace'),
            self::EXPRESS => __('Express shipping', 'sutore-marketplace'),
            self::INTERNATIONAL => __('International shipping', 'sutore-marketplace'),
            self::CYPRUS => __('Cyprus shipping', 'sutore-marketplace'),
            self::IMPORTED_FREE => __('Imported — free shipping', 'sutore-marketplace'),
        ];
    }

    public static function label(string $type): string
    {
        return self::labels()[$type] ?? $type;
    }
}
