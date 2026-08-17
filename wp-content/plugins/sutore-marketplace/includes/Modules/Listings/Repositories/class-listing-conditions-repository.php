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
    public function forListing(int $variationId): array
    {
        $map = $this->forListings([$variationId]);

        return $map[$variationId] ?? [];
    }

    /**
     * @param list<int> $variationIds
     * @return array<int, array<string, bool>>
     */
    public function forListings(array $variationIds): array
    {
        $variationIds = array_values(array_unique(array_filter(array_map('intval', $variationIds))));
        if ($variationIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($variationIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT variation_id, condition_key, condition_value FROM ' . $this->table()
            . " WHERE variation_id IN ({$placeholders})",
            ...$variationIds
        ));

        $out = [];
        foreach ($variationIds as $variationId) {
            $out[$variationId] = [];
        }
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->variation_id][(string) $row->condition_key] = (int) $row->condition_value === 1;
        }

        return $out;
    }

    public function deleteForListing(int $variationId): void
    {
        global $wpdb;
        $wpdb->delete($this->table(), ['variation_id' => $variationId]);
    }

    public function sync(int $variationId, array $conditions): void
    {
        global $wpdb;
        $wpdb->delete($this->table(), ['variation_id' => $variationId]);

        foreach ($conditions as $key => $value) {
            if (is_int($key)) {
                $key = (string) $value;
                $value = true;
            }
            if (empty($value)) {
                continue;
            }
            $wpdb->insert($this->table(), [
                'variation_id' => $variationId,
                'condition_key' => sanitize_key((string) $key),
                'condition_value' => 1,
            ]);
        }
    }
}
