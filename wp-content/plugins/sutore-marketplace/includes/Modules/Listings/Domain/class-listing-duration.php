<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class ListingDuration
{
    /** @var list<int> */
    public const ALLOWED_DAYS = [2, 7, 30, 45, 60];

    public static function isAllowed(int $days): bool
    {
        return in_array($days, self::ALLOWED_DAYS, true);
    }

    public static function computeExpireAt(int $days): string
    {
        $days = max(1, $days);

        return wp_date('Y-m-d H:i:s', current_time('timestamp') + ($days * DAY_IN_SECONDS));
    }

    public static function optionLabel(int $days): string
    {
        return sprintf(
            /* translators: %d: number of days */
            _n('%d day', '%d days', $days, 'sutore-marketplace'),
            $days
        );
    }

    /**
     * @param list<int> $allowed
     */
    public static function clampToAllowed(int $days, array $allowed): int
    {
        if ($allowed === []) {
            return self::ALLOWED_DAYS[count(self::ALLOWED_DAYS) - 1];
        }

        if (in_array($days, $allowed, true)) {
            return $days;
        }

        $filtered = array_values(array_filter(
            self::ALLOWED_DAYS,
            static fn (int $option): bool => in_array($option, $allowed, true)
        ));

        foreach ($filtered as $option) {
            if ($option <= $days) {
                return $option;
            }
        }

        return $filtered[0] ?? $allowed[0];
    }
}
