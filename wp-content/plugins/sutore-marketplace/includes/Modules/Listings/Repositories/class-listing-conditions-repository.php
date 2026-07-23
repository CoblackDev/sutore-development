<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Repositories;

use SutoreMarketplace\Shared\Database\Schema;

final class ListingConditionsRepository
{
    public function table(): string
    {
        return Schema::table('listing_conditions');
    }

    /** @return array<string, bool> */
    public function forListing(int $listingId): array
    {
        $map = $this->forListings([$listingId]);

        return $map[$listingId] ?? [];
    }

    /**
     * @param list<int> $listingIds
     * @return array<int, array<string, bool>>
     */
    public function forListings(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter(array_map('intval', $listingIds))));
        if ($listingIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($listingIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT listing_id, condition_key, condition_value FROM ' . $this->table()
            . " WHERE listing_id IN ({$placeholders})",
            ...$listingIds
        ));

        $out = [];
        foreach ($listingIds as $id) {
            $out[$id] = [];
        }
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->listing_id][(string) $row->condition_key] = (int) $row->condition_value === 1;
        }

        return $out;
    }

    public function deleteForListing(int $listingId): void
    {
        global $wpdb;
        $wpdb->delete($this->table(), ['listing_id' => $listingId]);
    }

    public function sync(int $listingId, array $conditions): void
    {
        global $wpdb;
        $wpdb->delete($this->table(), ['listing_id' => $listingId]);

        foreach ($conditions as $key => $value) {
            if (is_int($key)) {
                $key = (string) $value;
                $value = true;
            }
            if (empty($value)) {
                continue;
            }
            $wpdb->insert($this->table(), [
                'listing_id' => $listingId,
                'condition_key' => sanitize_key((string) $key),
                'condition_value' => 1,
            ]);
        }
    }
}
