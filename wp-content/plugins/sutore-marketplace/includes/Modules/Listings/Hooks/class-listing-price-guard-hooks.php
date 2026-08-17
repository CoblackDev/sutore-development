<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Hooks;

use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\CampaignWcSaleSync;

final class ListingPriceGuardHooks
{
    public function register(): void
    {
        add_action('woocommerce_before_product_object_save', [$this, 'guardVariationSave'], 20, 1);
        add_filter('update_post_metadata', [$this, 'blockInvalidPriceMeta'], 10, 5);
        add_filter('add_post_metadata', [$this, 'blockInvalidPriceMetaAdd'], 10, 4);
    }

    public function guardVariationSave(\WC_Product $product): void
    {
        if (!$product->is_type('variation')) {
            return;
        }

        $variationId = (int) $product->get_id();
        if (!$this->isMarketplaceVariation($variationId)) {
            return;
        }

        // Accepted campaign: WC native sale/compare owned by CampaignWcSaleSync.
        if (CampaignWcSaleSync::listingHasActiveCampaignSale($variationId)) {
            return;
        }

        $raw = $product->get_regular_price('edit');
        if ($raw === '' || $raw === null) {
            return;
        }

        $valid = ListingPriceValidator::requireValidAsking($raw);
        if (is_wp_error($valid)) {
            throw new \WC_Data_Exception(
                (string) $valid->get_error_code(),
                $valid->get_error_message()
            );
        }

        $price = (string) $valid;
        $product->set_regular_price($price);
        $product->set_sale_price('');
        $product->set_price($price);
    }

    /** @param mixed $metaValue */
    public function blockInvalidPriceMeta($check, int $objectId, string $metaKey, $metaValue, $prevValue = null): mixed
    {
        unset($prevValue);

        if ($check === false) {
            return false;
        }

        if (!in_array($metaKey, ['_regular_price', '_price', '_sale_price'], true)) {
            return $check;
        }

        if (!$this->isMarketplaceVariation($objectId)) {
            return $check;
        }

        if (CampaignWcSaleSync::listingHasActiveCampaignSale($objectId)) {
            return $check;
        }

        if ($metaKey === '_sale_price') {
            return $check;
        }

        if ($metaValue === '' || $metaValue === null) {
            return $check;
        }

        if (is_wp_error(ListingPriceValidator::requireValidAsking($metaValue))) {
            return false;
        }

        return $check;
    }

    /** @param mixed $metaValue */
    public function blockInvalidPriceMetaAdd($check, int $objectId, string $metaKey, $metaValue): mixed
    {
        return $this->blockInvalidPriceMeta($check, $objectId, $metaKey, $metaValue);
    }

    private function isMarketplaceVariation(int $variationId): bool
    {
        if ($variationId <= 0) {
            return false;
        }

        return (new ListingRepository())->findByVariationId($variationId) !== null;
    }
}
