<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;

final class CartPricingHooks
{
    public function register(): void
    {
        add_action('woocommerce_before_calculate_totals', [$this, 'applyCartPrices'], 20);
        add_filter('woocommerce_cart_item_price', [$this, 'filterCartItemPrice'], 20, 3);
    }

    public function applyCartPrices($cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if ($this->shouldPassthrough()) {
            return;
        }

        $variationIds = [];
        foreach ($cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product || !is_object($product)) {
                continue;
            }
            $variationIds[] = (int) ($item['variation_id'] ?? $product->get_id());
        }

        $repo = new ListingRepository();
        $byVariation = $repo->findByVariationIds($variationIds);

        foreach ($cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product || !is_object($product)) {
                continue;
            }

            $variationId = (int) ($item['variation_id'] ?? $product->get_id());
            $listing = $byVariation[$variationId] ?? null;
            if (!$listing) {
                continue;
            }

            $product->set_price(MarketplacePricing::customerPrice($listing));
        }
    }

    public function filterCartItemPrice(string $priceHtml, array $cartItem, string $cartItemKey): string
    {
        unset($cartItemKey);
        if ($this->shouldPassthrough()) {
            return $priceHtml;
        }

        $variationId = (int) ($cartItem['variation_id'] ?? 0);
        if (!$variationId) {
            return $priceHtml;
        }

        $listing = (new ListingRepository())->findByVariationId($variationId);
        if (!$listing) {
            return $priceHtml;
        }

        return wc_price(MarketplacePricing::customerPrice($listing));
    }

    private function shouldPassthrough(): bool
    {
        return is_admin() && !wp_doing_ajax();
    }
}
