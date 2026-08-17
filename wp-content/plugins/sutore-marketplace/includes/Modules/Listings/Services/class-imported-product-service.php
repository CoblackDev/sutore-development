<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Shared\Settings\Settings;

final class ImportedProductService
{
    private const META_KEY = '_sutore_marketplace_imported_product';

    public static function isVariationImported(int $variationId): bool
    {
        if ($variationId <= 0) {
            return false;
        }

        return (int) get_post_meta($variationId, self::META_KEY, true) === 1;
    }

    /** Variation meta is the source of truth read by shipping / checkout / deadline code. */
    public static function setVariationImported(int $variationId, bool $imported): void
    {
        if ($variationId <= 0) {
            return;
        }

        if ($imported) {
            update_post_meta($variationId, self::META_KEY, 1);

            return;
        }

        delete_post_meta($variationId, self::META_KEY);
    }

    /**
     * @param list<int> $variationIds
     * @return array{marked: int, skipped: list<string>}
     */
    public function markVariationsImported(array $variationIds): array
    {
        $marked = 0;
        $skipped = [];
        $listings = new ListingRepository();
        $expireAt = wp_date(
            'Y-m-d H:i:s',
            current_time('timestamp') + Settings::defaultListingDurationDays() * DAY_IN_SECONDS
        );

        foreach (array_unique($variationIds) as $variationId) {
            $variationId = absint($variationId);
            if ($variationId <= 0) {
                continue;
            }

            $product = wc_get_product($variationId);
            if (!$product instanceof \WC_Product || !$product->is_type('variation')) {
                $skipped[] = sprintf(
                    /* translators: %d: product ID */
                    __('%d is not a product variation.', 'sutore-marketplace'),
                    $variationId
                );
                continue;
            }

            self::setVariationImported($variationId, true);

            $listing = $listings->findByVariationId($variationId);
            if ($listing && $listing->variationId) {
                $listings->update((int) $listing->variationId, [
                    'is_imported' => 1,
                    'listing_duration_days' => Settings::defaultListingDurationDays(),
                    'expire_at' => $expireAt,
                ]);
            }

            ++$marked;
        }

        return ['marked' => $marked, 'skipped' => $skipped];
    }

    /**
     * @param list<int> $variationIds
     * @return array{unmarked: int, skipped: list<string>}
     */
    public function unmarkVariationsImported(array $variationIds): array
    {
        $unmarked = 0;
        $skipped = [];
        $listings = new ListingRepository();

        foreach (array_unique($variationIds) as $variationId) {
            $variationId = absint($variationId);
            if ($variationId <= 0) {
                continue;
            }

            $product = wc_get_product($variationId);
            if (!$product instanceof \WC_Product || !$product->is_type('variation')) {
                $skipped[] = sprintf(
                    /* translators: %d: product ID */
                    __('%d is not a product variation.', 'sutore-marketplace'),
                    $variationId
                );
                continue;
            }

            self::setVariationImported($variationId, false);

            $listing = $listings->findByVariationId($variationId);
            if ($listing && $listing->variationId) {
                $listings->update((int) $listing->variationId, [
                    'is_imported' => 0,
                ]);
            }

            ++$unmarked;
        }

        return ['unmarked' => $unmarked, 'skipped' => $skipped];
    }
}
