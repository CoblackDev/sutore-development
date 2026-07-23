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

    public function findAcceptedForListing(int $listingId): ?object
    {
        $map = $this->findAcceptedForListings([$listingId]);

        return $map[$listingId] ?? null;
    }

    /**
     * @param list<int> $listingIds
     * @return array<int, object>
     */
    public function findAcceptedForListings(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter(array_map('intval', $listingIds))));
        if ($listingIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($listingIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE listing_id IN ({$placeholders}) AND status = %s
             ORDER BY id DESC",
            ...array_merge($listingIds, [CampaignOfferStatus::ACCEPTED])
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $lid = (int) $row->listing_id;
            if (!isset($out[$lid])) {
                $out[$lid] = $row;
            }
        }

        return $out;
    }

    public function findPendingForListingCampaign(int $listingId, int $campaignId): ?object
    {
        $map = $this->findPendingForListingCampaigns([[$listingId, $campaignId]]);

        return $map[$listingId . ':' . $campaignId] ?? null;
    }

    /**
     * @param list<array{0:int,1:int}> $pairs listing_id, campaign_id
     * @return array<string, object> keyed by "listingId:campaignId"
     */
    public function findPendingForListingCampaigns(array $pairs): array
    {
        $pairs = array_values(array_filter($pairs, static function ($pair): bool {
            return is_array($pair) && count($pair) >= 2 && (int) $pair[0] > 0 && (int) $pair[1] > 0;
        }));
        if ($pairs === []) {
            return [];
        }

        $listingIds = array_values(array_unique(array_map(static fn ($p): int => (int) $p[0], $pairs)));
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($listingIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE listing_id IN ({$placeholders}) AND status = %s",
            ...array_merge($listingIds, [CampaignOfferStatus::PENDING])
        ));

        $wanted = [];
        foreach ($pairs as $pair) {
            $wanted[(int) $pair[0] . ':' . (int) $pair[1]] = true;
        }

        $out = [];
        foreach ($rows ?: [] as $row) {
            $key = (int) $row->listing_id . ':' . (int) $row->campaign_id;
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
}
