<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;

final class SourcingBridge
{
    public function onFulfilled(int $requestId, object $row): void
    {
        if (empty($row->accepted_merchant_id)) {
            return;
        }

        global $wpdb;
        $listingsTable = $wpdb->prefix . 'sutore_marketplace_listings';
        $listingRow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$listingsTable}
             WHERE sourcing_request_id = %d
             LIMIT 1",
            $requestId
        ));

        if (!$listingRow) {
            return;
        }

        /** @var ListingOrderBridge $bridge */
        $bridge = new ListingOrderBridge();
        $listing = $bridge->find((int) $listingRow->id);
        if (!$listing) {
            return;
        }

        $order = wc_get_order((int) $row->order_id);
        if (!$order) {
            return;
        }

        $fulfillmentRepo = new FulfillmentRepository();
        $orderItemId = (int) ($row->order_item_id ?? 0);
        if ($orderItemId > 0) {
            $oldFulfillment = $fulfillmentRepo->findByOrderItem((int) $row->order_id, $orderItemId);
            if ($oldFulfillment) {
                (new FulfillmentService())->splitFromOrder((int) $oldFulfillment->id, false);
            } elseif ($orderItemId) {
                $order->remove_item($orderItemId);
                $order->calculate_totals();
                $order->save();
            }
        }

        $product = wc_get_product($listing->variationId);
        if (!$product) {
            return;
        }

        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->save();

        $newItemId = 0;
        foreach ($order->get_items() as $itemId => $item) {
            if ((int) $item->get_variation_id() === $listing->variationId) {
                $newItemId = (int) $itemId;
                break;
            }
        }

        (new FulfillmentService())->onPaymentComplete($listing, (int) $row->order_id, $newItemId);
    }
}
