<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Repositories;

use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Shared\Database\Schema;

final class PayoutLineRepository
{
    public function table(): string
    {
        return Schema::table('merchant_payout_lines');
    }

    public function find(int $id): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id));
    }

    public function findByVariationId(int $variationId): ?object
    {
        $map = $this->findByVariationIds([$variationId]);

        return $map[$variationId] ?? null;
    }

    /**
     * @param list<int> $variationIds
     * @return array<int, object>
     */
    public function findByVariationIds(array $variationIds): array
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
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->variation_id] = $row;
        }

        return $out;
    }

    public function insert(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $wpdb->insert($this->table(), $data);

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');

        return false !== $wpdb->update($this->table(), $data, ['id' => $id]);
    }

    /** @return array{paid_total:float,pending_total:float,paid_count:int,pending_count:int} */
    public function summaryForMerchant(int $merchantId): array
    {
        $all = $this->summariesForMerchants([$merchantId]);

        return $all[$merchantId] ?? [
            'paid_total' => 0.0,
            'pending_total' => 0.0,
            'paid_count' => 0,
            'pending_count' => 0,
        ];
    }

    /**
     * @param list<int> $merchantIds
     * @return array<int, array{paid_total:float,pending_total:float,paid_count:int,pending_count:int}>
     */
    public function summariesForMerchants(array $merchantIds): array
    {
        $merchantIds = array_values(array_filter(array_map('intval', $merchantIds)));
        if ($merchantIds === []) {
            return [];
        }

        global $wpdb;
        $table = $this->table();
        $placeholders = implode(',', array_fill(0, count($merchantIds), '%d'));
        $sql = "SELECT merchant_id,
                COALESCE(SUM(CASE WHEN payout_status = %s THEN net_amount ELSE 0 END), 0) AS paid_total,
                COALESCE(SUM(CASE WHEN payout_status = %s THEN net_amount ELSE 0 END), 0) AS pending_total,
                COALESCE(SUM(CASE WHEN payout_status = %s THEN 1 ELSE 0 END), 0) AS paid_count,
                COALESCE(SUM(CASE WHEN payout_status = %s THEN 1 ELSE 0 END), 0) AS pending_count
             FROM {$table}
             WHERE merchant_id IN ({$placeholders})
             GROUP BY merchant_id";

        $params = array_merge(
            [PayoutStatus::PAID, PayoutStatus::PENDING, PayoutStatus::PAID, PayoutStatus::PENDING],
            $merchantIds
        );
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['merchant_id'];
            $out[$id] = [
                'paid_total' => round((float) ($row['paid_total'] ?? 0), 2),
                'pending_total' => round((float) ($row['pending_total'] ?? 0), 2),
                'paid_count' => (int) ($row['paid_count'] ?? 0),
                'pending_count' => (int) ($row['pending_count'] ?? 0),
            ];
        }

        return $out;
    }

    /** @return list<object> */
    public function recentForMerchant(int $merchantId, int $limit = 20): array
    {
        global $wpdb;
        $limit = max(1, min(50, $limit));

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE merchant_id = %d ORDER BY id DESC LIMIT %d',
            $merchantId,
            $limit
        )) ?: [];
    }
}
