<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingConditionRank;
use SutoreMarketplace\Modules\Listings\Domain\ListingDuration;
use SutoreMarketplace\Modules\Listings\Domain\ListingExpireDisplay;
use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Shared\Domain\ReleasePriceService;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Shared\Settings\Settings;

final class ListingFormContext
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
    ) {
    }

    public function build(array $input): array
    {
        $variationId = isset($input['variation_id']) ? (int) $input['variation_id'] : null;
        $existing = $variationId ? $this->listings->find($variationId) : null;

        $parentId = (int) ($input['parent_product_id'] ?? ($existing?->parentProductId ?? 0));
        $sizeTermId = (int) ($input['size_term_id'] ?? ($existing?->sizeTermId ?? 0));
        $conditions = $input['conditions'] ?? ($existing?->conditions ?? []);
        if (!is_array($conditions)) {
            $conditions = [];
        }
        if ($existing && empty($input['conditions'])) {
            $conditions = $existing->conditions;
        }
        $defectConditions = ListingRepository::defectConditionsOnly($conditions);
        // Request flags win over saved columns so context refresh keeps the live form selection.
        [$fastShipment, $hasInvoice] = ListingRepository::resolveShippingFlags(
            $input,
            array_key_exists('fast_shipment', $input)
                ? !empty($input['fast_shipment'])
                : $existing?->fastShipment,
            array_key_exists('has_invoice', $input)
                ? !empty($input['has_invoice'])
                : $existing?->hasInvoice,
        );

        $canFlagImported = ListingPolicy::canFlagImported();
        $isImported = ($canFlagImported && array_key_exists('is_imported', $input))
            ? !empty($input['is_imported'])
            : (bool) ($existing?->isImported ?? false);

        $fingerprint = ListingRepository::fingerprint($defectConditions, $fastShipment, $hasInvoice);
        $asking = $this->resolveAsking($input, $existing);

        $slotWinner = ($parentId && $sizeTermId)
            ? $this->listings->getWinnerForSize($parentId, $sizeTermId)
            : null;
        if (!$slotWinner && $parentId && $sizeTermId) {
            $competing = $this->listings->findCompetingForSize($parentId, $sizeTermId);
            $slotWinner = $competing[0] ?? null;
        }

        // Min price shown to merchant is for this beden across all condition variants.
        $sizeLeader = ($parentId && $sizeTermId)
            ? $this->listings->getLowestOnSaleForSize($parentId, $sizeTermId)
            : null;
        $priceLeader = $sizeLeader ?: $slotWinner;

        $step = Settings::listingPriceStep();
        $minAsking = $priceLeader ? MarketplacePricing::activeAsking($priceLeader) : null;

        $viewerId = get_current_user_id();
        $requestedMerchant = isset($input['merchant_id']) ? (int) $input['merchant_id'] : 0;
        if ($existing) {
            $merchantId = (int) $existing->merchantId;
        } elseif ($requestedMerchant > 0 && user_can($viewerId, 'manage_woocommerce')) {
            $resolved = ListingPolicy::assertValidMerchantTarget($requestedMerchant);
            $merchantId = is_wp_error($resolved) ? $viewerId : $resolved;
        } else {
            $merchantId = $viewerId;
        }

        $ranked = ($parentId && $sizeTermId)
            ? $this->rankedWithDraft($parentId, $sizeTermId, $existing, $asking, $defectConditions, $fastShipment, $hasInvoice, $merchantId, $fingerprint)
            : [];
        $draftId = $existing?->variationId ?? -1;
        $queuePosition = 1;
        $queueTotal = count($ranked);
        foreach ($ranked as $index => $listing) {
            if ((int) $listing->variationId === (int) $draftId) {
                $queuePosition = $index + 1;
                break;
            }
        }

        $firstPlace = ListingConditionRank::firstPlaceAskingForDraft($ranked, $draftId);
        $canWinSale = $queuePosition === 1;
        $showFirstPlaceButton = $firstPlace !== null;
        $staffSimple = !empty($input['staff_simple']) && user_can($viewerId, 'manage_woocommerce');
        if ($staffSimple) {
            $showFirstPlaceButton = false;
        }

        $commission = $existing
            ? (new \SutoreMarketplace\Modules\Merchants\Services\CommissionResolver())->forListing($existing)['percent']
            : MerchantLevels::commissionPercentForUser($merchantId);
        $merchantStatus = MerchantLevels::statusForUser($merchantId);
        $canViewCompetingPrices = !$staffSimple && ListingPolicy::canViewCompetingPrices($viewerId);
        $estimatedPayout = null;
        if ($asking !== null) {
            $fake = new \SutoreMarketplace\Modules\Listings\Domain\Listing(
                0, $parentId, $sizeTermId, $merchantId, 'pending', (float) $asking, $fingerprint
            );
            $estimatedPayout = MarketplacePricing::merchantPayout($fake, $commission);
        }

        $allowedDurations = ListingPolicy::allowedListingDurations($merchantId);
        $defaultDuration = Settings::defaultListingDurationDays();
        $selectedDuration = $existing?->durationDays ?? $defaultDuration;
        if (array_key_exists('duration_days', $input)) {
            $draftDuration = (int) $input['duration_days'];
            if (in_array($draftDuration, $allowedDurations, true)) {
                $selectedDuration = $draftDuration;
            }
        }
        if (!in_array($selectedDuration, $allowedDurations, true)) {
            $selectedDuration = ListingDuration::clampToAllowed($selectedDuration, $allowedDurations);
        }

        $previewExpireAt = $existing?->expireAt;
        $durationChangedInDraft = $existing
            && array_key_exists('duration_days', $input)
            && (int) $input['duration_days'] !== $existing->durationDays;
        if (
            !$previewExpireAt
            || $existing?->listingStatus === ListingStatus::EXPIRED
            || $durationChangedInDraft
            || !$existing
        ) {
            $previewExpireAt = ListingDuration::computeExpireAt($selectedDuration);
        }

        $expireRemaining = null;
        if ($previewExpireAt) {
            $expireRemaining = max(0, (int) ceil((strtotime((string) $previewExpireAt) - current_time('timestamp')) / DAY_IN_SECONDS));
        }

        $parentThumbnail = $parentId ? ProductThumbnail::url($parentId) : '';
        $parentTitle = $parentId ? get_the_title($parentId) : '';
        $sizeLabel = $sizeTermId ? ProductSizeLookup::labelForTermId($sizeTermId) : '';
        $retail = ReleasePriceService::context($parentId);

        return array_merge([
            'mode' => $existing ? 'edit' : 'create',
            'variation_id' => $existing?->variationId,
            'listing' => $existing ? array_merge($existing->toArray(), [
                'parent_title' => get_the_title($existing->parentProductId),
                'product_code' => ProductCodeLookup::codeForProduct($existing->parentProductId),
                'thumbnail' => ProductThumbnail::url($existing->parentProductId),
            ]) : null,
            'parent_product_id' => $parentId,
            'parent_title' => $parentTitle,
            'parent_thumbnail' => $parentThumbnail,
            'size_term_id' => $sizeTermId,
            'size_label' => $sizeLabel,
            'conditions' => $defectConditions,
            'asking' => $asking,
            'fast_shipment' => $fastShipment,
            'has_invoice' => $hasInvoice,
            'is_imported' => $isImported,
            'can_flag_imported' => $canFlagImported,
            'min_on_sale_asking' => $minAsking,
            'min_on_sale_display' => $minAsking !== null
                ? MarketplacePricing::formatTl((float) $minAsking)
                : null,
            'first_place_asking' => $firstPlace,
            'show_first_place_button' => $showFirstPlaceButton,
            'can_win_sale' => $canWinSale,
            'merchant_auto_activates' => Settings::merchantAutoActivates($merchantId),
            'listing_price_step' => $step,
            'has_winner' => (bool) $priceLeader,
            'winner_variation_id' => $priceLeader?->variationId,
            'queue_position' => $queuePosition,
            'queue_total' => $queueTotal,
            'estimated_net_payout' => $estimatedPayout,
            'commission_percent' => $commission,
            'merchant_status' => $merchantStatus,
            'merchant_status_label' => MerchantLevels::labelForStatus($merchantStatus),
            'listing_compare_display' => $asking !== null
                ? MarketplacePricing::formatTl(MarketplacePricing::listingComparePrice((float) $asking))
                : null,
            'fast_shipment_eligible' => $staffSimple || ListingPolicy::canUseFastShipment($merchantId),
            'duration_days' => $selectedDuration,
            'default_duration_days' => $defaultDuration,
            'allowed_duration_days' => $allowedDurations,
            'duration_options' => array_map(
                static fn (int $days): array => [
                    'days' => $days,
                    'label' => ListingDuration::optionLabel($days),
                ],
                $allowedDurations
            ),
            'expire_days_remaining' => $expireRemaining ?? $selectedDuration,
            'expire_at' => wp_date('c', strtotime((string) $previewExpireAt)),
            'no_active_sale_message' => $priceLeader ? null : __('No active sale for this size', 'sutore-marketplace'),
            'can_view_competing_prices' => $canViewCompetingPrices,
            'can_request_catalog_product' => ListingPolicy::canRequestCatalogProduct($merchantId),
            'staff_simple' => $staffSimple,
            'merchant_id' => $merchantId,
            'competing_prices' => ($parentId && $sizeTermId && $canViewCompetingPrices)
                ? $this->competingPricesForSize($parentId, $sizeTermId, $existing?->variationId, $viewerId)
                : [],
        ], $retail);
    }

    /**
     * @return Listing[]
     */
    private function rankedWithDraft(
        int $parentId,
        int $sizeTermId,
        ?Listing $existing,
        ?float $asking,
        array $defectConditions,
        bool $fastShipment,
        bool $hasInvoice,
        int $merchantId,
        string $fingerprint,
    ): array {
        $candidates = array_values(array_filter(
            $this->listings->findCompetingForSize($parentId, $sizeTermId),
            static fn (Listing $listing): bool => in_array($listing->listingStatus, ['publish', 'queued', 'pending'], true)
        ));

        $draft = $this->buildDraftListing($existing, $parentId, $sizeTermId, $merchantId, $asking, $defectConditions, $fastShipment, $hasInvoice, $fingerprint);
        if ($draft === null) {
            return ListingConditionRank::sortForSale($candidates);
        }

        $candidates = array_values(array_filter(
            $candidates,
            static fn (Listing $listing): bool => (int) $listing->variationId !== (int) $draft->variationId
        ));

        $candidates[] = $draft;

        return ListingConditionRank::sortForSale($candidates);
    }

    private function resolveAsking(array $input, ?Listing $existing): ?float
    {
        if (array_key_exists('asking', $input)) {
            $raw = $input['asking'];
            if ($raw === null || $raw === '') {
                return null;
            }

            return (float) $raw;
        }

        return $existing !== null ? (float) $existing->asking : null;
    }

    private function buildDraftListing(
        ?Listing $existing,
        int $parentId,
        int $sizeTermId,
        int $merchantId,
        ?float $asking,
        array $defectConditions,
        bool $fastShipment,
        bool $hasInvoice,
        string $fingerprint,
    ): ?Listing {
        if ($parentId <= 0 || $sizeTermId <= 0) {
            return null;
        }

        $rankConditions = $this->rankConditions($defectConditions, $existing);
        $rankAsking = $asking ?? PHP_FLOAT_MAX;

        if ($existing) {
            return new Listing(
                variationId: $existing->variationId,
                parentProductId: $existing->parentProductId,
                sizeTermId: $existing->sizeTermId,
                merchantId: $existing->merchantId,
                listingStatus: $existing->listingStatus,
                asking: (float) $rankAsking,
                conditionFingerprint: $fingerprint,
                campaignStatus: $existing->campaignStatus,
                campaignId: $existing->campaignId,
                campaignCooledUntil: $existing->campaignCooledUntil,
                campaignAgingStep: $existing->campaignAgingStep,
                expireAt: $existing->expireAt,
                fastShipment: $fastShipment,
                hasInvoice: $hasInvoice,
                isImported: $existing->isImported,
                productDesc: $existing->productDesc,
                isWinner: $existing->isWinner,
                createdAt: $existing->createdAt,
                updatedAt: $existing->updatedAt,
                conditions: $rankConditions,
                orderId: $existing->orderId,
            );
        }

        return new Listing(
            variationId: -1,
            parentProductId: $parentId,
            sizeTermId: $sizeTermId,
            merchantId: $merchantId,
            listingStatus: 'pending',
            asking: (float) $rankAsking,
            conditionFingerprint: $fingerprint,
            fastShipment: $fastShipment,
            hasInvoice: $hasInvoice,
            createdAt: current_time('mysql'),
            conditions: $rankConditions,
        );
    }

    /** @param array<string, mixed> $conditions */
    private function rankConditions(array $conditions, ?Listing $existing): array
    {
        $out = [];
        foreach (ListingConditionRank::DEFECT_KEYS as $key) {
            if (!empty($conditions[$key])) {
                $out[$key] = true;
            }
        }

        if (!$out && $existing) {
            foreach (ListingConditionRank::DEFECT_KEYS as $key) {
                if (!empty($existing->conditions[$key])) {
                    $out[$key] = true;
                }
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function competingPricesForSize(
        int $parentId,
        int $sizeTermId,
        ?int $currentListingId,
        int $viewerId,
    ): array {
        $ranked = ListingConditionRank::sortForSale(array_values(array_filter(
            $this->listings->findCompetingForSize($parentId, $sizeTermId),
            static fn (Listing $listing): bool => in_array($listing->listingStatus, ['publish', 'queued'], true)
        )));

        $rows = [];
        foreach ($ranked as $index => $listing) {
            $asking = (float) $listing->asking;
            $rows[] = [
                'position' => $index + 1,
                'variation_id' => $listing->variationId,
                'listing_status' => $listing->listingStatus,
                'is_winner' => $listing->isWinner,
                'asking' => $asking,
                'asking_display' => MarketplacePricing::formatTl($asking),
                'compare_display' => MarketplacePricing::formatTl(
                    MarketplacePricing::listingComparePrice($asking)
                ),
                'is_own' => (int) $listing->merchantId === $viewerId,
                'is_current' => $currentListingId !== null && (int) $listing->variationId === $currentListingId,
                'is_flawless' => ListingConditionRank::isFlawless($listing),
                'has_defect' => ListingConditionRank::hasDefect($listing),
                'conditions' => $listing->conditions,
                'fast_shipment' => $listing->fastShipment,
                'has_invoice' => $listing->hasInvoice,
                'remaining_label' => ListingExpireDisplay::label(
                    $listing->expireAt,
                    $listing->listingStatus,
                    $listing->isWinner
                ),
            ];
        }

        return $rows;
    }

}
