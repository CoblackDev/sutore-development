<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

use SutoreMarketplace\Shared\Settings\Settings;

/**
 * Campaign discount language: rate band, max duration, cooldown, aging ladder.
 */
final class CampaignGuardrails
{
    /** @var list<int> */
    public const DURATION_CHOICES = [3, 7, 14];

    public static function minPercent(): int
    {
        $min = max(1, (int) Settings::get('campaign_discount_min_percent', 10));
        $max = self::maxPercent();

        return min($min, $max);
    }

    public static function maxPercent(): int
    {
        return max(1, min(90, (int) Settings::get('campaign_discount_max_percent', 40)));
    }

    public static function maxDays(): int
    {
        return max(1, min(90, (int) Settings::get('campaign_max_days', 14)));
    }

    public static function cooldownDays(): int
    {
        return max(0, min(90, (int) Settings::get('campaign_cooldown_days', 14)));
    }

    public static function agingDay(int $step): int
    {
        $key = $step <= 1 ? 'campaign_aging_day_1' : 'campaign_aging_day_2';
        $fallback = $step <= 1 ? 45 : 60;

        return max(1, (int) Settings::get($key, $fallback));
    }

    /** @return list<int> */
    public static function percentOptions(): array
    {
        $min = self::minPercent();
        $max = self::maxPercent();
        $out = [];
        for ($p = $min; $p <= $max; $p += 5) {
            $out[] = $p;
        }
        if ($out === [] || $out[count($out) - 1] !== $max) {
            $out[] = $max;
        }

        return array_values(array_unique($out));
    }

    /** @return list<int> */
    public static function durationOptions(): array
    {
        $max = self::maxDays();
        $out = array_values(array_filter(
            self::DURATION_CHOICES,
            static fn (int $days): bool => $days <= $max
        ));
        if ($out === []) {
            return [$max];
        }
        if ($out[count($out) - 1] !== $max && !in_array($max, $out, true)) {
            $out[] = $max;
        }

        return $out;
    }

    public static function snapPercent(float $percent): int
    {
        $percent = max(0.0, $percent);
        $options = self::percentOptions();
        $best = $options[0];
        $bestDelta = abs($best - $percent);
        foreach ($options as $option) {
            $delta = abs($option - $percent);
            if ($delta < $bestDelta || ($delta === $bestDelta && $option > $best)) {
                $best = $option;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    /**
     * @return true|\WP_Error
     */
    public static function assertPercent(float $percent): true|\WP_Error
    {
        $min = self::minPercent();
        $max = self::maxPercent();
        if ($percent + 0.0001 < $min || $percent - 0.0001 > $max) {
            return new \WP_Error(
                'sutore_campaign_percent_band',
                sprintf(
                    /* translators: 1: min percent, 2: max percent */
                    __('Seller discount must be between %1$s%% and %2$s%%.', 'sutore-marketplace'),
                    (string) $min,
                    (string) $max
                )
            );
        }

        return true;
    }

    /**
     * @return true|\WP_Error
     */
    public static function assertDurationDays(int $days): true|\WP_Error
    {
        $max = self::maxDays();
        if ($days < 1 || $days > $max) {
            return new \WP_Error(
                'sutore_campaign_duration',
                sprintf(
                    /* translators: %d: max campaign days */
                    __('Campaign duration cannot exceed %d days.', 'sutore-marketplace'),
                    $max
                )
            );
        }

        return true;
    }

    /**
     * @return true|\WP_Error
     */
    public static function assertDateSpan(?string $startsAt, ?string $endsAt): true|\WP_Error
    {
        $startTs = CampaignDatetime::toTimestamp($startsAt);
        $endTs = CampaignDatetime::toTimestamp($endsAt);
        if ($startTs === null || $endTs === null) {
            return true;
        }

        $days = (int) ceil(max(0, $endTs - $startTs) / DAY_IN_SECONDS);
        if ($days < 1) {
            $days = 1;
        }

        return self::assertDurationDays($days);
    }

    public static function cooldownUntilMysql(): ?string
    {
        $days = self::cooldownDays();
        if ($days <= 0) {
            return null;
        }

        return CampaignDatetime::plusDays($days);
    }

    /**
     * @return array{
     *   min_percent: int,
     *   max_percent: int,
     *   max_days: int,
     *   cooldown_days: int,
     *   aging_day_1: int,
     *   aging_day_2: int,
     *   percent_options: list<int>,
     *   duration_options: list<int>
     * }
     */
    public static function toArray(): array
    {
        return [
            'min_percent' => self::minPercent(),
            'max_percent' => self::maxPercent(),
            'max_days' => self::maxDays(),
            'cooldown_days' => self::cooldownDays(),
            'aging_day_1' => self::agingDay(1),
            'aging_day_2' => self::agingDay(2),
            'percent_options' => self::percentOptions(),
            'duration_options' => self::durationOptions(),
        ];
    }
}
