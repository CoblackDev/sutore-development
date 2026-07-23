<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\CampaignOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\CampaignStatus;
use SutoreMarketplace\Modules\Listings\Domain\CampaignTargeting;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Settings\Settings;

final class CampaignService
{
    public function __construct(
        private readonly CampaignRepository $campaigns = new CampaignRepository(),
        private readonly CampaignOfferRepository $offers = new CampaignOfferRepository(),
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
        private readonly ListingSelector $selector = new ListingSelector(),
        private readonly CampaignTargetingQuery $targetingQuery = new CampaignTargetingQuery(),
        private readonly NotificationService $notifications = new NotificationService(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createCampaign(array $input): int|\WP_Error
    {
        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        if ($name === '') {
            return new \WP_Error('sutore_campaign_name', __('Campaign name is required.', 'sutore-marketplace'));
        }

        $sellerType = CampaignDiscountType::normalize($input['seller_discount_type'] ?? CampaignDiscountType::FIXED);
        $platformType = CampaignDiscountType::normalize($input['platform_discount_type'] ?? CampaignDiscountType::FIXED);
        $sellerValue = max(0.0, (float) ($input['seller_discount_amount'] ?? 0));
        $platformValue = max(0.0, (float) ($input['platform_discount_amount'] ?? 0));
        if ($sellerType === CampaignDiscountType::PERCENT) {
            $sellerValue = min(100.0, $sellerValue);
        }
        if ($platformType === CampaignDiscountType::PERCENT) {
            $platformValue = min(100.0, $platformValue);
        }
        if ($sellerValue <= 0 && $platformValue <= 0) {
            return new \WP_Error(
                'sutore_campaign_discount',
                __('At least one of seller or platform discount must be greater than zero.', 'sutore-marketplace')
            );
        }

        $startsAt = CampaignDatetime::normalizeInput($input['starts_at'] ?? null);
        $endsAt = CampaignDatetime::normalizeInput($input['ends_at'] ?? null);
        $startsTs = CampaignDatetime::toTimestamp($startsAt);
        $endsTs = CampaignDatetime::toTimestamp($endsAt);
        if ($startsTs !== null && $endsTs !== null && $endsTs <= $startsTs) {
            return new \WP_Error(
                'sutore_campaign_dates',
                __('Campaign end must be after start.', 'sutore-marketplace')
            );
        }

        $targeting = $this->resolveTargeting($input);

        return $this->campaigns->create([
            'name' => $name,
            'status' => CampaignStatus::DRAFT,
            'seller_discount_amount' => $sellerValue,
            'seller_discount_type' => $sellerType,
            'platform_discount_amount' => $platformValue,
            'platform_discount_type' => $platformType,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'targeting' => $targeting->toJson(),
            'notes' => sanitize_textarea_field((string) ($input['notes'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{listings: list<Listing>, listing_count: int, merchant_count: int}
     */
    public function previewTargeting(array $input): array
    {
        return $this->targetingQuery->preview($this->resolveTargeting($input));
    }

    public function publish(int $campaignId): array|\WP_Error
    {
        $campaign = $this->campaigns->find($campaignId);
        if (!$campaign) {
            return new \WP_Error('sutore_campaign_missing', __('Campaign not found.', 'sutore-marketplace'));
        }
        if ((string) $campaign->status !== CampaignStatus::DRAFT
            && (string) $campaign->status !== CampaignStatus::ACTIVE
        ) {
            return new \WP_Error(
                'sutore_campaign_status',
                __('Only draft or active campaigns can publish offers.', 'sutore-marketplace')
            );
        }

        $startsAt = $campaign->starts_at ?? null;
        $endsAt = $campaign->ends_at ?? null;
        if (!$startsAt || !$endsAt) {
            return new \WP_Error(
                'sutore_campaign_dates',
                __('Campaign start and end dates are required before publishing.', 'sutore-marketplace')
            );
        }

        $targeting = CampaignTargeting::fromJson(
            isset($campaign->targeting) ? (string) $campaign->targeting : null
        );
        $matched = $this->targetingQuery->findListings($targeting);

        $created = 0;
        $skipped = 0;
        $sellerType = CampaignDiscountType::normalize(
            isset($campaign->seller_discount_type) ? (string) $campaign->seller_discount_type : CampaignDiscountType::FIXED
        );
        $platformType = CampaignDiscountType::normalize(
            isset($campaign->platform_discount_type) ? (string) $campaign->platform_discount_type : CampaignDiscountType::FIXED
        );
        $sellerValue = (float) $campaign->seller_discount_amount;
        $platformValue = (float) $campaign->platform_discount_amount;

        foreach ($matched as $listing) {
            if (!$listing->id) {
                continue;
            }
            $existing = $this->offers->findPendingForListingCampaign((int) $listing->id, $campaignId);
            if ($existing) {
                $skipped++;
                continue;
            }
            if ($listing->campaignStatus !== 'none') {
                $skipped++;
                continue;
            }

            $math = MarketplacePricing::resolveCampaignOfferMath(
                (float) $listing->asking,
                $sellerType,
                $sellerValue,
                $platformType,
                $platformValue
            );
            $sellerTl = $math['seller_discount'];
            $platformTl = $math['platform_discount'];

            if ($sellerValue > 0 && $sellerTl <= 0 && $sellerType === CampaignDiscountType::PERCENT) {
                $skipped++;
                continue;
            }

            $offerId = $this->offers->create([
                'campaign_id' => $campaignId,
                'listing_id' => (int) $listing->id,
                'merchant_id' => $listing->merchantId,
                'status' => CampaignOfferStatus::PENDING,
                'asking_before' => $listing->asking,
                'seller_discount_type' => $sellerType,
                'seller_discount_value' => $sellerValue,
                'seller_discount' => $sellerTl,
                'platform_discount_type' => $platformType,
                'platform_discount_value' => $platformValue,
                'platform_discount' => $platformTl,
                'compare_regular' => null,
                'customer_sale' => null,
            ]);

            $this->listings->update((int) $listing->id, [
                'campaign_id' => $campaignId,
                'campaign_status' => 'offer',
            ]);

            $this->events->log('campaign_offer_sent', [
                'campaign_id' => $campaignId,
                'offer_id' => $offerId,
                'seller_discount_type' => $sellerType,
                'seller_discount_value' => $sellerValue,
                'seller_discount' => $sellerTl,
                'platform_discount_type' => $platformType,
                'platform_discount_value' => $platformValue,
                'platform_discount' => $platformTl,
                'asking_before' => $listing->asking,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ], (int) $listing->id, $listing->variationId, $listing->merchantId, 'merchant_visible');

            $product = get_the_title($listing->parentProductId) ?: __('Product', 'sutore-marketplace');
            $this->notifications->dispatch($listing->merchantId, NotificationType::CAMPAIGN_OFFER, [
                'product' => $product,
                'listing_id' => (int) $listing->id,
                'offer_id' => $offerId,
                'campaign_id' => $campaignId,
                'seller_discount' => $sellerTl,
                'platform_discount' => $platformTl,
                'seller_discount_label' => CampaignDiscountType::offerLabel($sellerType, $sellerValue, $sellerTl),
                'platform_discount_label' => CampaignDiscountType::offerLabel($platformType, $platformValue, $platformTl),
            ], 0);

            $created++;
        }

        $this->campaigns->update($campaignId, ['status' => CampaignStatus::ACTIVE]);

        return [
            'offers_created' => $created,
            'offers_skipped' => $skipped,
            'listing_count' => count($matched),
        ];
    }

    public function acceptOffer(int $offerId, int $merchantId): true|\WP_Error
    {
        $offer = $this->offers->find($offerId);
        if (!$offer || (int) $offer->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_campaign_offer_missing', __('Campaign offer not found.', 'sutore-marketplace'));
        }
        if ((string) $offer->status !== CampaignOfferStatus::PENDING) {
            return new \WP_Error('sutore_campaign_offer_status', __('This offer can no longer be accepted.', 'sutore-marketplace'));
        }

        $campaign = $this->campaigns->find((int) $offer->campaign_id);
        if (!$campaign || (string) $campaign->status !== CampaignStatus::ACTIVE) {
            return new \WP_Error('sutore_campaign_inactive', __('Campaign is not active.', 'sutore-marketplace'));
        }
        if (CampaignDatetime::isPast(isset($campaign->ends_at) ? (string) $campaign->ends_at : null)) {
            return new \WP_Error('sutore_campaign_ended', __('Campaign has ended.', 'sutore-marketplace'));
        }

        $listing = $this->listings->find((int) $offer->listing_id);
        if (!$listing || !$listing->id) {
            return new \WP_Error('sutore_campaign_missing', __('Listing not found.', 'sutore-marketplace'));
        }

        $askingBefore = (float) $offer->asking_before;
        $math = MarketplacePricing::campaignAcceptMath(
            $askingBefore,
            (float) $offer->seller_discount,
            (float) $offer->platform_discount
        );

        if ($math['asking_effective'] <= 0) {
            return new \WP_Error(
                'sutore_campaign_asking',
                __('Seller discount would reduce asking below the minimum price step.', 'sutore-marketplace')
            );
        }

        $this->listings->update((int) $listing->id, [
            'asking' => $math['asking_effective'],
            'campaign_id' => (int) $offer->campaign_id,
            'campaign_status' => 'active',
        ]);

        $this->offers->update($offerId, [
            'status' => CampaignOfferStatus::ACCEPTED,
            'compare_regular' => $math['compare_regular'],
            'customer_sale' => $math['customer_sale'],
            'responded_at' => current_time('mysql'),
        ]);

        CampaignWcSaleSync::writeSale(
            $listing->variationId,
            $math['compare_regular'],
            $math['customer_sale'],
            isset($campaign->starts_at) ? (string) $campaign->starts_at : null,
            isset($campaign->ends_at) ? (string) $campaign->ends_at : null
        );
        Settings::bumpPricingRevision();

        $this->events->log('campaign_applied', [
            'campaign_id' => (int) $offer->campaign_id,
            'offer_id' => $offerId,
            'seller_discount' => (float) $offer->seller_discount,
            'platform_discount' => (float) $offer->platform_discount,
            'asking_before' => $askingBefore,
            'asking_effective' => $math['asking_effective'],
        ], (int) $listing->id, $listing->variationId, $listing->merchantId, 'merchant_visible');

        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        return true;
    }

    public function declineOffer(int $offerId, int $merchantId): true|\WP_Error
    {
        $offer = $this->offers->find($offerId);
        if (!$offer || (int) $offer->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_campaign_offer_missing', __('Campaign offer not found.', 'sutore-marketplace'));
        }
        if ((string) $offer->status !== CampaignOfferStatus::PENDING) {
            return new \WP_Error('sutore_campaign_offer_status', __('This offer can no longer be declined.', 'sutore-marketplace'));
        }

        $this->offers->update($offerId, [
            'status' => CampaignOfferStatus::DECLINED,
            'responded_at' => current_time('mysql'),
        ]);

        $listing = $this->listings->find((int) $offer->listing_id);
        if ($listing && $listing->id && $listing->campaignStatus === 'offer'
            && (int) $listing->campaignId === (int) $offer->campaign_id
        ) {
            $this->listings->update((int) $listing->id, [
                'campaign_id' => null,
                'campaign_status' => 'none',
            ]);
        }

        if ($listing && $listing->id) {
            $this->events->log('campaign_offer_declined', [
                'campaign_id' => (int) $offer->campaign_id,
                'offer_id' => $offerId,
                'seller_discount' => (float) $offer->seller_discount,
                'platform_discount' => (float) $offer->platform_discount,
            ], (int) $listing->id, $listing->variationId, $listing->merchantId, 'merchant_visible');
        }

        return true;
    }

    public function revertOffer(object $offer): void
    {
        if ((string) $offer->status !== CampaignOfferStatus::ACCEPTED) {
            return;
        }

        $listing = $this->listings->find((int) $offer->listing_id);
        $askingBefore = (float) $offer->asking_before;

        if ($listing && $listing->id) {
            $this->listings->update((int) $listing->id, [
                'asking' => $askingBefore,
                'campaign_id' => null,
                'campaign_status' => 'none',
            ]);
            CampaignWcSaleSync::clearSaleToAsking($listing->variationId, $askingBefore);
            Settings::bumpPricingRevision();
            $this->events->log('campaign_cleared', [
                'campaign_id' => (int) $offer->campaign_id,
                'offer_id' => (int) $offer->id,
                'reason' => 'ended',
                'asking_restored' => $askingBefore,
            ], (int) $listing->id, $listing->variationId, $listing->merchantId, 'merchant_visible');
            $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);
        }

        $this->offers->update((int) $offer->id, [
            'status' => CampaignOfferStatus::EXPIRED,
            'responded_at' => current_time('mysql'),
        ]);
    }

    public function endCampaign(int $campaignId): true|\WP_Error
    {
        $campaign = $this->campaigns->find($campaignId);
        if (!$campaign) {
            return new \WP_Error('sutore_campaign_missing', __('Campaign not found.', 'sutore-marketplace'));
        }

        foreach ($this->offers->findAcceptedByCampaign($campaignId) as $offer) {
            $this->revertOffer($offer);
        }

        foreach ($this->offers->findPendingByCampaign($campaignId) as $offer) {
            $this->offers->update((int) $offer->id, [
                'status' => CampaignOfferStatus::EXPIRED,
                'responded_at' => current_time('mysql'),
            ]);
            $listing = $this->listings->find((int) $offer->listing_id);
            if ($listing && $listing->id && $listing->campaignStatus === 'offer'
                && (int) $listing->campaignId === $campaignId
            ) {
                $this->listings->update((int) $listing->id, [
                    'campaign_id' => null,
                    'campaign_status' => 'none',
                ]);
            }
            if ($listing && $listing->id) {
                $this->events->log('campaign_offer_expired', [
                    'campaign_id' => $campaignId,
                    'offer_id' => (int) $offer->id,
                    'reason' => 'ended',
                ], (int) $listing->id, $listing->variationId, $listing->merchantId, 'merchant_visible');
            }
        }

        $this->campaigns->update($campaignId, ['status' => CampaignStatus::ENDED]);
        Settings::bumpPricingRevision();

        return true;
    }

    public function runExpiryPass(int $limit = 100): int
    {
        $reverted = 0;
        foreach ($this->offers->findAcceptedExpired($limit) as $offer) {
            $this->revertOffer($offer);
            $reverted++;
        }

        foreach ($this->campaigns->findActivePastEnd(50) as $campaign) {
            $this->endCampaign((int) $campaign->id);
        }

        return $reverted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        $items = [];
        foreach ($this->campaigns->all() as $row) {
            $items[] = $this->serializeCampaign($row);
        }

        return $items;
    }

    /**
     * Admin listing activity payload (listing lookup + presenter).
     *
     * @return array{listing_id: int, deleted: bool, activity: list<array<string, mixed>>}
     */
    public function listingActivityForAdmin(int $listingId, int $variationId = 0): array
    {
        $listing = $this->listings->find($listingId);
        if ($listing) {
            $variationId = $listing->variationId;
        }

        return [
            'listing_id' => $listingId,
            'deleted' => $listing === null,
            'activity' => (new ListingActivityPresenter())->present($listingId, $variationId, null),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeCampaign(object $row): array
    {
        $targeting = CampaignTargeting::fromJson(isset($row->targeting) ? (string) $row->targeting : null);
        $pending = 0;
        $accepted = 0;
        foreach ($this->offers->findPendingByCampaign((int) $row->id) as $_) {
            $pending++;
        }
        foreach ($this->offers->findAcceptedByCampaign((int) $row->id) as $_) {
            $accepted++;
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'status' => (string) $row->status,
            'seller_discount_amount' => (float) $row->seller_discount_amount,
            'seller_discount_type' => CampaignDiscountType::normalize(
                isset($row->seller_discount_type) ? (string) $row->seller_discount_type : CampaignDiscountType::FIXED
            ),
            'platform_discount_amount' => (float) $row->platform_discount_amount,
            'platform_discount_type' => CampaignDiscountType::normalize(
                isset($row->platform_discount_type) ? (string) $row->platform_discount_type : CampaignDiscountType::FIXED
            ),
            'seller_discount_label' => CampaignDiscountType::ruleLabel(
                isset($row->seller_discount_type) ? (string) $row->seller_discount_type : CampaignDiscountType::FIXED,
                (float) $row->seller_discount_amount,
                __('asking', 'sutore-marketplace')
            ),
            'platform_discount_label' => CampaignDiscountType::ruleLabel(
                isset($row->platform_discount_type) ? (string) $row->platform_discount_type : CampaignDiscountType::FIXED,
                (float) $row->platform_discount_amount,
                __('fees', 'sutore-marketplace')
            ),
            'starts_at' => $row->starts_at ?? null,
            'ends_at' => $row->ends_at ?? null,
            'targeting' => $targeting->toArray(),
            'notes' => (string) ($row->notes ?? ''),
            'offers_pending' => $pending,
            'offers_accepted' => $accepted,
            'created_at' => $row->created_at ?? null,
        ];
    }

    /** @param array<string, mixed> $input */
    private function resolveTargeting(array $input): CampaignTargeting
    {
        if (isset($input['targeting']) && is_array($input['targeting'])) {
            return CampaignTargeting::fromArray($input['targeting']);
        }

        return CampaignTargeting::fromArray([
            'merchant_levels' => $this->parseList($input['targeting_merchant_levels'] ?? $input['merchant_levels'] ?? []),
            'asking_min' => $input['targeting_asking_min'] ?? $input['asking_min'] ?? null,
            'asking_max' => $input['targeting_asking_max'] ?? $input['asking_max'] ?? null,
            'category_ids' => $this->parseList($input['targeting_category_ids'] ?? $input['category_ids'] ?? []),
            'brand_ids' => $this->parseList($input['targeting_brand_ids'] ?? $input['brand_ids'] ?? []),
            'product_ids' => $this->parseList($input['targeting_product_ids'] ?? $input['product_ids'] ?? []),
        ]);
    }

    /** @param mixed $raw @return list<string|int> */
    private function parseList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values($raw);
    }
}
