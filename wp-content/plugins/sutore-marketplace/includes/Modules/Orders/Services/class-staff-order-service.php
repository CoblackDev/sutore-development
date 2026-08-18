<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

/**
 * Staff WC order list / status updates (My Account Orders page).
 */
final class StaffOrderService
{
    public function __construct(
        private readonly FulfillmentRepository $fulfillments = new FulfillmentRepository(),
    ) {
    }

    /**
     * @param array{
     *   search?: string,
     *   status?: string,
     *   orderby?: string,
     *   page?: int,
     *   per_page?: int
     * } $args
     * @return array{orders: list<\WC_Order>, total: int, page: int, per_page: int}
     */
    public function query(array $args): array
    {
        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 30)));
        $search = sanitize_text_field((string) ($args['search'] ?? ''));
        $search = ltrim(trim($search), '#');
        $status = sanitize_key((string) ($args['status'] ?? ''));
        $orderby = sanitize_key((string) ($args['orderby'] ?? 'date_desc'));

        // Direct ID / order-number lookup — avoid include+paginate misses under HPOS.
        if ($search !== '' && preg_match('/^\d+$/', $search)) {
            $matched = $this->findByIdOrNumber((int) $search);
            if ($matched instanceof \WC_Order) {
                if ($status !== '' && $this->isValidStatus($status) && !$matched->has_status($status)) {
                    return [
                        'orders' => [],
                        'total' => 0,
                        'page' => 1,
                        'per_page' => $perPage,
                    ];
                }

                return [
                    'orders' => [$matched],
                    'total' => 1,
                    'page' => 1,
                    'per_page' => $perPage,
                ];
            }
        }

        $query = [
            'limit' => $perPage,
            'page' => $page,
            'paginate' => true,
            'return' => 'objects',
            'type' => 'shop_order',
        ];

        if ($status !== '' && $this->isValidStatus($status)) {
            $query['status'] = [$status];
        } else {
            $query['status'] = 'any';
        }

        if ($search !== '') {
            $query['s'] = $search;
        }

        switch ($orderby) {
            case 'date_asc':
                $query['orderby'] = 'date';
                $query['order'] = 'ASC';
                break;
            case 'id_asc':
                $query['orderby'] = 'ID';
                $query['order'] = 'ASC';
                break;
            case 'id_desc':
                $query['orderby'] = 'ID';
                $query['order'] = 'DESC';
                break;
            case 'total_asc':
                $query['orderby'] = 'total';
                $query['order'] = 'ASC';
                break;
            case 'total_desc':
                $query['orderby'] = 'total';
                $query['order'] = 'DESC';
                break;
            case 'deadline_asc':
                $query['meta_key'] = \SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta::DEADLINE_TIMESTAMP;
                $query['orderby'] = 'meta_value_num';
                $query['order'] = 'ASC';
                break;
            case 'deadline_desc':
                $query['meta_key'] = \SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta::DEADLINE_TIMESTAMP;
                $query['orderby'] = 'meta_value_num';
                $query['order'] = 'DESC';
                break;
            case 'date_desc':
            default:
                $query['orderby'] = 'date';
                $query['order'] = 'DESC';
                break;
        }

        $result = wc_get_orders($query);
        $orders = [];
        $total = 0;

        if (is_object($result) && isset($result->orders)) {
            foreach ($result->orders as $order) {
                if ($order instanceof \WC_Order) {
                    $orders[] = $order;
                }
            }
            $total = (int) ($result->total ?? 0);
        } elseif (is_array($result)) {
            foreach ($result as $order) {
                if ($order instanceof \WC_Order) {
                    $orders[] = $order;
                }
            }
            $total = count($orders);
        }

        return [
            'orders' => $orders,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function findByIdOrNumber(int $needle): ?\WC_Order
    {
        if ($needle <= 0) {
            return null;
        }

        $order = wc_get_order($needle);
        if ($order instanceof \WC_Order) {
            return $order;
        }

        $byNumber = wc_get_orders([
            'limit' => 1,
            'return' => 'objects',
            'type' => 'shop_order',
            'status' => 'any',
            'orderby' => 'ID',
            'order' => 'DESC',
            // WC core searches order number / id via `s` when numeric.
            's' => (string) $needle,
        ]);
        if (is_array($byNumber)) {
            foreach ($byNumber as $candidate) {
                if ($candidate instanceof \WC_Order) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function find(int $orderId): \WC_Order|\WP_Error
    {
        if ($orderId <= 0) {
            return new \WP_Error(
                'sutore_marketplace_order_missing',
                __('Order not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error(
                'sutore_marketplace_order_missing',
                __('Order not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        return $order;
    }

    /**
     * Distinct marketplace sellers per order (listings.merchant_id).
     *
     * @param list<int> $orderIds
     * @return array<int, int> order_id => seller_count
     */
    public function sellerCountsByOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($orderIds === []) {
            return [];
        }

        global $wpdb;
        $table = Schema::table('listings');
        $placeholders = implode(',', array_fill(0, count($orderIds), '%d'));
        /** @var list<object{order_id: string|int, seller_count: string|int}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id, COUNT(DISTINCT merchant_id) AS seller_count
             FROM {$table}
             WHERE order_id IN ({$placeholders})
               AND merchant_id > 0
             GROUP BY order_id",
            ...$orderIds
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->order_id] = (int) $row->seller_count;
        }

        return $out;
    }

    /**
     * @return list<object>
     */
    public function listingsForOrder(int $orderId): array
    {
        return $this->fulfillments->findByOrderId($orderId);
    }

    /**
     * Eligible marketplace listings to attach to a staff-managed order.
     *
     * @param array{search?:string,per_page?:int} $args
     * @return array{items:list<array<string,mixed>>,total:int}|\WP_Error
     */
    public function listAttachCandidates(int $orderId, array $args = []): array|\WP_Error
    {
        $order = $this->find($orderId);
        if ($order instanceof \WP_Error) {
            return $order;
        }
        if ($order->has_status(['cancelled', 'refunded', 'failed', 'trash'])) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('This order cannot receive new products in its current status.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $search = sanitize_text_field((string) ($args['search'] ?? ''));
        $perPage = min(50, max(1, (int) ($args['per_page'] ?? 30)));
        $queryArgs = [
            'search' => $search,
            'per_page' => $perPage,
            'page' => 1,
            'orderby' => 'asking',
            'order' => 'ASC',
        ];
        if ($search === '') {
            // Fast default: on-sale winners only; search broadens to other attachable statuses.
            $queryArgs['status'] = 'winner';
        }
        $result = (new ListingRepository())->query($queryArgs);

        $candidateIds = [];
        foreach ($result['items'] as $listing) {
            $candidateIds[] = (int) $listing->variationId;
        }
        $activeMap = $this->fulfillments->findActiveByVariationIds($candidateIds);

        $items = [];
        foreach ($result['items'] as $listing) {
            $variationId = (int) $listing->variationId;
            if ($variationId <= 0) {
                continue;
            }
            if (!ListingStatus::allowsManualOrderAttach((string) $listing->listingStatus)) {
                continue;
            }
            if ((int) $listing->orderId > 0 || isset($activeMap[$variationId])) {
                continue;
            }

            $user = get_userdata((int) $listing->merchantId);
            $merchantName = $user ? (string) $user->display_name : ('#' . (int) $listing->merchantId);
            $sizeLabel = ProductSizeLookup::labelForTerm((int) $listing->parentProductId, (int) $listing->sizeTermId);
            if ($sizeLabel === '') {
                $sizeLabel = ProductSizeLookup::labelForTermId((int) $listing->sizeTermId);
            }
            $productTitle = Notifications::productTitle(
                $variationId,
                (int) $listing->variationId,
                (int) $listing->parentProductId,
                (int) $listing->sizeTermId
            );
            $variationProduct = wc_get_product($variationId);
            if ($variationProduct instanceof \WC_Product && trim($variationProduct->get_name()) !== '') {
                $productTitle = $variationProduct->get_name();
            } else {
                $parentProduct = wc_get_product((int) $listing->parentProductId);
                if ($parentProduct instanceof \WC_Product && trim($parentProduct->get_name()) !== '') {
                    $productTitle = $parentProduct->get_name();
                    if ($sizeLabel !== '') {
                        $productTitle .= ' — ' . $sizeLabel;
                    }
                }
            }
            $thumbnail = ProductThumbnail::url($variationId);
            if ($thumbnail === '' && (int) $listing->parentProductId > 0) {
                $thumbnail = ProductThumbnail::url((int) $listing->parentProductId);
            }

            $customerPrice = MarketplacePricing::customerPrice($listing);
            $fees = MarketplacePricing::feeBreakdownForListing($listing);
            $items[] = [
                'id' => $variationId,
                'variation_id' => $variationId,
                'product_title' => $productTitle,
                'merchant_id' => (int) $listing->merchantId,
                'merchant_name' => $merchantName,
                'parent_product_id' => (int) $listing->parentProductId,
                'size_label' => $sizeLabel,
                'asking' => (float) $listing->asking,
                'asking_display' => MarketplacePricing::formatTl((float) $listing->asking),
                'customer_price' => $customerPrice,
                'customer_price_display' => MarketplacePricing::formatTl($customerPrice),
                'hizmet_fee' => (float) $fees['hizmet'],
                'guvence_fee' => (float) $fees['guvence'],
                'thumbnail' => $thumbnail,
                'listing_status' => (string) $listing->listingStatus,
                'listing_status_label' => ListingStatus::label((string) $listing->listingStatus),
            ];
        }

        return [
            'items' => $items,
            'total' => count($items),
        ];
    }

    /**
     * @return array{order: \WC_Order}|\WP_Error
     */
    public function attachListing(int $orderId, int $variationId, string $staffNote = ''): array|\WP_Error
    {
        $result = (new FulfillmentService())->attachToOrder($variationId, $orderId, [
            'staff_note' => $staffNote,
            'allow_open_orders' => true,
        ]);
        if ($result instanceof \WP_Error) {
            return $result;
        }

        $order = $this->find($orderId);
        if ($order instanceof \WP_Error) {
            return $order;
        }

        return ['order' => $order];
    }

    /**
     * Remove a non-marketplace WC line item from an order.
     *
     * @return array{order: \WC_Order}|\WP_Error
     */
    public function removeLineItem(int $orderId, int $orderItemId): array|\WP_Error
    {
        $order = $this->find($orderId);
        if ($order instanceof \WP_Error) {
            return $order;
        }

        $orderItemId = max(0, $orderItemId);
        if ($orderItemId <= 0) {
            return new \WP_Error(
                'sutore_marketplace_order_item_missing',
                __('Order item not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        foreach ($this->listingsForOrder($orderId) as $listing) {
            if ((int) ($listing->order_item_id ?? 0) !== $orderItemId) {
                continue;
            }

            $variationId = (int) ($listing->variation_id ?? $listing->id ?? 0);
            $listingStatus = (string) ($listing->fulfillment_status ?? $listing->listing_status ?? '');
            $product = $variationId > 0 ? wc_get_product($variationId) : null;

            // Prefer the detach workflow when the listing status still allows it.
            if (
                $product instanceof \WC_Product
                && ListingStatus::allowsDetach($listingStatus)
            ) {
                return new \WP_Error(
                    'sutore_marketplace_use_detach',
                    __('Use detach for marketplace products on this order.', 'sutore-marketplace'),
                    ['status' => 400]
                );
            }

            $note = $product instanceof \WC_Product
                ? __('Removed product from order by staff.', 'sutore-marketplace')
                : __('Removed missing product from order.', 'sutore-marketplace');

            $split = (new FulfillmentService())->splitFromOrder($variationId, false, 'split', $note);
            if (!($split instanceof \WP_Error)) {
                $fresh = wc_get_order($orderId);
                if (!$fresh instanceof \WC_Order) {
                    return new \WP_Error(
                        'sutore_marketplace_order_missing',
                        __('Order not found.', 'sutore-marketplace'),
                        ['status' => 404]
                    );
                }

                return ['order' => $fresh];
            }

            // Status no longer allows detach — force-remove the WC line and release the listing.
            $item = $order->get_item($orderItemId);
            if ($item instanceof \WC_Order_Item_Product) {
                $order->remove_item($orderItemId);
                $order->calculate_totals();
                if (method_exists($order, 'recalculate_coupons')) {
                    $order->recalculate_coupons();
                }
                $order->save();
            }

            (new ListingOrderBridge())->releaseFromOrder($variationId, ListingStatus::ORDER_DETACHED, [
                'reason' => 'staff_remove',
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'staff_note' => $note,
            ]);

            $this->fulfillments->update($variationId, [
                'fulfillment_status' => ListingStatus::ORDER_DETACHED,
                'order_item_id' => 0,
            ]);

            $fresh = wc_get_order($orderId);
            if (!$fresh instanceof \WC_Order) {
                return new \WP_Error(
                    'sutore_marketplace_order_missing',
                    __('Order not found.', 'sutore-marketplace'),
                    ['status' => 404]
                );
            }

            return ['order' => $fresh];
        }

        $item = $order->get_item($orderItemId);
        if (!$item instanceof \WC_Order_Item_Product) {
            return new \WP_Error(
                'sutore_marketplace_order_item_missing',
                __('Order item not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $order->remove_item($orderItemId);
        $order->calculate_totals();
        if (method_exists($order, 'recalculate_coupons')) {
            $order->recalculate_coupons();
        }
        $order->save();

        $fresh = wc_get_order($orderId);
        if (!$fresh instanceof \WC_Order) {
            return new \WP_Error(
                'sutore_marketplace_order_missing',
                __('Order not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        return ['order' => $fresh];
    }

    /**
     * @return array{order: \WC_Order}|\WP_Error
     */
    public function updateStatus(int $orderId, string $status): array|\WP_Error
    {
        $status = $this->normalizeStatus($status);
        if (!$this->isValidStatus($status)) {
            return new \WP_Error(
                'sutore_marketplace_invalid_order_status',
                __('Invalid order status.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $order = $this->find($orderId);
        if ($order instanceof \WP_Error) {
            return $order;
        }

        $order->update_status(
            $status,
            __('Order status updated by staff.', 'sutore-marketplace'),
            true
        );

        $fresh = wc_get_order($orderId);
        if (!$fresh instanceof \WC_Order) {
            return new \WP_Error(
                'sutore_marketplace_order_missing',
                __('Order not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        return ['order' => $fresh];
    }

    /**
     * @param list<int> $orderIds
     * @return array{updated: int, failed: list<array{id: int, message: string}>}|\WP_Error
     */
    public function bulkUpdateStatus(array $orderIds, string $status): array|\WP_Error
    {
        $status = $this->normalizeStatus($status);
        if (!$this->isValidStatus($status)) {
            return new \WP_Error(
                'sutore_marketplace_invalid_order_status',
                __('Invalid order status.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($ids === []) {
            return new \WP_Error(
                'sutore_marketplace_orders_missing',
                __('Select at least one order.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $updated = 0;
        $failed = [];
        foreach ($ids as $id) {
            $result = $this->updateStatus($id, $status);
            if ($result instanceof \WP_Error) {
                $failed[] = [
                    'id' => $id,
                    'message' => $result->get_error_message(),
                ];
                continue;
            }
            $updated++;
        }

        return [
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    /** @return array<string, string> status_key => label (no wc- prefix) */
    public function statusLabels(): array
    {
        $out = [];
        foreach (wc_get_order_statuses() as $key => $label) {
            $out[$this->normalizeStatus((string) $key)] = (string) $label;
        }

        return $out;
    }

    public function isValidStatus(string $status): bool
    {
        $status = $this->normalizeStatus($status);

        return isset($this->statusLabels()[$status]);
    }

    public function normalizeStatus(string $status): string
    {
        $status = sanitize_key($status);
        if (str_starts_with($status, 'wc-')) {
            $status = substr($status, 3);
        }

        return $status;
    }
}
