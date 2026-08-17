<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Repositories;

use SutoreMarketplace\Modules\Invoices\Domain\InvoiceStatus;
use SutoreMarketplace\Shared\Database\Schema;

final class InvoiceRepository
{
    public function table(): string
    {
        return Schema::table('invoices');
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1",
            $id
        ));

        return $row ?: null;
    }

    public function findByKindAndVariation(string $kind, int $variationId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE kind = %s AND variation_id = %d LIMIT 1",
            $kind,
            $variationId
        ));

        return $row ?: null;
    }

    public function findByKindAndOrder(string $kind, int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE kind = %s AND order_id = %d ORDER BY id ASC LIMIT 1",
            $kind,
            $orderId
        ));

        return $row ?: null;
    }

    /**
     * @param array<int, int> $orderIdByVariation variation_id => order_id
     * @return array<int, list<object>>
     */
    public function findForVariations(array $orderIdByVariation): array
    {
        $variationIds = array_values(array_unique(array_filter(array_map('intval', array_keys($orderIdByVariation)))));
        $orderIds = array_values(array_unique(array_filter(array_map('intval', array_values($orderIdByVariation)))));
        if ($variationIds === []) {
            return [];
        }

        global $wpdb;
        $varPlaceholders = implode(',', array_fill(0, count($variationIds), '%d'));
        $params = $variationIds;
        $sql = "SELECT * FROM {$this->table()} WHERE variation_id IN ({$varPlaceholders})";
        if ($orderIds !== []) {
            $orderPlaceholders = implode(',', array_fill(0, count($orderIds), '%d'));
            $sql .= ' OR (kind = %s AND variation_id = %d AND order_id IN (' . $orderPlaceholders . '))';
            $params[] = \SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind::CUSTOMER_FEES;
            $params[] = \SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind::ORDER_SCOPE_VARIATION;
            $params = array_merge($params, $orderIds);
        }
        $sql .= ' ORDER BY id ASC';

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
        $byVariation = [];
        $customerByOrder = [];
        foreach ($rows as $row) {
            $variationId = (int) $row->variation_id;
            if ($variationId > 0) {
                $byVariation[$variationId][] = $row;
            }
            if ((string) $row->kind === \SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind::CUSTOMER_FEES) {
                $customerByOrder[(int) $row->order_id] = $row;
            }
        }

        $out = [];
        foreach ($orderIdByVariation as $variationId => $orderId) {
            $variationId = (int) $variationId;
            $list = $byVariation[$variationId] ?? [];
            $customer = $customerByOrder[(int) $orderId] ?? null;
            if ($customer) {
                $already = false;
                foreach ($list as $existing) {
                    if ((int) $existing->id === (int) $customer->id) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    array_unshift($list, $customer);
                }
            }
            $out[$variationId] = $list;
        }

        return $out;
    }

    /**
     * @param list<int> $variationIds
     * @return array<int, list<object>>
     */
    public function findByVariationIds(array $variationIds): array
    {
        $variationIds = array_values(array_unique(array_filter(array_map('intval', $variationIds))));
        if ($variationIds === []) {
            return [];
        }

        $orderIdByVariation = [];
        foreach ($variationIds as $variationId) {
            $orderIdByVariation[$variationId] = 0;
        }

        return $this->findForVariations($orderIdByVariation);
    }

    /**
     * @return list<array{variation_id:int,title:string,code:string,amount:float}>
     */
    public static function decodeLines(object $row): array
    {
        $decoded = json_decode((string) ($row->line_items ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $line) {
            if (!is_array($line)) {
                continue;
            }
            $code = sanitize_key((string) ($line['code'] ?? ''));
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($code === '' || $amount < 0.01) {
                continue;
            }
            $out[] = [
                'variation_id' => (int) ($line['variation_id'] ?? 0),
                'title' => sanitize_text_field((string) ($line['title'] ?? '')),
                'code' => $code,
                'amount' => $amount,
            ];
        }

        return $out;
    }

    /** @return list<object> */
    public function dueBatch(int $limit = 8): array
    {
        global $wpdb;
        $limit = max(1, min(25, $limit));
        $statuses = InvoiceStatus::dueForCron();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $now = current_time('mysql');
        $stale = wp_date('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE (
                    (status IN ({$placeholders}) AND (next_retry_at IS NULL OR next_retry_at <= %s))
                    OR (status = %s AND updated_at <= %s)
             )
             ORDER BY id ASC
             LIMIT {$limit}",
            ...[...$statuses, $now, InvoiceStatus::PROCESSING, $stale]
        )) ?: [];
    }

    public function claim(int $id): bool
    {
        global $wpdb;
        $now = current_time('mysql');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()}
             SET status = %s, updated_at = %s
             WHERE id = %d
               AND status IN (%s, %s, %s, %s, %s)",
            InvoiceStatus::PROCESSING,
            $now,
            $id,
            InvoiceStatus::QUEUED,
            InvoiceStatus::WAITING_JOB,
            InvoiceStatus::WAITING_PDF,
            InvoiceStatus::ERROR,
            InvoiceStatus::PROCESSING
        ));

        return (int) $updated === 1;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $now;
        $wpdb->insert($this->table(), $data);

        return (int) $wpdb->insert_id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($this->table(), $data, ['id' => $id]);
    }
}
