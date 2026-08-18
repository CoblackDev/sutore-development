<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

use SutoreMarketplace\Shared\Domain\ReleasePriceService;

final class ProductCodeLookup
{
    public static function codeForProduct(int $productId): string
    {
        $product = wc_get_product($productId);

        return $product instanceof \WC_Product ? trim((string) $product->get_sku('edit')) : '';
    }

    /**
     * @return list<array{id:int,title:string,product_code:string,thumbnail:string}>
     */
    public static function searchParents(string $query, string $categorySlug = ''): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $ids = [];
        foreach (self::parentIdsBySku($query, $categorySlug) as $id) {
            $ids[$id] = true;
        }
        foreach (self::parentIdsByTitle($query, $categorySlug) as $id) {
            $ids[$id] = true;
        }

        $items = [];
        foreach (array_keys($ids) as $id) {
            $items[] = self::presentParent($id);
            if (count($items) >= 20) {
                break;
            }
        }

        return $items;
    }

    public static function findParentByExactCode(string $code): ?int
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $posts = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_parent' => 0,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_sku',
            'meta_value' => $code,
        ]);

        if ($posts) {
            $id = (int) $posts[0];
            $product = wc_get_product($id);
            if ($product && $product->is_type('variable')) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private static function parentIdsBySku(string $query, string $categorySlug): array
    {
        $args = self::baseParentQueryArgs($categorySlug);
        $args['meta_query'] = [[
            'key' => '_sku',
            'value' => $query,
            'compare' => 'LIKE',
        ]];

        return self::queryParentIds($args);
    }

    /**
     * @return list<int>
     */
    private static function parentIdsByTitle(string $query, string $categorySlug): array
    {
        $args = self::baseParentQueryArgs($categorySlug);
        $filter = static function (string $where) use ($query): string {
            global $wpdb;
            $like = '%' . $wpdb->esc_like($query) . '%';

            return $where . $wpdb->prepare(" AND {$wpdb->posts}.post_title LIKE %s", $like);
        };
        add_filter('posts_where', $filter);
        try {
            return self::queryParentIds($args);
        } finally {
            remove_filter('posts_where', $filter);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseParentQueryArgs(string $categorySlug): array
    {
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_parent' => 0,
            'posts_per_page' => 20,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ];

        if ($categorySlug !== '') {
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => [$categorySlug],
            ]];
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $args
     * @return list<int>
     */
    private static function queryParentIds(array $args): array
    {
        $q = new \WP_Query($args);
        $ids = [];
        foreach ($q->posts as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return array{id:int,title:string,product_code:string,thumbnail:string,permalink:string}
     */
    private static function presentParent(int $id): array
    {
        return [
            'id' => $id,
            'title' => get_the_title($id),
            'product_code' => self::codeForProduct($id),
            'thumbnail' => ProductThumbnail::url($id),
            'permalink' => get_permalink($id) ?: '',
        ] + ReleasePriceService::context($id);
    }
}
