<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Services\PayoutLineService;
use SutoreMarketplace\Modules\Orders\Domain\StaffBulkAction;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class PaymentReservationCommands
{
    public function __construct(
        private readonly FulfillmentCommandSupport $support,
        private readonly FulfillmentRepository $repo,
    ) {
    }

    public function onPaymentComplete(Listing $listing, int $orderId, int $orderItemId): true|\WP_Error
    {
        $listingId = (int) $listing->variationId;
        if ($listingId <= 0) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $existing = $this->repo->findActiveByVariationId($listingId);
        $requireAdmin = Settings::requireAdminPaymentConfirm();
        if ($existing) {
            $existingOrderId = (int) ($existing->order_id ?? 0);
            if ($existingOrderId > 0 && $existingOrderId !== $orderId) {
                return new \WP_Error(
                    'sutore_marketplace_already_linked',
                    __('Product is already linked to an order.', 'sutore-marketplace')
                );
            }

            $status = (string) ($existing->fulfillment_status ?? '');
            if ($status === ListingStatus::PAYMENT && !$requireAdmin) {
                return $this->adminConfirmPayment($listingId);
            }

            return true;
        }

        $order = wc_get_order($orderId);
        $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
        $vars = $order ? $this->support->templateVars($order, $listing, $title) : ['product' => $title, 'order_id' => (string) $orderId];

        $bridge = $this->support->bridge();

        if ($requireAdmin) {
            $result = $bridge->markPaymentPending($listingId, $orderId, $orderItemId);
            if (is_wp_error($result)) {
                return $result;
            }

            $this->repo->update($listingId, [
                'merchant_snapshot' => wp_json_encode(MerchantSnapshot::capture($listing->merchantId)),
            ]);

            Notifications::notifyAdmins('sale_admin', $vars, true);
            WebhookNotifier::dispatch('fulfillment.payment', [
                'variation_id' => $listingId,
                'order_id' => $orderId,
            ]);

            $this->support->logListingEvent('fulfillment_payment', $listing, [
                'variation_id' => $listingId,
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'to_status' => ListingStatus::PAYMENT,
            ]);

            return true;
        }

        $sold = $bridge->markSold($listingId, $orderId, $orderItemId);
        if (is_wp_error($sold)) {
            return $sold;
        }

        $this->repo->update($listingId, [
            'confirm_deadline_at' => DeadlineCalculator::fromNow(Settings::confirmDeadlineSeconds($listing->merchantId)),
            'confirm_notice_sent' => 0,
            'confirm_punished' => 0,
            'merchant_snapshot' => wp_json_encode(MerchantSnapshot::capture($listing->merchantId)),
        ]);

        $this->support->notifySaleApproved($listing, $orderId, $listingId);
        WebhookNotifier::dispatch('fulfillment.sold', [
            'variation_id' => $listingId,
            'order_id' => $orderId,
        ]);

        $this->support->logListingEvent('fulfillment_sold', $listing, [
            'variation_id' => $listingId,
            'order_id' => $orderId,
            'to_status' => ListingStatus::SOLD,
        ]);

        return true;
    }

    /**
     * Reserve listing for unpaid on-hold gateways (BACS etc.) without “paid” side effects.
     */
    public function onPaymentReserved(Listing $listing, int $orderId, int $orderItemId): true|\WP_Error
    {
        $listingId = (int) $listing->variationId;
        if ($listingId <= 0) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $existing = $this->repo->findActiveByVariationId($listingId);
        if ($existing) {
            $existingOrderId = (int) ($existing->order_id ?? 0);
            if ($existingOrderId > 0 && $existingOrderId !== $orderId) {
                return new \WP_Error(
                    'sutore_marketplace_already_linked',
                    __('Product is already linked to an order.', 'sutore-marketplace')
                );
            }

            return true;
        }

        $result = $this->support->bridge()->markPaymentPending($listingId, $orderId, $orderItemId);
        if (is_wp_error($result)) {
            return $result;
        }

        $this->repo->update($listingId, [
            'merchant_snapshot' => wp_json_encode(MerchantSnapshot::capture($listing->merchantId)),
        ]);

        WebhookNotifier::dispatch('fulfillment.payment_reserved', [
            'variation_id' => $listingId,
            'order_id' => $orderId,
        ]);

        $this->support->logListingEvent('fulfillment_payment', $listing, [
            'variation_id' => $listingId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'to_status' => ListingStatus::PAYMENT,
            'reserved' => true,
        ]);

        return true;
    }

    public function adminConfirmPayment(int $listingId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }
        if ($row->fulfillment_status !== ListingStatus::PAYMENT) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Only sales awaiting payment confirmation can be confirmed.', 'sutore-marketplace'));
        }

        $bridge = $this->support->bridge();
        $listing = $bridge->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $sold = $bridge->markSold($listingId, (int) $row->order_id, (int) $row->order_item_id);
        if (is_wp_error($sold)) {
            return $sold;
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::SOLD,
            'confirm_deadline_at' => DeadlineCalculator::fromNow(Settings::confirmDeadlineSeconds((int) $row->merchant_id)),
            'confirm_notice_sent' => 0,
            'confirm_punished' => 0,
        ]);

        $this->support->notifySaleApproved($listing, (int) $row->order_id, $listingId);
        $this->support->logListingEvent('fulfillment_payment_confirmed', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'from_status' => ListingStatus::PAYMENT,
            'to_status' => ListingStatus::SOLD,
        ]);
        $this->support->logListingEvent('fulfillment_sold', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'to_status' => ListingStatus::SOLD,
        ]);
        WebhookNotifier::dispatch('fulfillment.sold', [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        return true;
    }

    /**
     * Staff: add a market listing to a WooCommerce order.
     * Paid orders (processing/completed) start as sold; unpaid (pending/on-hold) wait for payment.
     *
     * @param array{staff_note?:string,allow_open_orders?:bool} $args
     */
    public function attachToOrder(int $listingId, int $orderId, array $args = []): true|\WP_Error
    {
        if (!Settings::allowManualOrderLink()) {
            return new \WP_Error(
                'sutore_marketplace_manual_link_disabled',
                __('Manual order linking is disabled.', 'sutore-marketplace')
            );
        }

        $listing = $this->support->bridge()->find($listingId);
        if (!$listing || !$listing->variationId) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        if (!ListingStatus::allowsManualOrderAttach($listing->listingStatus)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('This product cannot be added to an order in its current status.', 'sutore-marketplace')
            );
        }

        if ($listing->orderId || ListingStatus::isInSaleLifecycle($listing->listingStatus)) {
            return new \WP_Error(
                'sutore_marketplace_already_linked',
                __('Product is already linked to an order.', 'sutore-marketplace')
            );
        }

        if ($this->repo->findActiveByVariationId($listingId)) {
            return new \WP_Error(
                'sutore_marketplace_already_linked',
                __('Product is already linked to an order.', 'sutore-marketplace')
            );
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return new \WP_Error('sutore_marketplace_fulfillment_order', __('Order not found.', 'sutore-marketplace'));
        }

        $allowOpen = !empty($args['allow_open_orders']);
        if ($order->has_status(['cancelled', 'refunded', 'failed', 'trash'])) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('This order cannot receive new products in its current status.', 'sutore-marketplace')
            );
        }

        $startAsSold = $order->has_status(['processing', 'completed']);
        $startAsPayment = $order->has_status(['pending', 'on-hold']);
        if (!$allowOpen && !$order->has_status('processing')) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('Only processing orders can receive a manual product attachment.', 'sutore-marketplace')
            );
        }
        if (!$startAsSold && !$startAsPayment) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('This order cannot receive new products in its current status.', 'sutore-marketplace')
            );
        }

        $product = wc_get_product($listing->variationId);
        if (!$product) {
            return new \WP_Error('sutore_marketplace_fulfillment_product', __('Product not found.', 'sutore-marketplace'));
        }

        $itemId = $order->add_product($product, 1);
        if (!$itemId) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('Could not add the product to the order.', 'sutore-marketplace')
            );
        }

        $item = $order->get_item($itemId);
        if ($item instanceof \WC_Order_Item_Product) {
            (new \SutoreMarketplace\Modules\Orders\Hooks\OrderItemPricingMetaHooks())->applyMarketplaceLineTotals($item);
            $item->save();
        }

        $order->calculate_totals();
        if (method_exists($order, 'recalculate_coupons')) {
            $order->recalculate_coupons();
        }
        $order->save();

        $staffNote = sanitize_textarea_field((string) ($args['staff_note'] ?? ''));
        $actor = $this->support->resolveActor();
        $fresh = $listing;

        if ($startAsPayment) {
            $pending = $this->support->bridge()->markPaymentPending($listingId, $orderId, (int) $itemId);
            if (is_wp_error($pending)) {
                return $pending;
            }

            $this->repo->update($listingId, [
                'merchant_snapshot' => wp_json_encode(MerchantSnapshot::capture($listing->merchantId)),
            ]);

            $fresh = $this->support->bridge()->find($listingId) ?: $listing;
            $title = Notifications::productTitle($listingId, $fresh->variationId, $fresh->parentProductId);
            Notifications::notifyAdmins('sale_admin', $this->support->templateVars($order, $fresh, $title), true);

            $payload = [
                'variation_id' => $listingId,
                'order_id' => $orderId,
                'order_item_id' => (int) $itemId,
                'attachment_mode' => 'manual',
                'to_status' => ListingStatus::PAYMENT,
                'actor_user_id' => $actor['user_id'],
                'actor_login' => $actor['login'],
                'staff_note' => $staffNote,
            ];
            $this->support->logListingEvent('order_listing_attached', $fresh, $payload);
            $this->support->logListingEvent('fulfillment_payment', $fresh, $payload);
            WebhookNotifier::dispatch('fulfillment.payment', [
                'variation_id' => $listingId,
                'order_id' => $orderId,
                'attachment_mode' => 'manual',
            ]);

            return true;
        }

        $sold = $this->support->bridge()->markSold($listingId, $orderId, (int) $itemId);
        if (is_wp_error($sold)) {
            return $sold;
        }

        $this->repo->update($listingId, [
            'confirm_deadline_at' => DeadlineCalculator::fromNow(Settings::confirmDeadlineSeconds($listing->merchantId)),
            'confirm_notice_sent' => 0,
            'confirm_punished' => 0,
            'merchant_snapshot' => wp_json_encode(MerchantSnapshot::capture($listing->merchantId)),
        ]);

        $fresh = $this->support->bridge()->find($listingId) ?: $listing;
        $this->support->notifySaleApproved($fresh, $orderId, $listingId);

        $payload = [
            'variation_id' => $listingId,
            'order_id' => $orderId,
            'order_item_id' => (int) $itemId,
            'attachment_mode' => 'manual',
            'to_status' => ListingStatus::SOLD,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
            'staff_note' => $staffNote,
        ];
        $this->support->logListingEvent('order_listing_attached', $fresh, $payload);
        $this->support->logListingEvent('fulfillment_sold', $fresh, $payload);
        WebhookNotifier::dispatch('fulfillment.sold', [
            'variation_id' => $listingId,
            'order_id' => $orderId,
            'attachment_mode' => 'manual',
        ]);

        $billingPhone = (string) $order->get_billing_phone();
        if ($billingPhone !== '') {
            $title = Notifications::productTitle($listingId, $fresh->variationId, $fresh->parentProductId);
            Notifications::sendEvent(
                'manual_order_attach_customer',
                $billingPhone,
                array_merge($this->support->templateVars($order, $fresh, $title), [
                    'order_id' => (string) $orderId,
                ])
            );
        }

        return true;
    }

    /**
     * Processing WooCommerce orders for the staff “add to order” dropdown.
     *
     * Lists processing orders (newest first). When variation_id is provided, orders that
     * already contain the same parent product are sorted to the top.
     *
     * @param array{variation_id?:int,search?:string} $args
     * @return list<array{id:int,label:string,status:string,total_display:string,contains_same_product:bool}>
     */
    public function listProcessingOrdersForAttach(int $limit = 50, array $args = []): array
    {
        $limit = max(1, min(100, $limit));
        $variationId = (int) ($args['variation_id'] ?? 0);
        $search = sanitize_text_field((string) ($args['search'] ?? ''));
        $parentId = 0;
        if ($variationId > 0) {
            $listing = $this->support->bridge()->find($variationId);
            $parentId = $listing ? (int) $listing->parentProductId : 0;
        }

        $orders = [];
        if ($search !== '' && preg_match('/^\d+$/', $search)) {
            $order = wc_get_order((int) $search);
            if ($order instanceof \WC_Order && $order->has_status('processing')) {
                $orders = [$order];
            }
        } else {
            $query = [
                'status' => ['processing'],
                'limit' => $search !== '' ? $limit : max($limit * 3, 100),
                'orderby' => 'date',
                'order' => 'DESC',
                'return' => 'objects',
            ];
            if ($search !== '') {
                $query['s'] = $search;
            }
            $fetched = wc_get_orders($query);
            $orders = is_array($fetched) ? $fetched : [];
        }

        $same = [];
        $other = [];
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $containsSame = $parentId > 0 && $this->support->orderContainsParentProduct($order, $parentId);

            $id = (int) $order->get_id();
            $name = trim($order->get_formatted_billing_full_name());
            $total = MarketplacePricing::formatTl((float) $order->get_total());
            $label = sprintf(
                /* translators: 1: order id, 2: customer name, 3: order total */
                __('Order #%1$d — %2$s — %3$s', 'sutore-marketplace'),
                $id,
                $name !== '' ? $name : __('Customer', 'sutore-marketplace'),
                $total
            );
            if ($containsSame) {
                $label .= ' · ' . __('Same product', 'sutore-marketplace');
            }
            $row = [
                'id' => $id,
                'label' => $label,
                'status' => $order->get_status(),
                'total_display' => $total,
                'contains_same_product' => $containsSame,
            ];
            if ($containsSame) {
                $same[] = $row;
            } else {
                $other[] = $row;
            }
        }

        return array_slice(array_merge($same, $other), 0, $limit);
    }

    public function splitFromOrder(
        int $listingId,
        bool $notifyCustomer = true,
        string $detachReason = 'split',
        string $staffNote = '',
        bool $returnToQueue = false
    ): true|\WP_Error {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        if (!ListingStatus::allowsDetach((string) $row->fulfillment_status)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('This product can no longer be detached from the order. Use an intervention action instead.', 'sutore-marketplace')
            );
        }

        if (!$this->isLinkedToOrder($row)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_not_linked',
                __('This product is not linked to an order.', 'sutore-marketplace')
            );
        }

        if ($returnToQueue) {
            return new \WP_Error(
                'sutore_marketplace_detach_no_relist',
                __('Order-detached products cannot be put back on sale. Create a new product instead.', 'sutore-marketplace')
            );
        }

        if ($detachReason !== 'swap_out') {
            $note = $this->support->requireStaffNote(['staff_note' => $staffNote]);
            if (is_wp_error($note)) {
                return $note;
            }
            $staffNote = $note;
        } else {
            $staffNote = sanitize_textarea_field($staffNote);
        }

        $actor = $this->support->resolveActor();
        $listing = $this->support->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if ($order && (int) $row->order_item_id > 0) {
            $this->support->addSplitOrderNote($order, $listing, $actor);
            $order->remove_item((int) $row->order_item_id);
            $order->calculate_totals();
            $order->save();
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::ORDER_DETACHED,
            'order_item_id' => 0,
        ]);
        $this->support->bridge()->releaseFromOrder($listingId, ListingStatus::ORDER_DETACHED, [
            'reason' => $detachReason,
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) $row->order_item_id,
            'variation_id' => $listingId,
            'staff_note' => $staffNote,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ]);

        if ($listing) {
            $this->support->logListingEvent('listing_left_sale', $listing, [
                'variation_id' => $listingId,
                'order_id' => (int) $row->order_id,
                'reason' => $detachReason,
                'staff_note' => $staffNote,
            ], $row);
        }

        if ($notifyCustomer && $order && $listing) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->support->templateVars($order, $listing, $title);
            Notifications::sendEvent('suspended_customer', (string) $order->get_billing_phone(), $vars);
        }

        if ($listing) {
            // Pre-order board uses listing rows — no separate sourcing_requests table.
        }

        WebhookNotifier::dispatch('fulfillment.split', [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ]);

        return true;
    }

    /**
     * True when the sale still has a live WooCommerce order line item.
     */
    public function isLinkedToOrder(object $row): bool
    {
        $orderId = (int) ($row->order_id ?? 0);
        $itemId = (int) ($row->order_item_id ?? 0);
        if ($orderId <= 0 || $itemId <= 0) {
            return false;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return false;
        }

        $item = $order->get_item($itemId);

        return $item !== false && $item !== null;
    }

    public function onWooCommerceOrderCancelled(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        if ($order->get_meta('_sutore_mp_cancel_release_done') === 'yes') {
            return;
        }
        $order->update_meta_data('_sutore_mp_cancel_release_done', 'yes');
        $order->save();

        $lateTitles = [];
        foreach ($this->repo->findByOrderId($orderId) as $row) {
            $listingId = (int) ($row->variation_id ?? $row->id ?? 0);
            $status = (string) ($row->fulfillment_status ?? $row->listing_status ?? '');
            if ($listingId <= 0 || $status === '') {
                continue;
            }

            if (ListingStatus::allowsEarlyOrderCancelRelease($status)) {
                $listing = $this->support->bridge()->find($listingId);
                $this->repo->update($listingId, [
                    'fulfillment_status' => ListingStatus::ORDER_DETACHED,
                    'order_item_id' => 0,
                ]);
                $this->support->detachListingFromOrder($listingId, ListingStatus::ORDER_DETACHED, [
                    'reason' => 'cancelled',
                    'order_id' => $orderId,
                    'order_item_id' => (int) ($row->order_item_id ?? 0),
                    'variation_id' => $listingId,
                ]);
                if ($listing) {
                    $this->support->logListingEvent('listing_left_sale', $listing, [
                        'variation_id' => $listingId,
                        'order_id' => $orderId,
                        'reason' => 'cancelled',
                    ], $row);
                }
                continue;
            }

            if (ListingStatus::isLateFulfillment($status)) {
                $listing = $this->support->bridge()->find($listingId);
                $title = $listing
                    ? Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId)
                    : ('#' . $listingId);
                $lateTitles[] = $title;
            }
        }

        if ($lateTitles !== []) {
            Notifications::notifyAdmins('order_cancelled_open_fulfillment', [
                'order_id' => (string) $orderId,
                'product' => implode(', ', $lateTitles),
                'status' => ListingStatus::label(ListingStatus::SHIPPED_TO_SUTORE),
            ], true);
        }
    }
}