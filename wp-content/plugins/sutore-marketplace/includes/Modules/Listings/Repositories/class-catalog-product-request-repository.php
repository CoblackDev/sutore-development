<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequest;
use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Shared\Database\Schema;

final class CatalogProductRequestRepository
{
    public function table(): string
    {
        return Schema::table('catalog_product_requests');
    }

    public function find(int $id): ?CatalogProductRequest
    {
        if ($id <= 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d',
            $id
        ));

        return $row ? CatalogProductRequest::fromRow($row) : null;
    }

    public function findPendingDuplicate(int $merchantId, string $skuOrLink): ?CatalogProductRequest
    {
        $skuOrLink = trim($skuOrLink);
        if ($merchantId <= 0 || $skuOrLink === '') {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . '
             WHERE merchant_id = %d AND status = %s AND sku_or_link = %s
             ORDER BY id DESC LIMIT 1',
            $merchantId,
            CatalogProductRequestStatus::PENDING,
            $skuOrLink
        ));

        return $row ? CatalogProductRequest::fromRow($row) : null;
    }

    /**
     * @return list<CatalogProductRequest>
     */
    public function findForMerchant(
        int $merchantId,
        ?string $status = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM ' . $this->table() . ' WHERE merchant_id = %d';
        $params = [$merchantId];
        if ($status !== null && $status !== '' && CatalogProductRequestStatus::isValid($status)) {
            $sql .= ' AND status = %s';
            $params[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];

        return array_map(
            static fn (object $row): CatalogProductRequest => CatalogProductRequest::fromRow($row),
            $rows
        );
    }

    public function countForMerchant(int $merchantId, ?string $status = null): int
    {
        global $wpdb;
        $sql = 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE merchant_id = %d';
        $params = [$merchantId];
        if ($status !== null && $status !== '' && CatalogProductRequestStatus::isValid($status)) {
            $sql .= ' AND status = %s';
            $params[] = $status;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * @param array{status?:string,search?:string,merchant_id?:int} $args
     * @return list<CatalogProductRequest>
     */
    public function findForStaff(array $args, int $limit = 30, int $offset = 0): array
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$where, $params] = $this->staffWhere($args);
        $sql = 'SELECT * FROM ' . $this->table() . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];

        return array_map(
            static fn (object $row): CatalogProductRequest => CatalogProductRequest::fromRow($row),
            $rows
        );
    }

    /**
     * @param array{status?:string,search?:string,merchant_id?:int} $args
     */
    public function countForStaff(array $args): int
    {
        global $wpdb;
        [$where, $params] = $this->staffWhere($args);

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . $where,
            ...$params
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
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }

        global $wpdb;
        $data['updated_at'] = current_time('mysql');

        return false !== $wpdb->update($this->table(), $data, ['id' => $id]);
    }

    /**
     * @param array{status?:string,search?:string,merchant_id?:int} $args
     * @return array{0:string,1:list<mixed>}
     */
    private function staffWhere(array $args): array
    {
        global $wpdb;
        $where = ' WHERE 1=1';
        $params = [];
        $status = sanitize_key((string) ($args['status'] ?? ''));
        if ($status !== '' && CatalogProductRequestStatus::isValid($status)) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }
        $merchantId = (int) ($args['merchant_id'] ?? 0);
        if ($merchantId > 0) {
            $where .= ' AND merchant_id = %d';
            $params[] = $merchantId;
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (sku_or_link LIKE %s OR size_note LIKE %s OR note LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [$where, $params];
    }
}
