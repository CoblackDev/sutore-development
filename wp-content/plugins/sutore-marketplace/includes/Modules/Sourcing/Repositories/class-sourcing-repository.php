<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Sourcing\Repositories;

use SutoreMarketplace\Shared\Database\Schema;

final class SourcingRepository
{
    public function table(): string
    {
        return Schema::table('sourcing_requests');
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id));
        return $row ?: null;
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
     * @param array{
     *   status?: string,
     *   status_in?: list<string>,
     *   accepted_merchant_id?: int,
     *   merchant_feed?: int,
     *   search?: string,
     *   orderby?: string,
     *   page?: int,
     *   per_page?: int
     * } $args
     * @return array{items: array, total: int}
     */
    public function query(array $args = []): array
    {
        global $wpdb;
        $table = $this->table();
        $where = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key((string) $args['status']);
        }

        if (!empty($args['status_in']) && is_array($args['status_in'])) {
            $statuses = array_values(array_filter(array_map('sanitize_key', $args['status_in'])));
            if ($statuses) {
                $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
                $where[] = "status IN ({$placeholders})";
                array_push($params, ...$statuses);
            }
        }

        if (!empty($args['accepted_merchant_id'])) {
            $where[] = 'accepted_merchant_id = %d';
            $params[] = (int) $args['accepted_merchant_id'];
        }

        // Merchant feed: open board plus this merchant's assigned requests.
        if (!empty($args['merchant_feed'])) {
            $merchantId = (int) $args['merchant_feed'];
            if (!empty($args['status'])) {
                $feedStatus = sanitize_key((string) $args['status']);
                if ($feedStatus === 'open') {
                    $where[] = 'status = %s';
                    $params[] = 'open';
                } else {
                    $where[] = 'accepted_merchant_id = %d AND status = %s';
                    $params[] = $merchantId;
                    $params[] = $feedStatus;
                }
            } else {
                $where[] = "(status = 'open' OR accepted_merchant_id = %d)";
                $params[] = $merchantId;
            }
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $whereSql = implode(' AND ', $where);
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", $params))
            : $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}"));

        $orderSql = $this->resolveOrderBy((string) ($args['orderby'] ?? 'default'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY {$orderSql} LIMIT %d OFFSET %d",
            array_merge($params, [$perPage, $offset])
        ));

        return ['items' => $rows ?: [], 'total' => $total];
    }

    private function resolveOrderBy(string $orderby): string
    {
        return match (sanitize_key($orderby)) {
            'created_asc' => 'id ASC',
            'created_desc' => 'id DESC',
            default => "FIELD(status,'open','accepted','fulfilled','cancelled'), id DESC",
        };
    }
}
