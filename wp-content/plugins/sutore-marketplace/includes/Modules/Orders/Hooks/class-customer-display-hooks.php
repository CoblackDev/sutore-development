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
        if (!$listing || !$listing->id) {
            return $name;
        }

        $fulfillment = (new FulfillmentRepository())->findActiveByListingId((int) $listing->id);
        if (!$fulfillment) {
            $fulfillment = (new FulfillmentRepository())->findByListingId((int) $listing->id);
        }
        if (!$fulfillment) {
            return $name;
        }

        $label = ListingStatus::label($fulfillment->fulfillment_status);
        $track = '';
        $order = wc_get_order((int) $fulfillment->order_id);
        $shipmentType = $order ? (string) $order->get_meta(ShipmentMeta::TYPE) : 'standard';

        if ($fulfillment->fulfillment_status === ListingStatus::SHIPPED_TO_SUTORE && $fulfillment->merchant_shipment_code) {
            $url = ShipmentTracking::customerTrackUrl('standard', (string) $fulfillment->merchant_shipment_code);
            if ($url !== '') {
                $track = ' <a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Tracking', 'sutore-marketplace') . '</a>';
            }
        }
        if ($fulfillment->fulfillment_status === ListingStatus::SHIPPED && $fulfillment->sutore_shipment_code) {
            $url = ShipmentTracking::customerTrackUrl($shipmentType, (string) $fulfillment->sutore_shipment_code);
            if ($url !== '') {
                $track = ' <a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Tracking', 'sutore-marketplace') . '</a>';
            }
        }

        return $name . ' <small>(' . esc_html($label) . $track . ')</small>';
    }
}
