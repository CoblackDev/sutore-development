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

    /**
     * Atomically bump progress while status is still in_progress.
     * Returns null if row missing / already completed / race lost.
     *
     * @return array{progress_count:int,status:string,completed_at:?string}|null
     */
    public function incrementIfInProgress(int $id, int $by, int $targetCount): ?array
    {
        global $wpdb;
        $table = $this->tasks->progressTable();
        $by = max(1, $by);
        $now = current_time('mysql');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET progress_count = progress_count + %d,
                 status = IF(progress_count >= %d, 'completed', 'in_progress'),
                 completed_at = IF(progress_count >= %d, %s, completed_at),
                 updated_at = %s
             WHERE id = %d AND status = 'in_progress'",
            $by,
            $targetCount,
            $targetCount,
            $now,
            $now,
            $id
        ));

        if (!is_int($updated) || $updated < 1) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT progress_count, status, completed_at FROM {$table} WHERE id = %d", $id));
        if (!$row) {
            return null;
        }

        return [
            'progress_count' => (int) $row->progress_count,
            'status' => (string) $row->status,
            'completed_at' => $row->completed_at !== null ? (string) $row->completed_at : null,
        ];
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
