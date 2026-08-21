<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Modules\Listings\Domain\CampaignOfferStatus;
use SutoreMarketplace\Shared\Database\Schema;

final class CampaignOfferRepository
{
    public function table(): string
    {
        return Schema::table('campaign_offers');
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id));

        return $row ?: null;
    }

    public function findAcceptedForVariation(int $variationId): ?object
    {
        $map = $this->findAcceptedForVariations([$variationId]);

        return $map[$variationId] ?? null;
    }

    /**
     * @param list<int> $variationIds
     * @return array<int, object>
     */
    public function findAcceptedForVariations(array $variationIds): array
    {
        $variationIds = array_values(array_unique(array_filter(array_map('intval', $variationIds))));
        if ($variationIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($variationIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE variation_id IN ({$placeholders}) AND status = %s
             ORDER BY id DESC",
            ...array_merge($variationIds, [CampaignOfferStatus::ACCEPTED])
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $variationId = (int) $row->variation_id;
            if (!isset($out[$variationId])) {
                $out[$variationId] = $row;
            }
        }

        return $out;
    }

    public function findPendingForVariationCampaign(int $variationId, int $campaignId): ?object
    {
        $map = $this->findPendingForVariationCampaigns([[$variationId, $campaignId]]);

        return $map[$variationId . ':' . $campaignId] ?? null;
    }

    /**
     * @param list<array{0:int,1:int}> $pairs variation_id, campaign_id
     * @return array<string, object> keyed by "variationId:campaignId"
     */
    public function findPendingForVariationCampaigns(array $pairs): array
    {
        $pairs = array_values(array_filter($pairs, static function ($pair): bool {
            return is_array($pair) && count($pair) >= 2 && (int) $pair[0] > 0 && (int) $pair[1] > 0;
        }));
        if ($pairs === []) {
            return [];
        }

        $variationIds = array_values(array_unique(array_map(static fn ($p): int => (int) $p[0], $pairs)));
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($variationIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE variation_id IN ({$placeholders}) AND status = %s",
            ...array_merge($variationIds, [CampaignOfferStatus::PENDING])
        ));

        $wanted = [];
        foreach ($pairs as $pair) {
            $wanted[(int) $pair[0] . ':' . (int) $pair[1]] = true;
        }

        $out = [];
        foreach ($rows ?: [] as $row) {
            $key = (int) $row->variation_id . ':' . (int) $row->campaign_id;
            if (isset($wanted[$key])) {
                $out[$key] = $row;
            }
        }

        return $out;
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
        $sql .= ' ORDER BY ' . $this->resolveMerchantOrderBy($orderby) . ' LIMIT %d OFFSET %d';
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

    private function resolveMerchantOrderBy(string $orderby): string
    {
        return match (sanitize_key($orderby)) {
            'created_asc' => 'created_at ASC, id ASC',
            'asking_asc' => 'asking_before ASC, id DESC',
            'asking_desc' => 'asking_before DESC, id DESC',
            default => 'created_at DESC, id DESC',
        };
    }

    /** @return object[] */
    public function findAcceptedExpired(int $limit = 100): array
    {
        global $wpdb;
        $campaigns = Schema::table('campaigns');
        $offers = $this->table();
        $now = current_time('mysql');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT o.* FROM {$offers} o
             INNER JOIN {$campaigns} c ON c.id = o.campaign_id
             WHERE o.status = %s
               AND c.ends_at IS NOT NULL
               AND c.ends_at <= %s
             ORDER BY o.id ASC
             LIMIT %d",
            CampaignOfferStatus::ACCEPTED,
            $now,
            max(1, min(500, $limit))
        )) ?: [];
    }

    /** @return object[] */
    public function findAcceptedByCampaign(int $campaignId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE campaign_id = %d AND status = %s',
            $campaignId,
            CampaignOfferStatus::ACCEPTED
        )) ?: [];
    }

    /** @return object[] */
    public function findPendingByCampaign(int $campaignId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE campaign_id = %d AND status = %s',
            $campaignId,
            CampaignOfferStatus::PENDING
        )) ?: [];
    }

    /**
     * @param list<int> $campaignIds
     * @return array<int, array{pending: int, accepted: int}>
     */
    public function countsByCampaignIds(array $campaignIds): array
    {
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds))));
        $out = [];
        foreach ($campaignIds as $id) {
            $out[$id] = ['pending' => 0, 'accepted' => 0];
        }
        if ($campaignIds === []) {
            return $out;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($campaignIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT campaign_id, status, COUNT(*) AS total FROM ' . $this->table() . "
             WHERE campaign_id IN ({$placeholders})
               AND status IN (%s, %s)
             GROUP BY campaign_id, status",
            ...array_merge($campaignIds, [CampaignOfferStatus::PENDING, CampaignOfferStatus::ACCEPTED])
        ));
        foreach ($rows ?: [] as $row) {
            $id = (int) $row->campaign_id;
            $status = (string) $row->status;
            if (!isset($out[$id])) {
                $out[$id] = ['pending' => 0, 'accepted' => 0];
            }
            if ($status === CampaignOfferStatus::PENDING) {
                $out[$id]['pending'] = (int) $row->total;
            } elseif ($status === CampaignOfferStatus::ACCEPTED) {
                $out[$id]['accepted'] = (int) $row->total;
            }
        }

        return $out;
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
     * Atomically apply $data only while status is still $expectedStatus.
     *
     * @param array<string, mixed> $data
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
}
