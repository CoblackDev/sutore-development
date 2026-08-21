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

final class StaffFulfillmentCommands
{
    public function __construct(
        private readonly FulfillmentCommandSupport $support,
        private readonly FulfillmentRepository $repo,
        private readonly PaymentReservationCommands $payment,
        private readonly PayoutCommands $payout,
        private readonly SourcingSwapCommands $sourcing,
    ) {
    }

    /**
     * @param array{staff_note?:string,sutore_shipment_code?:string} $args
     */
    public function markArrivedAtSutore(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->support->advanceStatus($listingId, ListingStatus::SHIPPED_TO_SUTORE, ListingStatus::ARRIVED_TO_SUTORE, 'fulfillment_arrived_at_sutore', $args);
    }

    /**
     * @param array{staff_note?:string} $args
     */
    public function markVerified(int $listingId, array $args = []): true|\WP_Error
    {
        $result = $this->support->advanceStatus($listingId, ListingStatus::ARRIVED_TO_SUTORE, ListingStatus::VERIFIED, 'fulfillment_verified', $args);
        if ($result !== true) {
            return $result;
        }

        $row = $this->repo->find($listingId);
        $listing = $row ? $this->support->bridge()->find($listingId) : null;
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
        return $this->support->advanceStatus($listingId, ListingStatus::VERIFIED, ListingStatus::READY_TO_SHIPPING, 'fulfillment_ready_to_ship', $args);
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

        return $this->support->advanceStatus(
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

        $listing = $this->support->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if ($listing && $order) {
            $this->support->notifyStatusChange($listing, $order, ListingStatus::DELIVERED_TO_CUSTOMER, null, $listingId);
        }

        $this->support->logListingEvent('fulfillment_delivered_to_customer', $listing, array_filter([
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'delivered_at' => $deliveredAt,
            'return_window_ends_at' => $windowEnds,
            'staff_note' => $this->support->optionalStaffNote($args),
        ], static fn ($value) => $value !== null && $value !== ''), $row);

        WebhookNotifier::dispatch('fulfillment.delivered_to_customer', [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        $this->support->logListingLifecycleCompleted($this->repo->find($listingId) ?? $row, $listing);
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
    public function hubRejectFulfillment(int $listingId, array $args = []): true|\WP_Error
    {
        $note = $this->support->requireStaffNote($args);
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
        $this->support->detachListingFromOrder($listingId, ListingStatus::NOT_SALE, [
            'reason' => 'hub_rejected',
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) $row->order_item_id,
            'variation_id' => $listingId,
            'staff_note' => $note,
        ]);

        $listing = $this->support->bridge()->find($listingId);
        $this->support->logListingEvent(\SutoreMarketplace\Modules\Listings\Domain\ListingEventType::HUB_REJECTED, $listing, [
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
        return $this->support->applyIntervention(
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
        $note = $this->support->requireStaffNote($args);
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
        $this->support->detachListingFromOrder($listingId, ListingStatus::CHARGEBACK, [
            'reason' => 'chargeback',
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) $row->order_item_id,
            'variation_id' => $listingId,
            'staff_note' => $note,
        ]);

        $listing = $this->support->bridge()->find($listingId);
        $this->support->logListingEvent('fulfillment_chargeback', $listing, [
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
        if (empty($caps['delete']) || $this->payment->isLinkedToOrder($row)) {
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

        $returnToQueue = $this->support->boolParam($params, 'return_to_queue');

        return match ($action) {
            'confirm_payment' => $this->payment->adminConfirmPayment($listingId),
            'swap' => $this->sourcing->swapMerchant(
                $listingId,
                (int) ($params['new_variation_id'] ?? 0),
                (string) ($noteArgs['staff_note'] ?? ''),
                $returnToQueue
            ),
            'attach_to_order' => $this->payment->attachToOrder(
                $listingId,
                (int) ($params['order_id'] ?? 0),
                $noteArgs
            ),
            'split' => $this->payment->splitFromOrder(
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
            'mark_payout_paid' => $this->payout->markMerchantPayout(
                $listingId,
                sanitize_text_field((string) ($params['payment_ref'] ?? ''))
            ),
            'adjust_commission' => $this->payout->adjustPayoutCommission($listingId, $params),
            'set_listing_commission' => $this->payout->setListingCommission($listingId, $params),
            'mark_imported' => $this->markListingImported($listingId),
            'unmark_imported' => $this->unmarkListingImported($listingId),
            'put_on_sale' => $this->putListingOnSale($listingId),
            'approve' => $this->approveListing($listingId),
            'mark_pre_order' => $this->sourcing->markAsPreOrder($listingId, 'staff'),
            'close_pre_order' => $this->sourcing->closeUnsourcedPreOrder($listingId, $noteArgs),
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
        $listing = $this->support->bridge()->find($listingId);
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
        $listing = $this->support->bridge()->find($listingId);
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
}