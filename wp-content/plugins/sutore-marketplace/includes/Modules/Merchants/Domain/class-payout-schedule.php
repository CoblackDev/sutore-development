<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Domain;

use SutoreMarketplace\Modules\Orders\Settings\Settings;

/**
 * Next payout calendar day: min hold (days) then the first configured weekday.
 * No batch/cron — the date is written onto the payout row at creation.
 */
final class PayoutSchedule
{
    public const WEEKDAY_MONDAY = 1;
    public const WEEKDAY_TUESDAY = 2;
    public const WEEKDAY_WEDNESDAY = 3;
    public const WEEKDAY_THURSDAY = 4;
    public const WEEKDAY_FRIDAY = 5;
    public const WEEKDAY_SATURDAY = 6;
    public const WEEKDAY_SUNDAY = 7;

    /** @return array<int, string> */
    public static function weekdayLabels(): array
    {
        return [
            self::WEEKDAY_MONDAY => __('Monday', 'sutore-marketplace'),
            self::WEEKDAY_TUESDAY => __('Tuesday', 'sutore-marketplace'),
            self::WEEKDAY_WEDNESDAY => __('Wednesday', 'sutore-marketplace'),
            self::WEEKDAY_THURSDAY => __('Thursday', 'sutore-marketplace'),
            self::WEEKDAY_FRIDAY => __('Friday', 'sutore-marketplace'),
            self::WEEKDAY_SATURDAY => __('Saturday', 'sutore-marketplace'),
            self::WEEKDAY_SUNDAY => __('Sunday', 'sutore-marketplace'),
        ];
    }

    /** @param list<mixed> $days */
    public static function normalizeWeekdays(array $days): array
    {
        $out = [];
        foreach ($days as $day) {
            $n = (int) $day;
            if ($n >= self::WEEKDAY_MONDAY && $n <= self::WEEKDAY_SUNDAY) {
                $out[$n] = $n;
            }
        }
        $list = array_values($out);
        sort($list);

        return $list !== [] ? $list : [self::WEEKDAY_WEDNESDAY];
    }

    public static function minHoldDays(): int
    {
        return Settings::payoutMinHoldDays();
    }

    /** @return list<int> */
    public static function weekdays(): array
    {
        return Settings::payoutWeekdays();
    }

    public static function today(): string
    {
        return wp_date('Y-m-d', null, wp_timezone());
    }

    /**
     * @param string|null $fromMysql Site-local datetime (Y-m-d H:i:s). Null = now.
     */
    public static function scheduledDateFrom(?string $fromMysql = null): string
    {
        $tz = wp_timezone();
        $fromMysql = trim((string) $fromMysql);
        if ($fromMysql !== '') {
            $start = date_create_immutable($fromMysql, $tz);
        } else {
            $start = date_create_immutable('now', $tz);
        }
        if (!$start) {
            $start = new \DateTimeImmutable('now', $tz);
        }

        $hold = max(0, self::minHoldDays());
        $cursor = $start->setTime(0, 0, 0)->add(new \DateInterval('P' . $hold . 'D'));
        $weekdays = self::weekdays();

        for ($i = 0; $i < 14; $i++) {
            $iso = (int) $cursor->format('N');
            if (in_array($iso, $weekdays, true)) {
                return $cursor->format('Y-m-d');
            }
            $cursor = $cursor->add(new \DateInterval('P1D'));
        }

        return $cursor->format('Y-m-d');
    }

    public static function isDue(?string $scheduledDate, ?string $today = null): bool
    {
        $scheduledDate = self::normalizeDate($scheduledDate);
        if ($scheduledDate === '') {
            return false;
        }
        $today = self::normalizeDate($today) ?: self::today();

        return $scheduledDate <= $today;
    }

    public static function formatDate(?string $ymd): string
    {
        $ymd = self::normalizeDate($ymd);
        if ($ymd === '') {
            return '';
        }
        $ts = strtotime($ymd . ' 12:00:00');
        if ($ts === false) {
            return $ymd;
        }

        return (string) wp_date('j F Y', $ts, wp_timezone());
    }

    public static function formatDateWithWeekday(?string $ymd): string
    {
        $ymd = self::normalizeDate($ymd);
        if ($ymd === '') {
            return '';
        }
        $ts = strtotime($ymd . ' 12:00:00');
        if ($ts === false) {
            return $ymd;
        }

        return (string) wp_date('l, j F Y', $ts, wp_timezone());
    }

    public static function merchantPendingMessage(?string $ymd): string
    {
        $label = self::formatDateWithWeekday($ymd);
        if ($label === '') {
            return '';
        }

        /* translators: %s: weekday and date, e.g. Wednesday, 15 January 2026 */
        return sprintf(__('Your payout will be made on %s.', 'sutore-marketplace'), $label);
    }

    public static function normalizeDate(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            return $m[1];
        }

        return '';
    }
}
