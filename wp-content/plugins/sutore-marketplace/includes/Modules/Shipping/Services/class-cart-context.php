<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Services;

use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Modules\Shipping\Settings\ShippingSettings;

final class CartContext
{
    /** @param array<int, array<string, mixed>> $cartItems */
    public function __construct(
        private readonly array $cartItems = [],
    ) {
    }

    public static function fromCart(): self
    {
        if (!function_exists('WC') || !WC()->cart) {
            return new self([]);
        }

        return new self(WC()->cart->get_cart());
    }

    public function itemCount(): int
    {
        return count($this->cartItems);
    }

    public function hasImportedItems(): bool
    {
        foreach ($this->cartItems as $values) {
            $product = $values['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $lookupId = (int) $product->get_id();
            if (ImportedProductService::isVariationImported($lookupId)) {
                return true;
            }
        }

        return false;
    }

    public function allItemsExpressEligible(): bool
    {
        if ($this->cartItems === []) {
            return false;
        }

        $variationIds = [];
        foreach ($this->cartItems as $values) {
            $product = $values['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                return false;
            }
            $variationIds[] = (int) $product->get_id();
        }

        $byVariation = (new ListingRepository())->findByVariationIds($variationIds);
        foreach ($variationIds as $variationId) {
            $listing = $byVariation[$variationId] ?? null;
            if (!$listing || !$listing->fastShipment) {
                return false;
            }
        }

        return true;
    }

    public function customerHasCompletedOrder(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        if (!function_exists('wc_get_orders')) {
            return false;
        }

        $orders = wc_get_orders([
            'customer_id' => get_current_user_id(),
            'status' => ['completed'],
            'limit' => 1,
            'return' => 'ids',
        ]);

        return $orders !== [];
    }

    public function expressFee(): float
    {
        if (!$this->allItemsExpressEligible()) {
            return 0.0;
        }

        return ShippingSettings::expressBaseFee()
            + ($this->itemCount() * ShippingSettings::expressPerItemSurcharge());
    }
}
