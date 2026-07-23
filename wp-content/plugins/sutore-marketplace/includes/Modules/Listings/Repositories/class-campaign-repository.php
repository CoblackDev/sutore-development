<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Modules\Listings\Domain\CampaignStatus;
use SutoreMarketplace\Shared\Database\Schema;

final class CampaignRepository
{
    public function table(): string
    {
        return Schema::table('campaigns');
    }

    public function find(int $id): ?object
    {
        $map = $this->findByIds([$id]);

        return $map[$id] ?? null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, object>
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
            $out[(int) $row->id] = $row;
        }

        return $out;
    }

    /** @return object[] */
    public function all(?string $status = null, int $limit = 200): array
    {
        global $wpdb;
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM ' . $this->table();
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = %s';
            $params[] = sanitize_key($status);
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
    }

    /** @return object[] */
    public function findActivePastEnd(int $limit = 50): array
    {
        global $wpdb;
        $now = current_time('mysql');

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE status = %s AND ends_at IS NOT NULL AND ends_at <= %s
             ORDER BY id ASC LIMIT %d',
            CampaignStatus::ACTIVE,
            $now,
            max(1, min(200, $limit))
        )) ?: [];
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
