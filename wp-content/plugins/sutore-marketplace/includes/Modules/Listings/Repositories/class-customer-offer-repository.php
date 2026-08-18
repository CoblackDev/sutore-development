<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferStatus;
use SutoreMarketplace\Shared\Database\Schema;

final class CustomerOfferRepository
{
    public function table(): string
    {
        return Schema::table('customer_offers');
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id));

        return $row ?: null;
    }

    /**
     * @return object[]
     */
    public function findForMerchant(
        int $merchantId,
        ?string $status = null,
        int $limit = 50,
        int $offset = 0,
        string $orderby = 'created_desc'
    ): array {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM ' . $this->table() . ' WHERE merchant_id = %d';
        $params = [$merchantId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = %s';
            $params[] = sanitize_key($status);
        }
        $sql .= ' ORDER BY ' . $this->resolveOrderBy($orderby) . ' LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
    }

    public function countForMerchant(int $merchantId, ?string $status = null): int
    {
        global $wpdb;
        $sql = 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE merchant_id = %d';
        $params = [$merchantId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = %s';
            $params[] = sanitize_key($status);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * @return object[]
     */
    public function findForCustomer(
        int $customerId,
        ?string $status = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM ' . $this->table() . ' WHERE customer_id = %d';
        $params = [$customerId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = %s';
            $params[] = sanitize_key($status);
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
    }

    public function countForCustomer(int $customerId, ?string $status = null): int
    {
        global $wpdb;
        $sql = 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE customer_id = %d';
        $params = [$customerId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = %s';
            $params[] = sanitize_key($status);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    public function findPendingForCustomerProductSize(int $customerId, int $parentId, int $sizeTermId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE customer_id = %d AND parent_product_id = %d AND size_term_id = %d AND status = %s
             ORDER BY id DESC LIMIT 1',
            $customerId,
            $parentId,
            $sizeTermId,
            CustomerOfferStatus::PENDING
        ));

        return $row ?: null;
    }

    public function findAcceptedForListingAndCustomer(int $listingId, int $customerId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE listing_id = %d AND customer_id = %d AND status = %s
             ORDER BY id DESC LIMIT 1',
            $listingId,
            $customerId,
            CustomerOfferStatus::ACCEPTED
        ));

        return $row ?: null;
    }

    public function findAcceptedByCouponId(int $couponId): ?object
    {
        if ($couponId <= 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE coupon_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
            $couponId,
            CustomerOfferStatus::ACCEPTED
        ));

        return $row ?: null;
    }

    public function findAcceptedByListing(int $listingId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE listing_id = %d AND status = %s
             ORDER BY id DESC LIMIT 1',
            $listingId,
            CustomerOfferStatus::ACCEPTED
        ));

        return $row ?: null;
    }

    public function countCreatedToday(int $customerId): int
    {
        global $wpdb;
        $start = wp_date('Y-m-d 00:00:00');

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE customer_id = %d AND created_at >= %s',
            $customerId,
            $start
        ));
    }

    /** @return object[] */
    public function findPendingExpired(int $limit = 100): array
    {
        global $wpdb;
        $now = current_time('mysql');

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s
             ORDER BY id ASC LIMIT %d',
            CustomerOfferStatus::PENDING,
            $now,
            max(1, min(500, $limit))
        )) ?: [];
    }

    /** @return object[] */
    public function findAcceptedExpired(int $limit = 100): array
    {
        global $wpdb;
        $now = current_time('mysql');

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s
             ORDER BY id ASC LIMIT %d',
            CustomerOfferStatus::ACCEPTED,
            $now,
            max(1, min(500, $limit))
        )) ?: [];
    }

    /** @return list<int> */
    public function merchantIdsInChain(int $originOfferId): array
    {
        if ($originOfferId <= 0) {
            return [];
        }

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT merchant_id FROM ' . $this->table() . '
             WHERE id = %d OR origin_offer_id = %d',
            $originOfferId,
            $originOfferId
        ));

        return array_values(array_filter(array_map('intval', $ids ?: [])));
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
     * Atomically apply $data only while the row is still $expectedStatus.
     * Prevents cancel/accept/decline/expiry from overwriting each other.
     */
    public function updateIfStatus(int $id, string $expectedStatus, array $data): bool
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $updated = $wpdb->update(
            $this->table(),
            $data,
            [
                'id' => $id,
                'status' => $expectedStatus,
            ]
        );

        return is_int($updated) && $updated > 0;
    }

    private function resolveOrderBy(string $orderby): string
    {
        return match (sanitize_key($orderby)) {
            'created_asc' => 'created_at ASC, id ASC',
            'bid_asc' => 'bid_amount ASC, id DESC',
            'bid_desc' => 'bid_amount DESC, id DESC',
            default => 'created_at DESC, id DESC',
        };
    }
}
