<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Sourcing\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Orders\Services\DeadlineCalculator;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Modules\Sourcing\Repositories\SourcingRepository;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class SourcingFeedPresenter
{
    public function __construct(
        private readonly SourcingRepository $sourcing = new SourcingRepository(),
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly SourcingService $sourcingService = new SourcingService(),
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function presentForMerchant(
        int $merchantId,
        int $page = 1,
        int $perPage = 20,
        ?string $status = null,
        string $search = '',
        string $orderby = 'default'
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $search = trim($search);
        $queryArgs = [
            'merchant_feed' => $merchantId,
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => $orderby,
        ];
        if ($status !== null && $status !== '') {
            $queryArgs['status'] = $status;
        }

        $priceSort = in_array($orderby, ['price_asc', 'price_desc'], true);
        $needsMemoryPage = $priceSort || $search !== '';
        if ($needsMemoryPage) {
            $queryArgs['orderby'] = $priceSort ? 'default' : $orderby;
            $queryArgs['page'] = 1;
            $queryArgs['per_page'] = 500;
        }

        $result = $this->sourcing->query($queryArgs);

        $pairs = [];
        foreach ($result['items'] as $row) {
            if ((string) ($row->status ?? '') !== 'open') {
                continue;
            }
            $parentId = (int) ($row->parent_product_id ?? 0);
            $sizeTermId = (int) ($row->size_term_id ?? 0);
            if ($parentId > 0 && $sizeTermId > 0) {
                $pairs[] = [$parentId, $sizeTermId];
            }
        }
        $matchingByPair = $this->listings->findMatchingForSourcing($merchantId, $pairs);

        $items = [];
        foreach ($result['items'] as $row) {
            $items[] = $this->enrichRow($row, $merchantId, $matchingByPair);
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

        if ($needsMemoryPage) {
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

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Single request for merchant feed (open board or this merchant’s accepted rows).
     *
     * @return array<string, mixed>|null
     */
    public function presentOneForMerchant(int $requestId, int $merchantId): ?array
    {
        $row = $this->sourcing->find($requestId);
        if (!$row) {
            return null;
        }

        $status = (string) ($row->status ?? '');
        $acceptedBy = (int) ($row->accepted_merchant_id ?? 0);
        if ($status !== 'open' && $acceptedBy !== $merchantId) {
            return null;
        }

        $matchingByPair = [];
        if ($status === 'open') {
            $parentId = (int) ($row->parent_product_id ?? 0);
            $sizeTermId = (int) ($row->size_term_id ?? 0);
            if ($parentId > 0 && $sizeTermId > 0) {
                $matchingByPair = $this->listings->findMatchingForSourcing($merchantId, [[$parentId, $sizeTermId]]);
            }
        }

        return $this->enrichRow($row, $merchantId, $matchingByPair);
    }

    /**
     * @param array<string, Listing> $matchingByPair
     * @return array<string, mixed>
     */
    private function enrichRow(object $row, int $merchantId, array $matchingByPair = []): array
    {
        $parentId = (int) $row->parent_product_id;
        $sizeTermId = (int) ($row->size_term_id ?? 0);
        $sizeLabel = ProductSizeLookup::labelForTermId($sizeTermId);

        $offerAsking = $this->sourcingService->askingForRequest($row);
        $commissionPercent = MerchantLevels::commissionPercentForUser($merchantId);
        $estimatedNet = MarketplacePricing::netFromAsking($offerAsking, $commissionPercent);

        $deliverySeconds = OrderSettings::cargoDeadlineSecondsForShipmentType('standard');
        $deliveryAt = DeadlineCalculator::fromNow($deliverySeconds);
        $deliveryTs = strtotime($deliveryAt) ?: (current_time('timestamp') + $deliverySeconds);
        $deliveryDisplay = date_i18n(get_option('date_format') ?: 'j F Y', $deliveryTs);

        $matching = null;
        if ((string) $row->status === 'open' && $parentId && $sizeTermId) {
            $pick = $matchingByPair[$parentId . ':' . $sizeTermId] ?? null;
            if ($pick instanceof Listing) {
                $matching = [
                    'listing_id' => $pick->id,
                    'asking' => $pick->asking,
                    'asking_display' => MarketplacePricing::formatTl((float) $pick->asking),
                    'variation_id' => $pick->variationId,
                ];
            }
        }

        return [
            'id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'order_item_id' => (int) ($row->order_item_id ?? 0),
            'parent_product_id' => $parentId,
            'size_term_id' => $sizeTermId,
            'status' => (string) $row->status,
            'accepted_merchant_id' => isset($row->accepted_merchant_id) ? (int) $row->accepted_merchant_id : null,
            'parent_title' => get_the_title($parentId),
            'product_code' => ProductCodeLookup::codeForProduct($parentId),
            'thumbnail' => ProductThumbnail::url($parentId),
            'size_label' => $sizeLabel,
            'permalink' => get_permalink($parentId) ?: '',
            'created_at' => (string) ($row->created_at ?? ''),
            'offer_asking' => $offerAsking,
            'offer_asking_display' => MarketplacePricing::formatTl((float) $offerAsking),
            'estimated_net' => (float) round($estimatedNet, 2),
            'estimated_net_display' => MarketplacePricing::formatTl((float) $estimatedNet),
            'delivery_deadline_at' => $deliveryAt,
            'delivery_deadline_display' => $deliveryDisplay,
            'matching_listing' => $matching,
            'is_mine' => (int) ($row->accepted_merchant_id ?? 0) === $merchantId,
            'can_accept' => (string) $row->status === 'open',
        ];
    }
}
