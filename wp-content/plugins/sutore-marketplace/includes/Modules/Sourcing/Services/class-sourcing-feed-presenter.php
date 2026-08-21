<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Sourcing\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductListChrome;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Orders\Services\DeadlineCalculator;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class SourcingFeedPresenter
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly SourcingService $sourcingService = new SourcingService(),
    ) {
    }

    /**
     * Open pre-order board — listings with status pre_order linked to an order.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function presentForMerchant(
        int $merchantId,
        int $page = 1,
        int $perPage = 20,
        string $search = '',
        string $orderby = 'default'
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $search = trim($search);

        $priceSort = in_array($orderby, ['price_asc', 'price_desc'], true);
        $result = $this->listings->query([
            'status' => ListingStatus::PRE_ORDER,
            'page' => 1,
            'per_page' => 500,
            'orderby' => $priceSort ? 'asking' : 'created_at',
            'order' => $orderby === 'price_asc' ? 'ASC' : 'DESC',
        ]);
        $items = [];
        $pairs = [];
        foreach ($result['items'] as $listing) {
            if (!$listing->orderId) {
                continue;
            }
            $pairs[] = [(int) $listing->parentProductId, (int) $listing->sizeTermId];
        }
        $matchingByPair = $this->listings->findMatchingForPreOrder($merchantId, $pairs);
        $chrome = $this->chromeForListings($result['items']);
        $sizeLabels = ProductSizeLookup::labelsForTermIds(array_map(
            static fn (Listing $listing): int => (int) $listing->sizeTermId,
            $result['items']
        ));

        foreach ($result['items'] as $listing) {
            if (!$listing->orderId) {
                continue;
            }
            if (!ListingPolicy::canViewPreOrderListing((string) ($listing->createdAt ?? ''), $merchantId)) {
                continue;
            }
            $items[] = $this->enrichListing($listing, $merchantId, $matchingByPair, $chrome, $sizeLabels);
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, static function (array $item) use ($needle): bool {
                $haystacks = [
                    (string) ($item['parent_title'] ?? ''),
                    (string) ($item['product_code'] ?? ''),
                    (string) ($item['size_label'] ?? ''),
                ];
                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if ($priceSort) {
            usort($items, static function (array $a, array $b) use ($orderby): int {
                $left = (float) ($a['offer_asking'] ?? 0);
                $right = (float) ($b['offer_asking'] ?? 0);
                if ($left === $right) {
                    return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
                }

                return $orderby === 'price_asc' ? ($left <=> $right) : ($right <=> $left);
            });
        }

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($items, $offset, $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function presentOneForMerchant(int $preOrderVariationId, int $merchantId): ?array
    {
        $listing = $this->listings->find($preOrderVariationId);
        if (!$listing || $listing->listingStatus !== ListingStatus::PRE_ORDER || !$listing->orderId) {
            return null;
        }
        if (!ListingPolicy::canViewPreOrderListing((string) ($listing->createdAt ?? ''), $merchantId)) {
            return null;
        }

        $matchingByPair = $this->listings->findMatchingForPreOrder(
            $merchantId,
            [[(int) $listing->parentProductId, (int) $listing->sizeTermId]]
        );
        $chrome = $this->chromeForListings([$listing]);
        $sizeLabels = ProductSizeLookup::labelsForTermIds([(int) $listing->sizeTermId]);

        return $this->enrichListing($listing, $merchantId, $matchingByPair, $chrome, $sizeLabels);
    }

    /**
     * @param list<Listing> $listings
     * @return array<int, array{title:string,code:string,thumbnail:string,permalink:string}>
     */
    private function chromeForListings(array $listings): array
    {
        $ids = [];
        foreach ($listings as $listing) {
            $ids[] = (int) $listing->parentProductId;
            if ((int) $listing->variationId > 0) {
                $ids[] = (int) $listing->variationId;
            }
        }

        return ProductListChrome::mapForIds($ids);
    }

    /**
     * @param array<string, Listing> $matchingByPair
     * @param array<int, array{title:string,code:string,thumbnail:string,permalink:string}> $chrome
     * @param array<int, string> $sizeLabels
     * @return array<string, mixed>
     */
    private function enrichListing(
        Listing $listing,
        int $merchantId,
        array $matchingByPair = [],
        array $chrome = [],
        array $sizeLabels = []
    ): array {
        $parentId = (int) $listing->parentProductId;
        $sizeTermId = (int) $listing->sizeTermId;
        $sizeLabel = $sizeLabels[$sizeTermId] ?? ProductSizeLookup::labelForTermId($sizeTermId);
        $parentChrome = $chrome[$parentId] ?? [
            'title' => '',
            'code' => '',
            'thumbnail' => '',
            'permalink' => '',
        ];

        $offerAsking = $this->sourcingService->askingForPreOrder($listing);
        $commissionPercent = MerchantLevels::commissionPercentForUser($merchantId);
        $estimatedNet = MarketplacePricing::netFromAsking($offerAsking, $commissionPercent);

        $deliverySeconds = OrderSettings::cargoDeadlineSecondsForShipmentType('standard');
        $deliveryAt = DeadlineCalculator::fromNow($deliverySeconds);
        $deliveryTs = strtotime($deliveryAt) ?: (current_time('timestamp') + $deliverySeconds);
        $deliveryDisplay = wp_date(get_option('date_format') ?: 'j F Y', $deliveryTs);

        $matching = null;
        if ($parentId && $sizeTermId) {
            $pick = $matchingByPair[$parentId . ':' . $sizeTermId] ?? null;
            if ($pick instanceof Listing) {
                $matching = [
                    'variation_id' => $pick->variationId,
                    'asking' => $pick->asking,
                    'asking_display' => MarketplacePricing::formatTl((float) $pick->asking),
                ];
            }
        }

        return [
            'id' => (int) $listing->variationId,
            'variation_id' => (int) $listing->variationId,
            'order_id' => (int) ($listing->orderId ?? 0),
            'order_item_id' => (int) ($listing->orderItemId ?? 0),
            'parent_product_id' => $parentId,
            'size_term_id' => $sizeTermId,
            'parent_title' => $parentChrome['title'] !== '' ? $parentChrome['title'] : get_the_title($parentId),
            'product_code' => $parentChrome['code'],
            'thumbnail' => $parentChrome['thumbnail'],
            'size_label' => $sizeLabel,
            'permalink' => $parentChrome['permalink'] !== '' ? $parentChrome['permalink'] : (get_permalink($parentId) ?: ''),
            'created_at' => (string) ($listing->createdAt ?? ''),
            'offer_asking' => $offerAsking,
            'offer_asking_display' => MarketplacePricing::formatTl((float) $offerAsking),
            'estimated_net' => (float) round($estimatedNet, 2),
            'estimated_net_display' => MarketplacePricing::formatTl((float) $estimatedNet),
            'delivery_deadline_at' => $deliveryAt,
            'delivery_deadline_display' => $deliveryDisplay,
            'matching_listing' => $matching,
            'is_own' => (int) $listing->merchantId === $merchantId,
        ];
    }
}
