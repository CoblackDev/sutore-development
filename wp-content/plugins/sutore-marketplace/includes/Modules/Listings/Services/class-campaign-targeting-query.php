<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignTargeting;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

/**
 * Resolves listings that match campaign targeting rules.
 */
final class CampaignTargetingQuery
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
    ) {
    }

    /**
     * @return array{listings: list<Listing>, listing_count: int, merchant_count: int}
     */
    public function preview(CampaignTargeting $targeting): array
    {
        $matched = $this->findListings($targeting);
        $merchantIds = [];
        foreach ($matched as $listing) {
            $merchantIds[$listing->merchantId] = true;
        }

        return [
            'listings' => $matched,
            'listing_count' => count($matched),
            'merchant_count' => count($merchantIds),
        ];
    }

    /**
     * @return list<Listing>
     */
    public function findListings(CampaignTargeting $targeting): array
    {
        global $wpdb;

        $table = Schema::table('listings');
        $profiles = Schema::table('merchant_profiles');
        $statuses = [ListingStatus::PUBLISH, ListingStatus::QUEUED];
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "SELECT " . ListingRepository::listColumns('l') . "
                FROM {$table} l
                LEFT JOIN {$profiles} p ON p.user_id = l.merchant_id
                WHERE l.listing_status IN ({$statusPlaceholders})
                  AND l.campaign_status = %s";
        $params = [...$statuses, 'none'];

        if ($targeting->askingMin !== null) {
            $sql .= ' AND l.asking >= %f';
            $params[] = $targeting->askingMin;
        }
        if ($targeting->askingMax !== null) {
            $sql .= ' AND l.asking <= %f';
            $params[] = $targeting->askingMax;
        }

        if ($targeting->merchantLevels !== []) {
            $levelPlaceholders = implode(',', array_fill(0, count($targeting->merchantLevels), '%s'));
            // Merchants without a profile row are treated as normal.
            $hasNormal = in_array(MerchantLevels::NORMAL, $targeting->merchantLevels, true);
            if ($hasNormal) {
                $sql .= " AND (COALESCE(NULLIF(p.merchant_status, ''), %s) IN ({$levelPlaceholders}))";
                $params[] = MerchantLevels::NORMAL;
                foreach ($targeting->merchantLevels as $level) {
                    $params[] = $level;
                }
            } else {
                $sql .= " AND p.merchant_status IN ({$levelPlaceholders})";
                foreach ($targeting->merchantLevels as $level) {
                    $params[] = $level;
                }
            }
        }

        if ($targeting->productIds !== []) {
            $ph = implode(',', array_fill(0, count($targeting->productIds), '%d'));
            $sql .= " AND l.parent_product_id IN ({$ph})";
            foreach ($targeting->productIds as $id) {
                $params[] = $id;
            }
        }

        $sql .= ' ORDER BY l.id ASC LIMIT 2000';

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
        if ($rows === []) {
            return [];
        }

        $needsTaxonomy = $targeting->categoryIds !== [] || $targeting->brandIds !== [];
        $termCache = [];
        if ($needsTaxonomy) {
            $parentIds = array_values(array_unique(array_filter(array_map(
                static fn ($row): int => (int) ($row->parent_product_id ?? 0),
                $rows
            ))));
            foreach ($parentIds as $parentId) {
                $termCache[$parentId] = [
                    'product_cat' => $targeting->categoryIds !== []
                        ? array_map('intval', (array) (wp_get_post_terms($parentId, 'product_cat', ['fields' => 'ids']) ?: []))
                        : [],
                    'product_brand' => $targeting->brandIds !== []
                        ? array_map('intval', (array) (wp_get_post_terms($parentId, 'product_brand', ['fields' => 'ids']) ?: []))
                        : [],
                ];
            }
        }

        $listings = [];
        foreach ($rows as $row) {
            $listing = Listing::fromRow($row);
            if (!$this->matchesTaxonomy($listing, $targeting, $termCache)) {
                continue;
            }
            $listings[] = $listing;
        }

        return $listings;
    }

    /**
     * @param array<int, array{product_cat: list<int>, product_brand: list<int>}> $termCache
     */
    private function matchesTaxonomy(Listing $listing, CampaignTargeting $targeting, array $termCache = []): bool
    {
        if ($targeting->categoryIds === [] && $targeting->brandIds === []) {
            return true;
        }

        $parentId = $listing->parentProductId;
        if ($parentId <= 0) {
            return false;
        }

        $cached = $termCache[$parentId] ?? null;

        if ($targeting->categoryIds !== []) {
            $terms = $cached['product_cat']
                ?? array_map('intval', (array) (wp_get_post_terms($parentId, 'product_cat', ['fields' => 'ids']) ?: []));
            if (array_intersect($targeting->categoryIds, $terms) === []) {
                return false;
            }
        }

        if ($targeting->brandIds !== []) {
            $terms = $cached['product_brand']
                ?? array_map('intval', (array) (wp_get_post_terms($parentId, 'product_brand', ['fields' => 'ids']) ?: []));
            if (array_intersect($targeting->brandIds, $terms) === []) {
                return false;
            }
        }

        return true;
    }
}
