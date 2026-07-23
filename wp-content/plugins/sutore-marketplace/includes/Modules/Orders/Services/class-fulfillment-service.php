<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Services\PayoutLineService;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

/**
 * Sale / fulfillment lifecycle service.
 *
 * Since the fulfillments table has been eliminated, every "$fulfillmentId"
 * argument in this class is the listing id. The repository still returns rows
 * shaped like the historical fulfillment rows (id / listing_id both = listing
 * id, fulfillment_status mirrors listing_status) so REST / JS consumers stay
 * unchanged. Repository writes go straight to the listing row: setting
 * `fulfillment_status` in the payload is translated to `listing_status`.
 */
final class FulfillmentService
{
    public function __construct(
        private readonly FulfillmentRepository $repo = new FulfillmentRepository(),
    ) {
    }

    private function bridge(): ListingOrderBridge
    {
        return new ListingOrderBridge();
    }

    public function onPaymentComplete(Listing $listing, int $orderId, int $orderItemId): true|\WP_Error
    {
        $listingId = (int) $listing->id;
        if ($listingId <= 0) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Listing not found.', 'sutore-marketplace'));
        }

        $existing = $this->repo->findActiveByListingId($listingId);
        if ($existing) {
            return true;
        }

        $order = wc_get_order($orderId);
        $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
        $vars = $order ? $this->templateVars($order, $listing, $title) : ['product' => $title, 'order_id' => (string) $orderId];

        $bridge = $this->bridge();
        $requireAdmin = Settings::requireAdminPaymentConfirm();

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
                'listing_id' => $listingId,
                'order_id' => $orderId,
            ]);

            $this->logListingEvent('fulfillment_payment', $listing, [
                'listing_id' => $listingId,
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

        $this->notifySaleApproved($listing, $orderId, $listingId);
        WebhookNotifier::dispatch('fulfillment.sold', [
            'listing_id' => $listingId,
            'order_id' => $orderId,
        ]);

        $this->logListingEvent('fulfillment_sold', $listing, [
            'listing_id' => $listingId,
            'order_id' => $orderId,
            'to_status' => ListingStatus::SOLD,
        ]);

        return true;
    }

    public function adminConfirmPayment(int $listingId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
        }
        if ($row->fulfillment_status !== ListingStatus::PAYMENT) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Only sales awaiting payment confirmation can be confirmed.', 'sutore-marketplace'));
        }

        $bridge = $this->bridge();
        $listing = $bridge->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Listing not found.', 'sutore-marketplace'));
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

        $this->notifySaleApproved($listing, (int) $row->order_id, $listingId);
        $this->logListingEvent('fulfillment_payment_confirmed', $listing, [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'from_status' => ListingStatus::PAYMENT,
            'to_status' => ListingStatus::SOLD,
        ]);
        $this->logListingEvent('fulfillment_sold', $listing, [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'to_status' => ListingStatus::SOLD,
        ]);
        WebhookNotifier::dispatch('fulfillment.sold', [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        return true;
    }

    public function merchantConfirmSale(int $listingId, int $merchantId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row || (int) $row->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_marketplace_fulfillment_forbidden', __('Unauthorized action.', 'sutore-marketplace'));
        }
        if ($row->fulfillment_status !== ListingStatus::SOLD) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('This sale cannot be confirmed.', 'sutore-marketplace'));
        }

        $order = wc_get_order((int) $row->order_id);
        $shipmentType = $order ? (string) $order->get_meta(ShipmentMeta::TYPE) : 'standard';
        $cargoSeconds = Settings::cargoDeadlineSecondsForShipmentType($shipmentType);
        $cargoDeadline = DeadlineCalculator::fromNow($cargoSeconds);

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::CONFIRMED,
            'seller_confirmed_at' => current_time('mysql'),
            'cargo_deadline_at' => $cargoDeadline,
            'cargo_notice_sent' => 0,
            'cargo_expired_flag' => 0,
        ]);

        $bridge = $this->bridge();
        $listing = $bridge->find($listingId);
        if ($listing && $order) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->templateVars($order, $listing, $title);
            $vars['cargo_hours'] = (string) (int) ($cargoSeconds / HOUR_IN_SECONDS);

            Notifications::sendEvent('seller_confirmed_customer', (string) $order->get_billing_phone(), $vars);
            Notifications::sendEvent('seller_confirmed_seller', Notifications::merchantPhone($merchantId), $vars);
            $this->dispatchMerchantNotification(
                NotificationType::SALE_CONFIRMED,
                $listing,
                $order,
                ['listing_id' => $listingId, 'cargo_hours' => (int) ($cargoSeconds / HOUR_IN_SECONDS)]
            );

            if ($shipmentType === 'international' && Settings::get('international_invoice_required', true)) {
                Notifications::sendEvent('international_warning', Notifications::merchantPhone($merchantId), $vars);
            }
            if ($shipmentType === 'express' && Settings::get('express_block_carrier_shipment', true)) {
                Notifications::sendEvent('express_warning', Notifications::merchantPhone($merchantId), $vars);
                Notifications::notifyExpress('express_warning', $vars);
            }
        }

        WebhookNotifier::dispatch('fulfillment.confirmed', [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        $this->logListingEvent('fulfillment_seller_confirmed', $listing, [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'seller_confirmed_at' => current_time('mysql'),
            'cargo_deadline_at' => $cargoDeadline,
        ], $row);

        return true;
    }

    public function merchantSubmitShipment(int $listingId, int $merchantId, string $shipmentCode): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row || (int) $row->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_marketplace_fulfillment_forbidden', __('Unauthorized action.', 'sutore-marketplace'));
        }
        if ($row->fulfillment_status !== ListingStatus::CONFIRMED) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Shipping can only be entered for confirmed sales.', 'sutore-marketplace'));
        }

        $code = sanitize_text_field($shipmentCode);
        if (!Settings::shipmentCodeValid($code)) {
            return new \WP_Error('sutore_marketplace_fulfillment_code', __('Enter a valid shipping tracking number.', 'sutore-marketplace'));
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::SHIPPED_TO_SUTORE,
            'merchant_shipment_code' => $code,
            'merchant_shipped_at' => current_time('mysql'),
        ]);

        $listing = $this->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if ($listing && $order) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->templateVars($order, $listing, $title);
            $vars['track_code'] = $code;

            Notifications::sendEvent('shipped_to_sutore_customer', (string) $order->get_billing_phone(), $vars);
            Notifications::sendEvent('shipped_to_sutore_seller', Notifications::merchantPhone($merchantId), $vars);
            $this->dispatchMerchantNotification(
                NotificationType::FULFILLMENT_SHIPPED_TO_SUTORE,
                $listing,
                $order,
                ['listing_id' => $listingId]
            );
        }

        WebhookNotifier::dispatch('fulfillment.shipped_to_sutore', [
            'listing_id' => $listingId,
            'track_code' => $code,
        ]);

        $this->logListingEvent('fulfillment_shipped_to_sutore', $listing, [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'merchant_shipment_code' => $code,
        ], $row);

        return true;
    }

    /**
     * @param array{staff_note?:string,sutore_shipment_code?:string} $args
     */
    public function markArrivedAtSutore(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->advanceStatus($listingId, ListingStatus::SHIPPED_TO_SUTORE, ListingStatus::ARRIVED_TO_SUTORE, 'fulfillment_arrived_at_sutore', $args);
    }

    /**
     * @param array{staff_note?:string} $args
     */
    public function markVerified(int $listingId, array $args = []): true|\WP_Error
    {
        $result = $this->advanceStatus($listingId, ListingStatus::ARRIVED_TO_SUTORE, ListingStatus::VERIFIED, 'fulfillment_verified', $args);
        if ($result !== true) {
            return $result;
        }

        $row = $this->repo->find($listingId);
        $listing = $row ? $this->bridge()->find($listingId) : null;
        if ($row && $listing) {
            (new PayoutLineService())->createForListing($row, $listing);
        }

        return true;
    }

    /**
     * @param array{staff_note?:string} $args
     */
    public function markReadyToShip(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->advanceStatus($listingId, ListingStatus::VERIFIED, ListingStatus::READY_TO_SHIPPING, 'fulfillment_ready_to_ship', $args);
    }

    /**
     * @param array{staff_note?:string,sutore_shipment_code?:string} $args
     */
    public function markShippedToCustomer(int $listingId, array $args = []): true|\WP_Error
    {
        $code = sanitize_text_field((string) ($args['sutore_shipment_code'] ?? ''));
        if ($code === '' || !Settings::shipmentCodeValid($code)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_code',
                __('Enter a valid Sutore shipping tracking number.', 'sutore-marketplace')
            );
        }

        return $this->advanceStatus(
            $listingId,
            ListingStatus::READY_TO_SHIPPING,
            ListingStatus::SHIPPED,
            'fulfillment_shipped',
            array_merge($args, ['sutore_shipment_code' => $code])
        );
    }

    /**
     * @param array{staff_note?:string} $args
     */
    public function markDeliveredToCustomer(int $listingId, array $args = []): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
        }
        if ((string) $row->fulfillment_status !== ListingStatus::SHIPPED) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Invalid status.', 'sutore-marketplace'));
        }

        $deliveredAt = current_time('mysql');
        $windowEnds = DeadlineCalculator::fromNow(Settings::returnWindowSeconds());

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::DELIVERED_TO_CUSTOMER,
            'delivered_at' => $deliveredAt,
            'return_window_ends_at' => $windowEnds,
        ]);

        $listing = $this->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if ($listing && $order) {
            $this->notifyStatusChange($listing, $order, ListingStatus::DELIVERED_TO_CUSTOMER, null, $listingId);
        }

        $this->logListingEvent('fulfillment_delivered_to_customer', $listing, array_filter([
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'delivered_at' => $deliveredAt,
            'return_window_ends_at' => $windowEnds,
            'staff_note' => $this->optionalStaffNote($args),
        ], static fn ($value) => $value !== null && $value !== ''), $row);

        WebhookNotifier::dispatch('fulfillment.delivered_to_customer', [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        $this->logListingLifecycleCompleted($this->repo->find($listingId) ?? $row, $listing);
        $this->maybeCompleteOrderWhenAllDelivered((int) $row->order_id);

        return true;
    }

    /**
     * When every marketplace listing still linked to the order is delivered,
     * move the WooCommerce order to completed (triggers customer SMS via hook).
     */
    public function maybeCompleteOrderWhenAllDelivered(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order || $order->has_status(['completed', 'cancelled', 'refunded', 'failed'])) {
            return;
        }

        if (!$order->has_status(['processing', 'on-hold'])) {
            return;
        }

        $rows = $this->repo->findByOrderId($orderId);
        if ($rows === []) {
            return;
        }

        $inSale = [];
        foreach ($rows as $row) {
            $status = (string) ($row->fulfillment_status ?? $row->listing_status ?? '');
            if (ListingStatus::isSaleActive($status)) {
                $inSale[] = $status;
            }
        }

        if ($inSale === []) {
            return;
        }

        foreach ($inSale as $status) {
            if ($status !== ListingStatus::DELIVERED_TO_CUSTOMER) {
                return;
            }
        }

        $order->update_status(
            'completed',
            __('All marketplace items on this order were delivered to the customer.', 'sutore-marketplace')
        );
    }

    /**
     * @param array{staff_note:string} $args
     */
    public function markNotForSale(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->applyIntervention(
            $listingId,
            'mark_not_for_sale',
            ListingStatus::NOT_SALE,
            'listing_left_sale',
            ListingStatus::NOT_SALE,
            $args
        );
    }

    /**
     * @param array{staff_note:string} $args
     */
    public function chargebackFulfillment(int $listingId, array $args = []): true|\WP_Error
    {
        $note = $this->requireStaffNote($args);
        if (is_wp_error($note)) {
            return $note;
        }

        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['chargeback'])) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Invalid status.', 'sutore-marketplace'));
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::CHARGEBACK,
        ]);

        (new PayoutLineService())->reverseForListing($listingId);
        $this->detachListingFromOrder($listingId, ListingStatus::CHARGEBACK, [
            'reason' => 'chargeback',
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) $row->order_item_id,
            'listing_id' => $listingId,
            'staff_note' => $note,
        ]);

        $listing = $this->bridge()->find($listingId);
        $this->logListingEvent('fulfillment_chargeback', $listing, [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'staff_note' => $note,
        ], $row);

        WebhookNotifier::dispatch('fulfillment.chargeback', [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        return true;
    }

    public function putListingOnSale(int $listingId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['put_on_sale'])) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('This product cannot be put back on sale in its current status.', 'sutore-marketplace')
            );
        }

        $result = (new ListingService())->putOnSale($listingId);
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function deleteListing(int $listingId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['delete']) || $this->isLinkedToOrder($row)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('This listing cannot be deleted right now. (In order or payment process.)', 'sutore-marketplace')
            );
        }

        $result = (new ListingService())->delete($listingId, null, [
            'deletion_source' => 'staff_fulfillment',
        ]);
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function markMerchantPayout(int $listingId, string $paymentRef = ''): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
        }

        if (!ListingStatus::allowsPayout((string) $row->fulfillment_status)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Merchant payout cannot be marked in this status.', 'sutore-marketplace')
            );
        }

        $listing = $this->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Listing not found.', 'sutore-marketplace'));
        }

        $payoutService = new PayoutLineService();
        $existing = (new PayoutLineRepository())->findByListingId($listingId);
        if (!$existing) {
            $payoutService->createForListing($row, $listing);
        }

        $paid = $payoutService->markPaid($listingId, get_current_user_id(), $paymentRef);
        if ($paid instanceof \WP_Error) {
            return $paid;
        }

        $order = wc_get_order((int) $row->order_id);
        if ($order) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->templateVars($order, $listing, $title);
            Notifications::sendEvent('paid_seller', Notifications::merchantPhone($listing->merchantId), $vars);
            $line = (new PayoutLineRepository())->findByListingId($listingId);
            $this->dispatchMerchantNotification(
                NotificationType::PAYOUT_PAID,
                $listing,
                $order,
                [
                    'listing_id' => $listingId,
                    'net_amount' => $line ? (float) $line->net_amount : MarketplacePricing::merchantPayout($listing),
                ]
            );
        }

        WebhookNotifier::dispatch('payout.paid', [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        $this->logListingEvent('fulfillment_payout_paid', $listing, [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'payment_ref' => $paymentRef,
        ], $row);

        return true;
    }

    public function swapMerchant(int $listingId, int $newListingId, string $staffNote = ''): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['swap']) || !Settings::swapAllowedFor((string) $row->fulfillment_status)) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Seller cannot be changed in this status.', 'sutore-marketplace'));
        }

        $bridge = $this->bridge();
        $oldListing = $bridge->find($listingId);
        $newListing = $bridge->find($newListingId);
        if (!$newListing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('No new listing found.', 'sutore-marketplace'));
        }

        if ($oldListing && (
            (int) $newListing->parentProductId !== (int) $oldListing->parentProductId
            || (int) $newListing->sizeTermId !== (int) $oldListing->sizeTermId
        )) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Replacement listing must match the same product and size.', 'sutore-marketplace')
            );
        }

        if ($newListing->listingStatus !== 'publish' || !$newListing->isWinner) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Replacement listing must be the active queue winner.', 'sutore-marketplace')
            );
        }

        if ($this->repo->findActiveByListingId((int) $newListing->id)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Replacement listing is already linked to an active fulfillment.', 'sutore-marketplace')
            );
        }

        $order = wc_get_order((int) $row->order_id);
        if (!$order) {
            return new \WP_Error('sutore_marketplace_fulfillment_order', __('Order not found.', 'sutore-marketplace'));
        }

        $note = $staffNote !== '' ? $staffNote : __('Seller swapped by staff.', 'sutore-marketplace');
        $this->splitFromOrder($listingId, false, 'swap_out', $note);

        $newProduct = wc_get_product($newListing->variationId);
        if (!$newProduct) {
            return new \WP_Error('sutore_marketplace_fulfillment_product', __('Product not found.', 'sutore-marketplace'));
        }

        $order->add_product($newProduct, 1);
        $order->calculate_totals();
        if (method_exists($order, 'recalculate_coupons')) {
            $order->recalculate_coupons();
        }
        $order->save();

        $newItemId = 0;
        foreach ($order->get_items() as $itemId => $item) {
            $vid = (int) $item->get_variation_id();
            if ($vid === $newListing->variationId) {
                $newItemId = (int) $itemId;
                break;
            }
        }

        $actor = $this->resolveActor();
        $swapPayload = [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'old_listing_id' => $listingId,
            'new_listing_id' => $newListingId,
            'old_merchant_id' => (int) $row->merchant_id,
            'new_merchant_id' => (int) $newListing->merchantId,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ];

        $this->logListingEvent('order_listing_swapped', $newListing, array_merge($swapPayload, [
            'role' => 'incoming',
        ]), $row);

        $oldListing = $bridge->find($listingId);
        $this->logListingEvent('order_listing_swapped', $oldListing, array_merge($swapPayload, [
            'role' => 'outgoing',
        ]), $row);

        return $this->onPaymentComplete($newListing, (int) $row->order_id, $newItemId);
    }

    /**
     * Staff: add a market listing to a processing WooCommerce order and start the sold lifecycle.
     *
     * @param array{staff_note?:string} $args
     */
    public function attachToOrder(int $listingId, int $orderId, array $args = []): true|\WP_Error
    {
        if (!Settings::allowManualOrderLink()) {
            return new \WP_Error(
                'sutore_marketplace_manual_link_disabled',
                __('Manual order linking is disabled.', 'sutore-marketplace')
            );
        }

        $listing = $this->bridge()->find($listingId);
        if (!$listing || !$listing->id) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Listing not found.', 'sutore-marketplace'));
        }

        if (!ListingStatus::allowsManualOrderAttach($listing->listingStatus)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('This listing cannot be added to an order in its current status.', 'sutore-marketplace')
            );
        }

        if ($listing->orderId || ListingStatus::isInSaleLifecycle($listing->listingStatus)) {
            return new \WP_Error(
                'sutore_marketplace_already_linked',
                __('Listing is already linked to an order.', 'sutore-marketplace')
            );
        }

        if ($this->repo->findActiveByListingId($listingId)) {
            return new \WP_Error(
                'sutore_marketplace_already_linked',
                __('Listing is already linked to an order.', 'sutore-marketplace')
            );
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return new \WP_Error('sutore_marketplace_fulfillment_order', __('Order not found.', 'sutore-marketplace'));
        }

        if (!$order->has_status('processing')) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('Only processing orders can receive a manual listing attachment.', 'sutore-marketplace')
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
            (new \SutoreMarketplace\Modules\Orders\Hooks\OrderItemPricingMetaHooks())->attachMeta($item);
            $item->save();
        }

        $order->calculate_totals();
        if (method_exists($order, 'recalculate_coupons')) {
            $order->recalculate_coupons();
        }
        $order->save();

        $staffNote = sanitize_textarea_field((string) ($args['staff_note'] ?? ''));
        $actor = $this->resolveActor();

        // Simulate a completed sale (sold), matching the old manual attach path.
        $sold = $this->bridge()->markSold($listingId, $orderId, (int) $itemId);
        if (is_wp_error($sold)) {
            return $sold;
        }

        $this->repo->update($listingId, [
            'confirm_deadline_at' => DeadlineCalculator::fromNow(Settings::confirmDeadlineSeconds($listing->merchantId)),
            'confirm_notice_sent' => 0,
            'confirm_punished' => 0,
            'merchant_snapshot' => wp_json_encode(MerchantSnapshot::capture($listing->merchantId)),
        ]);

        $fresh = $this->bridge()->find($listingId) ?: $listing;
        $this->notifySaleApproved($fresh, $orderId, $listingId);

        $payload = [
            'listing_id' => $listingId,
            'order_id' => $orderId,
            'order_item_id' => (int) $itemId,
            'attachment_mode' => 'manual',
            'to_status' => ListingStatus::SOLD,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
            'staff_note' => $staffNote,
        ];
        $this->logListingEvent('order_listing_attached', $fresh, $payload);
        $this->logListingEvent('fulfillment_sold', $fresh, $payload);
        WebhookNotifier::dispatch('fulfillment.sold', [
            'listing_id' => $listingId,
            'order_id' => $orderId,
            'attachment_mode' => 'manual',
        ]);

        $billingPhone = (string) $order->get_billing_phone();
        if ($billingPhone !== '') {
            $title = Notifications::productTitle($listingId, $fresh->variationId, $fresh->parentProductId);
            Notifications::sendEvent(
                'manual_order_attach_customer',
                $billingPhone,
                array_merge($this->templateVars($order, $fresh, $title), [
                    'order_id' => (string) $orderId,
                ])
            );
        }

        return true;
    }

    /**
     * Processing WooCommerce orders for the staff “add to order” dropdown.
     *
     * @return list<array{id:int,label:string,status:string,total_display:string}>
     */
    public function listProcessingOrdersForAttach(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $orders = wc_get_orders([
            'status' => ['processing'],
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ]);

        $out = [];
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $id = (int) $order->get_id();
            $name = trim($order->get_formatted_billing_full_name());
            $total = wp_strip_all_tags(wc_price((float) $order->get_total(), ['currency' => $order->get_currency()]));
            $label = sprintf(
                /* translators: 1: order id, 2: customer name, 3: order total */
                __('Order #%1$d — %2$s — %3$s', 'sutore-marketplace'),
                $id,
                $name !== '' ? $name : __('Customer', 'sutore-marketplace'),
                $total
            );
            $out[] = [
                'id' => $id,
                'label' => $label,
                'status' => $order->get_status(),
                'total_display' => $total,
            ];
        }

        return $out;
    }

    public function splitFromOrder(
        int $listingId,
        bool $notifyCustomer = true,
        string $detachReason = 'split',
        string $staffNote = ''
    ): true|\WP_Error {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
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

        if ($detachReason !== 'swap_out') {
            $note = $this->requireStaffNote(['staff_note' => $staffNote]);
            if (is_wp_error($note)) {
                return $note;
            }
            $staffNote = $note;
        } else {
            $staffNote = sanitize_textarea_field($staffNote);
        }

        $actor = $this->resolveActor();
        $listing = $this->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if ($order && (int) $row->order_item_id > 0) {
            $this->addSplitOrderNote($order, $listing, $actor);
            $order->remove_item((int) $row->order_item_id);
            $order->calculate_totals();
            $order->save();
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::NOT_SALE,
            'order_item_id' => 0,
        ]);
        $this->bridge()->releaseFromOrder($listingId, ListingStatus::NOT_SALE, [
            'reason' => $detachReason,
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) $row->order_item_id,
            'listing_id' => $listingId,
            'staff_note' => $staffNote,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ]);

        if ($listing) {
            $this->logListingEvent('listing_left_sale', $listing, [
                'listing_id' => $listingId,
                'order_id' => (int) $row->order_id,
                'reason' => $detachReason,
                'staff_note' => $staffNote,
            ], $row);
        }

        if ($notifyCustomer && $order && $listing) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->templateVars($order, $listing, $title);
            Notifications::sendEvent('suspended_customer', (string) $order->get_billing_phone(), $vars);
        }

        if ($listing) {
            (new SourcingAutoOpen())->forSplit($listing, (int) $row->order_id, (int) $row->order_item_id);
        }

        WebhookNotifier::dispatch('fulfillment.split', [
            'listing_id' => $listingId,
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

    /**
     * @param array<string, mixed> $context
     */
    private function detachListingFromOrder(int $listingId, string $newStatus, array $context = []): void
    {
        $actor = $this->resolveActor();
        $this->bridge()->releaseFromOrder($listingId, $newStatus, array_merge([
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ], $context));
    }

    /**
     * @return array{user_id: int, login: string}
     */
    private function resolveActor(): array
    {
        $userId = get_current_user_id();
        if ($userId > 0) {
            $user = get_userdata($userId);
            return [
                'user_id' => $userId,
                'login' => $user && $user->user_login !== '' ? $user->user_login : (string) $userId,
            ];
        }

        return [
            'user_id' => 0,
            'login' => 'system',
        ];
    }

    /**
     * @param array{user_id: int, login: string} $actor
     */
    private function addSplitOrderNote(\WC_Order $order, ?Listing $listing, array $actor): void
    {
        $variationId = $listing ? $listing->variationId : 0;
        $productName = '';
        $sellerLogin = __('unknown seller', 'sutore-marketplace');

        if ($listing) {
            $productName = Notifications::productTitle((int) $listing->id, $listing->variationId, $listing->parentProductId);
            $user = get_userdata($listing->merchantId);
            if ($user && $user->user_login !== '') {
                $sellerLogin = $user->user_login;
            } else {
                $sellerLogin = (string) $listing->merchantId;
            }
        }

        if ($productName === '' && $variationId > 0) {
            $product = wc_get_product($variationId);
            $productName = $product ? $product->get_name() : '';
        }

        $actorLabel = $actor['login'] === 'system'
            ? __('system', 'sutore-marketplace')
            : $actor['login'];

        /* translators: 1: variation ID 2: product name 3: merchant username 4: staff/system username who detached the item */
        $note = sprintf(
            __('%1$d %2$s (%3$s) was detached from the order by %4$s.', 'sutore-marketplace'),
            $variationId,
            $productName !== '' ? $productName : __('(untitled product)', 'sutore-marketplace'),
            $sellerLogin,
            $actorLabel
        );

        $meta = [];
        if (class_exists(\Automattic\WooCommerce\Internal\Orders\OrderNoteGroup::class)) {
            $meta['note_group'] = \Automattic\WooCommerce\Internal\Orders\OrderNoteGroup::FULFILLMENT;
        }

        $order->add_order_note($note, false, $actor['user_id'] > 0, $meta);
    }

    public function processDeadline(object $row): void
    {
        $listing = $this->bridge()->find((int) $row->listing_id);
        if ($listing && ImportedProductService::isVariationImported($listing->variationId)) {
            return;
        }

        if ($row->fulfillment_status === ListingStatus::SOLD) {
            $this->processConfirmDeadline($row);
        } elseif ($row->fulfillment_status === ListingStatus::CONFIRMED) {
            $this->processCargoDeadline($row);
        }
    }

    private function processConfirmDeadline(object $row): void
    {
        $listingId = (int) $row->listing_id;
        $listing = $this->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if (!$listing || !$order) {
            return;
        }

        $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
        $vars = $this->templateVars($order, $listing, $title);
        $vars['confirm_hours'] = (string) (int) (Settings::confirmGraceSeconds() / HOUR_IN_SECONDS);
        $merchantPhone = Notifications::merchantPhone((int) $row->merchant_id);
        $customerPhone = (string) $order->get_billing_phone();

        if (!(int) $row->confirm_notice_sent) {
            Notifications::sendEvent('seller_confirm_reminder', $merchantPhone, $vars, true);
            $this->dispatchMerchantNotification(
                NotificationType::SALE_CONFIRM_REMINDER,
                $listing,
                $order,
                [
                    'listing_id' => $listingId,
                    'confirm_hours' => (int) (Settings::confirmGraceSeconds() / HOUR_IN_SECONDS),
                ]
            );
            $this->repo->update($listingId, [
                'confirm_notice_sent' => 1,
                'confirm_deadline_at' => DeadlineCalculator::fromNow(Settings::confirmGraceSeconds()),
            ]);
            $this->logListingEvent('fulfillment_confirm_reminder', $listing, [
                'listing_id' => $listingId,
                'order_id' => (int) $row->order_id,
            ], $row);
            return;
        }

        if (!(int) $row->confirm_punished) {
            $this->repo->update($listingId, [
                'fulfillment_status' => ListingStatus::NOT_SALE,
                'confirm_punished' => 1,
            ]);
            $this->bridge()->releaseFromOrder($listingId, ListingStatus::NOT_SALE, [
                'reason' => 'confirm_deadline',
                'order_id' => (int) $row->order_id,
                'listing_id' => $listingId,
                'actor_user_id' => 0,
                'actor_login' => 'system',
            ]);
            Notifications::sendEvent('suspended_customer', $customerPhone, $vars, true);
            Notifications::sendEvent('suspended_seller', $merchantPhone, $vars, true);
            $this->dispatchMerchantNotification(
                NotificationType::SALE_SUSPENDED,
                $listing,
                $order,
                ['listing_id' => $listingId]
            );
            (new AskMerchants())->notifyForSize($listing->parentProductId, $listing->sizeTermId, $listing->asking, $title);
            (new SourcingAutoOpen())->forFailedSeller($listing, (int) $row->order_id, (int) $row->order_item_id);
            $this->logListingEvent('listing_left_sale', $listing, [
                'listing_id' => $listingId,
                'order_id' => (int) $row->order_id,
                'reason' => 'confirm_deadline',
            ], $row);
            WebhookNotifier::dispatch('listing.left_sale', [
                'listing_id' => $listingId,
                'reason' => 'confirm_deadline',
            ]);
        }
    }

    private function processCargoDeadline(object $row): void
    {
        $listingId = (int) $row->listing_id;
        $listing = $this->bridge()->find($listingId);
        if (!$listing) {
            return;
        }

        $order = wc_get_order((int) $row->order_id);
        if (!$order) {
            return;
        }

        $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
        $vars = $this->templateVars($order, $listing, $title);
        $merchantPhone = Notifications::merchantPhone((int) $row->merchant_id);
        $deadlineTs = $row->cargo_deadline_at ? strtotime((string) $row->cargo_deadline_at) : false;
        $now = current_time('timestamp');

        if ($deadlineTs === false) {
            return;
        }

        if (!(int) $row->cargo_notice_sent && $now >= $deadlineTs - Settings::cargoReminderSeconds()) {
            Notifications::sendEvent('seller_cargo_reminder', $merchantPhone, $vars, true);
            $shipmentType = (string) $order->get_meta(ShipmentMeta::TYPE);
            $this->dispatchMerchantNotification(
                NotificationType::SALE_CARGO_REMINDER,
                $listing,
                $order,
                [
                    'listing_id' => $listingId,
                    'cargo_hours' => (int) (Settings::cargoDeadlineSecondsForShipmentType($shipmentType) / HOUR_IN_SECONDS),
                ]
            );
            $this->repo->update($listingId, ['cargo_notice_sent' => 1]);
            $this->logListingEvent('fulfillment_cargo_reminder', $listing, [
                'listing_id' => $listingId,
                'order_id' => (int) $row->order_id,
            ], $row);
            return;
        }

        if (!(int) $row->cargo_expired_flag && $now >= $deadlineTs) {
            $this->repo->update($listingId, ['cargo_expired_flag' => 1]);
            Notifications::sendEvent('seller_cargo_expired', (string) $order->get_billing_phone(), $vars, true);
            Notifications::sendEvent('seller_cargo_expired', $merchantPhone, $vars, true);
            $this->dispatchMerchantNotification(
                NotificationType::SALE_CARGO_EXPIRED,
                $listing,
                $order,
                ['listing_id' => $listingId]
            );
            $this->logListingEvent('fulfillment_cargo_expired', $listing, [
                'listing_id' => $listingId,
                'order_id' => (int) $row->order_id,
                'cargo_deadline_at' => (string) $row->cargo_deadline_at,
            ], $row);
        }
    }

    /** @return array<string, mixed> */
    public function merchantDetails(int $listingId, int $merchantId): array|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row || (int) $row->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_marketplace_fulfillment_forbidden', __('Unauthorized.', 'sutore-marketplace'));
        }
        $listing = $this->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('No listings.', 'sutore-marketplace'));
        }

        $order = wc_get_order((int) $row->order_id);
        $shipmentType = $order ? (string) $order->get_meta(ShipmentMeta::TYPE) : 'standard';
        $cargoHours = (int) (Settings::cargoDeadlineSecondsForShipmentType($shipmentType) / HOUR_IN_SECONDS);

        return [
            'listing_id' => $listingId,
            'status' => $row->fulfillment_status,
            'status_label' => ListingStatus::label($row->fulfillment_status),
            'order_id' => (int) $row->order_id,
            'asking' => $listing->asking,
            'asking_display' => MarketplacePricing::formatTl($listing->asking),
            'net_payout_display' => MarketplacePricing::formatTl(MarketplacePricing::merchantPayout($listing)),
            'confirm_deadline_at' => $row->confirm_deadline_at,
            'cargo_deadline_at' => $row->cargo_deadline_at,
            'merchant_shipment_code' => $row->merchant_shipment_code,
            'sutore_shipment_code' => $row->sutore_shipment_code,
            'yurtici_customer_code' => Settings::yurticiCustomerCode(),
            'shipment_hint' => sprintf(
                __('Deliver your product in a double box to Yurtici Kargo (%s) within %d hours.', 'sutore-marketplace'),
                $cargoHours,
                Settings::yurticiCustomerCode()
            ),
            'can_confirm' => $row->fulfillment_status === ListingStatus::SOLD,
            'can_ship' => $row->fulfillment_status === ListingStatus::CONFIRMED,
            'can_track' => in_array($row->fulfillment_status, [
                ListingStatus::SHIPPED_TO_SUTORE,
                ListingStatus::ARRIVED_TO_SUTORE,
                ListingStatus::VERIFIED,
            ], true) && !empty($row->merchant_shipment_code),
        ];
    }

    private function notifySaleApproved(Listing $listing, int $orderId, int $listingId = 0): void
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $title = Notifications::productTitle((int) $listing->id, $listing->variationId, $listing->parentProductId);
        $vars = $this->templateVars($order, $listing, $title);
        $vars['confirm_hours'] = (string) (int) (Settings::confirmDeadlineSeconds($listing->merchantId) / HOUR_IN_SECONDS);

        Notifications::sendEvent('seller_confirm_request', Notifications::merchantPhone($listing->merchantId), $vars);
        $this->dispatchMerchantNotification(
            NotificationType::SALE_RECEIVED,
            $listing,
            $order,
            [
                'listing_id' => $listingId ?: (int) $listing->id,
                'confirm_hours' => (int) (Settings::confirmDeadlineSeconds($listing->merchantId) / HOUR_IN_SECONDS),
            ]
        );

        $shipmentType = (string) $order->get_meta(ShipmentMeta::TYPE);
        if ($shipmentType === 'international' && Settings::get('international_invoice_required', true)) {
            Notifications::sendEvent('international_warning', Notifications::merchantPhone($listing->merchantId), $vars);
        }
        if ($shipmentType === 'express' && Settings::get('express_block_carrier_shipment', true)) {
            Notifications::sendEvent('express_warning', Notifications::merchantPhone($listing->merchantId), $vars);
            Notifications::notifyExpress('express_warning', $vars);
        }
    }

    private function notifyStatusChange(Listing $listing, \WC_Order $order, string $status, ?string $trackCode, int $listingId = 0): void
    {
        $title = Notifications::productTitle((int) $listing->id, $listing->variationId, $listing->parentProductId);
        $vars = $this->templateVars($order, $listing, $title);
        if ($trackCode) {
            $vars['track_code'] = $trackCode;
        }

        $customer = (string) $order->get_billing_phone();
        $merchant = Notifications::merchantPhone($listing->merchantId);
        $listingContext = ['listing_id' => $listingId ?: (int) $listing->id];

        match ($status) {
            ListingStatus::ARRIVED_TO_SUTORE => [
                Notifications::sendEvent('arrived_customer', $customer, $vars),
                Notifications::sendEvent('arrived_seller', $merchant, $vars),
                $this->dispatchMerchantNotification(
                    NotificationType::FULFILLMENT_ARRIVED_AT_SUTORE,
                    $listing,
                    $order,
                    $listingContext
                ),
            ],
            ListingStatus::VERIFIED => [
                Notifications::sendEvent('verified_customer', $customer, $vars),
                Notifications::sendEvent('verified_seller', $merchant, $vars),
                $this->dispatchMerchantNotification(
                    NotificationType::FULFILLMENT_VERIFIED,
                    $listing,
                    $order,
                    $listingContext
                ),
            ],
            ListingStatus::SHIPPED => [
                Notifications::sendEvent('shipped_customer', $customer, $vars),
                $this->dispatchMerchantNotification(
                    NotificationType::FULFILLMENT_SHIPPED,
                    $listing,
                    $order,
                    $listingContext
                ),
            ],
            default => null,
        };
    }

    /** @param array<string, mixed> $extra */
    private function dispatchMerchantNotification(
        string $type,
        Listing $listing,
        ?\WC_Order $order = null,
        array $extra = []
    ): void {
        $title = Notifications::productTitle((int) $listing->id, $listing->variationId, $listing->parentProductId);
        $context = array_merge([
            'product' => $title,
            'price' => $listing->asking,
            'listing_id' => (int) $listing->id,
        ], $extra);

        if ($order) {
            $context['order_id'] = (int) $order->get_id();
            $shipmentType = (string) $order->get_meta(ShipmentMeta::TYPE);
            $context['confirm_hours'] = $context['confirm_hours']
                ?? (int) (Settings::confirmDeadlineSeconds($listing->merchantId) / HOUR_IN_SECONDS);
            $context['cargo_hours'] = $context['cargo_hours']
                ?? (int) (Settings::cargoDeadlineSecondsForShipmentType($shipmentType) / HOUR_IN_SECONDS);
        }

        (new NotificationService())->dispatch((int) $listing->merchantId, $type, $context);
    }

    private function advanceStatus(
        int $listingId,
        string $expectedFrom,
        string $toStatus,
        string $eventType,
        array $args = []
    ): true|\WP_Error {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
        }
        if ((string) $row->fulfillment_status !== $expectedFrom) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Invalid status.', 'sutore-marketplace'));
        }

        $patch = ['fulfillment_status' => $toStatus];
        if (!empty($args['sutore_shipment_code'])) {
            $patch['sutore_shipment_code'] = sanitize_text_field((string) $args['sutore_shipment_code']);
        }
        if ($toStatus === ListingStatus::SHIPPED) {
            $patch['sutore_shipped_at'] = current_time('mysql');
        }

        $this->repo->update($listingId, $patch);

        $listing = $this->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if ($listing && $order) {
            $this->notifyStatusChange(
                $listing,
                $order,
                $toStatus,
                $patch['sutore_shipment_code'] ?? null,
                $listingId
            );
        }

        $this->logListingEvent($eventType, $listing, array_filter([
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'from_status' => $expectedFrom,
            'to_status' => $toStatus,
            'sutore_shipment_code' => $patch['sutore_shipment_code'] ?? null,
            'staff_note' => $this->optionalStaffNote($args),
        ], static fn ($value) => $value !== null && $value !== ''), $row);

        WebhookNotifier::dispatch('fulfillment.' . $toStatus, [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        return true;
    }

    /**
     * @param array{staff_note?:string} $args
     */
    private function applyIntervention(
        int $listingId,
        string $capabilityKey,
        string $toStatus,
        string $eventType,
        string $listingStatus,
        array $args
    ): true|\WP_Error {
        $note = $this->requireStaffNote($args);
        if (is_wp_error($note)) {
            return $note;
        }

        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Fulfillment not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps[$capabilityKey])) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Invalid status.', 'sutore-marketplace'));
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => $toStatus,
        ]);

        $this->detachListingFromOrder($listingId, $listingStatus, [
            'reason' => $capabilityKey,
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) $row->order_item_id,
            'listing_id' => $listingId,
            'staff_note' => $note,
        ]);

        $listing = $this->bridge()->find($listingId);
        $this->logListingEvent($eventType, $listing, [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'staff_note' => $note,
            'to_status' => $toStatus,
        ], $row);

        WebhookNotifier::dispatch('fulfillment.' . $toStatus, [
            'listing_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        return true;
    }

    /** @param array<string, mixed> $args */
    private function requireStaffNote(array $args): string|\WP_Error
    {
        $note = sanitize_textarea_field((string) ($args['staff_note'] ?? ''));
        if (trim($note) === '') {
            return new \WP_Error(
                'sutore_marketplace_staff_note_required',
                __('A staff note is required for this action.', 'sutore-marketplace')
            );
        }

        return $note;
    }

    /** @param array<string, mixed> $args */
    private function optionalStaffNote(array $args): ?string
    {
        $note = sanitize_textarea_field((string) ($args['staff_note'] ?? ''));

        return $note !== '' ? $note : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logListingEvent(string $eventType, ?Listing $listing, array $payload = [], ?object $row = null): void
    {
        $listingId = $listing?->id ?? (isset($row->listing_id) ? (int) $row->listing_id : null);
        $variationId = $listing?->variationId ?? (isset($row->variation_id) ? (int) $row->variation_id : null);
        $merchantId = $listing?->merchantId ?? (isset($row->merchant_id) ? (int) $row->merchant_id : null);

        (new ListingEventsRepository())->log(
            $eventType,
            $payload,
            $listingId ?: null,
            $variationId ?: null,
            $merchantId ?: null,
            'merchant_visible'
        );
    }

    private function logListingLifecycleCompleted(object $row, ?Listing $listing): void
    {
        $listingId = (int) ($row->listing_id ?? 0);
        if ($listingId <= 0) {
            return;
        }

        if ((string) ($row->fulfillment_status ?? '') !== ListingStatus::DELIVERED_TO_CUSTOMER) {
            return;
        }

        $eventsRepo = new ListingEventsRepository();
        if ($eventsRepo->hasEventForListing($listingId, 'listing_lifecycle_completed')) {
            return;
        }

        $this->logListingEvent('listing_lifecycle_completed', $listing, [
            'listing_id' => $listingId,
            'order_id' => (int) ($row->order_id ?? 0),
            'order_item_id' => (int) ($row->order_item_id ?? 0),
            'delivered_at' => (string) ($row->delivered_at ?? ''),
        ], $row);

        WebhookNotifier::dispatch('listing.lifecycle_completed', [
            'listing_id' => $listingId,
            'order_id' => (int) ($row->order_id ?? 0),
            'order_item_id' => (int) ($row->order_item_id ?? 0),
        ]);
    }

    /** @return array<string, string|int> */
    private function templateVars(\WC_Order $order, Listing $listing, string $title): array
    {
        $shipmentType = (string) $order->get_meta(ShipmentMeta::TYPE);
        $vars = Notifications::baseVars($order, $title, $listing->asking);
        $vars['confirm_hours'] = (string) (int) (Settings::confirmDeadlineSeconds($listing->merchantId) / HOUR_IN_SECONDS);
        $vars['cargo_hours'] = (string) (int) (Settings::cargoDeadlineSecondsForShipmentType($shipmentType) / HOUR_IN_SECONDS);

        return $vars;
    }
}
