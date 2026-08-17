<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Modules\Listings\Domain\OutletOptin;
use SutoreMarketplace\Modules\Listings\Domain\OutletOptinStatus;
use SutoreMarketplace\Shared\Database\Schema;

final class OutletOptinRepository
{
    public function table(): string
    {
        return Schema::table('outlet_optins');
    }

    public function find(int $id): ?OutletOptin
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d',
            $id
        ));

        return $row ? OutletOptin::fromRow($row) : null;
    }

    public function findForItemMerchant(int $itemId, int $merchantId): ?OutletOptin
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE item_id = %d AND merchant_id = %d',
            $itemId,
            $merchantId
        ));

        return $row ? OutletOptin::fromRow($row) : null;
    }

    public function findLiveByVariationId(int $variationId): ?OutletOptin
    {
        if ($variationId <= 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE variation_id = %d AND status = %s',
            $variationId,
            OutletOptinStatus::LIVE
        ));

        return $row ? OutletOptin::fromRow($row) : null;
    }

    /** @return list<OutletOptin> */
    public function findPendingByWindowItems(array $itemIds): array
    {
        return $this->findByItemIdsAndStatus($itemIds, OutletOptinStatus::PENDING);
    }

    /** @return list<OutletOptin> */
    public function findLiveByWindowItems(array $itemIds): array
    {
        return $this->findByItemIdsAndStatus($itemIds, OutletOptinStatus::LIVE);
    }

    /**
     * @param list<int> $itemIds
     * @return list<OutletOptin>
     */
    public function findByItemIdsAndStatus(array $itemIds, string $status): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($itemIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($itemIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE item_id IN ({$placeholders}) AND status = %s
             ORDER BY id ASC",
            ...array_merge($itemIds, [$status])
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = OutletOptin::fromRow($row);
        }

        return $out;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, OutletOptin> keyed by item_id
     */
    public function findActiveForMerchantItems(int $merchantId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($itemIds === [] || $merchantId <= 0) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($itemIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE merchant_id = %d
               AND item_id IN ({$placeholders})
               AND status IN (%s, %s)",
            ...array_merge(
                [$merchantId],
                $itemIds,
                [OutletOptinStatus::PENDING, OutletOptinStatus::LIVE]
            )
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $optin = OutletOptin::fromRow($row);
            $out[$optin->itemId] = $optin;
        }

        return $out;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, array{pending: int, live: int}>
     */
    public function countsByItemIds(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $out = [];
        foreach ($itemIds as $id) {
            $out[$id] = ['pending' => 0, 'live' => 0];
        }
        if ($itemIds === []) {
            return $out;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($itemIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT item_id, status, COUNT(*) AS total FROM ' . $this->table() . "
             WHERE item_id IN ({$placeholders})
               AND status IN (%s, %s)
             GROUP BY item_id, status",
            ...array_merge($itemIds, [OutletOptinStatus::PENDING, OutletOptinStatus::LIVE])
        ));
        foreach ($rows ?: [] as $row) {
            $id = (int) $row->item_id;
            if (!isset($out[$id])) {
                $out[$id] = ['pending' => 0, 'live' => 0];
            }
            if ((string) $row->status === OutletOptinStatus::PENDING) {
                $out[$id]['pending'] = (int) $row->total;
            } elseif ((string) $row->status === OutletOptinStatus::LIVE) {
                $out[$id]['live'] = (int) $row->total;
            }
        }

        return $out;
    }

    public function countJoinableForMerchant(int $merchantId): int
    {
        if ($merchantId <= 0) {
            return 0;
        }

        global $wpdb;
        $items = Schema::table('outlet_items');
        $windows = Schema::table('outlet_windows');
        $optins = $this->table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items} i
             INNER JOIN {$windows} w ON w.id = i.window_id
             WHERE w.status IN (%s, %s)
               AND NOT EXISTS (
                   SELECT 1 FROM {$optins} o
                   WHERE o.item_id = i.id
                     AND o.merchant_id = %d
                     AND o.status IN (%s, %s)
               )",
            \SutoreMarketplace\Modules\Listings\Domain\OutletWindowStatus::SCHEDULED,
            \SutoreMarketplace\Modules\Listings\Domain\OutletWindowStatus::ACTIVE,
            $merchantId,
            OutletOptinStatus::PENDING,
            OutletOptinStatus::LIVE
        ));
    }

    public function create(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $wpdb->insert($this->table(), $data);

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');

        return false !== $wpdb->update($this->table(), $data, ['id' => $id]);
    }
}
