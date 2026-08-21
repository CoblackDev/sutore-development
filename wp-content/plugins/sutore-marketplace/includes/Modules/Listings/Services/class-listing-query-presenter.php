<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind;
use SutoreMarketplace\Modules\Invoices\Repositories\InvoiceRepository;
use SutoreMarketplace\Modules\Invoices\Services\InvoicePresenter;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\CampaignSource;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingCampaignPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingExpireDisplay;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductListChrome;
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

    /** @var array<int, array{title:string,code:string,thumbnail:string,permalink:string}> */
    private array $productChrome = [];

    /** @var array<int, string> */
    private array $sizeLabels = [];

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

        // Listing expiry is cron-only (Shared\Hooks\Cron) — never runExpiryPass on list GET.
        $result = $this->listings->query($args);
        $listingIds = array_values(array_filter(array_map(
            static fn (Listing $listing): int => (int) ($listing->variationId ?? 0),
            $result['items']
        )));
        ListingIntegration::primeFulfillmentCache($listingIds);
        $this->primeCampaignMaps($result['items']);
        $this->primeProductChrome($result['items']);

        $orderIdByVariation = [];
        foreach ($result['items'] as $listing) {
            $variationId = (int) ($listing->variationId ?? 0);
            if ($variationId > 0) {
                $orderIdByVariation[$variationId] = (int) ($listing->orderId ?? 0);
            }
        }
        $invoiceMap = (new InvoiceRepository())->findForVariations($orderIdByVariation);

        $items = [];
        foreach ($result['items'] as $listing) {
            $item = $this->enrich($listing);
            $variationId = (int) ($listing->variationId ?? 0);
            $invoices = $invoiceMap[$variationId] ?? [];
            if (!\SutoreMarketplace\Admin\StaffCapabilities::canManageOps()) {
                $invoices = array_values(array_filter(
                    $invoices,
                    static fn (object $row): bool => (string) $row->kind === InvoiceKind::SELLER_COMMISSION
                ));
            }
            $item['invoices'] = InvoicePresenter::forStaff($invoices);
            $items[] = $item;
        }

        $sizeFilters = $this->sizeFiltersForMerchant((int) ($args['merchant_id'] ?? 0));

        $this->campaignMap = [];
        $this->acceptedOffers = [];
        $this->pendingOffers = [];
        $this->productChrome = [];
        $this->sizeLabels = [];
        ListingIntegration::clearPayoutCache();

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'size_filters' => $sizeFilters,
        ];
    }

    /**
     * @return list<array{term_id:int,name:string}>
     */
    private function sizeFiltersForMerchant(int $merchantId): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        $termIds = $this->listings->distinctSizeTermIdsForMerchant($merchantId);
        $labels = ProductSizeLookup::labelsForTermIds($termIds);
        $filters = [];
        foreach ($termIds as $termId) {
            $label = $labels[$termId] ?? '';
            if ($label === '') {
                continue;
            }
            $filters[] = [
                'term_id' => $termId,
                'name' => $label,
            ];
        }

        return $filters;
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

        $createdAt = $listing->createdAt ? trim((string) $listing->createdAt) : '';
        $createdAtLabel = '';
        if ($createdAt !== '') {
            $createdTs = strtotime($createdAt);
            $createdAtLabel = $createdTs
                ? (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $createdTs)
                : $createdAt;
        }

        $campaignStatus = (string) $listing->campaignStatus;
        $productId = $listing->variationId ?: $listing->parentProductId;
        $chrome = $this->productChrome[$productId]
            ?? $this->productChrome[(int) $listing->parentProductId]
            ?? null;
        if ($chrome === null) {
            $chromeMap = ProductListChrome::mapForIds([$productId, (int) $listing->parentProductId]);
            $chrome = $chromeMap[$productId] ?? $chromeMap[(int) $listing->parentProductId] ?? [
                'title' => '',
                'code' => '',
                'thumbnail' => '',
                'permalink' => '',
            ];
        }
        $productName = $chrome['title'] !== ''
            ? $chrome['title']
            : (string) get_the_title($listing->parentProductId);
        $sizeTermId = (int) $listing->sizeTermId;
        $sizeLabel = $this->sizeLabels[$sizeTermId] ?? ProductSizeLookup::labelForTermId($sizeTermId);
        $item = array_merge($listing->toArray(), [
            'id' => (int) $listing->variationId,
            'parent_title' => $productName,
            'product_code' => $chrome['code'] !== ''
                ? $chrome['code']
                : ProductCodeLookup::codeForProduct($listing->parentProductId),
            'thumbnail' => $chrome['thumbnail'] !== ''
                ? $chrome['thumbnail']
                : ProductThumbnail::url($listing->parentProductId),
            'size_label' => $sizeLabel,
            'permalink' => $chrome['permalink'] !== ''
                ? $chrome['permalink']
                : (get_permalink($listing->parentProductId) ?: ''),
            'listing_status_label' => ListingStatus::label($listing->listingStatus),
            'remaining_label' => ListingExpireDisplay::label(
                $listing->expireAt,
                $listing->listingStatus,
                $listing->isWinner
            ),
            'created_at_label' => $createdAtLabel,
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
            'can_start_campaign' => ListingCampaignPolicy::canStart($listing),
            'campaign_cooling' => ListingCampaignPolicy::isCoolingDown($listing),
            'campaign_cooled_until' => $listing->campaignCooledUntil,
            'campaign_cooled_until_label' => $listing->campaignCooledUntil
                ? CampaignDatetime::formatLabel($listing->campaignCooledUntil)
                : '',
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
            if ($listing->campaignStatus === 'none' || !$listing->campaignId || !$listing->variationId) {
                continue;
            }
            $campaignIds[] = (int) $listing->campaignId;
            if ($listing->campaignStatus === 'active') {
                $activeListingIds[] = (int) $listing->variationId;
            } elseif ($listing->campaignStatus === 'offer') {
                $pendingPairs[] = [(int) $listing->variationId, (int) $listing->campaignId];
            }
        }

        $this->campaignMap = $this->campaigns->findByIds($campaignIds);
        $this->acceptedOffers = $this->offers->findAcceptedForVariations($activeListingIds);
        $this->pendingOffers = $this->offers->findPendingForVariationCampaigns($pendingPairs);
    }

    /**
     * @param list<Listing> $listings
     */
    private function primeProductChrome(array $listings): void
    {
        $productIds = [];
        $termIds = [];
        foreach ($listings as $listing) {
            $productIds[] = (int) $listing->parentProductId;
            if ($listing->variationId > 0) {
                $productIds[] = (int) $listing->variationId;
            }
            $termIds[] = (int) $listing->sizeTermId;
        }

        $this->productChrome = ProductListChrome::mapForIds($productIds);
        $this->sizeLabels = ProductSizeLookup::labelsForTermIds($termIds);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function campaignDetails(Listing $listing): ?array
    {
        if ($listing->campaignStatus === 'none' || !$listing->campaignId || !$listing->variationId) {
            return null;
        }

        $campaignId = (int) $listing->campaignId;
        $campaign = $this->campaignMap[$campaignId] ?? $this->campaigns->find($campaignId);
        $offer = null;
        if ($listing->campaignStatus === 'active') {
            $offer = $this->acceptedOffers[(int) $listing->variationId]
                ?? $this->offers->findAcceptedForVariation((int) $listing->variationId);
        } elseif ($listing->campaignStatus === 'offer') {
            $key = (int) $listing->variationId . ':' . $campaignId;
            $offer = $this->pendingOffers[$key]
                ?? $this->offers->findPendingForVariationCampaign((int) $listing->variationId, $campaignId);
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

        $startsAt = $campaign?->starts_at ?? null;
        $endsAt = $campaign?->ends_at ?? null;
        $source = CampaignSource::normalize((string) ($campaign?->source ?? CampaignSource::ADMIN));

        return [
            'campaign_id' => $campaignId,
            'offer_id' => $offer ? (int) $offer->id : null,
            'offer_status' => $offer ? (string) $offer->status : null,
            'name' => $campaign ? (string) $campaign->name : '',
            'source' => $source,
            'source_label' => CampaignSource::label($source),
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
            'remaining_label' => ListingExpireDisplay::remainingFromDatetime(
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
            'is_pre_order' => $listing->listingStatus === ListingStatus::PRE_ORDER,
            'is_sourcing' => $listing->listingStatus === ListingStatus::PRE_ORDER,
        ];
    }
}
