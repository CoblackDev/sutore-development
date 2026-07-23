<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Repositories;

use SutoreMarketplace\Shared\Database\Schema;

final class TasksRepository
{
    public function definitionsTable(): string
    {
        return Schema::table('task_definitions');
    }

    public function progressTable(): string
    {
        return Schema::table('merchant_task_progress');
    }

    public function rewardsTable(): string
    {
        return Schema::table('merchant_rewards');
    }

    public function allDefinitions(bool $activeOnly = false): array
    {
        global $wpdb;
        $sql = 'SELECT * FROM ' . $this->definitionsTable();
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY id ASC';
        return $wpdb->get_results($sql) ?: [];
    }

    public function saveDefinition(array $data, ?int $id = null): int
    {
        global $wpdb;
        $now = current_time('mysql');
        if ($id) {
            $data['updated_at'] = $now;
            $wpdb->update($this->definitionsTable(), $data, ['id' => $id]);
            return $id;
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $wpdb->insert($this->definitionsTable(), $data);
        return (int) $wpdb->insert_id;
    }

    public function addReward(array $data): int
    {
        global $wpdb;
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($this->rewardsTable(), $data);
        return (int) $wpdb->insert_id;
    }

    public function rewardsForMerchant(int $merchantId, int $limit = 100): array
    {
        global $wpdb;
        $limit = max(1, min(500, $limit));

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->rewardsTable() . ' WHERE merchant_id = %d ORDER BY id DESC LIMIT %d',
            $merchantId,
            $limit
        )) ?: [];
    }
}
