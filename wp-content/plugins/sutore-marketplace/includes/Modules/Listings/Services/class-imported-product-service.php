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
            current_time('timestamp') + Settings::expireDays() * DAY_IN_SECONDS
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

            update_post_meta($variationId, self::META_KEY, 1);

            $listing = $listings->findByVariationId($variationId);
            if ($listing && $listing->id) {
                $listings->update((int) $listing->id, [
                    'is_imported' => 1,
                    'expire_at' => $expireAt,
                ]);
            }

            ++$marked;
        }

        return ['marked' => $marked, 'skipped' => $skipped];
    }
}
