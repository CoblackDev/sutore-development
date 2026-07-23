<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Admin\AdminMenu;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingActivityPresenter;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Orders\Domain\StaffQueueFilter;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentType;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class StaffFulfillmentPresenter
{
    public function __construct(
        private readonly FulfillmentRepository $fulfillments = new FulfillmentRepository(),
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingService $listingService = new ListingService(),
        private readonly FulfillmentService $fulfillmentService = new FulfillmentService(),
        private readonly PayoutLineRepository $payouts = new PayoutLineRepository(),
        private readonly ListingActivityPresenter $activity = new ListingActivityPresenter(),
    ) {
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function presentDetail(int $fulfillmentId): array|\WP_Error
    {
        $row = $this->fulfillments->find($fulfillmentId);
        if (!$row) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_missing',
                __('Fulfillment not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $item = $this->presentRow($row);
        $payout = $this->payouts->findByListingId($fulfillmentId);
        $actions = $this->buildActions($row, $item, $payout);

        $snapshot = is_array($item['merchant_snapshot'] ?? null) ? $item['merchant_snapshot'] : [];
        $capturedAt = (string) ($snapshot['captured_at'] ?? '');
        $capturedTs = $capturedAt !== '' ? strtotime($capturedAt) : false;
        if ($capturedTs) {
            $snapshot['captured_at_display'] = date_i18n(
                get_option('date_format') . ' ' . get_option('time_format'),
                $capturedTs
            );
        } else {
            $snapshot['captured_at_display'] = $capturedAt;
        }

        $payoutPayload = null;
        if ($payout) {
            $payoutPayload = [
                'payout_status' => (string) $payout->payout_status,
                'payout_status_label' => PayoutStatus::label((string) $payout->payout_status),
                'net_amount' => (float) $payout->net_amount,
                'net_amount_display' => MarketplacePricing::formatTl((float) $payout->net_amount),
            ];
        }

        return array_merge($item, [
            'merchant_snapshot' => $snapshot,
            'has_merchant_snapshot' => MerchantSnapshot::hasPaymentFields($snapshot),
            'payout' => $payoutPayload,
            'payment_status_display' => $payoutPayload
                ? ($payoutPayload['payout_status_label'] . ' · ' . $payoutPayload['net_amount_display'])
                : __('Not created yet (after verification)', 'sutore-marketplace'),
            'actions' => $actions,
            'activity' => $this->activity->present(
                (int) $item['listing_id'],
                (int) $item['variation_id']
            ),
        ]);
    }

    /**
     * Staff fulfillments list: query + present + labels.
     *
     * @param array<string, mixed> $args
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   status_labels: array<string, string>,
     *   queue_labels: array<string, string>
     * }
     */
    public function presentStaffQuery(array $args): array
    {
        $result = $this->fulfillments->query($args);

        return [
            'items' => $this->presentRows($result['items']),
            'total' => $result['total'],
            'page' => (int) $args['page'],
            'per_page' => (int) $args['per_page'],
            'status_labels' => ListingStatus::labels(),
            'queue_labels' => StaffQueueFilter::labels(),
        ];
    }

    /**
     * Merchant fulfillments list: query + light row map.
     *
     * @param array<string, mixed> $args
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int
     * }
     */
    public function presentMerchantQuery(array $args): array
    {
        $result = $this->fulfillments->query($args);
        $items = array_map(static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'listing_id' => (int) $row->listing_id,
                'variation_id' => (int) $row->variation_id,
                'order_id' => (int) $row->order_id,
                'order_item_id' => (int) $row->order_item_id,
                'merchant_id' => (int) $row->merchant_id,
                'fulfillment_status' => (string) $row->fulfillment_status,
                'status_label' => ListingStatus::label((string) $row->fulfillment_status),
                'confirm_deadline_at' => (string) ($row->confirm_deadline_at ?? ''),
                'cargo_deadline_at' => (string) ($row->cargo_deadline_at ?? ''),
                'delivered_at' => (string) ($row->delivered_at ?? ''),
                'return_window_ends_at' => (string) ($row->return_window_ends_at ?? ''),
                'merchant_shipment_code' => (string) ($row->merchant_shipment_code ?? ''),
                'sutore_shipment_code' => (string) ($row->sutore_shipment_code ?? ''),
            ];
        }, $result['items']);

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => (int) $args['page'],
            'per_page' => (int) $args['per_page'],
        ];
    }

    /**
     * Batch-present fulfillment/listing rows (staff list path).
     *
     * @param list<object> $rows
     * @return list<array<string, mixed>>
     */
    public function presentRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $merchantIds = [];
        foreach ($rows as $row) {
            $merchantId = (int) ($row->merchant_id ?? 0);
            if ($merchantId > 0) {
                $merchantIds[$merchantId] = $merchantId;
            }
        }

        $merchantNames = [];
        if ($merchantIds !== []) {
            $users = get_users([
                'include' => array_values($merchantIds),
                'fields' => ['ID', 'display_name'],
            ]);
            foreach ($users as $user) {
                $merchantNames[(int) $user->ID] = (string) $user->display_name;
            }
        }

        $listingIds = [];
        foreach ($rows as $row) {
            $listingIds[] = (int) ($row->id ?? $row->listing_id ?? 0);
        }
        $payoutMap = $this->payouts->findByListingIds($listingIds);

        $out = [];
        foreach ($rows as $row) {
            $merchantId = (int) ($row->merchant_id ?? 0);
            $item = $this->presentRow(
                $row,
                $merchantNames[$merchantId] ?? ('#' . $merchantId)
            );
            $listingId = (int) ($item['listing_id'] ?? $item['id'] ?? 0);
            $payout = $payoutMap[$listingId] ?? null;
            if ($payout) {
                $status = (string) $payout->payout_status;
                $label = PayoutStatus::label($status);
                $netDisplay = MarketplacePricing::formatTl((float) $payout->net_amount);
                $item['payout_status'] = $status;
                $item['payout_status_label'] = $label;
                $item['payout_net_amount_display'] = $netDisplay;
                $item['payment_status_display'] = $label;
            } else {
                $item['payout_status'] = '';
                $item['payout_status_label'] = __('Not created yet', 'sutore-marketplace');
                $item['payout_net_amount_display'] = '';
                $item['payment_status_display'] = $item['payout_status_label'];
            }
            $item['actions'] = $this->buildActions($row, $item, $payout);
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function buildActions(object $row, array $item, ?object $payout): array
    {
        $status = (string) ($row->fulfillment_status ?? $row->listing_status ?? '');
        $caps = ListingStatus::staffCapabilities($status);

        $canDetach = !empty($caps['detach'])
            && ListingStatus::allowsDetach($status)
            && !empty($item['has_order_link']);
        $canMarkPayout = !empty($caps['mark_payout'])
            && ListingStatus::allowsPayout($status)
            && $payout
            && (string) $payout->payout_status === PayoutStatus::PENDING;
        $canPutOnSale = !empty($caps['put_on_sale']) && !empty($item['can_put_on_sale']);
        $canDelete = !empty($caps['delete'])
            && !empty($item['can_delete'])
            && empty($item['has_order_link']);
        $canAttachToOrder = !empty($caps['attach_to_order'])
            && ListingStatus::allowsManualOrderAttach($status)
            && empty($item['has_order_link'])
            && Settings::allowManualOrderLink();

        return [
            'confirm_payment' => !empty($caps['confirm_payment']),
            'swap' => !empty($caps['swap']),
            'detach' => $canDetach,
            'attach_to_order' => $canAttachToOrder,
            'mark_arrived' => !empty($caps['mark_arrived']),
            'mark_verified' => !empty($caps['mark_verified']),
            'mark_ready_to_ship' => !empty($caps['mark_ready_to_ship']),
            'mark_shipped_to_customer' => !empty($caps['mark_shipped_to_customer']),
            'mark_delivered' => !empty($caps['mark_delivered']),
            'mark_not_for_sale' => !empty($caps['mark_not_for_sale']),
            'chargeback' => !empty($caps['chargeback']),
            'mark_payout' => $canMarkPayout,
            'put_on_sale' => $canPutOnSale,
            'delete' => $canDelete,
            'requires_staff_note' => ListingStatus::actionsRequiringStaffNote(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRow(object $row, ?string $merchantName = null): array
    {
        $listingId = (int) $row->listing_id;
        $variationId = (int) $row->variation_id;
        $orderId = (int) $row->order_id;
        $merchantId = (int) $row->merchant_id;
        $hasOrderLink = $this->fulfillmentService->isLinkedToOrder($row);

        // Facade row is the listings row — prefer in-row fields over a re-find.
        $parentId = isset($row->parent_product_id) ? (int) $row->parent_product_id : 0;
        $sizeTermId = isset($row->size_term_id) ? (int) $row->size_term_id : 0;
        $listing = null;
        if ($parentId > 0 || isset($row->listing_status)) {
            $listing = \SutoreMarketplace\Modules\Listings\Domain\Listing::fromRow($row);
            $parentId = $parentId > 0 ? $parentId : (int) $listing->parentProductId;
            $sizeTermId = $sizeTermId > 0 ? $sizeTermId : (int) $listing->sizeTermId;
        } else {
            $listing = $this->listings->find($listingId);
            $parentId = $listing ? (int) $listing->parentProductId : 0;
            $sizeTermId = $listing ? (int) $listing->sizeTermId : 0;
        }

        $title = Notifications::productTitle($listingId, $variationId, $parentId, $sizeTermId);
        if ($title === '' || $title === (string) $variationId) {
            $title = sprintf(
                /* translators: %d: listing id */
                __('Listing #%d', 'sutore-marketplace'),
                $listingId
            );
        }

        if ($merchantName === null) {
            $user = get_userdata($merchantId);
            $merchantName = $user ? $user->display_name : ('#' . $merchantId);
        }

        $orderEditUrl = '';
        if ($hasOrderLink && $orderId > 0 && current_user_can(AdminMenu::CAP)) {
            $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            $orderEditUrl = $hpos
                ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId)
                : admin_url('post.php?post=' . $orderId . '&action=edit');
        }

        $canPutOnSale = $listing ? $this->listingService->canPutOnSale($listing) : false;
        $canDelete = $listing ? $this->listingService->canDelete($listing) : false;
        $listingStatus = $listing ? (string) $listing->listingStatus : (string) ($row->listing_status ?? '');
        $asking = isset($row->asking) ? (float) $row->asking : ($listing ? (float) $listing->asking : 0.0);
        $campaignStatus = $listing
            ? (string) $listing->campaignStatus
            : (string) ($row->campaign_status ?? 'none');
        $isSourcing = $listing
            ? $listing->sourcingRequestId !== null
            : (isset($row->sourcing_request_id) && $row->sourcing_request_id !== null && (int) $row->sourcing_request_id > 0);
        $isPreOrder = $isSourcing;
        $orderShipmentType = sanitize_key((string) ($row->order_shipment_type ?? ''));
        if ($orderShipmentType === '' && $orderId > 0) {
            $order = wc_get_order($orderId);
            if ($order instanceof \WC_Order) {
                $orderShipmentType = sanitize_key((string) $order->get_meta(ShipmentMeta::TYPE));
            }
        }
        $orderShipmentTypeLabel = $orderShipmentType !== ''
            ? ShipmentType::label($orderShipmentType)
            : '';

        // Staff/list previews always use the parent product image.
        $thumbnail = $parentId > 0 ? ProductThumbnail::url($parentId) : '';
        if ($thumbnail === '' && $variationId > 0) {
            $thumbnail = ProductThumbnail::url($variationId);
        }

        $merchantShippedAt = (string) ($row->merchant_shipped_at ?? '');
        $sutoreShippedAt = (string) ($row->sutore_shipped_at ?? '');
        $deliveredAt = (string) ($row->delivered_at ?? '');

        return [
            'id' => (int) $row->id,
            'listing_id' => $listingId,
            'variation_id' => $variationId,
            'parent_product_id' => $parentId,
            'order_id' => $orderId,
            'has_order_link' => $hasOrderLink,
            'in_sale_lifecycle' => ListingStatus::isInSaleLifecycle($listingStatus),
            'merchant_id' => $merchantId,
            'fulfillment_status' => (string) $row->fulfillment_status,
            'status_label' => $listingStatus !== ''
                ? ListingStatus::label($listingStatus)
                : ListingStatus::label((string) $row->fulfillment_status),
            'product_title' => $title,
            'thumbnail' => $thumbnail,
            'asking' => $asking,
            'asking_display' => MarketplacePricing::formatTl($asking),
            'merchant_name' => $merchantName,
            'order_edit_url' => $orderEditUrl,
            'sutore_shipment_code' => (string) ($row->sutore_shipment_code ?? ''),
            'merchant_shipment_code' => (string) ($row->merchant_shipment_code ?? ''),
            'order_shipment_type' => $orderShipmentType,
            'order_shipment_type_label' => $orderShipmentTypeLabel !== '' ? $orderShipmentTypeLabel : '—',
            'merchant_shipped_at' => $merchantShippedAt,
            'merchant_shipped_at_display' => self::formatDateTime($merchantShippedAt),
            'sutore_shipped_at' => $sutoreShippedAt,
            'sutore_shipped_at_display' => self::formatDateTime($sutoreShippedAt),
            'delivered_at' => $deliveredAt,
            'delivered_at_display' => self::formatDateTime($deliveredAt),
            'return_window_ends_at' => (string) ($row->return_window_ends_at ?? ''),
            'can_put_on_sale' => $canPutOnSale,
            'can_delete' => $canDelete,
            'listing_status' => $listingStatus,
            'listing_status_label' => $listingStatus !== '' ? ListingStatus::label($listingStatus) : '—',
            'campaign_status' => $campaignStatus,
            'campaign_status_label' => ListingStatus::campaignLabel($campaignStatus),
            'is_sourcing' => $isSourcing,
            'is_pre_order' => $isPreOrder,
            'is_imported' => $listing
                ? $listing->isImported
                : !empty($row->is_imported),
            'merchant_snapshot' => MerchantSnapshot::decode($row->merchant_snapshot ?? null),
        ];
    }

    private static function formatDateTime(string $mysqlDatetime): string
    {
        $mysqlDatetime = trim($mysqlDatetime);
        if ($mysqlDatetime === '') {
            return '';
        }

        $ts = strtotime($mysqlDatetime);

        return $ts
            ? (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts)
            : $mysqlDatetime;
    }
}
