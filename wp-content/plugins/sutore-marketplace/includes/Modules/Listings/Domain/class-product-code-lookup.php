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
    public static function searchParents(string $code, string $categorySlug = ''): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_parent' => 0,
            'posts_per_page' => 20,
            'meta_query' => [[
                'key' => '_sku',
                'value' => $code,
                'compare' => 'LIKE',
            ]],
        ];

        if ($categorySlug !== '') {
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => [$categorySlug],
            ]];
        }

        $q = new \WP_Query($args);
        $items = [];
        $seen = [];
        foreach ($q->posts as $post) {
            $id = (int) $post->ID;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $items[] = [
                'id' => $id,
                'title' => get_the_title($id),
                'product_code' => self::codeForProduct($id),
                'thumbnail' => ProductThumbnail::url($id),
                'permalink' => get_permalink($id) ?: '',
            ] + ReleasePriceService::context($id);
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
}
