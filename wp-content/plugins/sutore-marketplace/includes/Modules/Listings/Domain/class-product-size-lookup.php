<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class ProductSizeLookup
{
    /**
     * @return list<array{term_id: int, slug: string, name: string, taxonomy: string}>
     */
    public static function termsForParent(int $parentId): array
    {
        $product = wc_get_product($parentId);
        if (!$product || !$product->is_type('variable')) {
            return [];
        }

        $sizes = [];
        foreach ($product->get_attributes() as $attr) {
            if (!$attr->get_variation()) {
                continue;
            }
            $name = $attr->get_name();
            if ($name !== 'pa_beden-numara' && !str_contains($name, 'beden')) {
                continue;
            }
            foreach (wc_get_product_terms($parentId, $name, ['fields' => 'all']) as $term) {
                if (!$term || is_wp_error($term)) {
                    continue;
                }
                $sizes[] = [
                    'term_id' => (int) $term->term_id,
                    'slug' => (string) $term->slug,
                    'name' => (string) $term->name,
                    'taxonomy' => $name,
                ];
            }
        }

        return $sizes;
    }

    public static function resolveTermId(int $parentId, string $sizeLabel): ?int
    {
        $needle = self::normalizeSizeLabel($sizeLabel);
        if ($needle === '') {
            return null;
        }

        foreach (self::termsForParent($parentId) as $term) {
            if (self::normalizeSizeLabel($term['name']) === $needle) {
                return (int) $term['term_id'];
            }
            if (self::normalizeSizeLabel($term['slug']) === $needle) {
                return (int) $term['term_id'];
            }
        }

        return null;
    }

    public static function labelForTerm(int $parentId, int $termId): string
    {
        foreach (self::termsForParent($parentId) as $term) {
            if ((int) $term['term_id'] === $termId) {
                return (string) $term['name'];
            }
        }

        return self::labelForTermId($termId);
    }

    public static function labelForTermId(int $termId): string
    {
        if ($termId <= 0) {
            return '';
        }

        $term = get_term($termId, 'pa_beden-numara');
        if (!$term || is_wp_error($term)) {
            $term = get_term($termId);
        }

        return ($term && !is_wp_error($term)) ? (string) $term->name : '';
    }

    private static function normalizeSizeLabel(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        $value = preg_replace('/\s+/', '', $value) ?? '';

        return strtolower($value);
    }
}
