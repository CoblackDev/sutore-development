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

    /**
     * Soft-delete: is_active is the pause/remove flag (hard delete is not used).
     */
    public function deactivate(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        global $wpdb;

        return false !== $wpdb->update(
            $this->table(),
            [
                'is_active' => 0,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * Currently applying: active, started, not expired. Merchant rows + platform (merchant_id = 0).
     *
     * @return list<object>
     */
    public function effectiveForMerchant(int $merchantId): array
    {
        return $this->queryWindow($merchantId, true, true);
    }

    /**
     * Staff list: active and not expired (includes scheduled starts_at in the future).
     *
     * @return list<object>
     */
    public function visibleForMerchant(int $merchantId): array
    {
        return $this->queryWindow($merchantId, true, false);
    }

    /**
     * @return list<object>
     */
    public function visiblePlatform(): array
    {
        return $this->queryWindow(0, false, false);
    }

    public function countReferralCreatedSince(int $merchantId, string $sinceMysql): int
    {
        if ($merchantId <= 0 || $sinceMysql === '') {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . '
              WHERE merchant_id = %d AND source = %s AND created_at >= %s',
            $merchantId,
            'referral',
            $sinceMysql
        ));
    }

    /**
     * @return list<object>
     */
    private function queryWindow(int $merchantId, bool $includePlatform, bool $mustHaveStarted): array
    {
        global $wpdb;
        $now = current_time('mysql');
        $table = $this->table();

        $where = ['is_active = 1', '(expires_at IS NULL OR expires_at > %s)'];
        $params = [$now];

        if ($mustHaveStarted) {
            $where[] = '(starts_at IS NULL OR starts_at <= %s)';
            $params[] = $now;
        }

        if ($merchantId > 0 && $includePlatform) {
            $where[] = '(merchant_id = %d OR merchant_id = 0)';
            $params[] = $merchantId;
        } elseif ($merchantId > 0) {
            $where[] = 'merchant_id = %d';
            $params[] = $merchantId;
        } else {
            $where[] = 'merchant_id = 0';
        }

        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC';

        return $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
    }
}
