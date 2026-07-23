<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Shared\Database\Schema;

final class ListingEventsRepository
{
    public function table(): string
    {
        return Schema::table('listing_events');
    }

    public function log(
        string $eventType,
        array $payload = [],
        ?int $listingId = null,
        ?int $variationId = null,
        ?int $merchantId = null,
        string $visibility = 'admin_only'
    ): int {
        global $wpdb;

        $payload = $this->withActor($payload);

        $wpdb->insert($this->table(), [
            'listing_id' => $listingId,
            'variation_id' => $variationId,
            'merchant_id' => $merchantId,
            'event_type' => sanitize_key($eventType),
            'visibility' => sanitize_key($visibility),
            'payload' => wp_json_encode($payload),
            'created_at' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withActor(array $payload): array
    {
        if (array_key_exists('actor_user_id', $payload) || array_key_exists('actor_login', $payload)) {
            return $payload;
        }

        $userId = get_current_user_id();
        if ($userId > 0) {
            $user = get_userdata($userId);
            $payload['actor_user_id'] = $userId;
            $payload['actor_login'] = $user && $user->user_login !== '' ? $user->user_login : (string) $userId;

            return $payload;
        }

        $payload['actor_user_id'] = 0;
        $payload['actor_login'] = 'system';

        return $payload;
    }

    /** @return array{items: array, total: int} */
    public function query(array $args): array
    {
        global $wpdb;
        $table = $this->table();
        $where = ['1=1'];
        $params = [];

        if (!empty($args['listing_id']) && !empty($args['or_variation_id'])) {
            $where[] = '(listing_id = %d OR variation_id = %d)';
            $params[] = (int) $args['listing_id'];
            $params[] = (int) $args['or_variation_id'];
        } elseif (!empty($args['listing_id'])) {
            $where[] = 'listing_id = %d';
            $params[] = (int) $args['listing_id'];
        }

        if (!empty($args['variation_id']) && empty($args['or_variation_id'])) {
            $where[] = 'variation_id = %d';
            $params[] = (int) $args['variation_id'];
        }

        if (!empty($args['merchant_id'])) {
            $where[] = 'merchant_id = %d';
            $params[] = (int) $args['merchant_id'];
        }
        if (!empty($args['event_type'])) {
            $where[] = 'event_type = %s';
            $params[] = sanitize_key((string) $args['event_type']);
        }
        if (!empty($args['visibility'])) {
            $where[] = 'visibility = %s';
            $params[] = sanitize_key((string) $args['visibility']);
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($args['per_page'] ?? 40)));
        $offset = ($page - 1) * $perPage;
        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$whereSql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($countSql, $params)) : $wpdb->get_var($countSql));

        $sql = "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$perPage, $offset])));

        return ['items' => $rows ?: [], 'total' => $total];
    }

    /** @return list<object> */
    public function forListing(
        int $listingId,
        int $variationId = 0,
        int $limit = 100,
        ?string $visibility = null
    ): array
    {
        $args = [
            'listing_id' => $listingId,
            'per_page' => $limit,
            'page' => 1,
        ];
        if ($variationId > 0) {
            $args['or_variation_id'] = $variationId;
        }
        if ($visibility !== null) {
            $args['visibility'] = $visibility;
        }

        return $this->query($args)['items'];
    }

    public function hasEventForListing(int $listingId, string $eventType): bool
    {
        if ($listingId <= 0 || $eventType === '') {
            return false;
        }

        global $wpdb;
        $table = $this->table();

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE listing_id = %d AND event_type = %s",
            $listingId,
            sanitize_key($eventType)
        ));

        return (int) $count > 0;
    }

    public function merchantCanAccessListing(int $listingId, int $merchantId): bool
    {
        if ($listingId <= 0 || $merchantId <= 0) {
            return false;
        }

        global $wpdb;
        $table = $this->table();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE listing_id = %d AND merchant_id = %d LIMIT 1",
            $listingId,
            $merchantId
        ));

        return (int) $count > 0;
    }

    public function deleteForListing(int $listingId, int $variationId = 0): void
    {
        global $wpdb;
        $table = $this->table();

        if ($listingId > 0) {
            $wpdb->delete($table, ['listing_id' => $listingId]);
        }

        if ($variationId > 0) {
            $wpdb->delete($table, ['variation_id' => $variationId]);
        }
    }
}
