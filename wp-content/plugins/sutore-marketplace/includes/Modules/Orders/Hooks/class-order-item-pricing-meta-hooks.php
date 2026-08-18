<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Hooks;

use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

/**
 * Snapshot marketplace fee breakdown onto order line items at checkout.
 */
final class OrderItemPricingMetaHooks
{
    public const META_ASKING = '_sutore_mp_asking';
    public const META_HIZMET = '_sutore_mp_hizmet_bedeli';
    public const META_GUVENCE = '_sutore_mp_guvence_bedeli';
    public const META_WAIVER = '_sutore_mp_platform_waiver';
    public const META_CUSTOMER_UNIT = '_sutore_mp_customer_unit';

    public function register(): void
    {
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'onCreateLineItem'], 20, 4);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'onStoreApiOrder'], 20, 2);
        add_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'formatMeta'], 10, 2);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function onCreateLineItem(\WC_Order_Item_Product $item, string $cartItemKey, array $values, \WC_Order $order): void
    {
        unset($cartItemKey, $values, $order);
        $this->attachMeta($item);
    }

    public function onStoreApiOrder(\WC_Order $order, $request): void
    {
        unset($request);
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $this->attachMeta($item);
            }
        }
    }

    public function attachMeta(\WC_Order_Item_Product $item): void
    {
        $variationId = (int) $item->get_variation_id();
        if ($variationId <= 0) {
            return;
        }

        $listing = (new ListingRepository())->findByVariationId($variationId);
        if (!$listing) {
            return;
        }

        $fees = MarketplacePricing::feeBreakdownForListing($listing);
        $customerUnit = MarketplacePricing::customerPrice($listing);

        $item->add_meta_data(self::META_ASKING, MarketplacePricing::activeAsking($listing), true);
        $item->add_meta_data(self::META_HIZMET, $fees['hizmet'], true);
        $item->add_meta_data(self::META_GUVENCE, $fees['guvence'], true);
        $item->add_meta_data(self::META_WAIVER, $fees['waiver'], true);
        $item->add_meta_data(self::META_CUSTOMER_UNIT, $customerUnit, true);
    }

    /**
     * Force the WC line totals to the marketplace customer price (manual attach/swap).
     */
    public function applyMarketplaceLineTotals(\WC_Order_Item_Product $item): void
    {
        $this->attachMeta($item);
        $customerUnit = (float) $item->get_meta(self::META_CUSTOMER_UNIT, true);
        if ($customerUnit <= 0) {
            return;
        }
        $qty = max(1, (int) $item->get_quantity());
        $lineTotal = round($customerUnit * $qty, 2);
        $item->set_subtotal($lineTotal);
        $item->set_total($lineTotal);
    }

    /**
     * @param array<int, object> $formattedMeta
     * @return array<int, object>
     */
    public function formatMeta(array $formattedMeta, $item): array
    {
        if (!$item instanceof \WC_Order_Item_Product) {
            return $formattedMeta;
        }

        $labels = [
            self::META_ASKING => __('Seller price', 'sutore-marketplace'),
            self::META_HIZMET => __('Service fee', 'sutore-marketplace'),
            self::META_GUVENCE => __('Guarantee fee', 'sutore-marketplace'),
            self::META_WAIVER => __('Platform campaign waiver', 'sutore-marketplace'),
            self::META_CUSTOMER_UNIT => __('Customer unit price', 'sutore-marketplace'),
        ];

        foreach ($formattedMeta as $meta) {
            $key = (string) ($meta->key ?? '');
            if (isset($labels[$key])) {
                $meta->display_key = $labels[$key];
                if (is_numeric($meta->value)) {
                    $meta->display_value = wc_price((float) $meta->value);
                }
            }
        }

        return $formattedMeta;
    }
}
