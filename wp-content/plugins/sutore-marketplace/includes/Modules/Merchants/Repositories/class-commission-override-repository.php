<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Repositories;

use SutoreMarketplace\Shared\Database\Schema;

final class CommissionOverrideRepository
{
    public function table(): string
    {
        return Schema::table('merchant_commission_overrides');
    }

    public function find(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d',
            $id
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $wpdb->insert($this->table(), $data);

        return (int) $wpdb->insert_id;
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        global $wpdb;

        return false !== $wpdb->delete($this->table(), ['id' => $id], ['%d']);
    }

    /** @return list<object> */
    public function activeForMerchant(int $merchantId): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        global $wpdb;
        $now = current_time('mysql');

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE merchant_id = %d
               AND is_active = 1
               AND (expires_at IS NULL OR expires_at > %s)
             ORDER BY commission_percent ASC, id ASC',
            $merchantId,
            $now
        )) ?: [];
    }

    /** Lowest active absolute commission percent, or null. */
    public function effectiveRowForMerchant(int $merchantId): ?object
    {
        $rows = $this->activeForMerchant($merchantId);

        return $rows[0] ?? null;
    }
}
