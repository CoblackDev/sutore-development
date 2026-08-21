<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Request-scoped product chrome for list presenters (title, SKU, thumb, permalink).
 */
final class ProductListChrome
{
    /**
     * @param list<int> $productIds Parent and/or variation IDs.
     * @return array<int, array{title:string,code:string,thumbnail:string,permalink:string}>
     */
    public static function mapForIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('absint', $productIds))));
        if ($productIds === []) {
            return [];
        }

        if (function_exists('wc_get_products')) {
            wc_get_products([
                'include' => $productIds,
                'limit' => count($productIds),
                'status' => ['publish', 'private', 'draft'],
                'type' => ['simple', 'variable', 'variation'],
            ]);
        }

        if (function_exists('_prime_post_caches')) {
            _prime_post_caches($productIds, false, true);
        } else {
            update_meta_cache('post', $productIds);
        }

        $out = [];
        foreach ($productIds as $id) {
            $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
            $title = '';
            $code = '';
            if ($product instanceof \WC_Product) {
                $title = trim((string) $product->get_name());
                $code = trim((string) $product->get_sku('edit'));
            }
            if ($title === '') {
                $title = trim((string) get_the_title($id));
            }

            $out[$id] = [
                'title' => $title,
                'code' => $code,
                'thumbnail' => ProductThumbnail::url($id),
                'permalink' => get_permalink($id) ?: '',
            ];
        }

        return $out;
    }
}
