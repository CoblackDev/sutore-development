<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Repositories;

final class TaskProgressRepository
{
    public function __construct(
        private readonly TasksRepository $tasks = new TasksRepository(),
    ) {
    }

    public function find(int $merchantId, int $taskId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->tasks->progressTable() . ' WHERE merchant_id = %d AND task_id = %d',
            $merchantId,
            $taskId
        ));

        return $row ?: null;
    }

    public function create(int $merchantId, int $taskId, int $progressCount): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($this->tasks->progressTable(), [
            'merchant_id' => $merchantId,
            'task_id' => $taskId,
            'progress_count' => $progressCount,
            'status' => 'in_progress',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(int $id, array $data): void
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($this->tasks->progressTable(), $data, ['id' => $id]);
    }

    /** @return object[] */
    public function progressWithDefinitionsForMerchant(int $merchantId): array
    {
        global $wpdb;
        $progressTable = $this->tasks->progressTable();
        $defsTable = $this->tasks->definitionsTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, d.task_key, d.title, d.target_count, d.reward_type, d.reward_value
             FROM {$progressTable} p
             INNER JOIN {$defsTable} d ON d.id = p.task_id
             WHERE p.merchant_id = %d
             ORDER BY p.id DESC",
            $merchantId
        )) ?: [];
    }
}
