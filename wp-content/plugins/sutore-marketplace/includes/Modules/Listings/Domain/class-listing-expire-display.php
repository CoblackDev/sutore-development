<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Human-readable remaining listing lifetime (WP site timezone).
 */
final class ListingExpireDisplay
{
    public static function label(?string $expireAt, string $listingStatus, bool $isWinner = false): ?string
    {
        unset($isWinner);

        if ($listingStatus === ListingStatus::EXPIRED) {
            return __('Expired', 'sutore-marketplace');
        }

        if (
            ListingStatus::isInSaleLifecycle($listingStatus)
            || $listingStatus === ListingStatus::NOT_SALE
            || $listingStatus === ListingStatus::ORDER_DETACHED
            || $listingStatus === ListingStatus::PRE_ORDER
        ) {
            return null;
        }

        return self::remainingFromDatetime($expireAt);
    }

    public static function remainingFromDatetime(?string $deadlineAt): ?string
    {
        if (!$deadlineAt) {
            return null;
        }

        $end = strtotime($deadlineAt);
        if ($end === false) {
            return null;
        }

        $seconds = $end - (int) current_time('timestamp');
        if ($seconds <= 0) {
            return __('Expired', 'sutore-marketplace');
        }

        if ($seconds >= 2 * DAY_IN_SECONDS) {
            $days = (int) floor($seconds / DAY_IN_SECONDS);

            return sprintf('%d %s', $days, __('days', 'sutore-marketplace'));
        }

        if ($seconds >= HOUR_IN_SECONDS) {
            $hours = (int) ceil($seconds / HOUR_IN_SECONDS);

            return sprintf('%d %s', $hours, __('hours', 'sutore-marketplace'));
        }

        $mins = max(1, (int) ceil($seconds / MINUTE_IN_SECONDS));

        return sprintf('%d %s', $mins, __('minutes', 'sutore-marketplace'));
    }
}
