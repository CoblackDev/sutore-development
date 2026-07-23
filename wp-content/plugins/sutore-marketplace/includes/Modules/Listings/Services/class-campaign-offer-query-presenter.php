<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\CampaignOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class CampaignOfferQueryPresenter
{
    public function __construct(
        private readonly CampaignOfferRepository $offers = new CampaignOfferRepository(),
        private readonly CampaignRepository $campaigns = new CampaignRepository(),
        private readonly ListingRepository $listings = new ListingRepository(),
    ) {
    }

    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int
     * }
     */
    public function presentForMerchant(
        int $userId,
        ?string $status,
        int $page,
        int $perPage,
        string $orderby = 'created_desc'
    ): array {
        $offset = ($page - 1) * $perPage;
        $rows = $this->offers->findForMerchant($userId, $status, $perPage, $offset, $orderby);
        $total = $this->offers->countForMerchant($userId, $status);

        $campaignIds = [];
        $listingIds = [];
        foreach ($rows as $row) {
            $campaignIds[] = (int) $row->campaign_id;
            $listingIds[] = (int) $row->listing_id;
        }

        $campaignCache = $this->campaigns->findByIds($campaignIds);
        $listingCache = $this->listings->findByIds($listingIds);
        $this->primeProducts($listingCache);

        $items = [];
        foreach ($rows as $row) {
            $campaignId = (int) $row->campaign_id;
            $campaign = $campaignCache[$campaignId] ?? null;
            $listing = $listingCache[(int) $row->listing_id] ?? null;
            $parentId = $listing ? (int) $listing->parentProductId : 0;
            $productTitle = $listing
                ? (get_the_title($parentId) ?: __('Product', 'sutore-marketplace'))
                : __('Product', 'sutore-marketplace');
            if ($listing && $listing->variationId) {
                $variation = wc_get_product($listing->variationId);
                if ($variation) {
                    $productTitle = (string) $variation->get_name();
                }
            }

            $math = MarketplacePricing::campaignAcceptMath(
                (float) $row->asking_before,
                (float) $row->seller_discount,
                (float) $row->platform_discount
            );

            $sellerType = CampaignDiscountType::normalize(
                isset($row->seller_discount_type) ? (string) $row->seller_discount_type : CampaignDiscountType::FIXED
            );
            $platformType = CampaignDiscountType::normalize(
                isset($row->platform_discount_type) ? (string) $row->platform_discount_type : CampaignDiscountType::FIXED
            );
            $sellerValue = isset($row->seller_discount_value)
                ? (float) $row->seller_discount_value
                : (float) $row->seller_discount;
            $platformValue = isset($row->platform_discount_value)
                ? (float) $row->platform_discount_value
                : (float) $row->platform_discount;

            $items[] = [
                'id' => (int) $row->id,
                'campaign_id' => $campaignId,
                'campaign_name' => $campaign ? (string) $campaign->name : '',
                'listing_id' => (int) $row->listing_id,
                'variation_id' => $listing ? (int) $listing->variationId : 0,
                'parent_product_id' => $parentId,
                'product_title' => $productTitle,
                'product_code' => $parentId > 0 ? ProductCodeLookup::codeForProduct($parentId) : '',
                'size_label' => $listing
                    ? ProductSizeLookup::labelForTermId((int) $listing->sizeTermId)
                    : '',
                'thumbnail' => $parentId > 0 ? ProductThumbnail::url($parentId) : '',
                'permalink' => $parentId > 0 ? (get_permalink($parentId) ?: '') : '',
                'is_sourcing' => $listing ? $listing->sourcingRequestId !== null : false,
                'status' => (string) $row->status,
                'status_label' => CampaignOfferStatus::label((string) $row->status),
                'asking_before' => (float) $row->asking_before,
                'seller_discount_type' => $sellerType,
                'seller_discount_value' => $sellerValue,
                'seller_discount' => (float) $row->seller_discount,
                'seller_discount_label' => CampaignDiscountType::offerLabel(
                    $sellerType,
                    $sellerValue,
                    (float) $row->seller_discount
                ),
                'platform_discount_type' => $platformType,
                'platform_discount_value' => $platformValue,
                'platform_discount' => (float) $row->platform_discount,
                'platform_discount_label' => CampaignDiscountType::offerLabel(
                    $platformType,
                    $platformValue,
                    (float) $row->platform_discount
                ),
                'asking_effective' => $math['asking_effective'],
                'starts_at' => $campaign ? ($campaign->starts_at ?? null) : null,
                'ends_at' => $campaign ? ($campaign->ends_at ?? null) : null,
                'starts_at_label' => $campaign && !empty($campaign->starts_at)
                    ? CampaignDatetime::formatLabel((string) $campaign->starts_at)
                    : '',
                'ends_at_label' => $campaign && !empty($campaign->ends_at)
                    ? CampaignDatetime::formatLabel((string) $campaign->ends_at)
                    : '',
                'created_at' => $row->created_at ?? null,
                'responded_at' => $row->responded_at ?? null,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<int, \SutoreMarketplace\Modules\Listings\Domain\Listing> $listings
     */
    private function primeProducts(array $listings): void
    {
        $ids = [];
        foreach ($listings as $listing) {
            $ids[] = $listing->parentProductId;
            if ($listing->variationId > 0) {
                $ids[] = $listing->variationId;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === [] || !function_exists('wc_get_products')) {
            return;
        }

        wc_get_products([
            'include' => $ids,
            'limit' => count($ids),
            'status' => ['publish', 'private', 'draft'],
            'type' => ['simple', 'variable', 'variation'],
        ]);
    }
}
