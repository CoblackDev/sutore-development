<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Hooks;

use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Support\ShipmentTracking;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;

final class CustomerDisplayHooks
{
    public function register(): void
    {
        add_filter('woocommerce_order_item_name', [$this, 'appendStatusLabel'], 20, 2);
    }

    public function appendStatusLabel(string $name, $item): string
    {
        if (is_admin() && !wp_doing_ajax()) {
            return $name;
        }
        if (!is_wc_endpoint_url('view-order') && !is_wc_endpoint_url('order-received')) {
            return $name;
        }

        $variationId = (int) $item->get_variation_id() ?: (int) $item->get_product_id();
        $listing = (new ListingRepository())->findByVariationId($variationId);
        if (!$listing || !$listing->variationId) {
            return $name;
        }

        $fulfillment = (new FulfillmentRepository())->findActiveByVariationId((int) $listing->variationId);
        if (!$fulfillment) {
            $fulfillment = (new FulfillmentRepository())->findByVariationId((int) $listing->variationId);
        }
        if (!$fulfillment) {
            return $name;
        }

        $order = wc_get_order((int) $fulfillment->order_id);
        $shipmentType = $order ? (string) $order->get_meta(ShipmentMeta::TYPE) : 'standard';
        if ($shipmentType === '') {
            $shipmentType = 'standard';
        }

        $status = (string) $fulfillment->fulfillment_status;
        $label = ListingStatus::customerLabel($status, $shipmentType);
        $track = '';

        if ($status === ListingStatus::SHIPPED_TO_SUTORE && $fulfillment->merchant_shipment_code) {
            $url = ShipmentTracking::customerTrackUrl('standard', (string) $fulfillment->merchant_shipment_code);
            if ($url !== '') {
                $track = ' — <a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Track', 'sutore-marketplace') . '</a>';
            }
        }
        if ($status === ListingStatus::SHIPPED && $fulfillment->sutore_shipment_code) {
            $url = ShipmentTracking::customerTrackUrl($shipmentType, (string) $fulfillment->sutore_shipment_code);
            if ($url !== '') {
                $track = ' — <a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Track', 'sutore-marketplace') . '</a>';
            }
        }

        return $name . ' <small>(' . esc_html($label) . $track . ')</small>';
    }
}
