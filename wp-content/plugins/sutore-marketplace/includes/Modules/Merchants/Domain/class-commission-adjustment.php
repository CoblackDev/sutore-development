<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Domain;

final class CommissionAdjustment
{
    public const ABSOLUTE = 'absolute';
    public const PERCENT_OFF = 'percent_off';
    public const POINTS_OFF = 'points_off';

    public static function isValid(string $value): bool
    {
        return in_array($value, [self::ABSOLUTE, self::PERCENT_OFF, self::POINTS_OFF], true);
    }

    /**
     * Turn a stored override value into an absolute commission percent.
     * Absolute rates may be higher than the level rate (a raise, not a discount).
     */
    public static function apply(float $levelPercent, string $adjustment, float $value): float
    {
        $result = match ($adjustment) {
            self::PERCENT_OFF => $levelPercent * (1 - max(0.0, $value) / 100),
            self::POINTS_OFF => $levelPercent - max(0.0, $value),
            default => $value,
        };

        return round(max(0.0, min(100.0, $result)), 2);
    }

    public static function label(string $adjustment): string
    {
        return match ($adjustment) {
            self::PERCENT_OFF => __('Percent off current rate', 'sutore-marketplace'),
            self::POINTS_OFF => __('Points off current rate', 'sutore-marketplace'),
            default => __('Absolute rate', 'sutore-marketplace'),
        };
    }
}
