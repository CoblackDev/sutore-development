<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class PriceBreakdown
{
    /** @return array{name:string,quantity:int,product_price:string,service_cost:string,insurance_cost:string,total_price:string,total_raw:float} */
    public static function forListing(Listing $listing, string $productName, int $quantity = 1): array
    {
        $asking = MarketplacePricing::activeAsking($listing);
        $fees = MarketplacePricing::feeBreakdownForListing($listing);
        $unitTotal = MarketplacePricing::customerPrice($listing);
        $lineTotal = $unitTotal * max(1, $quantity);

        return [
            'name' => $productName,
            'quantity' => max(1, $quantity),
            'product_price' => wc_price($asking),
            'service_cost' => wc_price($fees['hizmet']),
            'insurance_cost' => wc_price($fees['guvence']),
            'total_price' => wc_price($lineTotal),
            'total_raw' => $lineTotal,
        ];
    }

    /** @return array{name:string,quantity:int,product_price:string,service_cost:string,insurance_cost:string,total_price:string,total_raw:float} */
    public static function forSimpleProduct(\WC_Product $product, int $quantity = 1): array
    {
        $qty = max(1, $quantity);
        $unitPrice = (float) $product->get_price();
        $lineTotal = $unitPrice * $qty;

        return [
            'name' => $product->get_name(),
            'quantity' => $qty,
            'product_price' => wc_price($unitPrice),
            'service_cost' => wc_price(0),
            'insurance_cost' => wc_price(0),
            'total_price' => wc_price($lineTotal),
            'total_raw' => $lineTotal,
        ];
    }

    /**
     * @return array{name:string,quantity:int,product_price:string,service_cost:string,insurance_cost:string,total_price:string,total_raw:float}
     */
    public static function forOrderItem(\WC_Order_Item_Product $item): array
    {
        $product = $item->get_product();
        $quantity = max(1, (int) $item->get_quantity());
        $lineTotal = (float) $item->get_total() + (float) $item->get_total_tax();
        $unitTotal = $quantity > 0 ? $lineTotal / $quantity : $lineTotal;

        if ($product instanceof \WC_Product) {
            $variationId = (int) $item->get_variation_id();
            if ($variationId > 0) {
                $listing = (new \SutoreMarketplace\Modules\Listings\Repositories\ListingRepository())->findByVariationId($variationId);
                if ($listing) {
                    $row = self::forListing($listing, $item->get_name(), $quantity);
                    $row['total_price'] = wc_price($lineTotal);
                    $row['total_raw'] = $lineTotal;

                    return $row;
                }
            }
        }

        return [
            'name' => $item->get_name(),
            'quantity' => $quantity,
            'product_price' => wc_price($unitTotal),
            'service_cost' => wc_price(0),
            'insurance_cost' => wc_price(0),
            'total_price' => wc_price($lineTotal),
            'total_raw' => $lineTotal,
        ];
    }
}
