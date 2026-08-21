<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Services\CommissionResolver;
use SutoreMarketplace\Modules\Orders\Hooks\OrderItemPricingMetaHooks;
use SutoreMarketplace\Shared\Services\YouthDiscount;

/**
 * Allocates the order-level youth discount against hizmet → güvence → commission.
 *
 * @phpstan-type Allocated array{hizmet: float, guvence: float, commission: float}
 */
final class YouthInvoiceAllocator
{
    /**
     * @param list<int> $poolVariationIds When non-empty, youth is split only across these listings.
     * @return Allocated
     */
    public function forListing(Listing $listing, \WC_Order $order, array $poolVariationIds = []): array
    {
        $hizmet = 0.0;
        $guvence = 0.0;
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            if ((int) $item->get_variation_id() !== (int) $listing->variationId) {
                continue;
            }
            $hizmet = (float) $item->get_meta(OrderItemPricingMetaHooks::META_HIZMET, true);
            $guvence = (float) $item->get_meta(OrderItemPricingMetaHooks::META_GUVENCE, true);
            break;
        }
        if ($hizmet <= 0 && $guvence <= 0) {
            $fees = \SutoreMarketplace\Shared\Domain\MarketplacePricing::feeBreakdownForListing($listing);
            $hizmet = (float) $fees['hizmet'];
            $guvence = (float) $fees['guvence'];
        }

        $asking = \SutoreMarketplace\Shared\Domain\MarketplacePricing::activeAsking($listing);
        $payout = (new \SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository())
            ->findByVariationAndOrder((int) $listing->variationId, (int) $order->get_id());
        if ($payout) {
            $commission = round((float) ($payout->commission_amount ?? 0), 2);
        } else {
            $percent = (new CommissionResolver())->percentForPayout($listing);
            $commission = round($asking * max(0.0, $percent) / 100, 2);
        }

        $youth = (float) $order->get_meta(YouthDiscount::ORDER_META_AMOUNT, true);
        if ($youth < 0.01) {
            return $this->roundTriplet($hizmet, $guvence, $commission);
        }

        $pools = $this->poolsForOrder($order, $poolVariationIds);
        $totalPool = array_sum($pools);
        $listingPool = $pools[(int) $listing->variationId] ?? ($hizmet + $guvence + $commission);
        $share = $totalPool > 0 ? round($youth * ($listingPool / $totalPool), 2) : 0.0;

        $hizmetCut = min($hizmet, $share);
        $hizmet = round($hizmet - $hizmetCut, 2);
        $share = round($share - $hizmetCut, 2);

        $guvenceCut = min($guvence, $share);
        $guvence = round($guvence - $guvenceCut, 2);
        $share = round($share - $guvenceCut, 2);

        $commissionCut = min($commission, $share);
        $commission = round($commission - $commissionCut, 2);

        return $this->roundTriplet($hizmet, $guvence, $commission);
    }

    /**
     * @param list<int> $onlyVariationIds When non-empty, only these listings appear (remaining billable items).
     * @return array{hizmet: float, guvence: float, lines: list<array{variation_id:int,title:string,code:string,amount:float}>}
     */
    public function customerLinesForOrder(\WC_Order $order, array $onlyVariationIds = []): array
    {
        $lines = [];
        $hizmet = 0.0;
        $guvence = 0.0;
        $seen = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $onlyVariationIds))));

        if ($ids === []) {
            foreach ($order->get_items() as $item) {
                if (!$item instanceof \WC_Order_Item_Product) {
                    continue;
                }
                $variationId = (int) $item->get_variation_id();
                if ($variationId <= 0) {
                    $variationId = (int) $item->get_product_id();
                }
                if ($variationId > 0) {
                    $ids[] = $variationId;
                }
            }
            $ids = array_values(array_unique($ids));
        }

        foreach ($ids as $variationId) {
            if ($variationId <= 0 || isset($seen[$variationId])) {
                continue;
            }
            $listing = (new ListingRepository())->find($variationId);
            if (!$listing instanceof Listing) {
                continue;
            }
            $seen[$variationId] = true;
            $allocated = $this->forListing($listing, $order, $ids);
            $title = \SutoreMarketplace\Modules\Orders\Services\Notifications::productTitle(
                $variationId,
                $variationId,
                (int) $listing->parentProductId,
                (int) $listing->sizeTermId
            );
            if ($allocated['hizmet'] >= 0.01) {
                $lines[] = [
                    'variation_id' => $variationId,
                    'title' => $title,
                    'code' => 'hizmet',
                    'amount' => $allocated['hizmet'],
                ];
                $hizmet = round($hizmet + $allocated['hizmet'], 2);
            }
            if ($allocated['guvence'] >= 0.01) {
                $lines[] = [
                    'variation_id' => $variationId,
                    'title' => $title,
                    'code' => 'guvence',
                    'amount' => $allocated['guvence'],
                ];
                $guvence = round($guvence + $allocated['guvence'], 2);
            }
        }

        return [
            'hizmet' => $hizmet,
            'guvence' => $guvence,
            'lines' => $lines,
        ];
    }

    /**
     * @param list<int> $onlyVariationIds
     * @return array<int, float>
     */
    private function poolsForOrder(\WC_Order $order, array $onlyVariationIds = []): array
    {
        $allow = [];
        foreach ($onlyVariationIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $allow[$id] = true;
            }
        }

        $variationIds = [];
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $variationId = (int) $item->get_variation_id();
                if ($allow === [] || isset($allow[$variationId])) {
                    $variationIds[] = $variationId;
                }
            }
        }
        foreach (array_keys($allow) as $variationId) {
            $variationIds[] = $variationId;
        }
        $listings = (new ListingRepository())->findByVariationIds($variationIds);
        $resolver = new CommissionResolver();
        $pools = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $variationId = (int) $item->get_variation_id();
            if ($allow !== [] && !isset($allow[$variationId])) {
                continue;
            }
            $listing = $listings[$variationId] ?? null;
            if (!$listing instanceof Listing) {
                continue;
            }
            $hizmet = (float) $item->get_meta(OrderItemPricingMetaHooks::META_HIZMET, true);
            $guvence = (float) $item->get_meta(OrderItemPricingMetaHooks::META_GUVENCE, true);
            $payout = (new \SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository())
                ->findByVariationAndOrder($variationId, (int) $order->get_id());
            if ($payout) {
                $commission = round((float) ($payout->commission_amount ?? 0), 2);
            } else {
                $asking = \SutoreMarketplace\Shared\Domain\MarketplacePricing::activeAsking($listing);
                $commission = round($asking * max(0.0, $resolver->percentForPayout($listing)) / 100, 2);
            }
            $pools[$variationId] = round($hizmet + $guvence + $commission, 2);
        }

        foreach (array_keys($allow) as $variationId) {
            if (isset($pools[$variationId])) {
                continue;
            }
            $listing = $listings[$variationId] ?? null;
            if (!$listing instanceof Listing) {
                continue;
            }
            $fees = \SutoreMarketplace\Shared\Domain\MarketplacePricing::feeBreakdownForListing($listing);
            $payout = (new \SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository())
                ->findByVariationAndOrder($variationId, (int) $order->get_id());
            if ($payout) {
                $commission = round((float) ($payout->commission_amount ?? 0), 2);
            } else {
                $asking = \SutoreMarketplace\Shared\Domain\MarketplacePricing::activeAsking($listing);
                $commission = round($asking * max(0.0, $resolver->percentForPayout($listing)) / 100, 2);
            }
            $pools[$variationId] = round((float) $fees['hizmet'] + (float) $fees['guvence'] + $commission, 2);
        }

        return $pools;
    }

    /**
     * @return Allocated
     */
    private function roundTriplet(float $hizmet, float $guvence, float $commission): array
    {
        return [
            'hizmet' => max(0.0, round($hizmet, 2)),
            'guvence' => max(0.0, round($guvence, 2)),
            'commission' => max(0.0, round($commission, 2)),
        ];
    }
}
