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

    /**
     * Active (pending/paid) payout for a listing, else the latest row.
     */
    public function findByVariationId(int $variationId): ?object
    {
        $map = $this->findByVariationIds([$variationId]);

        return $map[$variationId] ?? null;
    }

    public function findByVariationAndOrder(int $variationId, int $orderId): ?object
    {
        if ($variationId <= 0 || $orderId <= 0) {
            return null;
        }

        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE variation_id = %d AND order_id = %d LIMIT 1',
            $variationId,
            $orderId
        ));
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
            "SELECT * FROM {$this->table()} WHERE variation_id IN ({$placeholders}) ORDER BY id DESC",
            ...$variationIds
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $vid = (int) $row->variation_id;
            if (!isset($out[$vid])) {
                $out[$vid] = $row;
                continue;
            }
            $out[$vid] = self::preferPayoutRow($out[$vid], $row);
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

    private static function preferPayoutRow(object $a, object $b): object
    {
        $rank = static function (object $row): int {
            return match ((string) ($row->payout_status ?? '')) {
                PayoutStatus::PENDING => 3,
                PayoutStatus::PAID => 2,
                default => 1,
            };
        };

        $ra = $rank($a);
        $rb = $rank($b);
        if ($ra !== $rb) {
            return $ra > $rb ? $a : $b;
        }

        return (int) ($a->id ?? 0) >= (int) ($b->id ?? 0) ? $a : $b;
    }
}
