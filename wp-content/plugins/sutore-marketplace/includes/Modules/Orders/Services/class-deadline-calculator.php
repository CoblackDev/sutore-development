<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Orders\Settings\Settings;

final class DeadlineCalculator
{
    public static function fromNow(int $seconds): string
    {
        $hours = (int) ceil($seconds / HOUR_IN_SECONDS);

        if (!Settings::useBusinessDays()) {
            return date('Y-m-d H:i:s', current_time('timestamp') + $seconds);
        }

        return date('Y-m-d H:i:s', self::addBusinessHours(current_time('timestamp'), $hours));
    }

    private static function addBusinessHours(int $startTs, int $hours): int
    {
        $remaining = $hours;
        $ts = $startTs;

        while ($remaining > 0) {
            $ts += HOUR_IN_SECONDS;
            $dow = (int) gmdate('N', $ts + (get_option('gmt_offset') * HOUR_IN_SECONDS));
            if ($dow >= 6) {
                continue;
            }
            $remaining--;
        }

        return $ts;
    }
}
