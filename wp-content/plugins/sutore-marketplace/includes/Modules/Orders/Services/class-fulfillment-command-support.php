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

final class FulfillmentCommandSupport
{
    public function __construct(
        private readonly FulfillmentRepository $repo,
    ) {
    }


    public function bridge(): ListingOrderBridge
    {
        return new ListingOrderBridge();
    }

    public function advanceStatus(
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

        $operationId = sanitize_text_field((string) ($args['operation_id'] ?? ''));
        if ($operationId === '') {
            $operationId = $eventType . ':' . $listingId . ':' . $expectedFrom . ':' . $toStatus;
        }

        $patch = ['fulfillment_status' => $toStatus];
        if (!empty($args['sutore_shipment_code'])) {
            $patch['sutore_shipment_code'] = sanitize_text_field((string) $args['sutore_shipment_code']);
        }
        if ($toStatus === ListingStatus::SHIPPED) {
            $patch['sutore_shipped_at'] = current_time('mysql');
        }

        $result = $this->repo->transition($listingId, $expectedFrom, $patch, $operationId);
        if ($result->isAlreadyDone()) {
            return true;
        }
        if (!$result->isChanged()) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Invalid status.', 'sutore-marketplace'));
        }

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
            'operation_id' => $result->operationId(),
            'sutore_shipment_code' => $patch['sutore_shipment_code'] ?? null,
            'staff_note' => $this->optionalStaffNote($args),
        ], static fn ($value) => $value !== null && $value !== ''), $row);

        WebhookNotifier::dispatch('fulfillment.' . $toStatus, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'operation_id' => $result->operationId(),
            'event_key' => $result->operationId(),
        ], $result->operationId());

        return true;
    }

    /**
     * @param array{staff_note?:string} $args
     */
    public function applyIntervention(
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

        $fromStatus = (string) $row->fulfillment_status;
        $operationId = sanitize_text_field((string) ($args['operation_id'] ?? ''));
        if ($operationId === '') {
            $operationId = $eventType . ':' . $listingId . ':' . $fromStatus . ':' . $toStatus;
        }

        $result = $this->repo->transition($listingId, $fromStatus, [
            'fulfillment_status' => $toStatus,
        ], $operationId);
        if ($result->isAlreadyDone()) {
            return true;
        }
        if (!$result->isChanged()) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Invalid status.', 'sutore-marketplace'));
        }

        $this->detachListingFromOrder($listingId, $listingStatus, [
            'reason' => $capabilityKey,
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) $row->order_item_id,
            'variation_id' => $listingId,
            'staff_note' => $note,
            'operation_id' => $result->operationId(),
        ]);

        $listing = $this->bridge()->find($listingId);
        $this->logListingEvent($eventType, $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'staff_note' => $note,
            'to_status' => $toStatus,
            'operation_id' => $result->operationId(),
        ], $row);

        WebhookNotifier::dispatch('fulfillment.' . $toStatus, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'operation_id' => $result->operationId(),
        ], $result->operationId());

        return true;
    }

    public function notifySaleApproved(Listing $listing, int $orderId, int $listingId = 0): void
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

    public function notifyStatusChange(Listing $listing, \WC_Order $order, string $status, ?string $trackCode, int $listingId = 0): void
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
    public function dispatchMerchantNotification(
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

    /** @param array<string, mixed> $args */
    public function requireStaffNote(array $args): string|\WP_Error
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
    public function optionalStaffNote(array $args): ?string
    {
        $note = sanitize_textarea_field((string) ($args['staff_note'] ?? ''));

        return $note !== '' ? $note : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function logListingEvent(string $eventType, ?Listing $listing, array $payload = [], ?object $row = null): void
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

    public function logListingLifecycleCompleted(object $row, ?Listing $listing): void
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
    public function templateVars(\WC_Order $order, Listing $listing, string $title): array
    {
        $shipmentType = (string) $order->get_meta(ShipmentMeta::TYPE);
        $vars = Notifications::baseVars($order, $title, $listing->asking);
        $vars['confirm_hours'] = (string) (int) (Settings::confirmDeadlineSeconds($listing->merchantId) / HOUR_IN_SECONDS);
        $vars['cargo_hours'] = (string) (int) (Settings::cargoDeadlineSecondsForShipmentType($shipmentType) / HOUR_IN_SECONDS);

        return $vars;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function detachListingFromOrder(int $listingId, string $newStatus, array $context = []): void
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
    public function resolveActor(): array
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
    public function addSplitOrderNote(\WC_Order $order, ?Listing $listing, array $actor): void
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

    public function refundOrderLineIfPaid(\WC_Order $order, int $itemId): void
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

    public function cancelOrderIfNoOpenItems(\WC_Order $order): void
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

    public function orderContainsParentProduct(\WC_Order $order, int $parentProductId): bool
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

    /**
     * @param array<string, mixed> $params
     */
    public function boolParam(array $params, string $key): bool
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
}