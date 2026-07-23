<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

use SutoreMarketplace\Shared\Settings\Settings;

final class ListingPriceValidator
{
    public static function assertStepMultiple(float|int|string $asking, ?int $step = null): true|\WP_Error
    {
        $step = max(1, $step ?? Settings::listingPriceStep());
        $normalized = self::normalizeAsking($asking);

        if ($normalized === null) {
            return new \WP_Error(
                'sutore_marketplace_invalid_price',
                sprintf(
                    /* translators: %d: price step */
                    __('Price must be in multiples of %d TL. Decimal prices are not allowed.', 'sutore-marketplace'),
                    $step
                )
            );
        }

        if ($normalized <= 0 || ($normalized % $step) !== 0) {
            return new \WP_Error(
                'sutore_marketplace_invalid_price',
                sprintf(
                    /* translators: %d: price step */
                    __('Price must be in multiples of %d TL. Decimal prices are not allowed.', 'sutore-marketplace'),
                    $step
                )
            );
        }

        return true;
    }

    public static function requireValidAsking(float|int|string $asking, ?int $step = null): int|\WP_Error
    {
        $check = self::assertStepMultiple($asking, $step);
        if (is_wp_error($check)) {
            return $check;
        }

        return (int) self::normalizeAsking($asking);
    }

    public static function normalizeAsking(float|int|string $asking): ?int
    {
        if (is_string($asking)) {
            $asking = str_replace([' ', ','], ['', '.'], trim($asking));
        }

        if (!is_numeric($asking)) {
            return null;
        }

        $float = (float) $asking;
        if (abs($float - round($float)) > 0.00001) {
            return null;
        }

        return (int) round($float);
    }

    public static function roundDownToStep(float $value, int $step): int
    {
        $step = max(1, $step);
        return (int) (floor($value / $step) * $step);
    }
}
