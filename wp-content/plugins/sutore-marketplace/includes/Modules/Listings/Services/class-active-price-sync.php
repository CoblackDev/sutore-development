<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;

/**
 * Winner change side-effects: WooCommerce cache invalidation.
 *
 * Customer-facing price is always computed live from listings DB + settings
 * (see MarketplacePricing + PdpIntegration + CartPricingHooks).
 * During an accepted campaign, WC native sale fields are owned by CampaignWcSaleSync.
 */
final class ActivePriceSync
{
    public static function writeVariationAsking(int $variationId, int|float|string $asking): void
    {
        $valid = ListingPriceValidator::requireValidAsking($asking);
        if (is_wp_error($valid)) {
            return;
        }

        $product = wc_get_product($variationId);
        if (!$product) {
            return;
        }

        $product->set_regular_price((string) $valid);
        $product->set_sale_price('');
        $product->set_date_on_sale_from(null);
        $product->set_date_on_sale_to(null);
        $product->set_price((string) $valid);
        $product->save();
    }

    public function syncFromWinner(Listing $listing): void
    {
        if (CampaignWcSaleSync::isCampaignManagedListing($listing)) {
            $this->bustCaches($listing->parentProductId, $listing->variationId);

            return;
        }

        $this->bustCaches($listing->parentProductId, $listing->variationId);
    }

    public function clearParentSize(int $parentId, int $sizeTermId): void
    {
        unset($sizeTermId);
        $this->bustCaches($parentId);
    }

    private function bustCaches(int $parentId, int $variationId = 0): void
    {
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($parentId);
            if ($variationId > 0) {
                wc_delete_product_transients($variationId);
            }
        }
        delete_transient('wc_var_prices_' . $parentId);
    }
}
