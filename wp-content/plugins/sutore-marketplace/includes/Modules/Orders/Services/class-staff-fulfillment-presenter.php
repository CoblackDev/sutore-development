<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Admin\StaffCapabilities;
use SutoreMarketplace\Modules\Invoices\Repositories\InvoiceRepository;
use SutoreMarketplace\Modules\Invoices\Services\InvoicePresenter;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductListChrome;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingActivityPresenter;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule;
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
        private readonly InvoiceRepository $invoices = new InvoiceRepository(),
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
                __('Sale record not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $item = $this->presentRow($row);
        $payout = $this->payouts->findByVariationId($fulfillmentId);
        $actions = $this->buildActions($row, $item, $payout);

        $snapshot = is_array($item['merchant_snapshot'] ?? null) ? $item['merchant_snapshot'] : [];
        $levelKey = sanitize_key((string) ($snapshot['merchant_level'] ?? ''));
        if ($levelKey !== '') {
            $snapshot['merchant_level_label'] = \SutoreMarketplace\Shared\Domain\MerchantLevels::labelForStatus($levelKey);
        }
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
            $scheduled = PayoutSchedule::normalizeDate($payout->scheduled_payout_date ?? '');
            $paidAt = trim((string) ($payout->paid_at ?? ''));
            $paidTs = $paidAt !== '' ? strtotime($paidAt) : false;
            $payoutPayload = [
                'payout_status' => (string) $payout->payout_status,
                'payout_status_label' => PayoutStatus::label((string) $payout->payout_status),
                'commission_percent' => round((float) $payout->commission_percent, 2),
                'commission_amount' => round((float) ($payout->commission_amount ?? 0), 2),
                'hizmet_fee' => round((float) ($payout->hizmet_fee ?? 0), 2),
                'guvence_fee' => round((float) ($payout->guvence_fee ?? 0), 2),
                'extra_deduction' => round((float) ($payout->extra_deduction ?? 0), 2),
                'gross_asking' => (float) $payout->gross_asking,
                'net_amount' => (float) $payout->net_amount,
                'net_amount_display' => MarketplacePricing::formatTl((float) $payout->net_amount),
                'scheduled_payout_date' => $scheduled,
                'scheduled_payout_date_display' => PayoutSchedule::formatDateWithWeekday($scheduled),
                'scheduled_message' => (string) $payout->payout_status === PayoutStatus::PENDING
                    ? PayoutSchedule::merchantPendingMessage($scheduled)
                    : '',
                'payout_due' => (string) $payout->payout_status === PayoutStatus::PENDING
                    && PayoutSchedule::isDue($scheduled),
                'paid_at' => $paidAt,
                'paid_at_display' => $paidTs
                    ? (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $paidTs)
                    : '',
                'payment_ref' => (string) ($payout->payment_ref ?? ''),
            ];
        }

        $commission = $this->commissionPayload($this->listings->find($fulfillmentId), $payout);
        $invoiceRows = $this->invoices->findForVariations([
            $fulfillmentId => (int) ($row->order_id ?? 0),
        ])[$fulfillmentId] ?? [];

        return array_merge($item, [
            'merchant_snapshot' => $snapshot,
            'has_merchant_snapshot' => MerchantSnapshot::hasPaymentFields($snapshot),
            'payout' => $payoutPayload,
            'commission' => $commission,
            'invoices' => InvoicePresenter::forStaff($invoiceRows),
            'invoice_summary' => InvoicePresenter::summary($invoiceRows),
            'invoice_has_error' => InvoicePresenter::hasError($invoiceRows),
            'payment_status_display' => $payoutPayload
                ? ($payoutPayload['payout_status_label'] . ' · ' . $payoutPayload['net_amount_display'])
                : __('Not created yet (after verification)', 'sutore-marketplace'),
            'actions' => $actions,
            'activity' => $this->activity->present((int) $item['variation_id']),
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
        // Manage Products includes pre-sale listings (pending / for sale / queue), not only the sale pipeline.
        $args['all_statuses'] = true;
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

        $variationIds = [];
        $productIds = [];
        $sizeTermIds = [];
        foreach ($rows as $row) {
            $variationId = (int) ($row->variation_id ?? 0);
            $variationIds[] = $variationId;
            if ($variationId > 0) {
                $productIds[] = $variationId;
            }
            $parentId = (int) ($row->parent_product_id ?? 0);
            if ($parentId > 0) {
                $productIds[] = $parentId;
            }
            $sizeTermIds[] = (int) ($row->size_term_id ?? 0);
        }
        $payoutMap = $this->payouts->findByVariationIds($variationIds);
        $orderIdByVariation = [];
        foreach ($rows as $row) {
            $variationId = (int) ($row->variation_id ?? 0);
            if ($variationId > 0) {
                $orderIdByVariation[$variationId] = (int) ($row->order_id ?? 0);
            }
        }
        $invoiceMap = $this->invoices->findForVariations($orderIdByVariation);
        $chrome = ProductListChrome::mapForIds($productIds);
        $sizeLabels = ProductSizeLookup::labelsForTermIds($sizeTermIds);
        $titles = [];
        foreach ($rows as $row) {
            $variationId = (int) ($row->variation_id ?? 0);
            $parentId = (int) ($row->parent_product_id ?? 0);
            $sizeTermId = (int) ($row->size_term_id ?? 0);
            $title = $chrome[$variationId]['title'] ?? '';
            if ($title === '' || $title === (string) $variationId) {
                $title = $chrome[$parentId]['title'] ?? '';
            }
            $sizeLabel = $sizeLabels[$sizeTermId] ?? '';
            if ($sizeLabel !== '' && $title !== '' && stripos($title, $sizeLabel) === false) {
                $title = trim($title . ' ' . $sizeLabel);
            }
            $title = trim(str_replace(['&#8211;', '–'], '', $title));
            if ($title === '' || $title === (string) $variationId) {
                $title = sprintf(
                    /* translators: %d: listing id */
                    __('Product #%d', 'sutore-marketplace'),
                    $variationId
                );
            }
            $titles[$variationId] = $title;
        }

        $out = [];
        foreach ($rows as $row) {
            $merchantId = (int) ($row->merchant_id ?? 0);
            $variationId = (int) ($row->variation_id ?? 0);
            $item = $this->presentRow(
                $row,
                $merchantNames[$merchantId] ?? ('#' . $merchantId),
                $titles[$variationId] ?? null,
                $chrome
            );
            $variationId = (int) ($item['variation_id'] ?? $item['id'] ?? 0);
            $payout = $payoutMap[$variationId] ?? null;
            $invoiceRows = $invoiceMap[$variationId] ?? [];
            $item['invoices'] = InvoicePresenter::forStaff($invoiceRows);
            $item['invoice_summary'] = InvoicePresenter::summary($invoiceRows);
            $item['invoice_has_error'] = InvoicePresenter::hasError($invoiceRows);
            if ($payout) {
                $status = (string) $payout->payout_status;
                $label = PayoutStatus::label($status);
                $netDisplay = MarketplacePricing::formatTl((float) $payout->net_amount);
                $scheduled = PayoutSchedule::normalizeDate($payout->scheduled_payout_date ?? '');
                $item['payout_status'] = $status;
                $item['payout_status_label'] = $label;
                $item['payout_net_amount_display'] = $netDisplay;
                $item['scheduled_payout_date'] = $scheduled;
                $item['scheduled_payout_date_display'] = PayoutSchedule::formatDateWithWeekday($scheduled);
                $item['payout_due'] = $status === PayoutStatus::PENDING && PayoutSchedule::isDue($scheduled);
                $item['payment_status_display'] = $label;
                if ($status === PayoutStatus::PENDING && $scheduled !== '') {
                    $item['payment_status_display'] = $item['payout_due']
                        ? $label . ' · ' . __('Due', 'sutore-marketplace')
                        : $label . ' · ' . $item['scheduled_payout_date_display'];
                }
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
        $canRemoveFromSale = !empty($caps['remove_from_sale']) && !empty($item['can_remove_from_sale']);
        $canApprove = !empty($caps['approve'])
            && $status === ListingStatus::PENDING
            && !empty($item['is_winner']);
        $canSendCampaignOffer = !empty($caps['send_campaign_offer'])
            && !empty($item['can_send_campaign_offer']);
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
            'mark_pre_order' => !empty($caps['mark_pre_order']) && !empty($item['has_order_link']),
            'detach' => $canDetach,
            'attach_to_order' => $canAttachToOrder,
            'mark_arrived' => !empty($caps['mark_arrived']),
            'mark_verified' => !empty($caps['mark_verified']),
            'mark_ready_to_ship' => !empty($caps['mark_ready_to_ship']),
            'mark_shipped_to_customer' => !empty($caps['mark_shipped_to_customer']),
            'mark_delivered' => !empty($caps['mark_delivered']),
            'mark_not_for_sale' => !empty($caps['mark_not_for_sale']),
            'remove_from_sale' => $canRemoveFromSale,
            'chargeback' => !empty($caps['chargeback']),
            'close_pre_order' => !empty($caps['close_pre_order']),
            'mark_payout' => $canMarkPayout,
            'adjust_commission' => $canMarkPayout,
            'mark_imported' => empty($item['is_imported']),
            'unmark_imported' => !empty($item['is_imported']),
            'put_on_sale' => $canPutOnSale,
            'approve' => $canApprove,
            'send_campaign_offer' => $canSendCampaignOffer,
            'delete' => $canDelete,
            'requires_staff_note' => ListingStatus::actionsRequiringStaffNote(),
        ];
    }

    /**
     * Public wrapper for bulk intersection checks (same flags as list/detail).
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function actionFlagsForRow(object $row, array $item, ?object $payout): array
    {
        return $this->buildActions($row, $item, $payout);
    }

    /**
     * @param array<int, array{title:string,code:string,thumbnail:string,permalink:string}>|null $chrome
     * @return array<string, mixed>
     */
    public function presentRow(
        object $row,
        ?string $merchantName = null,
        ?string $productTitle = null,
        ?array $chrome = null
    ): array {
        $variationId = (int) $row->variation_id;
        $orderId = (int) $row->order_id;
        $merchantId = (int) $row->merchant_id;
        $hasOrderLink = $this->fulfillmentService->isLinkedToOrder($row);

        // Facade row is the listings row — never re-find on list path.
        $parentId = isset($row->parent_product_id) ? (int) $row->parent_product_id : 0;
        $sizeTermId = isset($row->size_term_id) ? (int) $row->size_term_id : 0;
        $listing = null;
        if ($parentId > 0 || isset($row->listing_status) || isset($row->asking)) {
            $listing = \SutoreMarketplace\Modules\Listings\Domain\Listing::fromRow($row);
            $parentId = $parentId > 0 ? $parentId : (int) $listing->parentProductId;
            $sizeTermId = $sizeTermId > 0 ? $sizeTermId : (int) $listing->sizeTermId;
        } elseif ($variationId > 0) {
            // Detail path only: row may be a sparse facade without listing columns.
            $listing = $this->listings->find($variationId);
            $parentId = $listing ? (int) $listing->parentProductId : 0;
            $sizeTermId = $listing ? (int) $listing->sizeTermId : 0;
        }

        if ($productTitle !== null && $productTitle !== '') {
            $title = $productTitle;
        } else {
            $title = Notifications::productTitle($variationId, $variationId, $parentId, $sizeTermId);
            if ($title === '' || $title === (string) $variationId) {
                $title = sprintf(
                    /* translators: %d: listing id */
                    __('Product #%d', 'sutore-marketplace'),
                    $variationId
                );
            }
        }

        if ($merchantName === null) {
            $user = get_userdata($merchantId);
            $merchantName = $user ? $user->display_name : ('#' . $merchantId);
        }

        $orderEditUrl = '';
        if ($hasOrderLink && $orderId > 0 && StaffCapabilities::canManageOps()) {
            $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            $orderEditUrl = $hpos
                ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId)
                : admin_url('post.php?post=' . $orderId . '&action=edit');
        }

        $canPutOnSale = $listing ? $this->listingService->canPutOnSale($listing) : false;
        $canDelete = $listing ? $this->listingService->canDelete($listing) : false;
        $canRemoveFromSale = $listing ? $this->listingService->canRemoveFromSale($listing) : false;
        $isWinner = $listing
            ? (bool) $listing->isWinner
            : !empty($row->is_winner);
        $listingStatus = $listing ? (string) $listing->listingStatus : (string) ($row->listing_status ?? '');
        $asking = isset($row->asking) ? (float) $row->asking : ($listing ? (float) $listing->asking : 0.0);
        $campaignStatus = $listing
            ? (string) $listing->campaignStatus
            : (string) ($row->campaign_status ?? 'none');
        $canSendCampaignOffer = in_array($listingStatus, [ListingStatus::PUBLISH, ListingStatus::QUEUED], true)
            && $campaignStatus === 'none';
        $isPreOrder = $listing
            ? $listing->listingStatus === ListingStatus::PRE_ORDER
            : (string) ($row->listing_status ?? '') === ListingStatus::PRE_ORDER;
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

        $thumbnail = '';
        if (is_array($chrome)) {
            $thumbnail = (string) ($chrome[$parentId]['thumbnail'] ?? '');
            if ($thumbnail === '' && $variationId > 0) {
                $thumbnail = (string) ($chrome[$variationId]['thumbnail'] ?? '');
            }
        }
        if ($thumbnail === '') {
            $thumbnail = $parentId > 0 ? ProductThumbnail::url($parentId) : '';
            if ($thumbnail === '' && $variationId > 0) {
                $thumbnail = ProductThumbnail::url($variationId);
            }
        }

        $merchantShippedAt = (string) ($row->merchant_shipped_at ?? '');
        $sutoreShippedAt = (string) ($row->sutore_shipped_at ?? '');
        $deliveredAt = (string) ($row->delivered_at ?? '');
        $createdAt = $listing
            ? trim((string) ($listing->createdAt ?? ''))
            : trim((string) ($row->created_at ?? ''));

        return [
            'id' => $variationId,
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
            'fast_shipment' => $listing
                ? $listing->fastShipment
                : !empty($row->fast_shipment),
            'has_invoice' => $listing
                ? $listing->hasInvoice
                : !empty($row->has_invoice),
            'created_at' => $createdAt,
            'created_at_display' => self::formatDateTime($createdAt),
            'merchant_shipped_at' => $merchantShippedAt,
            'merchant_shipped_at_display' => self::formatDateTime($merchantShippedAt),
            'sutore_shipped_at' => $sutoreShippedAt,
            'sutore_shipped_at_display' => self::formatDateTime($sutoreShippedAt),
            'delivered_at' => $deliveredAt,
            'delivered_at_display' => self::formatDateTime($deliveredAt),
            'return_window_ends_at' => (string) ($row->return_window_ends_at ?? ''),
            'can_put_on_sale' => $canPutOnSale,
            'can_remove_from_sale' => $canRemoveFromSale,
            'can_delete' => $canDelete,
            'can_send_campaign_offer' => $canSendCampaignOffer,
            'is_winner' => $isWinner,
            'listing_status' => $listingStatus,
            'listing_status_label' => $listingStatus !== '' ? ListingStatus::label($listingStatus) : '—',
            'campaign_status' => $campaignStatus,
            'campaign_status_label' => ListingStatus::campaignLabel($campaignStatus),
            'is_pre_order' => $isPreOrder,
            'is_sourcing' => $isPreOrder,
            'is_imported' => $listing
                ? $listing->isImported
                : !empty($row->is_imported),
            'listing_commission_percent' => $listing?->commissionPercent,
            'sale_commission_percent' => $listing?->saleCommissionPercent,
            'notes' => $listing
                ? (string) ($listing->notes ?? '')
                : (string) ($row->notes ?? ''),
            'merchant_snapshot' => MerchantSnapshot::decode($row->merchant_snapshot ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commissionPayload(?\SutoreMarketplace\Modules\Listings\Domain\Listing $listing, ?object $payout): array
    {
        if (!$listing) {
            return [
                'listing_percent' => null,
                'sale_percent' => null,
                'live_percent' => null,
                'payout_percent' => $payout ? round((float) $payout->commission_percent, 2) : null,
                'source' => '',
                'level_percent' => null,
            ];
        }

        $resolved = (new \SutoreMarketplace\Modules\Merchants\Services\CommissionResolver())->forListing($listing);

        return [
            'listing_percent' => $listing->commissionPercent,
            'sale_percent' => $listing->saleCommissionPercent,
            'live_percent' => (float) $resolved['percent'],
            'payout_percent' => $payout ? round((float) $payout->commission_percent, 2) : null,
            'source' => (string) $resolved['source'],
            'level_percent' => (float) $resolved['level_percent'],
            'raises_level' => (bool) $resolved['raises_level'],
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
