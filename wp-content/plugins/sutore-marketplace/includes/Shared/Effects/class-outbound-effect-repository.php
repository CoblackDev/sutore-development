<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Effects;

use SutoreMarketplace\Shared\Database\Schema;

final class OutboundEffectRepository
{
    private function table(): string
    {
        return Schema::table('outbound_effects');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function insert(string $effectType, array $payload, string $dedupeKey): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $encoded = wp_json_encode($payload);
        $wpdb->insert(
            $this->table(),
            [
                'effect_type' => $effectType,
                'status' => OutboundEffectStatus::PENDING,
                'dedupe_key' => $dedupeKey,
                'payload' => is_string($encoded) ? $encoded : '{}',
                'last_error' => '',
                'attempts' => 0,
                'next_retry_at' => null,
                'processed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return (int) $wpdb->insert_id;
    }

    public function findByDedupe(string $dedupeKey): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table() . ' WHERE dedupe_key = %s LIMIT 1',
                $dedupeKey
            )
        );

        return $row instanceof \stdClass ? $row : null;
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d LIMIT 1', $id)
        );

        return $row instanceof \stdClass ? $row : null;
    }

    public function claim(int $id): bool
    {
        global $wpdb;
        $now = current_time('mysql');
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $this->table() . '
                 SET status = %s, updated_at = %s, attempts = attempts + 1
                 WHERE id = %d AND status IN (%s, %s)',
                OutboundEffectStatus::PROCESSING,
                $now,
                $id,
                OutboundEffectStatus::PENDING,
                OutboundEffectStatus::FAILED
            )
        );

        return is_int($updated) && $updated > 0;
    }

    public function markDone(int $id): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->update(
            $this->table(),
            [
                'status' => OutboundEffectStatus::DONE,
                'last_error' => '',
                'next_retry_at' => null,
                'processed_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $id]
        );
    }

    public function markFailed(int $id, string $error, ?string $nextRetryAt, bool $permanent): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->update(
            $this->table(),
            [
                'status' => $permanent ? OutboundEffectStatus::FAILED : OutboundEffectStatus::PENDING,
                'last_error' => mb_substr($error, 0, 2000),
                'next_retry_at' => $nextRetryAt,
                'processed_at' => $permanent ? $now : null,
                'updated_at' => $now,
            ],
            ['id' => $id]
        );
    }

    /**
     * @return list<object>
     */
    public function dueForRetry(int $limit = 40): array
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $now = current_time('mysql');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table() . '
                 WHERE status = %s
                   AND (next_retry_at IS NULL OR next_retry_at <= %s)
                 ORDER BY id ASC
                 LIMIT %d',
                OutboundEffectStatus::PENDING,
                $now,
                $limit
            )
        );

        return is_array($rows) ? $rows : [];
    }
}
