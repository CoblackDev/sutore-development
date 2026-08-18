<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Hooks;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingConditionRank;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;

final class ProductConditionHooks
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('woocommerce_get_item_data', [$this, 'filterCartItemData'], 20, 2);
        add_action('woocommerce_blocks_loaded', [$this, 'registerStoreApi']);
    }

    public function enqueueAssets(): void
    {
        if (!is_product() && !is_shop() && !is_product_taxonomy() && !is_cart() && !is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'sutore-marketplace-pdp-conditions',
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-pdp-conditions.css',
            [],
            (string) (is_file(SUTORE_MARKETPLACE_PATH . 'assets/css/marketplace-pdp-conditions.css')
                ? (int) filemtime(SUTORE_MARKETPLACE_PATH . 'assets/css/marketplace-pdp-conditions.css')
                : SUTORE_MARKETPLACE_VERSION)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $itemData
     * @param array<string, mixed> $cartItem
     * @return array<int, array<string, mixed>>
     */
    public function filterCartItemData(array $itemData, array $cartItem): array
    {
        $product = $cartItem['data'] ?? null;
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return $itemData;
        }

        $listing = $this->listingForProduct($product);
        $labels = $listing ? ListingConditionRank::activeLabels($listing) : [];
        if ($labels === []) {
            return $itemData;
        }

        $itemData[] = [
            'key' => __('Condition', 'sutore-marketplace'),
            'name' => __('Condition', 'sutore-marketplace'),
            'value' => implode(', ', $labels),
            'display' => implode(', ', $labels),
        ];

        return $itemData;
    }

    public function registerStoreApi(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'product',
            'namespace' => 'sutore-marketplace',
            'data_callback' => [$this, 'storeApiProductData'],
            'schema_callback' => [$this, 'storeApiSchema'],
            'schema_type' => ARRAY_A,
        ]);

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'cart-item',
            'namespace' => 'sutore-marketplace',
            'data_callback' => [$this, 'storeApiCartItemData'],
            'schema_callback' => [$this, 'storeApiSchema'],
            'schema_type' => ARRAY_A,
        ]);
    }

    /**
     * @return array{conditions: list<string>, condition_labels: list<string>}
     */
    public function storeApiProductData($product): array
    {
        return $this->payload($this->listingForProduct($product));
    }

    /**
     * @param array<string, mixed>|object $cartItem
     * @return array{conditions: list<string>, condition_labels: list<string>}
     */
    public function storeApiCartItemData(array|object $cartItem): array
    {
        $item = is_array($cartItem) ? $cartItem : (array) $cartItem;
        $product = $item['data'] ?? null;

        return $this->payload($this->listingForProduct($product));
    }

    /**
     * @return array<string, mixed>
     */
    public function storeApiSchema(): array
    {
        return [
            'conditions' => [
                'description' => __('Active defect keys for the on-sale product.', 'sutore-marketplace'),
                'type' => 'array',
                'items' => ['type' => 'string'],
                'context' => ['view', 'edit'],
                'readonly' => true,
            ],
            'condition_labels' => [
                'description' => __('Translated defect labels for the on-sale product.', 'sutore-marketplace'),
                'type' => 'array',
                'items' => ['type' => 'string'],
                'context' => ['view', 'edit'],
                'readonly' => true,
            ],
        ];
    }

    /**
     * @return array{conditions: list<string>, condition_labels: list<string>}
     */
    private function payload(?Listing $listing): array
    {
        if (!$listing) {
            return ['conditions' => [], 'condition_labels' => []];
        }

        return [
            'conditions' => ListingConditionRank::activeKeys($listing),
            'condition_labels' => ListingConditionRank::activeLabels($listing),
        ];
    }

    private function listingForProduct(mixed $product): ?Listing
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return null;
        }

        if (method_exists($product, 'is_type') && ($product->is_type('variation') || (int) $product->get_parent_id() > 0)) {
            return $this->listings->findByVariationId((int) $product->get_id());
        }

        return $this->listings->getCheapestWinnerForParent((int) $product->get_id());
    }
}
