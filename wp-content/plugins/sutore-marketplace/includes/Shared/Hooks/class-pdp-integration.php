<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Shared\Settings\Settings;

/**
 * Customer-facing price display for PDP + shop/catalog.
 *
 * WC stores variation `_price` as merchant asking (çıpa). Customer price =
 * asking + hizmet + güvence (− platform fee waiver, capped by fees).
 * Cart uses the same formula.
 *
 * Variable product HTML reads variation prices via get_price('edit'), so
 * woocommerce_product_*_get_price alone is not enough — variation_prices_*
 * and available_variation must also be hooked. Catalog cards resolve via listings DB.
 */
final class PdpIntegration
{
    private ListingRepository $listings;

    public function __construct(?ListingRepository $listings = null)
    {
        $this->listings = $listings ?? new ListingRepository();
    }

    public function register(): void
    {
        add_filter('woocommerce_product_get_price', [$this, 'filterProductPrice'], 99, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'filterProductRegularPrice'], 99, 2);
        add_filter('woocommerce_product_get_sale_price', [$this, 'filterProductSalePrice'], 99, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'filterProductPrice'], 99, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'filterProductRegularPrice'], 99, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [$this, 'filterProductSalePrice'], 99, 2);

        add_filter('woocommerce_variation_prices_price', [$this, 'filterVariationPricesPrice'], 99, 3);
        add_filter('woocommerce_variation_prices_regular_price', [$this, 'filterVariationPricesRegularPrice'], 99, 3);
        add_filter('woocommerce_variation_prices_sale_price', [$this, 'filterVariationPricesSalePrice'], 99, 3);
        add_filter('woocommerce_get_variation_prices_hash', [$this, 'filterVariationPricesHash'], 99, 3);

        add_filter('woocommerce_product_is_on_sale', [$this, 'filterIsOnSale'], 99, 2);
        add_filter('woocommerce_available_variation', [$this, 'filterAvailableVariation'], 99, 3);
        add_filter('woocommerce_get_price_html', [$this, 'filterPriceHtml'], 99, 2);
    }

    public function filterProductPrice($price, $product)
    {
        if ($this->shouldPassthrough() || !$this->isProductObject($product)) {
            return $price;
        }

        return $this->resolveDisplayPrice($price, $product);
    }

    public function filterProductRegularPrice($price, $product)
    {
        if ($this->shouldPassthrough() || !$this->isProductObject($product)) {
            return $price;
        }

        return $this->resolveComparePrice($price, $product);
    }

    public function filterProductSalePrice($price, $product)
    {
        if ($this->shouldPassthrough() || !$this->isProductObject($product)) {
            return $price;
        }

        $listing = $this->resolveListing($product);
        if (!$listing || $listing->campaignStatus !== 'active') {
            return $price;
        }

        $customer = MarketplacePricing::customerPrice($listing);
        $compare = MarketplacePricing::compareAtPrice($listing);

        return $compare > $customer ? $customer : '';
    }

    public function filterVariationPricesPrice($price, $variation, $product)
    {
        unset($product);
        if ($this->shouldPassthrough() || !$this->isProductObject($variation)) {
            return $price;
        }

        return $this->resolveDisplayPrice($price, $variation);
    }

    public function filterVariationPricesRegularPrice($price, $variation, $product)
    {
        unset($product);
        if ($this->shouldPassthrough() || !$this->isProductObject($variation)) {
            return $price;
        }

        return $this->resolveComparePrice($price, $variation);
    }

    public function filterVariationPricesSalePrice($price, $variation, $product)
    {
        unset($product);

        return $this->filterProductSalePrice($price, $variation);
    }

    public function filterIsOnSale(bool $onSale, $product): bool
    {
        if ($this->shouldPassthrough() || !$this->isProductObject($product)) {
            return $onSale;
        }

        if ($product->is_type('variable')) {
            $listing = $this->listings->getCheapestWinnerForParent((int) $product->get_id());
            if ($listing && $listing->campaignStatus === 'active') {
                $customer = MarketplacePricing::customerPrice($listing);
                $compare = MarketplacePricing::compareAtPrice($listing);

                return $compare > $customer;
            }

            return $onSale;
        }

        $listing = $this->resolveListing($product);
        if (!$listing || $listing->campaignStatus !== 'active') {
            return $onSale;
        }

        return MarketplacePricing::compareAtPrice($listing) > MarketplacePricing::customerPrice($listing);
    }

    /**
     * @param array<int, mixed> $hash
     * @return array<int, mixed>
     */
    public function filterVariationPricesHash(array $hash, $product, $forDisplay): array
    {
        unset($forDisplay);
        $hash[] = 'sutore_mp_v3';
        $hash[] = (string) Settings::hizmetBedeli();
        $hash[] = (string) Settings::guvenceBedeli();
        $hash[] = (string) Settings::pricingRevision();

        if ($this->isProductObject($product)) {
            $listing = $this->listings->getCheapestWinnerForParent((int) $product->get_id());
            if ($listing) {
                $hash[] = (string) $listing->campaignStatus;
                $hash[] = (string) ($listing->campaignId ?? 0);
                $hash[] = (string) $listing->asking;
            }
        }

        return $hash;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function filterAvailableVariation(array $data, $product, $variation): array
    {
        unset($product);
        if ($this->shouldPassthrough() || !$this->isProductObject($variation)) {
            return $data;
        }

        $listing = $this->listings->findByVariationId((int) $variation->get_id());
        if (!$listing) {
            return $data;
        }

        $customer = MarketplacePricing::customerPrice($listing);
        $compare = MarketplacePricing::compareAtPrice($listing);

        $data['display_price'] = $customer;
        $data['display_regular_price'] = $compare;
        $data['price_html'] = $this->formatPriceHtml($customer, $compare);

        return $data;
    }

    public function filterPriceHtml(string $html, $product): string
    {
        if ($this->shouldPassthrough() || !$this->isProductObject($product)) {
            return $html;
        }

        if ($product->is_type('variable')) {
            $listing = $this->listings->getCheapestWinnerForParent((int) $product->get_id());
            if (!$listing) {
                return $html;
            }

            // Catalog + PDP (no size): cheapest winner — "+" = starting-from.
            return $this->formatPriceHtml(
                MarketplacePricing::customerPrice($listing),
                MarketplacePricing::compareAtPrice($listing),
                true
            );
        }

        if ($product->is_type('variation')) {
            $listing = $this->listings->findByVariationId((int) $product->get_id());
            if (!$listing) {
                return $html;
            }

            return $this->formatPriceHtml(
                MarketplacePricing::customerPrice($listing),
                MarketplacePricing::compareAtPrice($listing)
            );
        }

        $listing = $this->listings->getCheapestWinnerForParent((int) $product->get_id());
        if (!$listing) {
            return $html;
        }

        return $this->formatPriceHtml(
            MarketplacePricing::customerPrice($listing),
            MarketplacePricing::compareAtPrice($listing)
        );
    }

    private function resolveDisplayPrice($price, object $product)
    {
        $listing = $this->resolveListing($product);
        if ($listing) {
            return MarketplacePricing::customerPrice($listing);
        }

        return $price;
    }

    private function resolveComparePrice($price, object $product)
    {
        $listing = $this->resolveListing($product);
        if ($listing) {
            return MarketplacePricing::compareAtPrice($listing);
        }

        return $price;
    }

    private function resolveListing(object $product): ?\SutoreMarketplace\Modules\Listings\Domain\Listing
    {
        if ($product->is_type('variation') || $product->get_parent_id()) {
            return $this->listings->findByVariationId((int) $product->get_id());
        }

        return $this->listings->getCheapestWinnerForParent((int) $product->get_id());
    }

    private function formatPriceHtml(float $customer, float $compare, bool $fromPrice = false): string
    {
        $html = $compare > $customer
            ? wc_format_sale_price($compare, $customer)
            : wc_price($customer);

        if (!$fromPrice) {
            return $html;
        }

        return $html . sprintf(
            ' <span class="sutore-mp-price-from" aria-label="%s">+</span>',
            esc_attr__('Starting from this price', 'sutore-marketplace')
        );
    }

    private function isProductObject($product): bool
    {
        return is_object($product) && method_exists($product, 'get_id');
    }

    private function shouldPassthrough(): bool
    {
        return is_admin() && !wp_doing_ajax();
    }
}
