<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Repositories;

use SutoreMarketplace\Shared\Database\Schema;

final class RestrictionsRepository
{
    public const KEYS = [
        'listing_create_ban',
        'price_update_ban',
        'disabled_account',
    ];

    public function table(): string
    {
        return Schema::table('merchant_restrictions');
    }

    public function hasActive(int $merchantId, string $key): bool
    {
        global $wpdb;
        $now = current_time('mysql');
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $this->table() . '
             WHERE merchant_id = %d
               AND restriction_key = %s
               AND is_active = 1
               AND (expires_at IS NULL OR expires_at > %s)
             LIMIT 1',
            $merchantId,
            sanitize_key($key),
            $now
        ));

        return !empty($id);
    }

    /** Whether a stored row is currently in effect. */
    public static function rowIsCurrentlyActive(object $row, ?string $now = null): bool
    {
        if ((int) ($row->is_active ?? 0) !== 1) {
            return false;
        }

        $expiresAt = isset($row->expires_at) ? trim((string) $row->expires_at) : '';
        if ($expiresAt === '') {
            return true;
        }

        $now = $now ?? current_time('mysql');

        return $expiresAt > $now;
    }

    public function find(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id));
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

    public function deactivate(int $id): bool
    {
        global $wpdb;
        return false !== $wpdb->update($this->table(), [
            'is_active' => 0,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    /** @return array{items: array, total: int} */
    public function query(array $args = []): array
    {
        global $wpdb;
        $table = $this->table();
        $where = ['1=1'];
        $params = [];

        if (!empty($args['merchant_id'])) {
            $where[] = 'merchant_id = %d';
            $params[] = (int) $args['merchant_id'];
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['per_page'] ?? 40)));
        $offset = ($page - 1) * $perPage;
        $whereSql = implode(' AND ', $where);
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", $params))
            : $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}"));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($params, [$perPage, $offset])
        ));

        return ['items' => $rows ?: [], 'total' => $total];
    }
}
