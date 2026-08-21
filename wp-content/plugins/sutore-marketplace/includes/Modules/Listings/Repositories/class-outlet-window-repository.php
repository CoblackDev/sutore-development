<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Modules\Listings\Domain\OutletWindow;
use SutoreMarketplace\Modules\Listings\Domain\OutletWindowStatus;
use SutoreMarketplace\Shared\Database\Schema;

final class OutletWindowRepository
{
    public function table(): string
    {
        return Schema::table('outlet_windows');
    }

    public function find(int $id): ?OutletWindow
    {
        $map = $this->findByIds([$id]);

        return $map[$id] ?? null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, OutletWindow>
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
            $window = OutletWindow::fromRow($row);
            $out[$window->id] = $window;
        }

        return $out;
    }

    /** @return list<OutletWindow> */
    public function all(int $limit = 200): array
    {
        global $wpdb;
        $limit = max(1, min(500, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' ORDER BY id DESC LIMIT %d',
            $limit
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = OutletWindow::fromRow($row);
        }

        return $out;
    }

    /** @return list<OutletWindow> */
    public function findOpenForCatalog(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE status IN (%s, %s) ORDER BY starts_at ASC, id ASC',
            OutletWindowStatus::SCHEDULED,
            OutletWindowStatus::ACTIVE
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = OutletWindow::fromRow($row);
        }

        return $out;
    }

    /** @return list<OutletWindow> */
    public function findScheduledDue(int $limit = 50): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE status = %s AND starts_at <= %s
             ORDER BY id ASC LIMIT %d',
            OutletWindowStatus::SCHEDULED,
            $now,
            max(1, min(200, $limit))
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = OutletWindow::fromRow($row);
        }

        return $out;
    }

    /** @return list<OutletWindow> */
    public function findActivePastEnd(int $limit = 50): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE status = %s AND ends_at <= %s
             ORDER BY id ASC LIMIT %d',
            OutletWindowStatus::ACTIVE,
            $now,
            max(1, min(200, $limit))
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = OutletWindow::fromRow($row);
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

    public function update(int $id, array $data): bool
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');

        return false !== $wpdb->update($this->table(), $data, ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateIfStatus(int $id, string $expectedStatus, array $data): bool
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $updated = $wpdb->update(
            $this->table(),
            $data,
            [
                'id' => $id,
                'status' => $expectedStatus,
            ]
        );

        return is_int($updated) && $updated > 0;
    }
}
