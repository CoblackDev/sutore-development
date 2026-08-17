<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Campaign discount unit: fixed TL or percent.
 * Seller percent = of asking. Platform percent = of (hizmet + güvence).
 */
final class CampaignDiscountType
{
    public const FIXED = 'fixed';
    public const PERCENT = 'percent';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::FIXED, self::PERCENT];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function normalize(mixed $raw): string
    {
        $type = sanitize_key((string) $raw);
        if ($type === 'pct' || $type === 'percentage') {
            $type = self::PERCENT;
        }
        if ($type === 'tl' || $type === 'amount') {
            $type = self::FIXED;
        }

        return self::isValid($type) ? $type : self::FIXED;
    }

    public static function label(string $type): string
    {
        return match (self::normalize($type)) {
            self::PERCENT => __('Percent', 'sutore-marketplace'),
            default => __('Fixed (TL)', 'sutore-marketplace'),
        };
    }

    /** Admin list / campaign rule (no per-listing TL yet). */
    public static function ruleLabel(string $type, float $value, string $ofWhat): string
    {
        $value = max(0.0, $value);
        $pretty = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        if ($pretty === '') {
            $pretty = '0';
        }

        if (self::normalize($type) === self::PERCENT) {
            return sprintf(
                /* translators: 1: percent number, 2: what the percent applies to (asking / fees) */
                __('%1$s%% of %2$s', 'sutore-marketplace'),
                $pretty,
                $ofWhat
            );
        }

        return sprintf(
            /* translators: %s: amount in TL */
            __('%s TL', 'sutore-marketplace'),
            number_format_i18n((int) round($value))
        );
    }

    /** Merchant-facing: percent shows resolved TL too. */
    public static function offerLabel(string $type, float $value, float $resolvedTl): string
    {
        $resolvedTl = max(0.0, $resolvedTl);
        $tl = number_format_i18n((int) round($resolvedTl));

        if (self::normalize($type) === self::PERCENT) {
            $pretty = rtrim(rtrim(number_format(max(0.0, $value), 2, '.', ''), '0'), '.');
            if ($pretty === '') {
                $pretty = '0';
            }

            return sprintf(
                /* translators: 1: percent number, 2: resolved TL amount */
                __('%1$s%% (≈ %2$s TL)', 'sutore-marketplace'),
                $pretty,
                $tl
            );
        }

        return sprintf(
            /* translators: %s: amount in TL */
            __('%s TL', 'sutore-marketplace'),
            $tl
        );
    }
}
