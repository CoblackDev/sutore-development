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
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Orders\Domain\StaffBulkAction;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

/**
 * Sale / fulfillment lifecycle service.
 *
 * Since the fulfillments table has been eliminated, every "$fulfillmentId"
 * argument in this class is the listing variation id. The repository still
 * returns rows shaped like the historical fulfillment rows (id = variation_id,
 * fulfillment_status mirrors listing_status) so REST / JS consumers stay
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
        $listingId = (int) $listing->variationId;
        if ($listingId <= 0) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $existing = $this->repo->findActiveByVariationId($listingId);
        $requireAdmin = Settings::requireAdminPaymentConfirm();
        if ($existing) {
            $status = (string) ($existing->fulfillment_status ?? '');
            if ($status === ListingStatus::PAYMENT && !$requireAdmin) {
                return $this->adminConfirmPayment($listingId);
            }

            return true;
        }

        $order = wc_get_order($orderId);
        $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
        $vars = $order ? $this->templateVars($order, $listing, $title) : ['product' => $title, 'order_id' => (string) $orderId];

        $bridge = $this->bridge();

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

            $this->logListingEvent('fulfillment_payment', $listing, [
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

        $this->notifySaleApproved($listing, $orderId, $listingId);
        WebhookNotifier::dispatch('fulfillment.sold', [
            'variation_id' => $listingId,
            'order_id' => $orderId,
        ]);

        $this->logListingEvent('fulfillment_sold', $listing, [
            'variation_id' => $listingId,
            'order_id' => $orderId,
            'to_status' => ListingStatus::SOLD,
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

        $bridge = $this->bridge();
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

        $this->notifySaleApproved($listing, (int) $row->order_id, $listingId);
        $this->logListingEvent('fulfillment_payment_confirmed', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'from_status' => ListingStatus::PAYMENT,
            'to_status' => ListingStatus::SOLD,
        ]);
        $this->logListingEvent('fulfillment_sold', $listing, [
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
        $confirmDeadline = (string) ($row->confirm_deadline_at ?? '');
        $onTime = $confirmDeadline === '' || current_time('mysql') <= $confirmDeadline;

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
            $this->dispatchMerchantNotification(
                NotificationType::SALE_CONFIRMED,
                $listing,
                $order,
                ['variation_id' => $listingId, 'cargo_hours' => (int) ($cargoSeconds / HOUR_IN_SECONDS)]
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
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        $this->logListingEvent('fulfillment_seller_confirmed', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'seller_confirmed_at' => current_time('mysql'),
            'cargo_deadline_at' => $cargoDeadline,
            'on_time' => $onTime,
        ], $row);

        if ($onTime) {
            (new \SutoreMarketplace\Modules\Tasks\Services\TaskProgressService())
                ->incrementByTemplate($merchantId, \SutoreMarketplace\Modules\Tasks\Domain\OpportunityTemplate::RECOVERY_TIMELY_CONFIRM);
        }
        (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())->refreshMerchant($merchantId);

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

        $cargoDeadline = (string) ($row->cargo_deadline_at ?? '');
        $shippedAt = current_time('mysql');
        $shipOnTime = $cargoDeadline === '' || $shippedAt <= $cargoDeadline;

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::SHIPPED_TO_SUTORE,
            'merchant_shipment_code' => $code,
            'merchant_shipped_at' => $shippedAt,
        ]);

        $listing = $this->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if ($listing && $order) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->templateVars($order, $listing, $title);
            $vars['track_code'] = $code;

            Notifications::sendEvent('shipped_to_sutore_customer', (string) $order->get_billing_phone(), $vars);
            $this->dispatchMerchantNotification(
                NotificationType::FULFILLMENT_SHIPPED_TO_SUTORE,
                $listing,
                $order,
                ['variation_id' => $listingId, 'track_code' => $code]
            );
        }

        WebhookNotifier::dispatch('fulfillment.shipped_to_sutore', [
            'variation_id' => $listingId,
            'track_code' => $code,
        ]);

        $this->logListingEvent('fulfillment_shipped_to_sutore', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'merchant_shipment_code' => $code,
            'on_time' => $shipOnTime,
        ], $row);

        (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())->refreshMerchant($merchantId);

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

        $orderId = $row ? (int) $row->order_id : 0;
        if ($orderId > 0) {
            (new \SutoreMarketplace\Modules\Invoices\Services\InvoiceIssuer())->syncCustomerFeesForOrder($orderId);
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
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
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
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'delivered_at' => $deliveredAt,
            'return_window_ends_at' => $windowEnds,
            'staff_note' => $this->optionalStaffNote($args),
        ], static fn ($value) => $value !== null && $value !== ''), $row);

        WebhookNotifier::dispatch('fulfillment.delivered_to_customer', [
            'variation_id' => $listingId,
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

    public function merchantCancelSale(int $listingId, int $merchantId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row || (int) $row->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_marketplace_fulfillment_forbidden', __('Unauthorized action.', 'sutore-marketplace'));
        }

        if ((string) $row->fulfillment_status !== ListingStatus::SOLD) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Only sales awaiting confirmation can be cancelled.', 'sutore-marketplace')
            );
        }

        $listing = $this->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $marked = $this->markAsPreOrder($listingId, 'seller_cancelled');
        if (is_wp_error($marked)) {
            return $marked;
        }

        $eventsRepo = new ListingEventsRepository();
        $this->logListingEvent(\SutoreMarketplace\Modules\Listings\Domain\ListingEventType::SELLER_CANCELLED, $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ], $row);

        if ($eventsRepo->hasPreOrderAcceptance($merchantId, $listingId)) {
            $this->logListingEvent(
                \SutoreMarketplace\Modules\Listings\Domain\ListingEventType::PRE_ORDER_COMMITMENT_BROKEN,
                $listing,
                [
                    'variation_id' => $listingId,
                    'order_id' => (int) $row->order_id,
                    'reason' => 'seller_cancelled',
                ],
                $row
            );
        }

        (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())->refreshMerchant($merchantId);

        return true;
    }

    /**
     * @param array{staff_note:string} $args
     */
    public function hubRejectFulfillment(int $listingId, array $args = []): true|\WP_Error
    {
        $note = $this->requireStaffNote($args);
        if (is_wp_error($note)) {
            return $note;
        }

        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['hub_reject'])) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Invalid status.', 'sutore-marketplace'));
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::NOT_SALE,
        ]);

        (new PayoutLineService())->reverseForListing($listingId);
        $this->detachListingFromOrder($listingId, ListingStatus::NOT_SALE, [
            'reason' => 'hub_rejected',
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) $row->order_item_id,
            'variation_id' => $listingId,
            'staff_note' => $note,
        ]);

        $listing = $this->bridge()->find($listingId);
        $this->logListingEvent(\SutoreMarketplace\Modules\Listings\Domain\ListingEventType::HUB_REJECTED, $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'staff_note' => $note,
        ], $row);

        (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())
            ->refreshMerchant((int) $row->merchant_id);

        WebhookNotifier::dispatch('fulfillment.hub_rejected', [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        return true;
    }

    /**
     * @param array{staff_note:string} $args
     */
    public function markNotForSale(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->applyIntervention(
            $listingId,
            'mark_not_for_sale',
            ListingStatus::ORDER_DETACHED,
            'listing_left_sale',
            ListingStatus::ORDER_DETACHED,
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
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
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
            'variation_id' => $listingId,
            'staff_note' => $note,
        ]);

        $listing = $this->bridge()->find($listingId);
        $this->logListingEvent('fulfillment_chargeback', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'staff_note' => $note,
        ], $row);

        WebhookNotifier::dispatch('fulfillment.chargeback', [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        return true;
    }

    public function putListingOnSale(int $listingId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
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

    /**
     * Remove a pre-sale market listing from sale (not an in-order intervention).
     *
     * @param array{staff_note?:string} $args
     */
    public function removeListingFromSale(int $listingId, array $args = []): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['remove_from_sale'])) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('This product cannot be removed from sale in its current status.', 'sutore-marketplace')
            );
        }

        $result = (new ListingService())->removeFromSale($listingId, null, [
            'staff_note' => (string) ($args['staff_note'] ?? ''),
        ]);
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function approveListing(int $listingId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['approve'])) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('This product cannot be approved in its current status.', 'sutore-marketplace')
            );
        }

        $result = (new ListingSelector())->approvePendingWinner($listingId);
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function deleteListing(int $listingId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['delete']) || $this->isLinkedToOrder($row)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('This product cannot be deleted right now. (In order or payment process.)', 'sutore-marketplace')
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

    /**
     * Dispatch a single staff workflow action (shared by single + bulk REST).
     *
     * @param array<string, mixed> $params
     */
    public function runStaffWorkflowAction(int $listingId, string $action, array $params = []): true|\WP_Error
    {
        $noteArgs = [
            'staff_note' => sanitize_textarea_field((string) ($params['staff_note'] ?? '')),
            'sutore_shipment_code' => sanitize_text_field((string) ($params['sutore_shipment_code'] ?? '')),
        ];

        $returnToQueue = $this->boolParam($params, 'return_to_queue');

        return match ($action) {
            'confirm_payment' => $this->adminConfirmPayment($listingId),
            'swap' => $this->swapMerchant(
                $listingId,
                (int) ($params['new_variation_id'] ?? 0),
                (string) ($noteArgs['staff_note'] ?? ''),
                $returnToQueue
            ),
            'attach_to_order' => $this->attachToOrder(
                $listingId,
                (int) ($params['order_id'] ?? 0),
                $noteArgs
            ),
            'split' => $this->splitFromOrder(
                $listingId,
                true,
                'split',
                (string) ($noteArgs['staff_note'] ?? ''),
                $returnToQueue
            ),
            'mark_arrived' => $this->markArrivedAtSutore($listingId, $noteArgs),
            'mark_verified' => $this->markVerified($listingId, $noteArgs),
            'mark_ready_to_ship' => $this->markReadyToShip($listingId, $noteArgs),
            'mark_shipped_to_customer' => $this->markShippedToCustomer($listingId, $noteArgs),
            'mark_delivered' => $this->markDeliveredToCustomer($listingId, $noteArgs),
            'mark_not_for_sale' => $this->markNotForSale($listingId, $noteArgs),
            'remove_from_sale' => $this->removeListingFromSale($listingId, $noteArgs),
            'chargeback' => $this->chargebackFulfillment($listingId, $noteArgs),
            'mark_payout_paid' => $this->markMerchantPayout(
                $listingId,
                sanitize_text_field((string) ($params['payment_ref'] ?? ''))
            ),
            'adjust_commission' => $this->adjustPayoutCommission($listingId, $params),
            'set_listing_commission' => $this->setListingCommission($listingId, $params),
            'mark_imported' => $this->markListingImported($listingId),
            'unmark_imported' => $this->unmarkListingImported($listingId),
            'put_on_sale' => $this->putListingOnSale($listingId),
            'approve' => $this->approveListing($listingId),
            'mark_pre_order' => $this->markAsPreOrder($listingId, 'staff'),
            'close_pre_order' => $this->closeUnsourcedPreOrder($listingId, $noteArgs),
            'hub_reject' => $this->hubRejectFulfillment($listingId, $noteArgs),
            'delete_listing' => $this->deleteListing($listingId),
            default => new \WP_Error('invalid', __('Invalid action.', 'sutore-marketplace')),
        };
    }

    /**
     * Apply one no-input workflow to every listing. Validates intersection first.
     *
     * @param list<int> $listingIds
     * @param array<string, mixed> $params
     * @return array{updated:int, action:string}|\WP_Error
     */
    public function bulkStaffWorkflowAction(array $listingIds, string $action, array $params = []): array|\WP_Error
    {
        if (!StaffBulkAction::isValid($action)) {
            return new \WP_Error(
                'sutore_marketplace_bulk_action_invalid',
                __('Invalid bulk action.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $listingIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return new \WP_Error(
                'sutore_marketplace_bulk_ids_required',
                __('Select at least one product.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        if (count($ids) > 100) {
            return new \WP_Error(
                'sutore_marketplace_bulk_too_many',
                __('You can update at most 100 products at once.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $presenter = new StaffFulfillmentPresenter();
        $payouts = new PayoutLineRepository();
        $flag = StaffBulkAction::actionFlag($action);

        foreach ($ids as $id) {
            $row = $this->repo->find($id);
            if (!$row) {
                return new \WP_Error(
                    'sutore_marketplace_fulfillment_missing',
                    /* translators: %d: listing id */
                    sprintf(__('Product #%d was not found.', 'sutore-marketplace'), $id),
                    ['status' => 404]
                );
            }

            $item = $presenter->presentRow($row);
            $payout = $payouts->findByVariationId($id);
            $actions = $presenter->actionFlagsForRow($row, $item, $payout);
            if (empty($actions[$flag])) {
                return new \WP_Error(
                    'sutore_marketplace_bulk_not_applicable',
                    __('This action cannot be applied to every selected product.', 'sutore-marketplace'),
                    ['status' => 409]
                );
            }
        }

        $params = array_merge([
            'staff_note' => StaffBulkAction::bulkStaffNote(),
        ], $params);

        foreach ($ids as $id) {
            $result = $this->runStaffWorkflowAction($id, $action, $params);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        return [
            'updated' => count($ids),
            'action' => $action,
        ];
    }

    public function markListingImported(int $listingId): true|\WP_Error
    {
        $listing = $this->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Product not found.', 'sutore-marketplace')
            );
        }

        $result = (new ImportedProductService())->markVariationsImported([$listing->variationId]);
        if ((int) $result['marked'] !== 1) {
            return new \WP_Error(
                'sutore_marketplace_imported_product_invalid',
                $result['skipped'][0] ?? __('The imported product could not be updated.', 'sutore-marketplace')
            );
        }

        return true;
    }

    public function unmarkListingImported(int $listingId): true|\WP_Error
    {
        $listing = $this->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Product not found.', 'sutore-marketplace')
            );
        }

        $result = (new ImportedProductService())->unmarkVariationsImported([$listing->variationId]);
        if ((int) $result['unmarked'] !== 1) {
            return new \WP_Error(
                'sutore_marketplace_imported_product_invalid',
                $result['skipped'][0] ?? __('The imported product could not be updated.', 'sutore-marketplace')
            );
        }

        return true;
    }

    public function markMerchantPayout(int $listingId, string $paymentRef = ''): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        if (!ListingStatus::allowsPayout((string) $row->fulfillment_status)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Merchant payout cannot be marked in this status.', 'sutore-marketplace')
            );
        }

        $listing = $this->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $payoutService = new PayoutLineService();
        $existing = (new PayoutLineRepository())->findByVariationId($listingId);
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
            $line = (new PayoutLineRepository())->findByVariationId($listingId);
            $this->dispatchMerchantNotification(
                NotificationType::PAYOUT_PAID,
                $listing,
                $order,
                [
                    'variation_id' => $listingId,
                    'net_amount' => $line ? (float) $line->net_amount : MarketplacePricing::merchantPayout($listing),
                    'price' => $listing->asking,
                ]
            );
        }

        WebhookNotifier::dispatch('payout.paid', [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        $this->logListingEvent('fulfillment_payout_paid', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'payment_ref' => $paymentRef,
        ], $row);

        return true;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function adjustPayoutCommission(int $listingId, array $params): true|\WP_Error
    {
        $raw = $params['commission_percent'] ?? null;
        if ($raw === null || $raw === '') {
            return new \WP_Error(
                'sutore_commission_invalid',
                __('Commission percent must be between 0 and 100.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $result = (new PayoutLineService())->adjustCommission(
            $listingId,
            (float) $raw,
            sanitize_textarea_field((string) ($params['staff_note'] ?? $params['note'] ?? ''))
        );

        return $result instanceof \WP_Error ? $result : true;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function setListingCommission(int $listingId, array $params): true|\WP_Error
    {
        $raw = $params['commission_percent'] ?? null;
        $percent = null;
        if ($raw !== null && $raw !== '') {
            $percent = (float) $raw;
        }

        $result = (new ListingService())->setCommissionPercent(
            $listingId,
            $percent,
            sanitize_textarea_field((string) ($params['staff_note'] ?? $params['note'] ?? ''))
        );

        return $result instanceof \WP_Error ? $result : true;
    }

    public function swapMerchant(
        int $listingId,
        int $newListingId,
        string $staffNote = '',
        bool $returnToQueue = false
    ): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['swap']) || !Settings::swapAllowedFor((string) $row->fulfillment_status)) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Seller cannot be changed in this status.', 'sutore-marketplace'));
        }

        $bridge = $this->bridge();
        $oldListing = $bridge->find($listingId);
        $newListing = $bridge->find($newListingId);
        if (!$newListing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('No new product found.', 'sutore-marketplace'));
        }

        if ((int) $newListing->variationId === $listingId) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Replacement product must be different from the current product.', 'sutore-marketplace')
            );
        }

        $sameParent = $oldListing
            && (int) $newListing->parentProductId === (int) $oldListing->parentProductId;
        $note = sanitize_textarea_field($staffNote);
        if (!$sameParent) {
            if (trim($note) === '') {
                return new \WP_Error(
                    'sutore_marketplace_staff_note_required',
                    __('A staff note is required when replacing with a different product.', 'sutore-marketplace')
                );
            }
        } elseif ($note === '') {
            $note = __('Seller swapped by staff.', 'sutore-marketplace');
        }

        if ($newListing->listingStatus !== 'publish' || !$newListing->isWinner) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Replacement product must be the active queue winner.', 'sutore-marketplace')
            );
        }

        if ((int) $newListing->orderId > 0 || $this->repo->findActiveByVariationId((int) $newListing->variationId)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Replacement product is already linked to an active sale.', 'sutore-marketplace')
            );
        }

        $order = wc_get_order((int) $row->order_id);
        if (!$order) {
            return new \WP_Error('sutore_marketplace_fulfillment_order', __('Order not found.', 'sutore-marketplace'));
        }

        $oldLineTotal = 0.0;
        $oldOrderItemId = (int) ($row->order_item_id ?? 0);
        if ($oldOrderItemId > 0) {
            $oldItem = $order->get_item($oldOrderItemId);
            if ($oldItem instanceof \WC_Order_Item_Product) {
                $oldLineTotal = (float) $oldItem->get_total();
            }
        }
        if ($oldLineTotal <= 0 && $oldListing) {
            $oldLineTotal = MarketplacePricing::customerPrice($oldListing);
        }

        $split = $this->splitFromOrder($listingId, false, 'swap_out', $note, false);
        if (is_wp_error($split)) {
            return $split;
        }

        // splitFromOrder may mutate order state — reload before adding the replacement.
        $order = wc_get_order((int) $row->order_id);
        if (!$order) {
            return new \WP_Error('sutore_marketplace_fulfillment_order', __('Order not found.', 'sutore-marketplace'));
        }

        $newProduct = wc_get_product($newListing->variationId);
        if (!$newProduct) {
            return new \WP_Error('sutore_marketplace_fulfillment_product', __('Product not found.', 'sutore-marketplace'));
        }

        $newItemId = $order->add_product($newProduct, 1);
        if (!$newItemId) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('Could not add the product to the order.', 'sutore-marketplace')
            );
        }

        $newItem = $order->get_item($newItemId);
        if ($newItem instanceof \WC_Order_Item_Product) {
            (new \SutoreMarketplace\Modules\Orders\Hooks\OrderItemPricingMetaHooks())->applyMarketplaceLineTotals($newItem);
            $newItem->save();
        }

        $newCustomer = MarketplacePricing::customerPrice($newListing);
        $priceDiff = round($newCustomer - $oldLineTotal, 2);
        if (abs($priceDiff) >= 0.01) {
            $prevDiff = (float) $order->get_meta('_sutore_mp_price_difference', true);
            $order->update_meta_data('_sutore_mp_price_difference', round($prevDiff + $priceDiff, 2));
        }

        $order->calculate_totals();
        if (method_exists($order, 'recalculate_coupons')) {
            $order->recalculate_coupons();
        }
        $order->save();

        $actor = $this->resolveActor();
        $swapPayload = [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'old_variation_id' => $listingId,
            'new_variation_id' => $newListingId,
            'old_merchant_id' => (int) $row->merchant_id,
            'new_merchant_id' => (int) $newListing->merchantId,
            'old_parent_product_id' => $oldListing ? (int) $oldListing->parentProductId : 0,
            'new_parent_product_id' => (int) $newListing->parentProductId,
            'old_size_term_id' => $oldListing ? (int) $oldListing->sizeTermId : 0,
            'new_size_term_id' => (int) $newListing->sizeTermId,
            'same_parent' => $sameParent,
            'price_difference' => $priceDiff,
            'staff_note' => $note,
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

        $attached = $this->onPaymentComplete($newListing, (int) $row->order_id, (int) $newItemId);
        if (is_wp_error($attached)) {
            return $attached;
        }

        if ($returnToQueue) {
            return new \WP_Error(
                'sutore_marketplace_detach_no_relist',
                __('Order-detached products cannot be put back on sale. Create a new product instead.', 'sutore-marketplace')
            );
        }

        return true;
    }

    /**
     * Move a linked sale to the open pre-order board (order link retained).
     */
    public function markAsPreOrder(int $listingId, string $reason = 'staff'): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        if (!in_array((string) $row->fulfillment_status, [ListingStatus::PAYMENT, ListingStatus::SOLD], true)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Only sales awaiting confirmation can be marked as pre-order.', 'sutore-marketplace')
            );
        }

        if (!$this->isLinkedToOrder($row)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_not_linked',
                __('This product is not linked to an order.', 'sutore-marketplace')
            );
        }

        $listing = $this->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::PRE_ORDER,
            'confirm_deadline_at' => null,
            'seller_confirmed_at' => null,
            'cargo_deadline_at' => null,
            'merchant_shipped_at' => null,
            'merchant_shipment_code' => null,
            'sutore_shipment_code' => null,
            'confirm_notice_sent' => 0,
            'confirm_punished' => $reason === 'confirm_deadline' ? 1 : (int) $row->confirm_punished,
            'cargo_notice_sent' => 0,
            'cargo_expired_flag' => 0,
            'merchant_snapshot' => null,
            'is_winner' => 0,
        ]);

        $this->logListingEvent('listing_pre_order', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'reason' => $reason,
        ], $row);

        if ($reason === 'staff') {
            $order = wc_get_order((int) $row->order_id);
            if ($order) {
                $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
                (new AskMerchants())->notifyForSize(
                    $listing->parentProductId,
                    $listing->sizeTermId,
                    $listing->asking,
                    $title
                );
            }
        }

        return true;
    }

    /**
     * Staff: pre-order could not be sourced — detach, refund the line if paid, notify the customer.
     *
     * @param array{staff_note?:string} $args
     */
    public function closeUnsourcedPreOrder(int $listingId, array $args = []): true|\WP_Error
    {
        $note = $this->requireStaffNote($args);
        if (is_wp_error($note)) {
            return $note;
        }

        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        if ((string) $row->fulfillment_status !== ListingStatus::PRE_ORDER) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Only open pre-orders can be marked as could not be sourced.', 'sutore-marketplace')
            );
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['close_pre_order'])) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Invalid status.', 'sutore-marketplace'));
        }

        $actor = $this->resolveActor();
        $listing = $this->bridge()->find($listingId);
        $orderId = (int) $row->order_id;
        $itemId = (int) $row->order_item_id;
        $order = $orderId > 0 ? wc_get_order($orderId) : false;

        if ($order instanceof \WC_Order && $itemId > 0) {
            $this->refundOrderLineIfPaid($order, $itemId);
            $order = wc_get_order($orderId) ?: $order;
            if ($order->get_item($itemId)) {
                $this->addSplitOrderNote($order, $listing, $actor);
                $order->remove_item($itemId);
                $order->calculate_totals();
                $order->save();
            }
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::ORDER_DETACHED,
            'order_item_id' => 0,
        ]);
        $this->bridge()->releaseFromOrder($listingId, ListingStatus::ORDER_DETACHED, [
            'reason' => 'unsourced',
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'variation_id' => $listingId,
            'staff_note' => $note,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ]);

        if ($listing) {
            $this->logListingEvent('listing_left_sale', $listing, [
                'variation_id' => $listingId,
                'order_id' => $orderId,
                'reason' => 'unsourced',
                'staff_note' => $note,
            ], $row);
        }

        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if ($order instanceof \WC_Order && $listing) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->templateVars($order, $listing, $title);
            Notifications::sendEvent('pre_order_unsourced_customer', (string) $order->get_billing_phone(), $vars);
            $this->cancelOrderIfNoOpenItems($order);
        }

        WebhookNotifier::dispatch('fulfillment.split', [
            'variation_id' => $listingId,
            'order_id' => $orderId,
            'reason' => 'unsourced',
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ]);

        return true;
    }

    /**
     * Merchant accepts a pre-order listing — immediate order swap (no staff step).
     */
    public function acceptPreOrderSwap(int $preOrderListingId, int $newListingId, int $acceptingMerchantId): true|\WP_Error
    {
        $preOrder = $this->bridge()->find($preOrderListingId);
        if (!$preOrder || $preOrder->listingStatus !== ListingStatus::PRE_ORDER) {
            return new \WP_Error(
                'sutore_pre_order_missing',
                __('Pre-order product not found.', 'sutore-marketplace')
            );
        }

        if (!$preOrder->orderId || !$preOrder->orderItemId) {
            return new \WP_Error(
                'sutore_pre_order_not_linked',
                __('Pre-order is not linked to an order.', 'sutore-marketplace')
            );
        }

        $newListing = $this->bridge()->find($newListingId);
        if (!$newListing) {
            return new \WP_Error('sutore_pre_order_listing', __('Replacement product not found.', 'sutore-marketplace'));
        }

        if ((int) $newListing->merchantId !== $acceptingMerchantId) {
            return new \WP_Error(
                'sutore_pre_order_forbidden',
                __('This product does not belong to you.', 'sutore-marketplace')
            );
        }

        if ($newListingId !== $preOrderListingId && ListingStatus::isProcessLocked($newListing)) {
            return new \WP_Error(
                'sutore_pre_order_listing_locked',
                __('This product is already in an order process.', 'sutore-marketplace')
            );
        }

        $orderId = (int) $preOrder->orderId;
        $order = wc_get_order($orderId);
        if (!$order) {
            return new \WP_Error('sutore_marketplace_fulfillment_order', __('Order not found.', 'sutore-marketplace'));
        }

        $oldOrderItemId = (int) $preOrder->orderItemId;
        $oldLineTotal = 0.0;
        if ($oldOrderItemId > 0) {
            $oldItem = $order->get_item($oldOrderItemId);
            if ($oldItem instanceof \WC_Order_Item_Product) {
                $oldLineTotal = (float) $oldItem->get_total();
            }
        }
        if ($oldLineTotal <= 0) {
            $oldLineTotal = MarketplacePricing::customerPrice($preOrder);
        }

        if ($oldOrderItemId > 0) {
            $order->remove_item($oldOrderItemId);
        }

        $sameListing = $preOrderListingId === $newListingId;
        if (!$sameListing) {
            $this->repo->update($preOrderListingId, [
                'fulfillment_status' => ListingStatus::ORDER_DETACHED,
                'order_id' => null,
                'order_item_id' => null,
                'sold_at' => null,
                'confirm_deadline_at' => null,
                'seller_confirmed_at' => null,
                'cargo_deadline_at' => null,
                'merchant_shipped_at' => null,
                'merchant_shipment_code' => null,
                'confirm_notice_sent' => 0,
                'confirm_punished' => 0,
                'merchant_snapshot' => null,
                'is_winner' => 0,
            ]);
            (new ListingSelector())->rerunSize($preOrder->parentProductId, $preOrder->sizeTermId);
        }

        $newProduct = wc_get_product($newListing->variationId);
        if (!$newProduct) {
            return new \WP_Error('sutore_marketplace_fulfillment_product', __('Product not found.', 'sutore-marketplace'));
        }

        $newItemId = $order->add_product($newProduct, 1);
        if (!$newItemId) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('Could not add the product to the order.', 'sutore-marketplace')
            );
        }

        $newItem = $order->get_item($newItemId);
        if ($newItem instanceof \WC_Order_Item_Product) {
            (new \SutoreMarketplace\Modules\Orders\Hooks\OrderItemPricingMetaHooks())->applyMarketplaceLineTotals($newItem);
            $newItem->save();
        }

        $newCustomer = MarketplacePricing::customerPrice($newListing);
        $priceDiff = round($newCustomer - $oldLineTotal, 2);
        if (abs($priceDiff) >= 0.01) {
            $prevDiff = (float) $order->get_meta('_sutore_mp_price_difference', true);
            $order->update_meta_data('_sutore_mp_price_difference', round($prevDiff + $priceDiff, 2));
        }

        $order->calculate_totals();
        if (method_exists($order, 'recalculate_coupons')) {
            $order->recalculate_coupons();
        }
        $order->save();

        $title = Notifications::productTitle($newListing->variationId, $newListing->variationId, $newListing->parentProductId);
        $vars = $this->templateVars($order, $newListing, $title);
        Notifications::sendEvent('pre_order_swapped_customer', (string) $order->get_billing_phone(), $vars, true);

        $swapPayload = [
            'variation_id' => $preOrderListingId,
            'order_id' => $orderId,
            'old_variation_id' => $preOrderListingId,
            'new_variation_id' => $newListingId,
            'old_merchant_id' => (int) $preOrder->merchantId,
            'new_merchant_id' => (int) $newListing->merchantId,
            'price_difference' => $priceDiff,
        ];
        $this->logListingEvent('order_listing_swapped', $newListing, array_merge($swapPayload, ['role' => 'incoming']));
        $this->logListingEvent('order_listing_swapped', $preOrder, array_merge($swapPayload, ['role' => 'outgoing']));

        $attached = $this->onPaymentComplete($newListing, $orderId, (int) $newItemId);
        if (is_wp_error($attached)) {
            return $attached;
        }

        WebhookNotifier::dispatch('pre_order.accepted', [
            'pre_order_variation_id' => $preOrderListingId,
            'new_variation_id' => $newListingId,
            'order_id' => $orderId,
            'merchant_id' => $acceptingMerchantId,
        ]);

        return true;
    }

    /**
     * Eligible replacement listings for staff “Change Seller”.
     *
     * Default (no search): same parent product, any size, publish + winner, not on an order.
     * With search: any eligible winner matching product title / SKU / listing id.
     *
     * @param array{search?:string,per_page?:int} $args
     * @return array{items:list<array<string,mixed>>,total:int,scope:string,parent_product_id:int}|\WP_Error
     */
    public function listSwapCandidates(int $listingId, array $args = []): array|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['swap']) || !Settings::swapAllowedFor((string) $row->fulfillment_status)) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Seller cannot be changed in this status.', 'sutore-marketplace'));
        }

        $current = $this->bridge()->find($listingId);
        $parentId = $current ? (int) $current->parentProductId : 0;
        $search = sanitize_text_field((string) ($args['search'] ?? ''));
        $perPage = min(50, max(1, (int) ($args['per_page'] ?? 30)));
        $scope = $search !== '' ? 'search' : 'same_product';

        $queryArgs = [
            'status' => 'winner',
            'per_page' => $perPage,
            'page' => 1,
            'orderby' => 'asking',
            'order' => 'ASC',
        ];
        if ($search !== '') {
            $queryArgs['search'] = $search;
        } elseif ($parentId > 0) {
            $queryArgs['parent_product_id'] = $parentId;
        } else {
            return [
                'items' => [],
                'total' => 0,
                'scope' => $scope,
                'parent_product_id' => $parentId,
            ];
        }

        $result = (new ListingRepository())->query($queryArgs);
        $candidateIds = [];
        foreach ($result['items'] as $listing) {
            $candidateIds[] = (int) $listing->variationId;
        }
        $activeMap = $this->repo->findActiveByVariationIds($candidateIds);

        $items = [];
        foreach ($result['items'] as $listing) {
            if ((int) $listing->variationId === $listingId) {
                continue;
            }
            if ((int) $listing->orderId > 0 || isset($activeMap[(int) $listing->variationId])) {
                continue;
            }
            if ($scope === 'same_product' && $parentId > 0 && (int) $listing->parentProductId !== $parentId) {
                continue;
            }
            $presented = $this->presentSwapCandidate($listing, $parentId);
            if ($presented !== null) {
                $items[] = $presented;
            }
        }

        return [
            'items' => $items,
            'total' => count($items),
            'scope' => $scope,
            'parent_product_id' => $parentId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentSwapCandidate(Listing $listing, int $currentParentId): ?array
    {
        $listingId = (int) $listing->variationId;
        if ($listingId <= 0) {
            return null;
        }

        $user = get_userdata((int) $listing->merchantId);
        $merchantName = $user ? (string) $user->display_name : ('#' . (int) $listing->merchantId);
        $sizeLabel = ProductSizeLookup::labelForTerm((int) $listing->parentProductId, (int) $listing->sizeTermId);
        if ($sizeLabel === '') {
            $sizeLabel = ProductSizeLookup::labelForTermId((int) $listing->sizeTermId);
        }
        $asking = (float) $listing->asking;
        $customerPrice = MarketplacePricing::customerPrice($listing);
        $fees = MarketplacePricing::feeBreakdownForListing($listing);
        // Staff order screens compare the fee-inclusive customer price (hizmet + güvence),
        // matching the amount charged on the WooCommerce order line.
        $priceDisplay = MarketplacePricing::formatTl($customerPrice);
        $productTitle = Notifications::productTitle(
            $listingId,
            (int) $listing->variationId,
            (int) $listing->parentProductId,
            (int) $listing->sizeTermId
        );
        $sameParent = $currentParentId > 0 && (int) $listing->parentProductId === $currentParentId;

        if ($sameParent) {
            $label = sprintf(
                /* translators: 1: merchant name, 2: size label, 3: price, 4: variation id */
                __('%1$s · Size %2$s · %3$s · Var #%4$d', 'sutore-marketplace'),
                $merchantName,
                $sizeLabel !== '' ? $sizeLabel : '—',
                $priceDisplay,
                (int) $listing->variationId
            );
        } else {
            $label = sprintf(
                /* translators: 1: product title, 2: merchant name, 3: size label, 4: price, 5: variation id */
                __('%1$s · %2$s · Size %3$s · %4$s · Var #%5$d', 'sutore-marketplace'),
                $productTitle !== '' ? $productTitle : ('#' . (int) $listing->parentProductId),
                $merchantName,
                $sizeLabel !== '' ? $sizeLabel : '—',
                $priceDisplay,
                (int) $listing->variationId
            );
        }

        return [
            'id' => (int) $listing->variationId,
            'label' => $label,
            'merchant_id' => (int) $listing->merchantId,
            'merchant_name' => $merchantName,
            'parent_product_id' => (int) $listing->parentProductId,
            'size_term_id' => (int) $listing->sizeTermId,
            'size_label' => $sizeLabel,
            'variation_id' => (int) $listing->variationId,
            'asking' => $asking,
            'asking_display' => MarketplacePricing::formatTl($asking),
            'customer_price' => $customerPrice,
            'customer_price_display' => $priceDisplay,
            'hizmet_fee' => (float) $fees['hizmet'],
            'guvence_fee' => (float) $fees['guvence'],
            'product_title' => $productTitle,
            'thumbnail' => ProductThumbnail::url((int) $listing->variationId),
            'same_parent' => $sameParent,
        ];
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

        $listing = $this->bridge()->find($listingId);
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
        $actor = $this->resolveActor();
        $fresh = $listing;

        if ($startAsPayment) {
            $pending = $this->bridge()->markPaymentPending($listingId, $orderId, (int) $itemId);
            if (is_wp_error($pending)) {
                return $pending;
            }

            $this->repo->update($listingId, [
                'merchant_snapshot' => wp_json_encode(MerchantSnapshot::capture($listing->merchantId)),
            ]);

            $fresh = $this->bridge()->find($listingId) ?: $listing;
            $title = Notifications::productTitle($listingId, $fresh->variationId, $fresh->parentProductId);
            Notifications::notifyAdmins('sale_admin', $this->templateVars($order, $fresh, $title), true);

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
            $this->logListingEvent('order_listing_attached', $fresh, $payload);
            $this->logListingEvent('fulfillment_payment', $fresh, $payload);
            WebhookNotifier::dispatch('fulfillment.payment', [
                'variation_id' => $listingId,
                'order_id' => $orderId,
                'attachment_mode' => 'manual',
            ]);

            return true;
        }

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
            'variation_id' => $listingId,
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
            $listing = $this->bridge()->find($variationId);
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
            $containsSame = $parentId > 0 && $this->orderContainsParentProduct($order, $parentId);

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

    private function orderContainsParentProduct(\WC_Order $order, int $parentProductId): bool
    {
        if ($parentProductId <= 0) {
            return false;
        }

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            // WooCommerce order items may store the parent product id OR the variation id,
            // depending on how the product was added to the order.
            if ((int) $item->get_product_id() === $parentProductId) {
                return true;
            }

            $variationId = 0;
            if (method_exists($item, 'get_variation_id')) {
                $variationId = (int) $item->get_variation_id();
            }
            if ($variationId > 0) {
                $variation = wc_get_product($variationId);
                if ($variation) {
                    $parentId = (int) $variation->get_parent_id();
                    if ($parentId > 0 && $parentId === $parentProductId) {
                        return true;
                    }
                }
            }
        }

        return false;
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
            'fulfillment_status' => ListingStatus::ORDER_DETACHED,
            'order_item_id' => 0,
        ]);
        $this->bridge()->releaseFromOrder($listingId, ListingStatus::ORDER_DETACHED, [
            'reason' => $detachReason,
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) $row->order_item_id,
            'variation_id' => $listingId,
            'staff_note' => $staffNote,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ]);

        if ($listing) {
            $this->logListingEvent('listing_left_sale', $listing, [
                'variation_id' => $listingId,
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
     * @param array<string, mixed> $params
     */
    private function boolParam(array $params, string $key): bool
    {
        $value = $params[$key] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
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
                $listing = $this->bridge()->find($listingId);
                $this->repo->update($listingId, [
                    'fulfillment_status' => ListingStatus::ORDER_DETACHED,
                    'order_item_id' => 0,
                ]);
                $this->detachListingFromOrder($listingId, ListingStatus::ORDER_DETACHED, [
                    'reason' => 'cancelled',
                    'order_id' => $orderId,
                    'order_item_id' => (int) ($row->order_item_id ?? 0),
                    'variation_id' => $listingId,
                ]);
                if ($listing) {
                    $this->logListingEvent('listing_left_sale', $listing, [
                        'variation_id' => $listingId,
                        'order_id' => $orderId,
                        'reason' => 'cancelled',
                    ], $row);
                }
                continue;
            }

            if (ListingStatus::isLateFulfillment($status)) {
                $listing = $this->bridge()->find($listingId);
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

    private function refundOrderLineIfPaid(\WC_Order $order, int $itemId): void
    {
        if ($itemId <= 0 || !$order->is_paid()) {
            return;
        }

        $item = $order->get_item($itemId);
        if (!$item instanceof \WC_Order_Item_Product) {
            return;
        }

        $qty = (int) $item->get_quantity();
        $total = (float) $item->get_total();
        $tax = (float) $item->get_total_tax();
        if ($qty <= 0 || ($total + $tax) <= 0) {
            return;
        }

        $refundTax = [];
        $taxes = $item->get_taxes();
        if (isset($taxes['total']) && is_array($taxes['total'])) {
            foreach ($taxes['total'] as $rateId => $amount) {
                $refundTax[(int) $rateId] = (float) $amount;
            }
        }

        wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => round($total + $tax, 2),
            'reason' => __('Pre-order could not be sourced.', 'sutore-marketplace'),
            'line_items' => [
                $itemId => [
                    'qty' => $qty,
                    'refund_total' => $total,
                    'refund_tax' => $refundTax,
                ],
            ],
            'restock_items' => false,
            'refund_payment' => false,
        ]);
    }

    private function cancelOrderIfNoOpenItems(\WC_Order $order): void
    {
        if ($order->has_status(['cancelled', 'refunded', 'failed', 'trash'])) {
            return;
        }

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $qty = (int) $item->get_quantity();
            $refunded = abs($order->get_qty_refunded_for_item($item->get_id()));
            if ($qty - $refunded > 0) {
                return;
            }
        }

        $order->update_status(
            'cancelled',
            __('Order cancelled because the last marketplace item could not be sourced.', 'sutore-marketplace')
        );
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
            $productName = Notifications::productTitle((int) $listing->variationId, $listing->variationId, $listing->parentProductId);
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
        $listing = $this->bridge()->find((int) $row->variation_id);
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
        $listingId = (int) $row->variation_id;
        $listing = $this->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if (!$listing || !$order) {
            return;
        }

        $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
        $vars = $this->templateVars($order, $listing, $title);
        $vars['confirm_hours'] = (string) (int) (Settings::confirmGraceSeconds() / HOUR_IN_SECONDS);
        $customerPhone = (string) $order->get_billing_phone();

        if (!(int) $row->confirm_notice_sent) {
            $this->dispatchMerchantNotification(
                NotificationType::SALE_CONFIRM_REMINDER,
                $listing,
                $order,
                [
                    'variation_id' => $listingId,
                    'confirm_hours' => (int) (Settings::confirmGraceSeconds() / HOUR_IN_SECONDS),
                ]
            );
            $this->repo->update($listingId, [
                'confirm_notice_sent' => 1,
                'confirm_deadline_at' => DeadlineCalculator::fromNow(Settings::confirmGraceSeconds()),
            ]);
            $this->logListingEvent('fulfillment_confirm_reminder', $listing, [
                'variation_id' => $listingId,
                'order_id' => (int) $row->order_id,
            ], $row);
            return;
        }

        if (!(int) $row->confirm_punished) {
            $marked = $this->markAsPreOrder($listingId, 'confirm_deadline');
            if (is_wp_error($marked)) {
                return;
            }
            Notifications::sendEvent('suspended_customer', $customerPhone, $vars, true);
            $this->dispatchMerchantNotification(
                NotificationType::SALE_SUSPENDED,
                $listing,
                $order,
                ['variation_id' => $listingId]
            );
            (new AskMerchants())->notifyForSize($listing->parentProductId, $listing->sizeTermId, $listing->asking, $title);
            $eventsRepo = new ListingEventsRepository();
            $merchantId = (int) $row->merchant_id;
            if ($eventsRepo->hasPreOrderAcceptance($merchantId, $listingId)) {
                $this->logListingEvent(
                    \SutoreMarketplace\Modules\Listings\Domain\ListingEventType::PRE_ORDER_COMMITMENT_BROKEN,
                    $listing,
                    [
                        'variation_id' => $listingId,
                        'order_id' => (int) $row->order_id,
                        'reason' => 'confirm_deadline',
                    ],
                    $row
                );
            } else {
                $this->logListingEvent(
                    \SutoreMarketplace\Modules\Listings\Domain\ListingEventType::CONFIRM_DEADLINE_MISSED,
                    $listing,
                    [
                        'variation_id' => $listingId,
                        'order_id' => (int) $row->order_id,
                    ],
                    $row
                );
            }
            (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())->refreshMerchant($merchantId);
            WebhookNotifier::dispatch('listing.pre_order', [
                'variation_id' => $listingId,
                'reason' => 'confirm_deadline',
            ]);
        }
    }

    private function processCargoDeadline(object $row): void
    {
        $listingId = (int) $row->variation_id;
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
        $deadlineTs = $row->cargo_deadline_at ? strtotime((string) $row->cargo_deadline_at) : false;
        $now = current_time('timestamp');

        if ($deadlineTs === false) {
            return;
        }

        if (!(int) $row->cargo_notice_sent && $now >= $deadlineTs - Settings::cargoReminderSeconds()) {
            $shipmentType = (string) $order->get_meta(ShipmentMeta::TYPE);
            $this->dispatchMerchantNotification(
                NotificationType::SALE_CARGO_REMINDER,
                $listing,
                $order,
                [
                    'variation_id' => $listingId,
                    'cargo_hours' => (int) (Settings::cargoDeadlineSecondsForShipmentType($shipmentType) / HOUR_IN_SECONDS),
                ]
            );
            $this->repo->update($listingId, ['cargo_notice_sent' => 1]);
            $this->logListingEvent('fulfillment_cargo_reminder', $listing, [
                'variation_id' => $listingId,
                'order_id' => (int) $row->order_id,
            ], $row);
            return;
        }

        if (!(int) $row->cargo_expired_flag && $now >= $deadlineTs) {
            $this->repo->update($listingId, ['cargo_expired_flag' => 1]);
            Notifications::sendEvent('seller_cargo_expired', (string) $order->get_billing_phone(), $vars, true);
            $this->dispatchMerchantNotification(
                NotificationType::SALE_CARGO_EXPIRED,
                $listing,
                $order,
                ['variation_id' => $listingId]
            );
            $this->logListingEvent('fulfillment_cargo_expired', $listing, [
                'variation_id' => $listingId,
                'order_id' => (int) $row->order_id,
                'cargo_deadline_at' => (string) $row->cargo_deadline_at,
            ], $row);
            (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())
                ->refreshMerchant((int) $row->merchant_id);
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
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('No products.', 'sutore-marketplace'));
        }

        $order = wc_get_order((int) $row->order_id);
        $shipmentType = $order ? (string) $order->get_meta(ShipmentMeta::TYPE) : 'standard';
        $cargoHours = (int) (Settings::cargoDeadlineSecondsForShipmentType($shipmentType) / HOUR_IN_SECONDS);

        return [
            'variation_id' => $listingId,
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

        $title = Notifications::productTitle((int) $listing->variationId, $listing->variationId, $listing->parentProductId);
        $vars = $this->templateVars($order, $listing, $title);
        $vars['confirm_hours'] = (string) (int) (Settings::confirmDeadlineSeconds($listing->merchantId) / HOUR_IN_SECONDS);

        $this->dispatchMerchantNotification(
            NotificationType::SALE_RECEIVED,
            $listing,
            $order,
            [
                'variation_id' => $listingId ?: (int) $listing->variationId,
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
        $title = Notifications::productTitle((int) $listing->variationId, $listing->variationId, $listing->parentProductId);
        $vars = $this->templateVars($order, $listing, $title);
        if ($trackCode) {
            $vars['track_code'] = $trackCode;
        }

        $customer = (string) $order->get_billing_phone();
        $listingContext = [
            'variation_id' => $listingId ?: (int) $listing->variationId,
            'track_code' => (string) ($trackCode ?? ''),
        ];

        match ($status) {
            ListingStatus::ARRIVED_TO_SUTORE => [
                Notifications::sendEvent('arrived_customer', $customer, $vars),
                $this->dispatchMerchantNotification(
                    NotificationType::FULFILLMENT_ARRIVED_AT_SUTORE,
                    $listing,
                    $order,
                    $listingContext
                ),
            ],
            ListingStatus::VERIFIED => [
                Notifications::sendEvent('verified_customer', $customer, $vars),
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
        $title = Notifications::productTitle((int) $listing->variationId, $listing->variationId, $listing->parentProductId);
        $context = array_merge([
            'product' => $title,
            'price' => $listing->asking,
            'variation_id' => (int) $listing->variationId,
        ], $extra);

        if ($order) {
            $context['order_id'] = (int) $order->get_id();
            $context['customer_name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $shipmentType = (string) $order->get_meta(ShipmentMeta::TYPE);
            $context['shipment_type'] = $shipmentType;
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
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
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
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'from_status' => $expectedFrom,
            'to_status' => $toStatus,
            'sutore_shipment_code' => $patch['sutore_shipment_code'] ?? null,
            'staff_note' => $this->optionalStaffNote($args),
        ], static fn ($value) => $value !== null && $value !== ''), $row);

        WebhookNotifier::dispatch('fulfillment.' . $toStatus, [
            'variation_id' => $listingId,
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
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
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
            'variation_id' => $listingId,
            'staff_note' => $note,
        ]);

        $listing = $this->bridge()->find($listingId);
        $this->logListingEvent($eventType, $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'staff_note' => $note,
            'to_status' => $toStatus,
        ], $row);

        WebhookNotifier::dispatch('fulfillment.' . $toStatus, [
            'variation_id' => $listingId,
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
        (new ListingEventsRepository())->logForListing(
            $eventType,
            $listing,
            $payload,
            $listing?->merchantId ?? (isset($row->merchant_id) ? (int) $row->merchant_id : null),
            'merchant_visible',
            $row
        );
    }

    private function logListingLifecycleCompleted(object $row, ?Listing $listing): void
    {
        $listingId = (int) ($row->variation_id ?? 0);
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
            'variation_id' => $listingId,
            'order_id' => (int) ($row->order_id ?? 0),
            'order_item_id' => (int) ($row->order_item_id ?? 0),
            'delivered_at' => (string) ($row->delivered_at ?? ''),
        ], $row);

        WebhookNotifier::dispatch('listing.lifecycle_completed', [
            'variation_id' => $listingId,
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
