<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingCampaignPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingExpireDisplay;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Orders\Hooks\ListingIntegration;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

/**
 * Shared listing list enrichment for REST responses.
 */
final class ListingQueryPresenter
{
    /** @var array<int, object> */
    private array $campaignMap = [];

    /** @var array<int, object> */
    private array $acceptedOffers = [];

    /** @var array<string, object> */
    private array $pendingOffers = [];

    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingService $listingService = new ListingService(),
        private readonly CampaignRepository $campaigns = new CampaignRepository(),
        private readonly CampaignOfferRepository $offers = new CampaignOfferRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function present(array $args): array
    {
        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = max(1, (int) ($args['per_page'] ?? 20));
        $args['page'] = $page;
        $args['per_page'] = $perPage;

        $result = $this->listings->query($args);
        $listingIds = array_values(array_filter(array_map(
            static fn (Listing $listing): int => (int) ($listing->id ?? 0),
            $result['items']
        )));
        ListingIntegration::primeFulfillmentCache($listingIds);
        $this->primeCampaignMaps($result['items']);
        $this->primeProductCaches($result['items']);

        $items = [];
        foreach ($result['items'] as $listing) {
            $items[] = $this->enrich($listing);
        }

        $this->campaignMap = [];
        $this->acceptedOffers = [];
        $this->pendingOffers = [];

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string, mixed> */
    public function enrich(Listing $listing): array
    {
        $soldAt = $listing->soldAt ? trim((string) $listing->soldAt) : '';
        $soldAtLabel = '';
        if ($soldAt !== '') {
            $ts = strtotime($soldAt);
            $soldAtLabel = $ts
                ? (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts)
                : $soldAt;
        }

        $campaignStatus = (string) $listing->campaignStatus;
        $productId = $listing->variationId ?: $listing->parentProductId;
        $product = wc_get_product($productId);
        $productName = $product ? (string) $product->get_name() : (string) get_the_title($listing->parentProductId);
        $item = array_merge($listing->toArray(), [
            'parent_title' => $productName,
            'product_code' => ProductCodeLookup::codeForProduct($listing->parentProductId),
            'thumbnail' => ProductThumbnail::url($listing->parentProductId),
            'size_label' => ProductSizeLookup::labelForTermId((int) $listing->sizeTermId),
            'permalink' => get_permalink($listing->parentProductId) ?: '',
            'listing_status_label' => ListingStatus::label($listing->listingStatus),
            'remaining_label' => ListingExpireDisplay::label(
                $listing->expireAt,
                $listing->listingStatus,
                $listing->isWinner
            ),
            'sold_at_label' => $soldAtLabel,
            'campaign_status_label' => $campaignStatus !== '' && $campaignStatus !== 'none'
                ? ListingStatus::campaignLabel($campaignStatus)
                : '',
            'campaign' => $this->campaignDetails($listing),
            'can_put_on_sale' => $this->listingService->canPutOnSale($listing),
            'can_remove_from_sale' => $this->listingService->canRemoveFromSale($listing),
            'can_delete' => $this->listingService->canDelete($listing),
            'can_edit' => $this->listingService->canEdit($listing),
            'can_increase_asking' => ListingCampaignPolicy::allowsAskingIncrease($listing),
            'max_asking' => ListingCampaignPolicy::isActiveCampaign($listing)
                ? (float) $listing->asking
                : null,
        ]);

        /** @var array<string, mixed> $filtered */
        $filtered = apply_filters('sutore_marketplace_listing_query_item', $item, $listing);

        return $filtered;
    }

    /**
     * @param list<Listing> $listings
     */
    private function primeCampaignMaps(array $listings): void
    {
        $campaignIds = [];
        $activeListingIds = [];
        $pendingPairs = [];
        foreach ($listings as $listing) {
            if ($listing->campaignStatus === 'none' || !$listing->campaignId || !$listing->id) {
                continue;
            }
            $campaignIds[] = (int) $listing->campaignId;
            if ($listing->campaignStatus === 'active') {
                $activeListingIds[] = (int) $listing->id;
            } elseif ($listing->campaignStatus === 'offer') {
                $pendingPairs[] = [(int) $listing->id, (int) $listing->campaignId];
            }
        }

        $this->campaignMap = $this->campaigns->findByIds($campaignIds);
        $this->acceptedOffers = $this->offers->findAcceptedForListings($activeListingIds);
        $this->pendingOffers = $this->offers->findPendingForListingCampaigns($pendingPairs);
    }

    /**
     * @param list<Listing> $listings
     */
    private function primeProductCaches(array $listings): void
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

    /**
     * @return array<string, mixed>|null
     */
    private function campaignDetails(Listing $listing): ?array
    {
        if ($listing->campaignStatus === 'none' || !$listing->campaignId || !$listing->id) {
            return null;
        }

        $campaignId = (int) $listing->campaignId;
        $campaign = $this->campaignMap[$campaignId] ?? $this->campaigns->find($campaignId);
        $offer = null;
        if ($listing->campaignStatus === 'active') {
            $offer = $this->acceptedOffers[(int) $listing->id]
                ?? $this->offers->findAcceptedForListing((int) $listing->id);
        } elseif ($listing->campaignStatus === 'offer') {
            $key = (int) $listing->id . ':' . $campaignId;
            $offer = $this->pendingOffers[$key]
                ?? $this->offers->findPendingForListingCampaign((int) $listing->id, $campaignId);
        }

        $sellerDiscount = $offer
            ? (float) $offer->seller_discount
            : ($campaign ? (float) $campaign->seller_discount_amount : 0.0);
        $platformDiscount = $offer
            ? (float) $offer->platform_discount
            : ($campaign ? (float) $campaign->platform_discount_amount : 0.0);
        $askingBefore = $offer
            ? (float) $offer->asking_before
            : (float) $listing->asking;

        $sellerType = CampaignDiscountType::normalize(
            $offer && isset($offer->seller_discount_type)
                ? (string) $offer->seller_discount_type
                : ($campaign && isset($campaign->seller_discount_type)
                    ? (string) $campaign->seller_discount_type
                    : CampaignDiscountType::FIXED)
        );
        $platformType = CampaignDiscountType::normalize(
            $offer && isset($offer->platform_discount_type)
                ? (string) $offer->platform_discount_type
                : ($campaign && isset($campaign->platform_discount_type)
                    ? (string) $campaign->platform_discount_type
                    : CampaignDiscountType::FIXED)
        );
        $sellerValue = $offer && isset($offer->seller_discount_value)
            ? (float) $offer->seller_discount_value
            : ($campaign ? (float) $campaign->seller_discount_amount : $sellerDiscount);
        $platformValue = $offer && isset($offer->platform_discount_value)
            ? (float) $offer->platform_discount_value
            : ($campaign ? (float) $campaign->platform_discount_amount : $platformDiscount);

        if ($offer) {
            $math = MarketplacePricing::campaignAcceptMath($askingBefore, $sellerDiscount, $platformDiscount);
        } else {
            $math = MarketplacePricing::resolveCampaignOfferMath(
                $askingBefore,
                $sellerType,
                $sellerValue,
                $platformType,
                $platformValue
            );
            $sellerDiscount = $math['seller_discount'];
            $platformDiscount = $math['platform_discount'];
        }

        $startsAt = $campaign->starts_at ?? null;
        $endsAt = $campaign->ends_at ?? null;

        return [
            'campaign_id' => $campaignId,
            'offer_id' => $offer ? (int) $offer->id : null,
            'offer_status' => $offer ? (string) $offer->status : null,
            'name' => $campaign ? (string) $campaign->name : '',
            'status' => (string) $listing->campaignStatus,
            'status_label' => ListingStatus::campaignLabel((string) $listing->campaignStatus),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'starts_at_label' => CampaignDatetime::formatLabel(
                is_string($startsAt) ? $startsAt : null
            ),
            'ends_at_label' => CampaignDatetime::formatLabel(
                is_string($endsAt) ? $endsAt : null
            ),
            'seller_discount_type' => $sellerType,
            'seller_discount_value' => $sellerValue,
            'seller_discount' => $sellerDiscount,
            'seller_discount_label' => CampaignDiscountType::offerLabel($sellerType, $sellerValue, $sellerDiscount),
            'platform_discount_type' => $platformType,
            'platform_discount_value' => $platformValue,
            'platform_discount' => $platformDiscount,
            'platform_discount_label' => CampaignDiscountType::offerLabel($platformType, $platformValue, $platformDiscount),
            'asking_before' => $askingBefore,
            'asking_effective' => $math['asking_effective'],
            'is_sourcing' => $listing->sourcingRequestId !== null,
        ];
    }
}
