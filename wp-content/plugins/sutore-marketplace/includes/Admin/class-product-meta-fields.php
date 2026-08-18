<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

use SutoreMarketplace\Shared\Domain\ReleasePriceService;

final class ProductMetaFields
{
    public function register(): void
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'addTab']);
        add_action('woocommerce_product_data_panels', [$this, 'renderPanel']);
        add_action('woocommerce_process_product_meta', [$this, 'save']);
    }

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public function addTab(array $tabs): array
    {
        $tabs['sutore_marketplace'] = [
            'label' => __('Sutore', 'sutore-marketplace'),
            'target' => 'sutore_marketplace_product_data',
            'class' => ['show_if_simple', 'show_if_variable'],
            'priority' => 65,
        ];

        return $tabs;
    }

    public function renderPanel(): void
    {
        echo '<div id="sutore_marketplace_product_data" class="panel woocommerce_options_panel hidden">';
        echo '<div class="options_group">';

        woocommerce_wp_text_input([
            'id' => ReleasePriceService::META_RETAIL_USD,
            'label' => __('Exit price (USD)', 'sutore-marketplace'),
            'desc_tip' => true,
            'description' => __('Parent product original release / list price (USD). Shown on the Product form; confirmation is required if the bid is below this.', 'sutore-marketplace'),
            'type' => 'number',
            'custom_attributes' => [
                'step' => '0.01',
                'min' => '0',
            ],
        ]);

        woocommerce_wp_text_input([
            'id' => ReleasePriceService::META_RELEASE_YEAR,
            'label' => __('Release year', 'sutore-marketplace'),
            'desc_tip' => true,
            'description' => __('The product’s original release year (e.g. 2024).', 'sutore-marketplace'),
            'type' => 'number',
            'custom_attributes' => [
                'step' => '1',
                'min' => '1900',
                'max' => '2100',
            ],
        ]);

        echo '</div>';
        echo '</div>';
    }

    public function save(int $postId): void
    {
        $product = wc_get_product($postId);
        if (!$product || $product->is_type('variation')) {
            return;
        }

        if (isset($_POST[ReleasePriceService::META_RETAIL_USD])) {
            $usd = (float) wc_clean(wp_unslash((string) $_POST[ReleasePriceService::META_RETAIL_USD]));
            if ($usd > 0) {
                update_post_meta($postId, ReleasePriceService::META_RETAIL_USD, $usd);
            } else {
                delete_post_meta($postId, ReleasePriceService::META_RETAIL_USD);
            }
        }

        if (isset($_POST[ReleasePriceService::META_RELEASE_YEAR])) {
            $year = (int) wc_clean(wp_unslash((string) $_POST[ReleasePriceService::META_RELEASE_YEAR]));
            if ($year >= 1900 && $year <= 2100) {
                update_post_meta($postId, ReleasePriceService::META_RELEASE_YEAR, $year);
            } else {
                delete_post_meta($postId, ReleasePriceService::META_RELEASE_YEAR);
            }
        }
    }
}
