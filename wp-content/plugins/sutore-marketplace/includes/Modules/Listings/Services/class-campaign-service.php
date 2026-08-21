<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\CampaignGuardrails;
use SutoreMarketplace\Modules\Listings\Domain\CampaignOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\CampaignSource;
use SutoreMarketplace\Modules\Listings\Domain\CampaignStatus;
use SutoreMarketplace\Modules\Listings\Domain\CampaignTargeting;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingCampaignPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
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
        private readonly CampaignSuggestionService $suggestions = new CampaignSuggestionService(),
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
        if ($sellerType === CampaignDiscountType::PERCENT && $sellerValue > 0) {
            $band = CampaignGuardrails::assertPercent($sellerValue);
            if (is_wp_error($band)) {
                return $band;
            }
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
        $span = CampaignGuardrails::assertDateSpan($startsAt, $endsAt);
        if (is_wp_error($span)) {
            return $span;
        }

        $targeting = $this->resolveTargeting($input);
        $source = CampaignSource::normalize($input['source'] ?? CampaignSource::ADMIN);

        return $this->campaigns->create([
            'name' => $name,
            'status' => CampaignStatus::DRAFT,
            'source' => $source,
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

        foreach ($matched as $listing) {
            if (!$listing->variationId) {
                continue;
            }
            $result = $this->createOfferForListing($campaignId, (int) $listing->variationId, [
                'skip_targeting' => true,
                'activate_campaign' => false,
                'campaign' => $campaign,
            ]);
            if (is_wp_error($result)) {
                $skipped++;
                continue;
            }
            $created++;
        }

        $this->campaigns->update($campaignId, ['status' => CampaignStatus::ACTIVE]);

        return [
            'offers_created' => $created,
            'offers_skipped' => $skipped,
            'listing_count' => count($matched),
        ];
    }

    /**
     * Create a pending campaign offer for one listing (staff or publish fan-out).
     * Targeting is not re-checked when skip_targeting is true (intentional staff send).
     *
     * @param array{
     *   skip_targeting?: bool,
     *   activate_campaign?: bool,
     *   campaign?: object|null,
     *   staff_manual?: bool,
     *   seller_discount_type?: string,
     *   seller_discount_value?: float,
     *   platform_discount_type?: string,
     *   platform_discount_value?: float,
     *   aging_step?: int
     * } $options
     * @return array{offer_id:int, variation_id:int, campaign_id:int}|\WP_Error
     */
    public function createOfferForListing(int $campaignId, int $listingId, array $options = []): array|\WP_Error
    {
        $campaign = $options['campaign'] ?? $this->campaigns->find($campaignId);
        if (!$campaign) {
            return new \WP_Error('sutore_campaign_missing', __('Campaign not found.', 'sutore-marketplace'));
        }
        if ((string) $campaign->status !== CampaignStatus::DRAFT
            && (string) $campaign->status !== CampaignStatus::ACTIVE
        ) {
            return new \WP_Error(
                'sutore_campaign_status',
                __('Only draft or active campaigns can send offers.', 'sutore-marketplace')
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
        if (CampaignDatetime::isPast((string) $endsAt)) {
            return new \WP_Error(
                'sutore_campaign_ended',
                __('Campaign has ended.', 'sutore-marketplace')
            );
        }

        $listing = $this->listings->find($listingId);
        if (!$listing || !$listing->variationId) {
            return new \WP_Error('sutore_campaign_missing', __('Product not found.', 'sutore-marketplace'));
        }

        if (!in_array($listing->listingStatus, [ListingStatus::PUBLISH, ListingStatus::QUEUED], true)) {
            return new \WP_Error(
                'sutore_campaign_listing_status',
                __('Only products that are for sale or in queue can receive a campaign offer.', 'sutore-marketplace')
            );
        }

        if ($listing->campaignStatus !== 'none') {
            return new \WP_Error(
                'sutore_campaign_listing_busy',
                __('This product already has a campaign offer or active campaign.', 'sutore-marketplace')
            );
        }

        $cool = ListingCampaignPolicy::assertCanStart($listing);
        if (is_wp_error($cool) && $cool->get_error_code() === 'sutore_campaign_cooldown') {
            return $cool;
        }

        $existing = $this->offers->findPendingForVariationCampaign($listingId, $campaignId);
        if ($existing) {
            return new \WP_Error(
                'sutore_campaign_offer_exists',
                __('A pending offer for this campaign already exists on this product.', 'sutore-marketplace')
            );
        }

        $sellerType = CampaignDiscountType::normalize(
            $options['seller_discount_type']
                ?? (isset($campaign->seller_discount_type) ? (string) $campaign->seller_discount_type : CampaignDiscountType::FIXED)
        );
        $platformType = CampaignDiscountType::normalize(
            $options['platform_discount_type']
                ?? (isset($campaign->platform_discount_type) ? (string) $campaign->platform_discount_type : CampaignDiscountType::FIXED)
        );
        $sellerValue = array_key_exists('seller_discount_value', $options)
            ? (float) $options['seller_discount_value']
            : (float) $campaign->seller_discount_amount;
        $platformValue = array_key_exists('platform_discount_value', $options)
            ? (float) $options['platform_discount_value']
            : (float) $campaign->platform_discount_amount;

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
            return new \WP_Error(
                'sutore_campaign_discount',
                __('Seller discount would reduce the price below the minimum price step.', 'sutore-marketplace')
            );
        }

        $offerId = $this->offers->create([
            'campaign_id' => $campaignId,
            'variation_id' => $listingId,
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

        $listingPatch = [
            'campaign_id' => $campaignId,
            'campaign_status' => 'offer',
        ];
        $agingStep = max(0, (int) ($options['aging_step'] ?? 0));
        if ($agingStep > 0) {
            $listingPatch['campaign_aging_step'] = max($agingStep, (int) $listing->campaignAgingStep);
        }
        $this->listings->update($listingId, $listingPatch);

        $source = CampaignSource::normalize(
            isset($campaign->source) ? (string) $campaign->source : CampaignSource::ADMIN
        );
        $headline = '';
        if ($source === CampaignSource::SYSTEM) {
            $suggestion = $this->suggestions->build($listing, max(1, $agingStep));
            $headline = (string) $suggestion['body'];
        }

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
            'staff_manual' => !empty($options['staff_manual']) ? 1 : 0,
            'source' => $source,
            'aging_step' => $agingStep,
        ], $listing->variationId, $listing->merchantId, 'merchant_visible');

        $product = get_the_title($listing->parentProductId) ?: __('Product', 'sutore-marketplace');
        if (empty($options['skip_notification'])) {
            $this->notifications->dispatch($listing->merchantId, NotificationType::CAMPAIGN_OFFER, [
            'product' => $product,
            'variation_id' => $listingId,
            'offer_id' => $offerId,
            'campaign_id' => $campaignId,
            'seller_discount' => $sellerTl,
            'platform_discount' => $platformTl,
            'seller_discount_label' => CampaignDiscountType::offerLabel($sellerType, $sellerValue, $sellerTl),
            'platform_discount_label' => CampaignDiscountType::offerLabel($platformType, $platformValue, $platformTl),
            'source' => $source,
            'headline' => $headline,
        ], 0);
        }

        $activate = array_key_exists('activate_campaign', $options)
            ? (bool) $options['activate_campaign']
            : true;
        if ($activate && (string) $campaign->status === CampaignStatus::DRAFT) {
            $this->campaigns->update($campaignId, ['status' => CampaignStatus::ACTIVE]);
        }

        return [
            'offer_id' => $offerId,
            'variation_id' => $listingId,
            'campaign_id' => $campaignId,
        ];
    }

    /**
     * Campaigns staff can attach offers to (draft/active with start+end, not ended).
     *
     * @return list<array<string, mixed>>
     */
    public function listSendableForStaff(): array
    {
        $items = [];
        foreach ($this->campaigns->all() as $row) {
            $status = (string) ($row->status ?? '');
            if ($status !== CampaignStatus::DRAFT && $status !== CampaignStatus::ACTIVE) {
                continue;
            }
            if (empty($row->starts_at) || empty($row->ends_at)) {
                continue;
            }
            if (CampaignDatetime::isPast((string) $row->ends_at)) {
                continue;
            }
            $items[] = $this->serializeCampaign($row);
        }

        return $items;
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

        $listing = $this->listings->find((int) $offer->variation_id);
        if (!$listing || !$listing->variationId) {
            return new \WP_Error('sutore_campaign_missing', __('Product not found.', 'sutore-marketplace'));
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
                __('Seller discount would reduce the price below the minimum price step.', 'sutore-marketplace')
            );
        }

        $claimed = $this->offers->updateIfStatus($offerId, CampaignOfferStatus::PENDING, [
            'status' => CampaignOfferStatus::ACCEPTED,
            'compare_regular' => $math['compare_regular'],
            'customer_sale' => $math['customer_sale'],
            'responded_at' => current_time('mysql'),
        ]);
        if (!$claimed) {
            return new \WP_Error(
                'sutore_campaign_offer_status',
                __('This offer can no longer be accepted.', 'sutore-marketplace')
            );
        }

        $this->listings->update((int) $listing->variationId, [
            'asking' => $math['asking_effective'],
            'campaign_id' => (int) $offer->campaign_id,
            'campaign_status' => 'active',
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
        ], (int) $listing->variationId, $listing->merchantId, 'merchant_visible');

        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        (new \SutoreMarketplace\Modules\Tasks\Services\TaskProgressService())
            ->incrementByTemplate($merchantId, \SutoreMarketplace\Modules\Tasks\Domain\OpportunityTemplate::ENGAGEMENT_CAMPAIGN);

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

        $claimed = $this->offers->updateIfStatus($offerId, CampaignOfferStatus::PENDING, [
            'status' => CampaignOfferStatus::DECLINED,
            'responded_at' => current_time('mysql'),
        ]);
        if (!$claimed) {
            return new \WP_Error(
                'sutore_campaign_offer_status',
                __('This offer can no longer be declined.', 'sutore-marketplace')
            );
        }

        $listing = $this->listings->find((int) $offer->variation_id);
        if ($listing && $listing->variationId && $listing->campaignStatus === 'offer'
            && (int) $listing->campaignId === (int) $offer->campaign_id
        ) {
            $this->listings->update((int) $listing->variationId, [
                'campaign_id' => null,
                'campaign_status' => 'none',
            ]);
        }

        if ($listing && $listing->variationId) {
            $this->events->log('campaign_offer_declined', [
                'campaign_id' => (int) $offer->campaign_id,
                'offer_id' => $offerId,
                'seller_discount' => (float) $offer->seller_discount,
                'platform_discount' => (float) $offer->platform_discount,
            ], (int) $listing->variationId, $listing->merchantId, 'merchant_visible');
        }

        return true;
    }

    public function revertOffer(object $offer): void
    {
        if ((string) $offer->status !== CampaignOfferStatus::ACCEPTED) {
            return;
        }

        $listing = $this->listings->find((int) $offer->variation_id);
        $askingBefore = (float) $offer->asking_before;

        if ($listing && $listing->variationId) {
            $this->listings->update((int) $listing->variationId, [
                'asking' => $askingBefore,
                'campaign_id' => null,
                'campaign_status' => 'none',
                'campaign_cooled_until' => CampaignGuardrails::cooldownUntilMysql(),
            ]);
            CampaignWcSaleSync::clearSaleToAsking($listing->variationId, $askingBefore);
            Settings::bumpPricingRevision();
            $this->events->log('campaign_cleared', [
                'campaign_id' => (int) $offer->campaign_id,
                'offer_id' => (int) $offer->id,
                'reason' => 'ended',
                'asking_restored' => $askingBefore,
            ], (int) $listing->variationId, $listing->merchantId, 'merchant_visible');
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
            $listing = $this->listings->find((int) $offer->variation_id);
            if ($listing && $listing->variationId && $listing->campaignStatus === 'offer'
                && (int) $listing->campaignId === $campaignId
            ) {
                $this->listings->update((int) $listing->variationId, [
                    'campaign_id' => null,
                    'campaign_status' => 'none',
                ]);
            }
            if ($listing && $listing->variationId) {
                $this->events->log('campaign_offer_expired', [
                    'campaign_id' => $campaignId,
                    'offer_id' => (int) $offer->id,
                    'reason' => 'ended',
                ], (int) $listing->variationId, $listing->merchantId, 'merchant_visible');
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

        $this->runAgingPass(40);

        return $reverted;
    }

    /**
     * Seller-started campaign: pick a rate from the band and a duration, then auto-accept.
     *
     * @return array{campaign_id:int,offer_id:int,variation_id:int}|\WP_Error
     */
    public function startMerchantCampaign(int $listingId, int $merchantId, float $percent, int $durationDays): array|\WP_Error
    {
        $auth = ListingPolicy::assertCanManage($merchantId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $listing = $this->listings->find($listingId);
        if (!$listing || !$listing->variationId) {
            return new \WP_Error('sutore_campaign_missing', __('Product not found.', 'sutore-marketplace'));
        }
        $owns = ListingPolicy::assertOwnsListing($listing, $merchantId);
        if (is_wp_error($owns)) {
            return $owns;
        }

        $allowed = ListingCampaignPolicy::assertCanStart($listing);
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $percentCheck = CampaignGuardrails::assertPercent($percent);
        if (is_wp_error($percentCheck)) {
            return $percentCheck;
        }
        $durationCheck = CampaignGuardrails::assertDurationDays($durationDays);
        if (is_wp_error($durationCheck)) {
            return $durationCheck;
        }

        $percent = (float) CampaignGuardrails::snapPercent($percent);
        $startsAt = CampaignDatetime::nowMysql();
        $endsAt = CampaignDatetime::plusDays($durationDays);
        $title = get_the_title($listing->parentProductId) ?: __('Product', 'sutore-marketplace');

        $campaignId = $this->createCampaign([
            'name' => sprintf(
                /* translators: %s: product title */
                __('Seller campaign: %s', 'sutore-marketplace'),
                $title
            ),
            'source' => CampaignSource::MERCHANT,
            'seller_discount_type' => CampaignDiscountType::PERCENT,
            'seller_discount_amount' => $percent,
            'platform_discount_type' => CampaignDiscountType::FIXED,
            'platform_discount_amount' => 0,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'targeting' => ['product_ids' => [$listing->parentProductId]],
        ]);
        if (is_wp_error($campaignId)) {
            return $campaignId;
        }

        $offer = $this->createOfferForListing((int) $campaignId, $listingId, [
            'skip_targeting' => true,
            'activate_campaign' => true,
            'skip_notification' => true,
        ]);
        if (is_wp_error($offer)) {
            $this->campaigns->update((int) $campaignId, ['status' => CampaignStatus::ENDED]);

            return $offer;
        }

        $accepted = $this->acceptOffer((int) $offer['offer_id'], $merchantId);
        if (is_wp_error($accepted)) {
            return $accepted;
        }

        return [
            'campaign_id' => (int) $campaignId,
            'offer_id' => (int) $offer['offer_id'],
            'variation_id' => $listingId,
        ];
    }

    public function runAgingPass(int $limit = 40): int
    {
        $created = 0;
        foreach ($this->listings->findAgingCandidates($limit) as $listing) {
            $step = $this->suggestions->agingStepDue($listing);
            if ($step < 1 || !$listing->variationId) {
                continue;
            }

            $suggestion = $this->suggestions->build($listing, $step);
            $startsAt = CampaignDatetime::nowMysql();
            $endsAt = CampaignDatetime::plusDays(CampaignGuardrails::maxDays());
            $title = get_the_title($listing->parentProductId) ?: __('Product', 'sutore-marketplace');

            $campaignId = $this->createCampaign([
                'name' => sprintf(
                    /* translators: %s: product title */
                    __('Price suggestion: %s', 'sutore-marketplace'),
                    $title
                ),
                'source' => CampaignSource::SYSTEM,
                'seller_discount_type' => CampaignDiscountType::PERCENT,
                'seller_discount_amount' => (float) $suggestion['percent'],
                'platform_discount_type' => CampaignDiscountType::PERCENT,
                'platform_discount_amount' => 100,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'targeting' => ['product_ids' => [$listing->parentProductId]],
                'notes' => (string) $suggestion['body'],
            ]);
            if (is_wp_error($campaignId)) {
                continue;
            }

            $offer = $this->createOfferForListing((int) $campaignId, (int) $listing->variationId, [
                'skip_targeting' => true,
                'activate_campaign' => true,
                'aging_step' => $step,
                'seller_discount_type' => CampaignDiscountType::PERCENT,
                'seller_discount_value' => (float) $suggestion['percent'],
                'platform_discount_type' => CampaignDiscountType::PERCENT,
                'platform_discount_value' => 100,
            ]);
            if (is_wp_error($offer)) {
                $this->campaigns->update((int) $campaignId, ['status' => CampaignStatus::ENDED]);
                continue;
            }
            $created++;
        }

        return $created;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        $rows = $this->campaigns->all();
        $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);
        $counts = $this->offers->countsByCampaignIds($ids);
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->serializeCampaign($row, $counts[(int) $row->id] ?? null);
        }

        return $items;
    }

    /**
     * Admin listing activity payload (listing lookup + presenter).
     *
     * @return array{variation_id: int, deleted: bool, activity: list<array<string, mixed>>}
     */
    public function listingActivityForAdmin(int $variationId): array
    {
        $listing = $this->listings->find($variationId);

        return [
            'variation_id' => $variationId,
            'deleted' => $listing === null,
            'activity' => (new ListingActivityPresenter())->present($variationId),
        ];
    }

    /** @return array<string, mixed> */
    /** @param array{pending?: int, accepted?: int}|null $counts */
    private function serializeCampaign(object $row, ?array $counts = null): array
    {
        $targeting = CampaignTargeting::fromJson(isset($row->targeting) ? (string) $row->targeting : null);
        if ($counts === null) {
            $counts = $this->offers->countsByCampaignIds([(int) $row->id])[(int) $row->id] ?? [
                'pending' => 0,
                'accepted' => 0,
            ];
        }
        $source = CampaignSource::normalize(isset($row->source) ? (string) $row->source : CampaignSource::ADMIN);

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'status' => (string) $row->status,
            'source' => $source,
            'source_label' => CampaignSource::label($source),
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
                __('price', 'sutore-marketplace')
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
            'offers_pending' => (int) ($counts['pending'] ?? 0),
            'offers_accepted' => (int) ($counts['accepted'] ?? 0),
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
