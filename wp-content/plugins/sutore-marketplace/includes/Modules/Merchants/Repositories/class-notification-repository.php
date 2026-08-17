<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Repositories;

use SutoreMarketplace\Shared\Database\Schema;

final class NotificationRepository
{
    public function table(): string
    {
        return Schema::table('merchant_notifications');
    }

    public function insert(array $data): int
    {
        global $wpdb;
        $data['created_at'] = $data['created_at'] ?? current_time('mysql');
        $wpdb->insert($this->table(), $data);

        return (int) $wpdb->insert_id;
    }

    public function findForUser(int $userId, int $page = 1, int $perPage = 20, bool $unreadOnly = false): array
    {
        global $wpdb;
        $table = $this->table();
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = 'user_id = %d';
        $params = [$userId];
        if ($unreadOnly) {
            $where .= ' AND read_at IS NULL';
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where}",
            $params
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($params, [$perPage, $offset])
        ));

        return ['items' => $rows ?: [], 'total' => $total];
    }

    public function countUnread(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE user_id = %d AND read_at IS NULL',
            $userId
        ));
    }

    public function findOwned(int $id, int $userId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d AND user_id = %d LIMIT 1',
            $id,
            $userId
        ));
    }

    public function markRead(int $id, int $userId): bool
    {
        global $wpdb;
        $now = current_time('mysql');

        return false !== $wpdb->query($wpdb->prepare(
            'UPDATE ' . $this->table() . ' SET read_at = %s WHERE id = %d AND user_id = %d AND read_at IS NULL',
            $now,
            $id,
            $userId
        ));
    }

    public function markAllRead(int $userId): int
    {
        global $wpdb;
        $now = current_time('mysql');

        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . $this->table() . ' SET read_at = %s WHERE user_id = %d AND read_at IS NULL',
            $now,
            $userId
        ));
    }

    public function hasRecentDuplicate(int $userId, string $dedupeKey, int $withinSeconds = 3600): bool
    {
        if ($dedupeKey === '') {
            return false;
        }

        global $wpdb;
        $since = date('Y-m-d H:i:s', current_time('timestamp') - $withinSeconds);

        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $this->table() . ' WHERE user_id = %d AND dedupe_key = %s AND created_at >= %s LIMIT 1',
            $userId,
            $dedupeKey,
            $since
        ));

        return (bool) $found;
    }
}
