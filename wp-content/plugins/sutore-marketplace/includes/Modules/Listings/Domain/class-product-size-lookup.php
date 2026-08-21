<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Variation-axis term lookup for listings (size, color, or any WC variation attribute).
 * DB column remains size_term_id — it stores the primary variation axis term id.
 */
final class ProductSizeLookup
{
    /** Preferred size taxonomy when a product has multiple variation axes. */
    public const PRIMARY_SIZE_TAXONOMY = 'pa_beden-numara';

    /**
     * @return list<array{term_id: int, slug: string, name: string, taxonomy: string}>
     */
    public static function termsForParent(int $parentId): array
    {
        $taxonomy = self::primaryVariationTaxonomy($parentId);
        if ($taxonomy === null) {
            return [];
        }

        $terms = [];
        foreach (wc_get_product_terms($parentId, $taxonomy, ['fields' => 'all']) as $term) {
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $terms[] = [
                'term_id' => (int) $term->term_id,
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
                'taxonomy' => $taxonomy,
            ];
        }

        return $terms;
    }

    /**
     * @return array{taxonomy: string, axis_label: string, items: list<array{term_id: int, slug: string, name: string, taxonomy: string}>}
     */
    public static function axisContextForParent(int $parentId): array
    {
        $taxonomy = self::primaryVariationTaxonomy($parentId) ?? '';
        $items = self::termsForParent($parentId);

        return [
            'taxonomy' => $taxonomy,
            'axis_label' => $taxonomy !== '' ? self::axisLabelForTaxonomy($taxonomy) : '',
            'items' => $items,
        ];
    }

    public static function primaryVariationTaxonomy(int $parentId): ?string
    {
        $product = wc_get_product($parentId);
        if (!$product || !$product->is_type('variable')) {
            return null;
        }

        $variationTaxonomies = [];
        foreach ($product->get_attributes() as $attr) {
            if (!$attr->get_variation()) {
                continue;
            }
            $name = $attr->get_name();
            if ($name !== '') {
                $variationTaxonomies[] = $name;
            }
        }

        if ($variationTaxonomies === []) {
            return null;
        }

        if (in_array(self::PRIMARY_SIZE_TAXONOMY, $variationTaxonomies, true)) {
            return self::PRIMARY_SIZE_TAXONOMY;
        }

        foreach ($variationTaxonomies as $taxonomy) {
            if (str_contains($taxonomy, 'beden')) {
                return $taxonomy;
            }
        }

        return $variationTaxonomies[0];
    }

    public static function assertTermAllowedForParent(int $parentId, int $termId): true|\WP_Error
    {
        if ($parentId <= 0 || $termId <= 0) {
            return new \WP_Error(
                'sutore_marketplace_size',
                __('Select a valid size.', 'sutore-marketplace')
            );
        }

        foreach (self::termsForParent($parentId) as $term) {
            if ((int) $term['term_id'] === $termId) {
                return true;
            }
        }

        return new \WP_Error(
            'sutore_marketplace_size',
            __('Select a valid size for this product.', 'sutore-marketplace')
        );
    }

    public static function axisLabelForTaxonomy(string $taxonomy): string
    {
        if ($taxonomy === self::PRIMARY_SIZE_TAXONOMY || str_contains($taxonomy, 'beden')) {
            return __('Size', 'sutore-marketplace');
        }

        if (
            $taxonomy === 'pa_color'
            || str_contains($taxonomy, 'color')
            || str_contains($taxonomy, 'renk')
        ) {
            return __('Color', 'sutore-marketplace');
        }

        if (function_exists('wc_attribute_label')) {
            $label = wc_attribute_label($taxonomy);
            if (is_string($label) && $label !== '' && $label !== $taxonomy) {
                return $label;
            }
        }

        return __('Variation', 'sutore-marketplace');
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

        $term = get_term($termId, self::PRIMARY_SIZE_TAXONOMY);
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
