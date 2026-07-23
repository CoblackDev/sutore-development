<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;

/**
 * Writes WooCommerce native scheduled sale for an accepted campaign offer.
 */
final class CampaignWcSaleSync
{
    public static function writeSale(
        int $variationId,
        float $compareRegular,
        float $customerSale,
        ?string $startsAt,
        ?string $endsAt
    ): void {
        $product = wc_get_product($variationId);
        if (!$product) {
            return;
        }

        $regular = self::money($compareRegular);
        $sale = self::money($customerSale);

        $product->set_regular_price($regular);
        $product->set_sale_price($sale);
        $product->set_price($sale);

        if ($startsAt) {
            $product->set_date_on_sale_from(self::toTimestamp($startsAt));
        } else {
            $product->set_date_on_sale_from(null);
        }
        if ($endsAt) {
            $product->set_date_on_sale_to(self::toTimestamp($endsAt));
        } else {
            $product->set_date_on_sale_to(null);
        }

        $product->save();
        self::bustCaches((int) $product->get_parent_id(), $variationId);
    }

    public static function clearSaleToAsking(int $variationId, float $asking): void
    {
        ActivePriceSync::writeVariationAsking($variationId, $asking);
    }

    public static function listingHasActiveCampaignSale(int $variationId): bool
    {
        $listing = (new ListingRepository())->findByVariationId($variationId);

        return $listing !== null && $listing->campaignStatus === 'active';
    }

    public static function isCampaignManagedListing(Listing $listing): bool
    {
        return $listing->campaignStatus === 'active';
    }

    private static function money(float $amount): string
    {
        return (string) round(max(0.0, $amount), 2);
    }

    private static function toTimestamp(string $mysqlDatetime): int
    {
        return CampaignDatetime::toTimestamp($mysqlDatetime) ?? time();
    }

    private static function bustCaches(int $parentId, int $variationId): void
    {
        if (function_exists('wc_delete_product_transients')) {
            if ($parentId > 0) {
                wc_delete_product_transients($parentId);
            }
            wc_delete_product_transients($variationId);
        }
        if ($parentId > 0) {
            delete_transient('wc_var_prices_' . $parentId);
        }
    }
}
