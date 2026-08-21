<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Repositories;

use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Orders\Domain\StaffQueueFilter;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\TransitionResult;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentType;

/**
 * Facade over the listings table for sale/fulfillment logistics.
 *
 * The physical fulfillments table has been eliminated; every sale field now
 * lives on `wp_sutore_marketplace_listings`. To keep existing REST payloads,
 * services and JS consumers stable this repository still returns stdClass rows
 * shaped like the old fulfillment rows:
 *
 *  - `id` = variation_id
 *  - `fulfillment_status` = listing_status (copy, kept in sync automatically)
 *  - every logistics column keeps its original name
 */
final class FulfillmentRepository
{
    public function table(): string
    {
        return Schema::table('listings');
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE variation_id = %d',
            $id
        ));

        return $row ? $this->hydrateFulfillmentShape($row) : null;
    }

    public function findByVariationId(int $listingId): ?object
    {
        return $this->find($listingId);
    }

    public function findActiveByVariationId(int $listingId): ?object
    {
        $map = $this->findActiveByVariationIds([$listingId]);

        return $map[$listingId] ?? null;
    }

    /**
     * Latest active (sale-lifecycle) row per listing id.
     *
     * @param list<int> $listingIds
     * @return array<int, object>
     */
    public function findActiveByVariationIds(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter(array_map('intval', $listingIds))));
        if ($listingIds === []) {
            return [];
        }

        global $wpdb;
        $active = \SutoreMarketplace\Modules\Listings\Domain\ListingStatus::saleActive();
        if ($active === []) {
            return [];
        }

        $idPlaceholders = implode(',', array_fill(0, count($listingIds), '%d'));
        $statusPlaceholders = implode(',', array_fill(0, count($active), '%s'));
        $params = array_merge($listingIds, $active);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE variation_id IN ({$idPlaceholders})
               AND listing_status IN ({$statusPlaceholders})
             ORDER BY variation_id DESC",
            ...$params
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->variation_id] = $this->hydrateFulfillmentShape($row);
        }

        return $out;
    }

    /**
     * @param list<int> $listingIds
     * @return array<int, object>
     */
    public function findLatestByVariationIds(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter(array_map('intval', $listingIds))));
        if ($listingIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($listingIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE variation_id IN ({$placeholders})",
            ...$listingIds
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->variation_id] = $this->hydrateFulfillmentShape($row);
        }

        return $out;
    }

    public function findByOrderItem(int $orderId, int $orderItemId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE order_id = %d AND order_item_id = %d ORDER BY variation_id DESC LIMIT 1',
            $orderId,
            $orderItemId
        ));

        return $row ? $this->hydrateFulfillmentShape($row) : null;
    }

    /**
     * All listing sale rows currently linked to a WooCommerce order.
     *
     * @return list<object>
     */
    public function findByOrderId(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE order_id = %d ORDER BY variation_id ASC',
            $orderId
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->hydrateFulfillmentShape($row);
        }

        return $out;
    }

    /**
     * Historically inserted a new fulfillment row; now writes sale fields back
     * onto the listing row. Expects a `variation_id` key to
     * update). If callers pass `fulfillment_status`, it is mapped to
     * `listing_status` so the linear product status stays in sync.
     */
    public function insert(array $data): int
    {
        $variationId = (int) ($data['variation_id'] ?? 0);
        if ($variationId <= 0) {
            return 0;
        }

        unset($data['id'], $data['variation_id'], $data['created_at']);

        if (array_key_exists('fulfillment_status', $data)) {
            $data['listing_status'] = (string) $data['fulfillment_status'];
            unset($data['fulfillment_status']);
        }

        $this->update($variationId, $data);

        return $variationId;
    }

    public function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }

        // fulfillment_status is a virtual column exposed by hydrateFulfillmentShape
        // — persist it as the single source-of-truth listing_status instead.
        if (array_key_exists('fulfillment_status', $data)) {
            $data['listing_status'] = (string) $data['fulfillment_status'];
            unset($data['fulfillment_status']);
        }

        if ($data === []) {
            return true;
        }

        global $wpdb;
        $data['updated_at'] = current_time('mysql');

        return false !== $wpdb->update($this->table(), $data, ['variation_id' => $id]);
    }

    /**
     * Atomically apply $data only while listing_status (fulfillment_status) is $expectedStatus.
     *
     * @param array<string, mixed> $data
     */
    public function updateIfStatus(int $id, string $expectedStatus, array $data): bool
    {
        return $this->transition($id, $expectedStatus, $data)->isChanged();
    }

    /**
     * @param list<string>|string $expectedStatus
     * @param array<string, mixed> $data
     */
    public function transition(
        int $id,
        array|string $expectedStatus,
        array $data,
        string $operationId = ''
    ): TransitionResult {
        if ($id <= 0) {
            return TransitionResult::conflict($operationId !== '' ? $operationId : 'invalid');
        }

        if (array_key_exists('fulfillment_status', $data)) {
            $data['listing_status'] = (string) $data['fulfillment_status'];
            unset($data['fulfillment_status']);
        }

        return (new ListingRepository())->transition($id, $expectedStatus, $data, $operationId);
    }

    /** @return array{items: object[], total: int} */
    public function query(array $args): array
    {
        global $wpdb;
        $table = $this->table();
        $posts = $wpdb->posts;
        $users = $wpdb->users;
        $where = ['1=1'];
        $params = [];
        $join = '';

        if (!empty($args['merchant_id'])) {
            $where[] = 'l.merchant_id = %d';
            $params[] = (int) $args['merchant_id'];
        }

        $queue = sanitize_key((string) ($args['queue'] ?? ''));
        $status = sanitize_key((string) ($args['status'] ?? ''));
        $campaign = sanitize_key((string) ($args['campaign'] ?? ''));
        $isSourcing = sanitize_key((string) ($args['is_sourcing'] ?? ''));
        $shipmentType = sanitize_key((string) ($args['shipment_type'] ?? ''));
        $isImported = sanitize_key((string) ($args['is_imported'] ?? ''));
        $hasFlagFilter = $campaign !== ''
            || $isSourcing === 'yes'
            || $isSourcing === 'no'
            || $shipmentType !== ''
            || $isImported === 'yes'
            || $isImported === 'no';

        if ($queue !== '' && StaffQueueFilter::isValid($queue)) {
            $this->applyQueueFilter($queue, $where, $params);
        } elseif ($status !== '') {
            $where[] = 'l.listing_status = %s';
            $params[] = $status;
        } elseif (!$hasFlagFilter && empty($args['all_statuses'])) {
            // Default (merchant sold list / staff without all_statuses): sale pipeline only.
            // Flag filters and staff manage-products (all_statuses) widen to every status.
            $lifecycle = array_merge(
                ListingStatus::saleActive(),
                ListingStatus::saleTerminal()
            );
            if ($lifecycle !== []) {
                $placeholders = implode(',', array_fill(0, count($lifecycle), '%s'));
                $where[] = "l.listing_status IN ({$placeholders})";
                array_push($params, ...$lifecycle);
            }
        }

        if (!empty($args['order_id'])) {
            $where[] = 'l.order_id = %d';
            $params[] = (int) $args['order_id'];
        }
        if (!empty($args['variation_id'])) {
            $where[] = 'l.variation_id = %d';
            $params[] = (int) $args['variation_id'];
        }

        if ($campaign !== '' && in_array($campaign, ['none', 'offer', 'active'], true)) {
            $where[] = 'l.campaign_status = %s';
            $params[] = $campaign;
        }

        if ($isSourcing === 'yes') {
            $where[] = 'l.listing_status = "pre_order"';
        } elseif ($isSourcing === 'no') {
            $where[] = 'l.listing_status <> "pre_order"';
        }

        if ($isImported === 'yes') {
            $where[] = 'l.is_imported = 1';
        } elseif ($isImported === 'no') {
            $where[] = 'l.is_imported = 0';
        }

        if ($shipmentType === 'none') {
            $where[] = "(l.order_shipment_type IS NULL OR l.order_shipment_type = '')";
        } elseif ($shipmentType !== '' && ShipmentType::isValid($shipmentType)) {
            $where[] = 'l.order_shipment_type = %s';
            $params[] = $shipmentType;
        }

        $payoutStatus = sanitize_key((string) ($args['payout_status'] ?? ''));
        $payoutDue = !empty($args['payout_due']);
        $hasPayout = !empty($args['has_payout']);
        if ($payoutDue) {
            $payoutTable = Schema::table('merchant_payout_lines');
            $join .= " INNER JOIN {$payoutTable} pl ON pl.variation_id = l.variation_id ";
            $where[] = 'pl.payout_status = %s';
            $params[] = PayoutStatus::PENDING;
            $where[] = 'pl.scheduled_payout_date IS NOT NULL';
            $where[] = 'pl.scheduled_payout_date <= %s';
            $params[] = PayoutSchedule::today();
        } elseif ($payoutStatus !== '') {
            $payoutTable = Schema::table('merchant_payout_lines');
            if ($payoutStatus === 'none') {
                $join .= " LEFT JOIN {$payoutTable} pl ON pl.variation_id = l.variation_id ";
                $where[] = 'pl.id IS NULL';
            } elseif (PayoutStatus::isValid($payoutStatus)) {
                $join .= " INNER JOIN {$payoutTable} pl ON pl.variation_id = l.variation_id AND pl.payout_status = %s ";
                $params[] = $payoutStatus;
            }
        } elseif ($hasPayout) {
            $payoutTable = Schema::table('merchant_payout_lines');
            $join .= " INNER JOIN {$payoutTable} pl ON pl.variation_id = l.variation_id ";
        }

        $soldFrom = PayoutSchedule::normalizeDate($args['sold_from'] ?? '');
        if ($soldFrom !== '') {
            $where[] = 'l.sold_at IS NOT NULL';
            $where[] = 'l.sold_at >= %s';
            $params[] = $soldFrom . ' 00:00:00';
        }
        $soldTo = PayoutSchedule::normalizeDate($args['sold_to'] ?? '');
        if ($soldTo !== '') {
            $where[] = 'l.sold_at IS NOT NULL';
            $where[] = 'l.sold_at <= %s';
            $params[] = $soldTo . ' 23:59:59';
        }

        if (!empty($args['search'])) {
            $search = sanitize_text_field((string) $args['search']);
            $join .= " LEFT JOIN {$users} u ON u.ID = l.merchant_id ";
            if (preg_match('/^ID(\d+)$/i', $search, $m)) {
                $where[] = '(l.variation_id = %d OR l.order_id = %d OR l.merchant_id = %d)';
                $params[] = (int) $m[1];
                $params[] = (int) $m[1];
                $params[] = (int) $m[1];
            } else {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $parentIds = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$posts} WHERE post_type = 'product' AND post_title LIKE %s LIMIT 200",
                    $like
                ));
                $metaParents = $wpdb->get_col($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s LIMIT 200",
                    $like
                ));
                $ids = array_unique(array_map('intval', array_merge($parentIds ?: [], $metaParents ?: [])));
                $clauses = [];
                if ($ids !== []) {
                    $clauses[] = 'l.parent_product_id IN (' . implode(',', $ids) . ')';
                }
                if (ctype_digit($search)) {
                    $clauses[] = 'l.variation_id = %d';
                    $clauses[] = 'l.order_id = %d';
                    $params[] = (int) $search;
                    $params[] = (int) $search;
                }
                $clauses[] = 'u.display_name LIKE %s';
                $clauses[] = 'u.user_email LIKE %s';
                $clauses[] = 'u.user_login LIKE %s';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $where[] = '(' . implode(' OR ', $clauses) . ')';
            }
        }

        $orderbyKey = sanitize_key((string) ($args['orderby'] ?? 'id_desc'));
        $orderbyMap = [
            'id_desc' => 'l.variation_id DESC',
            'id_asc' => 'l.variation_id ASC',
            'asking_asc' => 'l.asking ASC, l.variation_id DESC',
            'asking_desc' => 'l.asking DESC, l.variation_id DESC',
            'deadline_asc' => 'l.order_shipment_deadline_at IS NULL ASC, l.order_shipment_deadline_at ASC, l.variation_id DESC',
            'deadline_desc' => 'l.order_shipment_deadline_at IS NULL ASC, l.order_shipment_deadline_at DESC, l.variation_id DESC',
            'sold_at_desc' => 'l.sold_at IS NULL ASC, l.sold_at DESC, l.variation_id DESC',
            'sold_at_asc' => 'l.sold_at IS NULL ASC, l.sold_at ASC, l.variation_id DESC',
            'status_asc' => 'l.listing_status ASC, l.variation_id DESC',
        ];
        $orderSql = $orderbyMap[$orderbyKey] ?? $orderbyMap['id_desc'];

        $whereSql = implode(' AND ', $where);
        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(!empty($args['full_row']) ? 2000 : 100, max(1, (int) ($args['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $fromSql = "{$table} l{$join}";
        $countSql = "SELECT COUNT(*) FROM {$fromSql} WHERE {$whereSql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($countSql, ...$params)) : $wpdb->get_var($countSql));

        $selectSql = !empty($args['full_row'])
            ? 'l.*'
            : ListingRepository::listColumns('l');
        $sql = "SELECT {$selectSql}
                FROM {$fromSql}
                WHERE {$whereSql}
                ORDER BY {$orderSql}
                LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$perPage, $offset])));
        $items = [];
        foreach ($rows ?: [] as $row) {
            $items[] = $this->hydrateFulfillmentShape($row);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param list<string> $where
     * @param list<mixed> $params
     */
    private function applyQueueFilter(string $queue, array &$where, array &$params): void
    {
        if ($queue === StaffQueueFilter::AWAITING_MERCHANT) {
            $statuses = StaffQueueFilter::awaitingMerchantStatuses();
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where[] = "l.listing_status IN ({$placeholders})";
            array_push($params, ...$statuses);

            return;
        }

        $statuses = StaffQueueFilter::inPipelineStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $where[] = "l.listing_status IN ({$placeholders})";
        array_push($params, ...$statuses);

        $within = match ($queue) {
            StaffQueueFilter::YELLOW_ZONE => StaffQueueFilter::YELLOW_WITHIN_SECONDS,
            StaffQueueFilter::RED_ZONE => StaffQueueFilter::RED_WITHIN_SECONDS,
            default => 0,
        };
        if ($within <= 0) {
            $where[] = '0=1';

            return;
        }

        $cutoff = wp_date('Y-m-d H:i:s', time() + $within, wp_timezone());
        $where[] = 'l.order_shipment_deadline_at IS NOT NULL';
        $where[] = 'l.order_shipment_deadline_at < %s';
        $params[] = $cutoff;
    }

    public function countActiveForMerchant(int $merchantId): int
    {
        global $wpdb;
        $active = ListingStatus::saleActive();
        if ($active === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($active), '%s'));
        $params = array_merge([$merchantId], $active);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE merchant_id = %d AND listing_status IN ({$placeholders})",
            ...$params
        ));
    }

    /**
     * Account deletion blockers. Chargeback and delivered+paid payout do not block.
     *
     * @return array{in_progress:int, pre_order:int, unpaid_delivered:int}
     */
    public function countAccountDeletionBlocks(int $merchantId): array
    {
        global $wpdb;
        $listings = $this->table();
        $inProgress = ListingStatus::saleInProgress();
        $inProgressCount = 0;
        if ($inProgress !== []) {
            $placeholders = implode(',', array_fill(0, count($inProgress), '%s'));
            $inProgressCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$listings} WHERE merchant_id = %d AND listing_status IN ({$placeholders})",
                ...array_merge([$merchantId], $inProgress)
            ));
        }

        $preOrderCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$listings} WHERE merchant_id = %d AND listing_status = %s",
            $merchantId,
            ListingStatus::PRE_ORDER
        ));

        $payouts = Schema::table('merchant_payout_lines');
        $unpaidDelivered = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$listings} l
             LEFT JOIN {$payouts} p ON p.variation_id = l.variation_id
             WHERE l.merchant_id = %d AND l.listing_status = %s
             AND (p.id IS NULL OR p.payout_status <> %s)",
            $merchantId,
            ListingStatus::DELIVERED_TO_CUSTOMER,
            PayoutStatus::PAID
        ));

        return [
            'in_progress' => $inProgressCount,
            'pre_order' => $preOrderCount,
            'unpaid_delivered' => $unpaidDelivered,
        ];
    }

    /** @return object[] */
    public function deadlineBatch(int $limit = 100): array
    {
        global $wpdb;
        $now = current_time('mysql');
        $awaiting = \SutoreMarketplace\Modules\Listings\Domain\ListingStatus::SOLD;
        $confirmed = \SutoreMarketplace\Modules\Listings\Domain\ListingStatus::CONFIRMED;
        $reminderHours = max(1, (int) Settings::get('cargo_reminder_hours', 24));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE (
               (listing_status = %s AND confirm_deadline_at IS NOT NULL AND confirm_deadline_at <= %s)
               OR (
                 listing_status = %s
                 AND cargo_deadline_at IS NOT NULL
                 AND (
                   (cargo_notice_sent = 0 AND cargo_deadline_at <= DATE_ADD(%s, INTERVAL %d HOUR))
                   OR cargo_deadline_at <= %s
                 )
               )
             )
             ORDER BY variation_id ASC
             LIMIT %d",
            $awaiting,
            $now,
            $confirmed,
            $now,
            $reminderHours,
            $now,
            $limit
        )) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrateFulfillmentShape($row);
        }

        return $out;
    }

    /**
     * Present a listing row in the historical fulfillment row shape:
     *  - id                 = variation_id
     *  - fulfillment_status mirrors listing_status
     *  - all logistics columns stay named as they were.
     *
     * We clone the row so callers can safely read $row->id / $row->variation_id
     * (both = variation ID) and $row->fulfillment_status while the underlying
     * table column stays as listing_status.
     */
    private function hydrateFulfillmentShape(object $row): object
    {
        $shaped = clone $row;
        $variationId = (int) ($row->variation_id ?? 0);
        $shaped->id = $variationId;
        $shaped->fulfillment_status = (string) ($row->listing_status ?? '');

        return $shaped;
    }
}
