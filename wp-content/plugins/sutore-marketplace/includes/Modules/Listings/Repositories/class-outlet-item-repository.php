<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Modules\Listings\Domain\OutletItem;
use SutoreMarketplace\Shared\Database\Schema;

final class OutletItemRepository
{
    public function table(): string
    {
        return Schema::table('outlet_items');
    }

    public function find(int $id): ?OutletItem
    {
        $map = $this->findByIds([$id]);

        return $map[$id] ?? null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, OutletItem>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id IN ({$placeholders})",
            ...$ids
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $item = OutletItem::fromRow($row);
            $out[$item->id] = $item;
        }

        return $out;
    }

    /** @return list<OutletItem> */
    public function findByWindow(int $windowId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE window_id = %d ORDER BY id ASC',
            $windowId
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = OutletItem::fromRow($row);
        }

        return $out;
    }

    /**
     * @param list<int> $windowIds
     * @return list<OutletItem>
     */
    public function findByWindowIds(array $windowIds): array
    {
        $windowIds = array_values(array_unique(array_filter(array_map('intval', $windowIds))));
        if ($windowIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($windowIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE window_id IN ({$placeholders}) ORDER BY window_id ASC, id ASC",
            ...$windowIds
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = OutletItem::fromRow($row);
        }

        return $out;
    }

    public function findDuplicate(int $windowId, int $parentProductId, int $sizeTermId): ?OutletItem
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE window_id = %d AND parent_product_id = %d AND size_term_id = %d',
            $windowId,
            $parentProductId,
            $sizeTermId
        ));

        return $row ? OutletItem::fromRow($row) : null;
    }

    /**
     * @param list<int> $windowIds
     * @return array<int, int>
     */
    public function countsByWindowIds(array $windowIds): array
    {
        $windowIds = array_values(array_unique(array_filter(array_map('intval', $windowIds))));
        $out = [];
        foreach ($windowIds as $id) {
            $out[$id] = 0;
        }
        if ($windowIds === []) {
            return $out;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($windowIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT window_id, COUNT(*) AS total FROM ' . $this->table() . "
             WHERE window_id IN ({$placeholders})
             GROUP BY window_id",
            ...$windowIds
        ));
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->window_id] = (int) $row->total;
        }

        return $out;
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

    public function delete(int $id): bool
    {
        global $wpdb;

        return false !== $wpdb->delete($this->table(), ['id' => $id]);
    }
}
