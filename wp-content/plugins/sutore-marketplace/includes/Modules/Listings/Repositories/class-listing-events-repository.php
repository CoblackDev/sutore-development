<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;
use SutoreMarketplace\Modules\Listings\Support\ListingEventPayload;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
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
        ?int $variationId = null,
        ?int $merchantId = null,
        string $visibility = 'admin_only',
        ?int $reversesEventId = null
    ): int {
        global $wpdb;

        $payload = $this->withActor($payload);

        $wpdb->insert($this->table(), [
            'variation_id' => $variationId,
            'merchant_id' => $merchantId,
            'event_type' => sanitize_key($eventType),
            'visibility' => sanitize_key($visibility),
            'payload' => wp_json_encode($payload),
            'reverses_event_id' => $reversesEventId,
            'created_at' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function logForListing(
        string $eventType,
        ?Listing $listing,
        array $payload = [],
        ?int $merchantId = null,
        string $visibility = 'admin_only',
        ?object $row = null
    ): int {
        $merchantId = $merchantId ?? ($listing ? (int) $listing->merchantId : null);
        $variationId = $listing ? (int) $listing->variationId : null;
        $payload = ListingEventPayload::withAsking($payload, $listing, $row);

        return $this->log($eventType, $payload, $variationId, $merchantId, $visibility);
    }

    public function logReversal(int $targetEventId, string $note = ''): int
    {
        global $wpdb;
        $target = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d',
            $targetEventId
        ));
        if (!$target) {
            return 0;
        }

        return $this->log(
            ListingEventType::EVENT_REVERSAL,
            [
                'reverses_event_id' => $targetEventId,
                'reversed_event_type' => (string) $target->event_type,
                'note' => $note,
                'asking' => self::payloadAsking($target),
            ],
            $target->variation_id ? (int) $target->variation_id : null,
            $target->merchant_id ? (int) $target->merchant_id : null,
            'admin_only',
            $targetEventId
        );
    }

    public function hasEventForListing(int $variationId, string $eventType): bool
    {
        global $wpdb;
        $table = $this->table();
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE variation_id = %d
               AND event_type = %s
             LIMIT 1",
            $variationId,
            sanitize_key($eventType)
        ));

        return (int) $found > 0;
    }

    /** @return list<object> */
    public function scorableForMerchant(int $merchantId, string $since): array
    {
        global $wpdb;
        $table = $this->table();
        $types = ListingEventType::scorableTypes();
        if ($types === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $params = array_merge([$merchantId, $since], $types);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE merchant_id = %d
               AND created_at >= %s
               AND event_type IN ({$placeholders})
             ORDER BY created_at ASC, id ASC",
            ...$params
        )) ?: [];
    }

    /** @return list<int> */
    public function reversedEventIds(int $merchantId, string $since): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT reverses_event_id FROM {$table}
             WHERE merchant_id = %d
               AND created_at >= %s
               AND event_type = %s
               AND reverses_event_id IS NOT NULL",
            $merchantId,
            $since,
            ListingEventType::EVENT_REVERSAL
        )) ?: [];

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->reverses_event_id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function hasPreOrderAcceptance(int $merchantId, int $variationId): bool
    {
        global $wpdb;
        $table = $this->table();
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE merchant_id = %d
               AND variation_id = %d
               AND event_type = %s
             LIMIT 1",
            $merchantId,
            $variationId,
            ListingEventType::PRE_ORDER_ACCEPTED
        ));

        return (int) $found > 0;
    }

    public static function payloadAsking(object $row): float
    {
        $payload = json_decode((string) ($row->payload ?? '{}'), true);
        if (!is_array($payload)) {
            return 0.0;
        }

        return max(0.0, (float) ($payload['asking'] ?? 0));
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

        if (!empty($args['variation_id'])) {
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

        if (!empty($args['since'])) {
            $where[] = 'created_at >= %s';
            $params[] = (string) $args['since'];
        }

        $sqlWhere = implode(' AND ', $where);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$sqlWhere}";
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params))
            : (int) $wpdb->get_var($countSql);

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT * FROM {$table} WHERE {$sqlWhere} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
        $listParams = array_merge($params, [$perPage, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($sql, ...$listParams)) ?: [];

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<object>
     */
    public function findTimelineForVariation(int $variationId): array
    {
        if ($variationId <= 0) {
            return [];
        }

        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT event_type, created_at FROM ' . $this->table() . '
             WHERE variation_id = %d
             ORDER BY created_at ASC, id ASC',
            $variationId
        )) ?: [];
    }
}
