<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Domain;

use SutoreMarketplace\Shared\Settings\Settings;

final class ReleasePriceService
{
    public const META_RETAIL_USD = 'liste_fiyati';
    public const META_RELEASE_YEAR = 'cikis_yili';

    public static function retailUsd(int $parentProductId): ?float
    {
        $raw = get_post_meta($parentProductId, self::META_RETAIL_USD, true);
        if ($raw === '' || $raw === null) {
            return null;
        }

        $usd = (float) $raw;
        return $usd > 0 ? $usd : null;
    }

    public static function retailTl(int $parentProductId): ?int
    {
        $usd = self::retailUsd($parentProductId);
        $rate = Settings::usdTryRate();
        if ($usd === null || $rate <= 0) {
            return null;
        }

        return (int) floor($usd * $rate);
    }

    public static function releaseYear(int $parentProductId): ?int
    {
        $raw = get_post_meta($parentProductId, self::META_RELEASE_YEAR, true);
        if ($raw === '' || $raw === null) {
            return null;
        }

        $year = (int) $raw;
        return $year >= 1900 && $year <= 2100 ? $year : null;
    }

    public static function context(int $parentProductId): array
    {
        $usd = self::retailUsd($parentProductId);
        $tl = self::retailTl($parentProductId);
        $year = self::releaseYear($parentProductId);

        return [
            'retail_price_usd' => $usd,
            'retail_price_tl' => $tl,
            'release_year' => $year,
            'usd_try_exchange_rate' => Settings::usdTryRate(),
            'has_retail_price' => $usd !== null && $tl !== null,
            'has_release_year' => $year !== null,
            'permalink' => $parentProductId ? get_permalink($parentProductId) : '',
        ];
    }
}
