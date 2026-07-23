<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Services;

use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;
use SutoreMarketplace\Modules\Orders\Services\MerchantSnapshot;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;

final class MerchantResolver
{
    /** @return list<array{merchant_id:int,fullname:string,city:string,is_platform:bool,items:list<array<string,mixed>>}> */
    public static function fromCart(): array
    {
        if (!function_exists('WC') || !WC()->cart) {
            return [];
        }

        $groups = [];

        foreach (WC()->cart->get_cart() as $cartItem) {
            $product = $cartItem['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $variationId = (int) ($cartItem['variation_id'] ?? 0);
            $quantity = max(1, (int) ($cartItem['quantity'] ?? 1));
            $groupKey = self::resolveGroupKey($product, $variationId);
            $meta = self::resolveMerchantMeta($product, $variationId);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'merchant_id' => $meta['merchant_id'],
                    'fullname' => $meta['fullname'],
                    'city' => $meta['city'],
                    'is_platform' => $meta['is_platform'],
                    'items' => [],
                ];
            }

            $groups[$groupKey]['items'][] = self::buildItemRow($product, $variationId, $product->get_name(), $quantity);
        }

        return array_values($groups);
    }

    /** @return list<array{merchant_id:int,fullname:string,city:string,is_platform:bool,items:list<array<string,mixed>>}> */
    public static function fromOrder(\WC_Order $order): array
    {
        $groups = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $variationId = (int) $item->get_variation_id();
            $groupKey = self::resolveGroupKey($product, $variationId);
            $meta = self::resolveMerchantMeta($product, $variationId);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'merchant_id' => $meta['merchant_id'],
                    'fullname' => $meta['fullname'],
                    'city' => $meta['city'],
                    'is_platform' => $meta['is_platform'],
                    'items' => [],
                ];
            }

            $groups[$groupKey]['items'][] = PriceBreakdown::forOrderItem($item);
        }

        return array_values($groups);
    }

    private static function resolveGroupKey(\WC_Product $product, int $variationId): string
    {
        if ($variationId > 0) {
            $listing = (new ListingRepository())->findByVariationId($variationId);
            if ($listing) {
                return 'merchant_' . $listing->merchantId;
            }
        }

        if ($product->is_type('simple')) {
            return 'platform_' . (int) get_post_field('post_author', $product->get_id());
        }

        return 'product_' . $product->get_id();
    }

    /** @return array{merchant_id:int,fullname:string,city:string,is_platform:bool} */
    private static function resolveMerchantMeta(\WC_Product $product, int $variationId): array
    {
        if ($variationId > 0) {
            $listing = (new ListingRepository())->findByVariationId($variationId);
            if ($listing) {
                $snapshot = MerchantSnapshot::capture($listing->merchantId);

                return [
                    'merchant_id' => $listing->merchantId,
                    'fullname' => $snapshot['name'] !== '' ? $snapshot['name'] : __('Seller', 'sutore-marketplace'),
                    'city' => self::formatCity($snapshot['city'], $snapshot['state']),
                    'is_platform' => false,
                ];
            }
        }

        if (!$product->is_type('simple')) {
            $merchantId = (int) get_post_field('post_author', $product->get_id());
            $snapshot = MerchantSnapshot::capture($merchantId);

            return [
                'merchant_id' => $merchantId,
                'fullname' => $snapshot['name'] !== '' ? $snapshot['name'] : __('Seller', 'sutore-marketplace'),
                'city' => self::formatCity($snapshot['city'], $snapshot['state']),
                'is_platform' => false,
            ];
        }

        return [
            'merchant_id' => (int) get_post_field('post_author', $product->get_id()),
            'fullname' => ContractSettings::PLATFORM_SELLER_NAME,
            'city' => ContractSettings::PLATFORM_SELLER_CITY,
            'is_platform' => true,
        ];
    }

    /** @return array<string,mixed> */
    private static function buildItemRow(\WC_Product $product, int $variationId, string $name, int $quantity): array
    {
        if ($variationId > 0) {
            $listing = (new ListingRepository())->findByVariationId($variationId);
            if ($listing) {
                return PriceBreakdown::forListing($listing, $name, $quantity);
            }
        }

        return PriceBreakdown::forSimpleProduct($product, $quantity);
    }

    private static function formatCity(string $cityCode, string $stateCode): string
    {
        if ($cityCode === '' && $stateCode === '') {
            return '';
        }

        if (function_exists('WC') && WC()->countries) {
            $states = WC()->countries->get_states('TR');
            if (is_array($states)) {
                if ($cityCode !== '' && isset($states[$cityCode])) {
                    return (string) $states[$cityCode];
                }
                if ($stateCode !== '' && isset($states[$stateCode])) {
                    return (string) $states[$stateCode];
                }
            }
        }

        return $cityCode !== '' ? $cityCode : $stateCode;
    }
}
