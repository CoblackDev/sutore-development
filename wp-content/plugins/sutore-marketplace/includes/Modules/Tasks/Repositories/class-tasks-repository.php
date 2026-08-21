<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Repositories;

use SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings;
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
        $table = $this->definitionsTable();

        if ($activeOnly) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE is_active = %d ORDER BY id ASC",
                    1
                )
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE 1 = %d ORDER BY id ASC",
                1
            )
        ) ?: [];
    }

    /** @return list<object> */
    public function cardsForMerchant(int $merchantId, ?string $periodKey = null): array
    {
        global $wpdb;
        $periodKey = $periodKey ?: BehaviorSettings::currentPeriodKey();
        $table = $this->definitionsTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE merchant_id = %d AND period_key = %s AND is_active = 1 AND is_template = 0
             ORDER BY card_family ASC, id ASC",
            $merchantId,
            $periodKey
        )) ?: [];
    }

    public function findByTaskKey(string $taskKey): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->definitionsTable() . ' WHERE task_key = %s LIMIT 1',
            $taskKey
        ));

        return $row ?: null;
    }

    public function findTemplateByKey(string $templateKey): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->definitionsTable() . ' WHERE template_key = %s AND is_template = 1 LIMIT 1',
            $templateKey
        ));

        return $row ?: null;
    }

    /** @return list<object> */
    public function findActiveCardsByTemplate(int $merchantId, string $templateKey, ?string $periodKey = null): array
    {
        global $wpdb;
        $periodKey = $periodKey ?: BehaviorSettings::currentPeriodKey();
        $table = $this->definitionsTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE merchant_id = %d AND template_key = %s AND period_key = %s AND is_active = 1 AND is_template = 0",
            $merchantId,
            $templateKey,
            $periodKey
        )) ?: [];
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

        $existing = $this->findByTaskKey((string) ($data['task_key'] ?? ''));
        if ($existing) {
            $data['updated_at'] = $now;
            $wpdb->update($this->definitionsTable(), $data, ['id' => (int) $existing->id]);

            return (int) $existing->id;
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $wpdb->insert($this->definitionsTable(), $data);

        return (int) $wpdb->insert_id;
    }

    public function deactivateLegacyTasks(): void
    {
        global $wpdb;
        $legacy = ['listings_created', 'listing_updates', 'first_sale', 'sales_count'];
        $placeholders = implode(',', array_fill(0, count($legacy), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->definitionsTable()} SET is_active = 0 WHERE task_key IN ({$placeholders})",
            ...$legacy
        ));
    }

    public function deactivateMerchantPeriodCards(int $merchantId, string $periodKey): void
    {
        global $wpdb;
        $wpdb->update(
            $this->definitionsTable(),
            ['is_active' => 0, 'updated_at' => current_time('mysql')],
            [
                'merchant_id' => $merchantId,
                'period_key' => $periodKey,
                'is_template' => 0,
            ]
        );
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
