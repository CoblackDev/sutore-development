<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Repositories;

use SutoreMarketplace\Shared\Database\Schema;

final class MerchantEventsRepository
{
    public function table(): string
    {
        return Schema::table('merchant_events');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function log(
        int $merchantId,
        string $eventType,
        array $payload = [],
        string $visibility = 'admin_only'
    ): int {
        if ($merchantId <= 0 || $eventType === '') {
            return 0;
        }

        global $wpdb;

        $payload = $this->withActor($payload);

        $wpdb->insert($this->table(), [
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

    /** @return list<object> */
    public function forMerchant(int $merchantId, int $limit = 100): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        global $wpdb;
        $limit = max(1, min(200, $limit));

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE merchant_id = %d ORDER BY id DESC LIMIT %d',
            $merchantId,
            $limit
        )) ?: [];
    }
}
