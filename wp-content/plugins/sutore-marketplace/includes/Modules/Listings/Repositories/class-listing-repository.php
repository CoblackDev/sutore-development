<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingConditionRank;
use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Shared\Database\Schema;

final class ListingRepository
{
    /**
     * Columns for paginated / feed list queries (omits longtext product_desc, merchant_snapshot, notes).
     * Detail paths keep SELECT *.
     */
    public static function listColumns(string $alias = 'l'): string
    {
        $cols = [
            'variation_id',
            'parent_product_id',
            'size_term_id',
            'merchant_id',
            'listing_status',
            'asking',
            'commission_percent',
            'sale_commission_percent',
            'condition_fingerprint',
            'campaign_status',
            'campaign_id',
            'campaign_cooled_until',
            'campaign_aging_step',
            'expire_at',
            'listing_duration_days',
            'sold_at',
            'order_id',
            'order_item_id',
            'order_shipment_type',
            'order_shipment_deadline_at',
            'fast_shipment',
            'has_invoice',
            'is_imported',
            'is_winner',
            'confirm_deadline_at',
            'seller_confirmed_at',
            'cargo_deadline_at',
            'merchant_shipped_at',
            'merchant_shipment_code',
            'sutore_shipment_code',
            'sutore_shipped_at',
            'confirm_notice_sent',
            'confirm_punished',
            'cargo_notice_sent',
            'cargo_expired_flag',
            'delivered_at',
            'return_window_ends_at',
            'created_at',
            'updated_at',
        ];

        if ($alias === '') {
            return implode(', ', $cols);
        }

        return implode(', ', array_map(static fn (string $col): string => $alias . '.' . $col, $cols));
    }

    /** @var array<int, Listing|false> */
    private static array $byVariationCache = [];

    /** @var array<int, Listing|false> */
    private static array $cheapestWinnerCache = [];

    public static function clearRequestCache(): void
    {
        self::$byVariationCache = [];
        self::$cheapestWinnerCache = [];
    }

    public function table(): string
    {
        return Schema::table('listings');
    }

    public function find(int $variationId): ?Listing
    {
        $map = $this->findByIds([$variationId]);

        return $map[$variationId] ?? null;
    }

    /**
     * @param list<int> $variationIds
     * @return array<int, Listing>
     */
    public function findByIds(array $variationIds): array
    {
        $variationIds = array_values(array_unique(array_filter(array_map('intval', $variationIds))));
        if ($variationIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($variationIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE variation_id IN ({$placeholders})",
            ...$variationIds
        ));

        $out = [];
        foreach ($this->hydrateMany($rows ?: []) as $listing) {
            $out[$listing->variationId] = $listing;
        }

        return $out;
    }

    public function findByVariationId(int $variationId): ?Listing
    {
        return $this->find($variationId);
    }

    /**
     * @param list<int> $variationIds
     * @return array<int, Listing>
     */
    public function findByVariationIds(array $variationIds): array
    {
        $variationIds = array_values(array_unique(array_filter(array_map('intval', $variationIds))));
        if ($variationIds === []) {
            return [];
        }

        $missing = [];
        $out = [];
        foreach ($variationIds as $vid) {
            if (array_key_exists($vid, self::$byVariationCache)) {
                $hit = self::$byVariationCache[$vid];
                if ($hit instanceof Listing) {
                    $out[$vid] = $hit;
                }
            } else {
                $missing[] = $vid;
            }
        }

        if ($missing === []) {
            return $out;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($missing), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE variation_id IN ({$placeholders})",
            ...$missing
        ));
        $found = [];
        foreach ($this->hydrateMany($rows ?: []) as $listing) {
            $found[$listing->variationId] = $listing;
            self::$byVariationCache[$listing->variationId] = $listing;
            $out[$listing->variationId] = $listing;
        }
        foreach ($missing as $vid) {
            if (!isset($found[$vid])) {
                self::$byVariationCache[$vid] = false;
            }
        }

        return $out;
    }

    /**
     * Lowest on-sale listing for parent+size across all condition slots.
     */
    public function getLowestOnSaleForSize(int $parentId, int $sizeTermId): ?Listing
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE parent_product_id = %d
               AND size_term_id = %d
               AND listing_status IN ("publish","queued")
               AND (is_winner = 1 OR listing_status = "publish")
             ORDER BY is_winner DESC, asking ASC, created_at ASC
             LIMIT 1',
            $parentId,
            $sizeTermId
        ));

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Competing listings for a parent+size (winner selection / queue).
     *
     * @return Listing[]
     */
    public function findCompetingForSize(int $parentId, int $sizeTermId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE parent_product_id = %d
               AND size_term_id = %d
               AND listing_status IN ("publish","queued","pending")
             ORDER BY asking ASC, created_at ASC',
            $parentId,
            $sizeTermId
        ));

        return $this->hydrateMany($rows ?: []);
    }

    /**
     * Best matching inventory listing per (parent, size) pair for a merchant's sourcing feed.
     *
     * Preference: active winner, else first active/queued/pending by asking ASC.
     *
     * @param list<array{0: int, 1: int}> $pairs [parent_product_id, size_term_id]
     * @return array<string, Listing> keyed by "{parentId}:{sizeTermId}"
     */
    public function findMatchingForPreOrder(int $merchantId, array $pairs): array
    {
        $merchantId = (int) $merchantId;
        if ($merchantId <= 0 || $pairs === []) {
            return [];
        }

        $normalized = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $parentId = (int) $pair[0];
            $sizeTermId = (int) $pair[1];
            if ($parentId <= 0 || $sizeTermId <= 0) {
                continue;
            }
            $normalized[$parentId . ':' . $sizeTermId] = [$parentId, $sizeTermId];
        }
        if ($normalized === []) {
            return [];
        }

        global $wpdb;
        $pairSql = [];
        $params = [$merchantId];
        foreach ($normalized as [$parentId, $sizeTermId]) {
            $pairSql[] = '(l.parent_product_id = %d AND l.size_term_id = %d)';
            $params[] = $parentId;
            $params[] = $sizeTermId;
        }

        $sql = 'SELECT ' . self::listColumns('l') . ' FROM ' . $this->table() . ' l
             WHERE l.merchant_id = %d
               AND l.listing_status IN ("publish","queued","pending")
               AND (' . implode(' OR ', $pairSql) . ')
             ORDER BY l.asking ASC, l.created_at ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        $candidates = $this->hydrateMany($rows ?: []);

        $picks = [];
        $winnerLocked = [];
        foreach ($candidates as $candidate) {
            $key = $candidate->parentProductId . ':' . $candidate->sizeTermId;
            if (!isset($normalized[$key]) || !empty($winnerLocked[$key])) {
                continue;
            }
            if ($candidate->isWinner && $candidate->listingStatus === 'publish') {
                $picks[$key] = $candidate;
                $winnerLocked[$key] = true;
                continue;
            }
            if (!isset($picks[$key])) {
                $picks[$key] = $candidate;
            }
        }

        return $picks;
    }

    public function getWinnerForSize(int $parentId, int $sizeTermId): ?Listing
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE parent_product_id = %d
               AND size_term_id = %d
               AND is_winner = 1
               AND listing_status IN ("publish","queued")
             LIMIT 1',
            $parentId,
            $sizeTermId
        ));

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Listings that can receive a system aging offer (status + cooldown + step).
     *
     * @return list<Listing>
     */
    public function findAgingCandidates(int $limit = 80): array
    {
        global $wpdb;
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::listColumns('') . ' FROM ' . $this->table() . '
             WHERE listing_status IN (%s, %s)
               AND campaign_status = %s
               AND (campaign_cooled_until IS NULL OR campaign_cooled_until <= %s)
               AND campaign_aging_step < %d
             ORDER BY created_at ASC
             LIMIT %d',
            ListingStatus::PUBLISH,
            ListingStatus::QUEUED,
            'none',
            $now,
            2,
            max(1, min(200, $limit))
        ));

        return $this->hydrateMany($rows ?: []);
    }

    /** Cheapest current winner for a parent (shop "from" price). */
    public function getCheapestWinnerForParent(int $parentId): ?Listing
    {
        if ($parentId <= 0) {
            return null;
        }
        if (array_key_exists($parentId, self::$cheapestWinnerCache)) {
            $hit = self::$cheapestWinnerCache[$parentId];

            return $hit instanceof Listing ? $hit : null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE parent_product_id = %d
               AND is_winner = 1
               AND listing_status IN ("publish","queued")
             ORDER BY asking ASC, created_at ASC
             LIMIT 1',
            $parentId
        ));
        $listing = $row ? $this->hydrate($row) : null;
        self::$cheapestWinnerCache[$parentId] = $listing ?? false;

        return $listing;
    }

    public function insert(array $data): int
    {
        global $wpdb;
        if (!$this->applyAskingGuard($data)) {
            return 0;
        }

        $now = current_time('mysql');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $wpdb->insert($this->table(), $data);
        self::clearRequestCache();

        return (int) ($data['variation_id'] ?? 0);
    }

    public function update(int $variationId, array $data): bool
    {
        global $wpdb;
        if (!$this->applyAskingGuard($data)) {
            return false;
        }

        $data['updated_at'] = current_time('mysql');
        $ok = false !== $wpdb->update($this->table(), $data, ['variation_id' => $variationId]);
        if ($ok) {
            self::clearRequestCache();
        }

        return $ok;
    }

    public function delete(int $variationId): bool
    {
        global $wpdb;
        $ok = false !== $wpdb->delete($this->table(), ['variation_id' => $variationId]);
        if ($ok) {
            self::clearRequestCache();
        }

        return $ok;
    }

    /** @return array{items: Listing[], total: int} */
    public function query(array $args): array
    {
        global $wpdb;
        $table = $this->table();
        $posts = $wpdb->posts;
        $where = ['1=1'];
        $params = [];
        $join = '';

        if (!empty($args['merchant_id'])) {
            $where[] = 'l.merchant_id = %d';
            $params[] = (int) $args['merchant_id'];
        }

        if (!empty($args['status'])) {
            if ($args['status'] === 'winner') {
                $where[] = 'l.is_winner = 1 AND l.listing_status = "publish"';
            } elseif ($args['status'] === 'queued') {
                $where[] = 'l.is_winner = 0 AND l.listing_status IN ("publish","queued","pending")';
            } elseif ($args['status'] === 'not_sale') {
                $where[] = 'l.listing_status = "not_sale"';
            } elseif ($args['status'] === 'order_detached') {
                $where[] = 'l.listing_status = "order_detached"';
            } elseif ($args['status'] === 'in_sale') {
                $saleActive = \SutoreMarketplace\Modules\Listings\Domain\ListingStatus::saleActive();
                $placeholders = implode(',', array_fill(0, count($saleActive), '%s'));
                $where[] = "l.listing_status IN ({$placeholders})";
                array_push($params, ...$saleActive);
            } elseif ($args['status'] === 'sale_ended') {
                $saleTerminal = \SutoreMarketplace\Modules\Listings\Domain\ListingStatus::saleTerminal();
                $placeholders = implode(',', array_fill(0, count($saleTerminal), '%s'));
                $where[] = "l.listing_status IN ({$placeholders})";
                array_push($params, ...$saleTerminal);
            } else {
                $where[] = 'l.listing_status = %s';
                $params[] = sanitize_key((string) $args['status']);
            }
        }

        if (!empty($args['campaign'])) {
            $where[] = 'l.campaign_status = %s';
            $params[] = sanitize_key((string) $args['campaign']);
        }

        if (($args['is_pre_order'] ?? '') === 'yes') {
            $where[] = 'l.listing_status = "pre_order"';
        } elseif (($args['is_pre_order'] ?? '') === 'no') {
            $where[] = 'l.listing_status <> "pre_order"';
        }

        if (($args['is_imported'] ?? '') === 'yes') {
            $where[] = 'l.is_imported = 1';
        } elseif (($args['is_imported'] ?? '') === 'no') {
            $where[] = 'l.is_imported = 0';
        }

        if (!empty($args['parent_product_id'])) {
            $where[] = 'l.parent_product_id = %d';
            $params[] = (int) $args['parent_product_id'];
        }

        if (!empty($args['size_term_id'])) {
            $where[] = 'l.size_term_id = %d';
            $params[] = (int) $args['size_term_id'];
        }

        if (!empty($args['fast_shipment'])) {
            $where[] = 'l.fast_shipment = 1';
        }

        if (!empty($args['condition_key'])) {
            $condKey = sanitize_key((string) $args['condition_key']);
            if ($condKey === 'fast_shipment') {
                $where[] = 'l.fast_shipment = 1';
            } elseif ($condKey === 'has_invoice') {
                $where[] = 'l.has_invoice = 1';
            } else {
                $condTable = Schema::table('listing_conditions');
                $join .= " INNER JOIN {$condTable} lc_filter ON lc_filter.variation_id = l.variation_id ";
                $where[] = 'lc_filter.condition_key = %s AND lc_filter.condition_value = 1';
                $params[] = $condKey;
            }
        }

        if (!empty($args['product_cat'])) {
            $term = get_term_by('slug', sanitize_title((string) $args['product_cat']), 'product_cat');
            if ($term && !is_wp_error($term)) {
                $objectIds = get_objects_in_term((int) $term->term_id, 'product_cat');
                $objectIds = array_map('intval', is_array($objectIds) ? $objectIds : []);
                if ($objectIds) {
                    $where[] = 'l.parent_product_id IN (' . implode(',', $objectIds) . ')';
                } else {
                    $where[] = '1=0';
                }
            }
        }

        if (!empty($args['search'])) {
            $search = sanitize_text_field((string) $args['search']);
            if (preg_match('/^ID(\d+)$/i', $search, $m)) {
                $where[] = '(l.variation_id = %d OR l.order_id = %d)';
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
                if ($ids) {
                    $clauses[] = 'l.parent_product_id IN (' . implode(',', $ids) . ')';
                }
                if (ctype_digit($search)) {
                    $clauses[] = 'l.variation_id = %d';
                    $clauses[] = 'l.order_id = %d';
                    $params[] = (int) $search;
                    $params[] = (int) $search;
                }
                $where[] = $clauses !== [] ? '(' . implode(' OR ', $clauses) . ')' : '1=0';
            }
        }

        $orderbyKey = (string) ($args['orderby'] ?? 'created_at');
        $order = strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $needsTitleJoin = in_array($orderbyKey, ['title', 'parent_title'], true);
        if ($needsTitleJoin) {
            $join = " LEFT JOIN {$posts} p ON p.ID = l.parent_product_id ";
        }

        $orderbyMap = [
            'created_at' => 'l.created_at',
            'asking' => 'l.asking',
            'expire_at' => 'l.expire_at',
            'listing_status' => 'l.listing_status',
            'id' => 'l.variation_id',
            'fast_shipment' => 'l.fast_shipment',
            'merchant_id' => 'l.merchant_id',
            'title' => 'p.post_title',
            'parent_title' => 'p.post_title',
        ];
        $orderby = $orderbyMap[$orderbyKey] ?? 'l.created_at';

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM {$table} l {$join} WHERE {$whereSql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($countSql, $params)) : $wpdb->get_var($countSql));

        $sql = "SELECT " . self::listColumns('l') . " FROM {$table} l {$join} WHERE {$whereSql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $queryParams = array_merge($params, [$perPage, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $queryParams));
        $items = $this->hydrateMany($rows ?: []);

        if ($orderbyKey === 'queue_position') {
            usort($items, static function (Listing $a, Listing $b) use ($order): int {
                $cmp = $a->asking <=> $b->asking;
                return $order === 'ASC' ? $cmp : -$cmp;
            });
        }

        return ['items' => $items, 'total' => $total];
    }

    /** @return Listing[] */
    public function expiredBatch(int $limit = 50): array
    {
        global $wpdb;
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE expire_at IS NOT NULL
               AND expire_at < %s
               AND listing_status IN ("pending","queued","publish")
             ORDER BY expire_at ASC
             LIMIT %d',
            $now,
            $limit
        ));

        return $this->hydrateMany($rows ?: []);
    }

    /** @return list<string> */
    public static function defectConditionKeys(): array
    {
        return ListingConditionRank::DEFECT_KEYS;
    }

    /**
     * @param array<string|int, mixed> $conditions Defect flags only (no shipping keys).
     */
    public static function fingerprint(array $conditions, bool $fastShipment = false, bool $hasInvoice = false): string
    {
        $normalized = [];
        foreach (self::defectConditionsOnly($conditions) as $key => $value) {
            if (!empty($value)) {
                $normalized[sanitize_key((string) $key)] = 1;
            }
        }
        if ($fastShipment) {
            $normalized['fast_shipment'] = 1;
        }
        if ($hasInvoice) {
            $normalized['has_invoice'] = 1;
        }
        ksort($normalized);

        return md5(wp_json_encode($normalized) ?: '');
    }

    /**
     * @param array<string|int, mixed> $conditions
     * @return array<string, bool>
     */
    public static function defectConditionsOnly(array $conditions): array
    {
        $allowed = array_fill_keys(self::defectConditionKeys(), true);
        $out = [];
        foreach ($conditions as $key => $value) {
            if (is_int($key)) {
                $key = (string) $value;
                $value = true;
            }
            $key = sanitize_key((string) $key);
            if (isset($allowed[$key]) && !empty($value)) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    public static function resolveShippingFlags(array $input, ?bool $fastShipment = null, ?bool $hasInvoice = null): array
    {
        $raw = $input['conditions'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $fast = $fastShipment
            ?? !empty($input['fast_shipment'])
            || !empty($raw['fast_shipment']);
        $invoice = $hasInvoice
            ?? !empty($input['has_invoice'])
            || !empty($raw['has_invoice']);

        return [(bool) $fast, (bool) $invoice];
    }

    private function hydrate(object $row): Listing
    {
        $conditions = (new ListingConditionsRepository())->forListing((int) $row->variation_id);

        return Listing::fromRow($row, $conditions);
    }

    /**
     * @param list<object> $rows
     * @return list<Listing>
     */
    private function hydrateMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $variationIds = array_map(static fn (object $row): int => (int) $row->variation_id, $rows);
        $conditionsByVariation = (new ListingConditionsRepository())->forListings($variationIds);
        $items = [];
        foreach ($rows as $row) {
            $variationId = (int) $row->variation_id;
            $items[] = Listing::fromRow($row, $conditionsByVariation[$variationId] ?? []);
        }

        return $items;
    }

    /** @param array<string, mixed> $data */
    private function applyAskingGuard(array &$data): bool
    {
        if (!array_key_exists('asking', $data)) {
            return true;
        }

        $valid = ListingPriceValidator::requireValidAsking($data['asking']);
        if (is_wp_error($valid)) {
            return false;
        }

        $data['asking'] = $valid;

        return true;
    }

    /**
     * Distinct variation-axis term ids used on this merchant's listings (any taxonomy).
     *
     * @return list<int>
     */
    public function distinctSizeTermIdsForMerchant(int $merchantId): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        global $wpdb;
        $table = Schema::table('listings');
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT size_term_id FROM {$table}
             WHERE merchant_id = %d AND size_term_id > 0
             ORDER BY size_term_id ASC",
            $merchantId
        ));

        return array_values(array_filter(array_map('intval', $rows ?: [])));
    }
}
