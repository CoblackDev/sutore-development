<?php

declare(strict_types=1);

/**
 * Shared WC catalog helpers for marketplace seed scripts.
 * Aligns with ProductSizeLookup axis rules (A2) and PRIMARY_SIZE_TAXONOMY constant.
 */

use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;

function seed_catalog_primary_taxonomy(): string
{
    return ProductSizeLookup::PRIMARY_SIZE_TAXONOMY;
}

function seed_catalog_ensure_taxonomy(string $taxonomy, string $attributeName, string $attributeSlug): void
{
    if (!taxonomy_exists($taxonomy)) {
        if (function_exists('wc_create_attribute')) {
            wc_create_attribute([
                'name' => $attributeName,
                'slug' => $attributeSlug,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);
            delete_transient('wc_attribute_taxonomies');
        }
        register_taxonomy($taxonomy, 'product', [
            'label' => $attributeName,
            'hierarchical' => false,
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => false,
        ]);
    }
}

function seed_catalog_ensure_term(string $taxonomy, string $slug, string $name): WP_Term
{
    $term = term_exists($slug, $taxonomy);
    if (!$term) {
        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        if (is_wp_error($created)) {
            throw new RuntimeException(
                sprintf('Term failed (%s/%s): %s', $taxonomy, $slug, $created->get_error_message())
            );
        }
        $termId = (int) $created['term_id'];
    } else {
        $termId = (int) (is_array($term) ? $term['term_id'] : $term);
    }

    $obj = get_term($termId, $taxonomy);
    if (!$obj || is_wp_error($obj)) {
        throw new RuntimeException(sprintf('Term missing after create: %s/%s', $taxonomy, $slug));
    }

    return $obj;
}

/**
 * @param list<WP_Term> $terms
 */
function seed_catalog_create_variable_parent(
    string $name,
    string $code,
    string $taxonomy,
    array $terms,
    string $seedMeta
): int {
    if ($terms === []) {
        throw new RuntimeException('Variable parent requires at least one term: ' . $name);
    }

    $product = new WC_Product_Variable();
    $product->set_name($name);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description('Scenario seed product (' . $code . ').');
    $product->set_sku('SEED-' . $code . '-' . wp_generate_password(4, false));
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');

    $ids = array_map(static fn (WP_Term $t): int => (int) $t->term_id, $terms);
    $attribute = new WC_Product_Attribute();
    $attributeId = wc_attribute_taxonomy_id_by_name($taxonomy);
    if ($attributeId) {
        $attribute->set_id($attributeId);
    }
    $attribute->set_name($taxonomy);
    $attribute->set_options($ids);
    $attribute->set_visible(true);
    $attribute->set_variation(true);
    $product->set_attributes([$attribute]);
    $parentId = (int) $product->save();
    if ($parentId <= 0) {
        throw new RuntimeException('Parent create failed: ' . $name);
    }

    wp_set_object_terms($parentId, $ids, $taxonomy);
    update_post_meta($parentId, $seedMeta, '1');
    update_post_meta($parentId, 'urun_kodu', $code);
    update_post_meta($parentId, '_sutore_release_price', (string) max(100, count($ids) * 100));

    return $parentId;
}

function seed_catalog_ensure_size_term(string $slug, string $name): WP_Term
{
    seed_catalog_ensure_taxonomy(
        seed_catalog_primary_taxonomy(),
        'Beden / Numara',
        'beden-numara'
    );

    return seed_catalog_ensure_term(seed_catalog_primary_taxonomy(), $slug, $name);
}

function seed_catalog_ensure_color_term(string $slug, string $name): WP_Term
{
    seed_catalog_ensure_taxonomy('pa_color', 'Color', 'color');

    return seed_catalog_ensure_term('pa_color', $slug, $name);
}
