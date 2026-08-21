<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Orders\Services\Notifications;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingCampaignPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingConditionRank;
use SutoreMarketplace\Modules\Listings\Domain\ListingDuration;
use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\MerchantBulkAction;
use SutoreMarketplace\Modules\Listings\Hooks\VariationLifecycleGuard;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignRepository;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Domain\ReleasePriceService;
use SutoreMarketplace\Modules\Listings\Repositories\ListingConditionsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Repositories\RestrictionsRepository;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Shared\Settings\Settings;

final class ListingService
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingConditionsRepository $conditions = new ListingConditionsRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
        private readonly ListingSelector $selector = new ListingSelector(),
        private readonly RestrictionsRepository $restrictions = new RestrictionsRepository(),
    ) {
    }

    public function create(array $input, ?int $userId = null, array $options = []): Listing|\WP_Error
    {
        $actorId = $userId ?: get_current_user_id();
        $auth = ListingPolicy::assertCanManage($actorId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $merchantId = ListingPolicy::resolveCreateMerchantId($actorId, $input, $options);
        if (is_wp_error($merchantId)) {
            return $merchantId;
        }

        // Staff creating for another merchant bypasses that seller's create restrictions.
        $staffForMerchant = $actorId !== $merchantId;
        if (!$staffForMerchant && ($this->restrictions->hasActive($merchantId, 'listing_create_ban')
            || $this->restrictions->hasActive($merchantId, 'disabled_account'))) {
            return new \WP_Error('sutore_marketplace_restricted', __('Product creation is restricted.', 'sutore-marketplace'));
        }

        $parentId = (int) ($input['parent_product_id'] ?? 0);
        $sizeTermId = (int) ($input['size_term_id'] ?? 0);

        $askingCheck = ListingPriceValidator::assertStepMultiple($input['asking'] ?? 0);
        if (is_wp_error($askingCheck)) {
            $this->events->log('price_validation_failed', [
                'asking' => $input['asking'] ?? null,
                'parent_product_id' => $parentId,
                'actor_user_id' => $actorId,
            ], null, $merchantId);
            return $askingCheck;
        }
        $asking = (int) ListingPriceValidator::normalizeAsking($input['asking']);

        $retailTl = ReleasePriceService::retailTl($parentId);
        $belowRetail = $retailTl !== null && $asking < $retailTl;

        $conditions = ListingRepository::defectConditionsOnly($this->normalizeConditions($input));
        if (!$staffForMerchant && !empty($input['fast_shipment']) && !ListingPolicy::canUseFastShipment($merchantId)) {
            return new \WP_Error('sutore_marketplace_fast_shipment', __('You are not eligible for fast shipping.', 'sutore-marketplace'));
        }

        [$fastShipment, $hasInvoice] = ListingRepository::resolveShippingFlags($input);

        $expireAtOverride = CampaignDatetime::normalizeInput($options['expire_at'] ?? null);
        if ($expireAtOverride) {
            $endsTs = CampaignDatetime::toTimestamp($expireAtOverride) ?? time();
            $durationDays = max(1, (int) ceil(($endsTs - time()) / DAY_IN_SECONDS));
            $expireAt = $expireAtOverride;
        } else {
            $durationDays = $this->resolveDurationForCreate($input, $merchantId);
            if (is_wp_error($durationDays)) {
                return $durationDays;
            }
            $expireAt = ListingDuration::computeExpireAt($durationDays);
        }

        $sizeOk = ProductSizeLookup::assertTermAllowedForParent($parentId, $sizeTermId);
        if (is_wp_error($sizeOk)) {
            return $sizeOk;
        }

        $variationId = $this->createVariation($parentId, $sizeTermId, $asking, $merchantId, $input);
        if (is_wp_error($variationId)) {
            return $variationId;
        }

        $isImported = $this->resolveImportedFlag($input, $actorId, $variationId);
        if ($isImported) {
            ImportedProductService::setVariationImported($variationId, true);
        }

        $fingerprint = ListingRepository::fingerprint($conditions, $fastShipment, $hasInvoice);

        $insert = [
            'variation_id' => $variationId,
            'parent_product_id' => $parentId,
            'size_term_id' => $sizeTermId,
            'merchant_id' => $merchantId,
            'listing_status' => 'pending',
            'asking' => $asking,
            'condition_fingerprint' => $fingerprint,
            'campaign_status' => 'none',
            'expire_at' => $expireAt,
            'listing_duration_days' => $durationDays,
            'fast_shipment' => $fastShipment ? 1 : 0,
            'has_invoice' => $hasInvoice ? 1 : 0,
            'is_imported' => $isImported ? 1 : 0,
            'is_winner' => 0,
        ];
        if (array_key_exists('commission_percent', $options)) {
            $override = $options['commission_percent'];
            $insert['commission_percent'] = $override === null ? null : round((float) $override, 2);
        }

        $insertedId = $this->listings->insert($insert);

        if ($insertedId <= 0) {
            VariationLifecycleGuard::allowPluginDelete(static function () use ($variationId): void {
                wp_delete_post($variationId, true);
            });

            return new \WP_Error(
                'sutore_marketplace_listing_insert',
                __('Product could not be saved.', 'sutore-marketplace')
            );
        }

        $this->conditions->sync($insertedId, $conditions);
        $listing = $this->listings->find($insertedId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_create_failed', __('Product could not be created.', 'sutore-marketplace'));
        }

        $this->events->log('listing_created', array_merge($listing->toArray(), [
            'below_retail' => $belowRetail,
            'actor_user_id' => $actorId,
        ]), $variationId, $merchantId, 'merchant_visible');

        if (empty($options['defer_selector'])) {
            $this->selector->rerunSize($parentId, $sizeTermId);
        }

        $fresh = $this->listings->find($insertedId);
        if (
            !empty($options['force_publish'])
            && $fresh
            && $fresh->listingStatus === ListingStatus::PENDING
        ) {
            $approved = $this->selector->approvePendingWinner($insertedId);
            if (!is_wp_error($approved)) {
                $fresh = $approved;
            }
        }

        return $fresh ?: $listing;
    }

    public function update(int $listingId, array $input, ?int $userId = null): Listing|\WP_Error
    {
        $userId = $userId ?: get_current_user_id();
        $auth = ListingPolicy::assertCanManage($userId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_not_found', __('Product not found.', 'sutore-marketplace'));
        }

        $owns = ListingPolicy::assertOwnsListing($listing, $userId);
        if (is_wp_error($owns)) {
            return $owns;
        }

        if ($this->restrictions->hasActive($userId, 'price_update_ban')
            || $this->restrictions->hasActive($userId, 'disabled_account')) {
            return new \WP_Error('sutore_marketplace_restricted', __('Update restricted.', 'sutore-marketplace'));
        }

        if (ListingStatus::isProcessLocked($listing)) {
            return new \WP_Error(
                'sutore_marketplace_order_locked',
                __('A product in the order process cannot be updated.', 'sutore-marketplace')
            );
        }

        $campaignLock = ListingCampaignPolicy::assertCanUpdate($listing);
        if (is_wp_error($campaignLock)) {
            return $campaignLock;
        }

        $outletLock = $this->assertOutletUnlocked($listing);
        if (is_wp_error($outletLock)) {
            return $outletLock;
        }

        if (isset($input['parent_product_id']) && (int) $input['parent_product_id'] !== $listing->parentProductId) {
            return new \WP_Error(
                'sutore_marketplace_product_locked',
                __('Cannot be changed after the product is created.', 'sutore-marketplace')
            );
        }

        if (
            array_key_exists('size_term_id', $input)
            && (int) $input['size_term_id'] > 0
            && (int) $input['size_term_id'] !== $listing->sizeTermId
        ) {
            return new \WP_Error(
                'sutore_marketplace_size_locked',
                __('Size cannot be changed after creation.', 'sutore-marketplace')
            );
        }

        $askingCheck = ListingPriceValidator::assertStepMultiple($input['asking'] ?? $listing->asking);
        if (is_wp_error($askingCheck)) {
            return $askingCheck;
        }
        $asking = (int) ListingPriceValidator::normalizeAsking($input['asking'] ?? $listing->asking);

        $askingAllowed = ListingCampaignPolicy::assertAskingChange($listing, (float) $asking);
        if (is_wp_error($askingAllowed)) {
            return $askingAllowed;
        }

        $retailTl = ReleasePriceService::retailTl($listing->parentProductId);
        $belowRetail = $retailTl !== null && $asking < $retailTl;

        $conditions = ListingRepository::defectConditionsOnly(
            $this->normalizeConditions($input + ['conditions' => $listing->conditions])
        );
        if (array_key_exists('fast_shipment', $input) && !empty($input['fast_shipment']) && !ListingPolicy::canUseFastShipment($userId)) {
            return new \WP_Error('sutore_marketplace_fast_shipment', __('You are not eligible for fast shipping.', 'sutore-marketplace'));
        }

        [$fastShipment, $hasInvoice] = ListingRepository::resolveShippingFlags(
            $input,
            array_key_exists('fast_shipment', $input) ? !empty($input['fast_shipment']) : $listing->fastShipment,
            array_key_exists('has_invoice', $input) ? !empty($input['has_invoice']) : $listing->hasInvoice,
        );

        $isImported = $listing->isImported;
        if (array_key_exists('is_imported', $input) && ListingPolicy::canFlagImported($userId)) {
            $isImported = !empty($input['is_imported']);
            ImportedProductService::setVariationImported($listing->variationId, $isImported);
        }

        $fingerprint = ListingRepository::fingerprint($conditions, $fastShipment, $hasInvoice);
        $durationPatch = $this->resolveDurationForUpdate($input, $listing);
        if (is_wp_error($durationPatch)) {
            return $durationPatch;
        }

        $this->listings->update($listingId, [
            'asking' => $asking,
            'condition_fingerprint' => $fingerprint,
            'fast_shipment' => $fastShipment ? 1 : 0,
            'has_invoice' => $hasInvoice ? 1 : 0,
            'is_imported' => $isImported ? 1 : 0,
            'listing_duration_days' => $durationPatch['duration_days'],
            'expire_at' => $durationPatch['expire_at'],
            'listing_status' => $durationPatch['listing_status'],
        ]);

        $this->conditions->sync($listingId, $conditions);

        if (ListingCampaignPolicy::isActiveCampaign($listing)) {
            $this->resyncActiveCampaignSale($listing, $asking);
        } else {
            $this->writeVariationPrices($listing->variationId, $asking);
        }

        $updated = $this->listings->find($listingId);
        if ($updated) {
            $this->logUpdateChangeEvents($listing, $updated, $userId, $belowRetail, $fastShipment, $hasInvoice, $conditions);
        }

        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        $updatedListing = $this->listings->find($listingId);

        return $updatedListing ?: new \WP_Error('sutore_marketplace_update_failed', __('Update failed.', 'sutore-marketplace'));
    }

    /**
     * Staff-only listing commission override. Null clears the field (normal resolver flow). 0 is a real rate.
     */
    public function setCommissionPercent(int $listingId, ?float $percent, string $note = ''): Listing|\WP_Error
    {
        if (!\SutoreMarketplace\Admin\StaffCapabilities::canManageOps()) {
            return new \WP_Error(
                'sutore_marketplace_forbidden',
                __('You are not allowed to set a product commission.', 'sutore-marketplace'),
                ['status' => 403]
            );
        }

        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_not_found', __('Product not found.', 'sutore-marketplace'));
        }

        if ($percent !== null) {
            $percent = round($percent, 2);
            if ($percent < 0 || $percent > 100) {
                return new \WP_Error(
                    'sutore_commission_invalid',
                    __('Commission percent must be between 0 and 100.', 'sutore-marketplace'),
                    ['status' => 400]
                );
            }
        }

        $this->listings->update($listingId, [
            'commission_percent' => $percent,
        ]);

        $note = sanitize_textarea_field($note);
        $this->events->log('listing_commission_set', [
            'previous_percent' => $listing->commissionPercent,
            'commission_percent' => $percent,
            'staff_note' => $note,
        ], $listing->variationId, $listing->merchantId, 'admin_only');

        if ($listing->merchantId > 0) {
            (new \SutoreMarketplace\Modules\Merchants\Repositories\MerchantEventsRepository())->log(
                $listing->merchantId,
                'merchant_listing_commission_set',
                [
                    'actor_role' => 'staff',
                    'variation_id' => $listing->variationId,
                    'previous_percent' => $listing->commissionPercent,
                    'commission_percent' => $percent,
                    'staff_note' => $note,
                ]
            );
        }

        $updated = $this->listings->find($listingId);

        return $updated ?: $listing;
    }

    /**
     * Put a not-for-sale / expired listing back into the sale queue.
     */
    public function putOnSale(int $listingId, ?int $userId = null): Listing|\WP_Error
    {
        $userId = $userId ?: get_current_user_id();
        $auth = ListingPolicy::assertCanManage($userId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_not_found', __('Product not found.', 'sutore-marketplace'));
        }

        $owns = ListingPolicy::assertOwnsListing($listing, $userId);
        if (is_wp_error($owns)) {
            return $owns;
        }

        $allowed = $this->assertCanPutOnSale($listing);
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $allowed = ListingPolicy::allowedListingDurations($userId);
        $durationDays = ListingDuration::clampToAllowed($listing->durationDays, $allowed);
        $expireAt = ListingDuration::computeExpireAt($durationDays);

        $this->listings->update($listingId, [
            'listing_status' => 'pending',
            'order_id' => null,
            'order_item_id' => null,
            'sold_at' => null,
            'is_winner' => 0,
            'listing_duration_days' => $durationDays,
            'expire_at' => $expireAt,
        ]);

        $product = wc_get_product($listing->variationId);
        if ($product) {
            $product->set_status('draft');
            $product->set_manage_stock(true);
            $product->set_stock_quantity(1);
            $product->set_stock_status('outofstock');
            $product->save();
        }

        $this->events->log('listing_put_on_sale', [
            'from_status' => $listing->listingStatus,
        ], $listing->variationId, $userId, 'merchant_visible');

        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        $updated = $this->listings->find($listingId);

        return $updated ?: new \WP_Error('sutore_marketplace_update_failed', __('Update failed.', 'sutore-marketplace'));
    }

    /**
     * Remove an on-sale / queued / pending listing without deleting it.
     *
     * @param array{staff_note?:string} $options
     */
    public function removeFromSale(int $listingId, ?int $userId = null, array $options = []): Listing|\WP_Error
    {
        $userId = $userId ?: get_current_user_id();
        $auth = ListingPolicy::assertCanManage($userId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_not_found', __('Product not found.', 'sutore-marketplace'));
        }

        $owns = ListingPolicy::assertOwnsListing($listing, $userId);
        if (is_wp_error($owns)) {
            return $owns;
        }

        $allowed = $this->assertCanRemoveFromSale($listing);
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $isStaff = \SutoreMarketplace\Admin\StaffCapabilities::canManageOps()
            && (int) $listing->merchantId !== $userId;
        $staffNote = sanitize_textarea_field((string) ($options['staff_note'] ?? ''));
        if ($isStaff && trim($staffNote) === '') {
            return new \WP_Error(
                'sutore_marketplace_staff_note_required',
                __('A staff note is required for this action.', 'sutore-marketplace')
            );
        }

        $this->listings->update($listingId, [
            'listing_status' => ListingStatus::NOT_SALE,
            'is_winner' => 0,
        ]);

        $product = wc_get_product($listing->variationId);
        if ($product) {
            $product->set_status('draft');
            $product->set_stock_status('outofstock');
            $product->set_stock_quantity(0);
            $product->save();
        }

        $this->events->log('listing_removed_from_sale', array_filter([
            'from_status' => $listing->listingStatus,
            'to_status' => ListingStatus::NOT_SALE,
            'staff_note' => $staffNote !== '' ? $staffNote : null,
        ], static fn ($value) => $value !== null && $value !== ''), $listing->variationId, $listing->merchantId, 'merchant_visible');

        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        $updated = $this->listings->find($listingId);

        return $updated ?: new \WP_Error('sutore_marketplace_update_failed', __('Update failed.', 'sutore-marketplace'));
    }

    public function canRemoveFromSale(Listing $listing): bool
    {
        return !is_wp_error($this->assertCanRemoveFromSale($listing));
    }

    public function assertCanRemoveFromSale(Listing $listing): true|\WP_Error
    {
        if (!in_array($listing->listingStatus, ListingStatus::removableFromSale(), true)) {
            return new \WP_Error(
                'sutore_marketplace_cannot_remove_from_sale',
                __('Only products awaiting sale or already for sale can be removed from sale.', 'sutore-marketplace')
            );
        }

        if ($listing->orderId || ListingStatus::isProcessLocked($listing)) {
            return new \WP_Error(
                'sutore_marketplace_order_locked',
                __('A product linked to an order cannot be removed from sale here. Use sale actions.', 'sutore-marketplace')
            );
        }

        $outletLock = $this->assertOutletUnlocked($listing);
        if (is_wp_error($outletLock)) {
            return $outletLock;
        }

        if ($listing->variationId) {
            $activeFulfillment = (new FulfillmentRepository())->findActiveByVariationId((int) $listing->variationId);
            if ($activeFulfillment) {
                return new \WP_Error(
                    'sutore_marketplace_order_locked',
                    __('A product linked to an order cannot be removed from sale here. Use sale actions.', 'sutore-marketplace')
                );
            }
        }

        return true;
    }

    /**
     * Whether this listing can re-enter the sale queue (status + seller restrictions + no active fulfillment).
     */
    public function canPutOnSale(Listing $listing): bool
    {
        return !is_wp_error($this->assertCanPutOnSale($listing));
    }

    public function assertCanPutOnSale(Listing $listing): true|\WP_Error
    {
        $status = (string) $listing->listingStatus;
        if (!in_array($status, ListingStatus::relistable(), true)) {
            return new \WP_Error(
                'sutore_marketplace_cannot_put_on_sale',
                __('Only products that are not for sale or expired can be put back on sale.', 'sutore-marketplace')
            );
        }

        $merchantId = (int) $listing->merchantId;
        if ($this->restrictions->hasActive($merchantId, 'disabled_account')) {
            return new \WP_Error(
                'sutore_marketplace_restricted',
                __('This seller account is disabled and cannot put products on sale.', 'sutore-marketplace')
            );
        }

        if ($this->restrictions->hasActive($merchantId, 'listing_create_ban')) {
            return new \WP_Error(
                'sutore_marketplace_restricted',
                __('This seller is restricted from creating or putting products on sale.', 'sutore-marketplace')
            );
        }

        if (ListingStatus::isPreOrder($listing)) {
            return new \WP_Error(
                'sutore_marketplace_sourcing_held',
                __('This product is held for a pre-order and cannot be put on sale.', 'sutore-marketplace')
            );
        }

        if ($listing->variationId) {
            $activeFulfillment = (new FulfillmentRepository())->findActiveByVariationId((int) $listing->variationId);
            if ($activeFulfillment) {
                return new \WP_Error(
                    'sutore_marketplace_order_locked',
                    __('A product in the order process cannot be updated.', 'sutore-marketplace')
                );
            }
        }

        return true;
    }

    public function delete(int $listingId, ?int $userId = null, array $options = []): true|\WP_Error
    {
        $userId = $userId ?: get_current_user_id();
        $auth = ListingPolicy::assertCanManage($userId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_not_found', __('Product not found.', 'sutore-marketplace'));
        }

        $owns = ListingPolicy::assertOwnsListing($listing, $userId);
        if (is_wp_error($owns)) {
            return $owns;
        }

        $allowed = $this->assertCanDelete($listing);
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $parent = $listing->parentProductId;
        $size = $listing->sizeTermId;
        $variationId = $listing->variationId;
        $merchantId = (int) $listing->merchantId;

        // Events keep merchant_id after this row is gone (accounting trail).
        // Payout lines are not deleted with the listing for the same reason.
        $this->events->log('listing_deleted', array_filter([
            'variation_id' => $variationId,
            'parent_product_id' => $listing->parentProductId,
            'size_term_id' => $listing->sizeTermId,
            'listing_status' => $listing->listingStatus,
            'asking' => $listing->asking,
            'is_winner' => $listing->isWinner ? 1 : 0,
            'condition_fingerprint' => $listing->conditionFingerprint,
            'deletion_source' => $this->resolveDeletionSource($userId, $listing, $options),
            'events_retained' => true,
        ], static fn ($value) => $value !== null && $value !== ''), $variationId, $merchantId, 'merchant_visible');

        $this->conditions->deleteForListing($listingId);
        $this->listings->delete($listingId);
        $this->forceDeleteVariation($variationId);

        $this->selector->rerunSize($parent, $size);

        return true;
    }

    public function canDelete(Listing $listing): bool
    {
        return !is_wp_error($this->assertCanDelete($listing));
    }

    public function canEdit(Listing $listing): bool
    {
        if (ListingCampaignPolicy::blocksAllUpdates($listing)) {
            return false;
        }

        if (is_wp_error($this->assertOutletUnlocked($listing))) {
            return false;
        }

        if (ListingStatus::isProcessLocked($listing)) {
            return false;
        }

        if ($listing->orderId || $this->isOrderLinked($listing->variationId)) {
            return false;
        }

        if ($listing->variationId) {
            $activeFulfillment = (new FulfillmentRepository())->findActiveByVariationId((int) $listing->variationId);
            if ($activeFulfillment) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keep WC scheduled sale in sync when asking changes during an active campaign.
     * Avoids ActivePriceSync clearing sale fields.
     */
    private function resyncActiveCampaignSale(Listing $listing, int $asking): void
    {
        if (!$listing->variationId) {
            return;
        }

        $offers = new CampaignOfferRepository();
        $offer = $offers->findAcceptedForVariation((int) $listing->variationId);
        if (!$offer) {
            $this->writeVariationPrices($listing->variationId, $asking);
            return;
        }

        $hizmet = Settings::hizmetBedeli();
        $guvence = MarketplacePricing::guvenceFeeForAsking((float) $asking);
        $feesFull = $hizmet + $guvence;
        $waiver = min(max(0.0, (float) ($offer->platform_discount ?? 0)), $feesFull);
        $customerSale = round(max(0.0, $asking + $feesFull - $waiver), 2);
        $compareRegular = isset($offer->compare_regular) && (float) $offer->compare_regular > 0
            ? (float) $offer->compare_regular
            : MarketplacePricing::listingComparePrice((float) ($offer->asking_before ?? $asking));

        $offers->update((int) $offer->id, [
            'customer_sale' => $customerSale,
            'compare_regular' => $compareRegular,
        ]);

        $campaign = $listing->campaignId
            ? (new CampaignRepository())->find((int) $listing->campaignId)
            : null;

        CampaignWcSaleSync::writeSale(
            $listing->variationId,
            $compareRegular,
            $customerSale,
            $campaign && isset($campaign->starts_at) ? (string) $campaign->starts_at : null,
            $campaign && isset($campaign->ends_at) ? (string) $campaign->ends_at : null
        );
        Settings::bumpPricingRevision();
    }

    public function assertCanDelete(Listing $listing): true|\WP_Error
    {
        $outletLock = $this->assertOutletUnlocked($listing);
        if (is_wp_error($outletLock)) {
            return $outletLock;
        }

        if ($listing->orderId || $this->isOrderLinked($listing->variationId) || ListingStatus::isProcessLocked($listing)) {
            return new \WP_Error(
                'sutore_marketplace_cannot_delete',
                __('This product cannot be deleted right now. (In order or payment process.)', 'sutore-marketplace')
            );
        }

        if ($listing->variationId) {
            $activeFulfillment = (new FulfillmentRepository())->findActiveByVariationId((int) $listing->variationId);
            if ($activeFulfillment) {
                return new \WP_Error(
                    'sutore_marketplace_cannot_delete',
                    __('This product cannot be deleted right now. (In order or payment process.)', 'sutore-marketplace')
                );
            }
        }

        return true;
    }

    private function assertOutletUnlocked(Listing $listing): true|\WP_Error
    {
        return (new OutletService())->assertListingUnlocked($listing);
    }

    public function purgeAllForMerchant(int $userId): true|\WP_Error
    {
        $ids = [];
        $page = 1;

        do {
            $result = $this->listings->query([
                'merchant_id' => $userId,
                'per_page' => 100,
                'page' => $page,
            ]);

            foreach ($result['items'] as $listing) {
                if ($listing->variationId) {
                    $ids[] = (int) $listing->variationId;
                }
            }

            $page++;
        } while (count($result['items']) === 100);

        foreach (array_values(array_unique($ids)) as $listingId) {
            $listing = $this->listings->find($listingId);
            if (!$listing) {
                continue;
            }

            // Keep completed / in-process rows (delivered, chargeback, open sales).
            if (is_wp_error($this->assertCanDelete($listing))) {
                continue;
            }

            $deleted = $this->delete($listingId, $userId, ['deletion_source' => 'account_purge']);
            if ($deleted instanceof \WP_Error) {
                return $deleted;
            }
        }

        return $this->purgeOrphanVariations($userId);
    }

    public function expire(Listing $listing): void
    {
        if (!$listing->variationId) {
            return;
        }

        $wasWinner = $listing->isWinner;
        $parentId = $listing->parentProductId;
        $sizeTermId = $listing->sizeTermId;

        $this->listings->update($listing->variationId, [
            'listing_status' => 'expired',
            'is_winner' => 0,
        ]);

        $product = wc_get_product($listing->variationId);
        if ($product) {
            $product->set_status('draft');
            $product->set_stock_status('outofstock');
            $product->save();
        }

        $this->events->log('listing_expired', [
            'variation_id' => $listing->variationId,
            'was_winner' => $wasWinner,
        ], $listing->variationId, $listing->merchantId, 'merchant_visible');

        (new OutletService())->markExpiredForListing((int) $listing->variationId);

        $title = Notifications::productTitle((int) $listing->variationId, $listing->variationId, $listing->parentProductId);
        (new NotificationService())->dispatch((int) $listing->merchantId, NotificationType::LISTING_EXPIRED, [
            'product' => $title,
            'variation_id' => (int) $listing->variationId,
        ]);

        $this->selector->rerunSize($parentId, $sizeTermId);
    }

    /** @param array<string, bool> $newConditions */
    private function logUpdateChangeEvents(
        Listing $before,
        Listing $after,
        int $userId,
        bool $belowRetail,
        bool $fastShipment,
        bool $hasInvoice,
        array $newConditions
    ): void {
        $variationId = $before->variationId;
        $merchantId = (int) $before->merchantId;
        $logged = 0;

        $newAsking = (int) $after->asking;
        $oldAsking = (int) $before->asking;
        if ($newAsking !== $oldAsking) {
            $this->events->log('listing_price_changed', [
                'old_asking' => $oldAsking,
                'new_asking' => $newAsking,
                'below_retail' => $belowRetail,
            ], $variationId, $merchantId, 'merchant_visible');
            $logged++;
        }

        $changedConditionKeys = $this->changedConditionKeys($before->conditions, $newConditions);
        if ($changedConditionKeys !== []) {
            $this->events->log('listing_condition_changed', [
                'old_fingerprint' => $before->conditionFingerprint,
                'new_fingerprint' => $after->conditionFingerprint,
                'changed_keys' => $changedConditionKeys,
                'old_conditions' => $this->activeConditionKeys($before->conditions),
                'new_conditions' => $this->activeConditionKeys($newConditions),
            ], $variationId, $merchantId, 'merchant_visible');
            $logged++;
        }

        if ($before->fastShipment !== $fastShipment || $before->hasInvoice !== $hasInvoice) {
            $this->events->log('listing_shipping_changed', [
                'old_fast_shipment' => $before->fastShipment ? 1 : 0,
                'new_fast_shipment' => $fastShipment ? 1 : 0,
                'old_has_invoice' => $before->hasInvoice ? 1 : 0,
                'new_has_invoice' => $hasInvoice ? 1 : 0,
            ], $variationId, $merchantId, 'merchant_visible');
            $logged++;
        }

        if ($before->durationDays !== $after->durationDays) {
            $this->events->log('listing_duration_changed', [
                'old_duration_days' => $before->durationDays,
                'new_duration_days' => $after->durationDays,
                'old_expire_at' => $before->expireAt,
                'new_expire_at' => $after->expireAt,
            ], $variationId, $merchantId, 'merchant_visible');
            $logged++;
        }

        if ($logged === 0 && $before->listingStatus !== $after->listingStatus) {
            $this->events->log('listing_status_changed', [
                'old_status' => $before->listingStatus,
                'new_status' => $after->listingStatus,
            ], $variationId, $merchantId, 'merchant_visible');
        }
    }

    /** @param array<string, mixed> $conditions @return list<string> */
    private function changedConditionKeys(array $conditions, array $newConditions): array
    {
        $changed = [];
        foreach (ListingConditionRank::DEFECT_KEYS as $key) {
            if (!empty($conditions[$key]) !== !empty($newConditions[$key])) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /** @param array<string, mixed> $conditions @return list<string> */
    private function activeConditionKeys(array $conditions): array
    {
        $active = [];
        foreach (ListingConditionRank::DEFECT_KEYS as $key) {
            if (!empty($conditions[$key])) {
                $active[] = $key;
            }
        }

        return $active;
    }

    private function normalizeConditions(array $input): array
    {
        $raw = $input['conditions'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $known = ListingConditionRank::DEFECT_KEYS;
        $out = [];
        foreach ($known as $key) {
            if (!empty($raw[$key]) || in_array($key, $raw, true) || !empty($input[$key])) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    /**
     * Staff may flag the listing explicitly; otherwise the variation meta decides.
     *
     * @param array<string, mixed> $input
     */
    private function resolveImportedFlag(array $input, int $actorId, int $variationId): bool
    {
        if (array_key_exists('is_imported', $input) && ListingPolicy::canFlagImported($actorId)) {
            return !empty($input['is_imported']);
        }

        return ImportedProductService::isVariationImported($variationId);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resolveDurationForCreate(array $input, int $merchantId): int|\WP_Error
    {
        $default = Settings::defaultListingDurationDays();
        $requested = array_key_exists('duration_days', $input)
            ? (int) $input['duration_days']
            : $default;

        return ListingPolicy::assertValidDuration($requested, $merchantId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{duration_days:int,expire_at:string,listing_status:string}|\WP_Error
     */
    private function resolveDurationForUpdate(array $input, Listing $listing): array|\WP_Error
    {
        $merchantId = (int) $listing->merchantId;
        $currentDays = $listing->durationDays;
        $requested = array_key_exists('duration_days', $input)
            ? (int) $input['duration_days']
            : $currentDays;
        $durationDays = ListingPolicy::assertValidDuration($requested, $merchantId);
        if (is_wp_error($durationDays)) {
            return $durationDays;
        }

        $wasExpired = $listing->listingStatus === ListingStatus::EXPIRED;
        $durationChanged = $durationDays !== $currentDays;
        $listingStatus = $wasExpired ? ListingStatus::PENDING : $listing->listingStatus;
        $expireAt = (string) ($listing->expireAt ?? '');

        if ($wasExpired || $durationChanged) {
            $expireAt = ListingDuration::computeExpireAt($durationDays);
        }

        return [
            'duration_days' => $durationDays,
            'expire_at' => $expireAt,
            'listing_status' => $listingStatus,
        ];
    }

    private function createVariation(int $parentId, int $sizeTermId, int $asking, int $userId, array $input): int|\WP_Error
    {
        $parent = wc_get_product($parentId);
        if (!$parent || $parent->get_type() !== 'variable') {
            return new \WP_Error('sutore_marketplace_parent', __('Select a valid parent product.', 'sutore-marketplace'));
        }

        $allowed = null;
        foreach (ProductSizeLookup::termsForParent($parentId) as $item) {
            if ((int) $item['term_id'] === $sizeTermId) {
                $allowed = $item;
                break;
            }
        }
        if ($allowed === null) {
            return new \WP_Error('sutore_marketplace_size', __('Select a valid size.', 'sutore-marketplace'));
        }

        $term = get_term($sizeTermId, (string) $allowed['taxonomy']);
        if (!$term || is_wp_error($term)) {
            return new \WP_Error('sutore_marketplace_size', __('Select a valid size.', 'sutore-marketplace'));
        }

        $variation = new \WC_Product_Variation();
        $variation->set_parent_id($parentId);
        $variation->set_status('draft');
        $variation->set_catalog_visibility('visible');
        $variation->set_regular_price((string) $asking);
        $variation->set_price((string) $asking);
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity(1);
        $variation->set_stock_status('outofstock');
        $variation->set_attributes([
            $term->taxonomy => $term->slug,
        ]);
        $vid = $variation->save();

        if (!$vid) {
            return new \WP_Error('sutore_marketplace_variation', __('Variation could not be created.', 'sutore-marketplace'));
        }

        wp_update_post([
            'ID' => $vid,
            'post_author' => $userId,
        ]);

        // Intentionally NEVER write merchant_product / child_of / term_id.
        return (int) $vid;
    }

    private function writeVariationPrices(int $variationId, int $asking): void
    {
        ActivePriceSync::writeVariationAsking($variationId, $asking);
    }

    private function isOrderLinked(int $variationId): bool
    {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
             WHERE oim.meta_key IN ('_product_id','_variation_id') AND oim.meta_value = %d
             LIMIT 1",
            $variationId
        ));

        return $count > 0;
    }

    private function purgeOrphanVariations(int $userId): true|\WP_Error
    {
        $variationIds = get_posts([
            'post_type' => 'product_variation',
            'post_status' => ['pending', 'publish', 'private', 'draft', 'trash'],
            'author' => $userId,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($variationIds as $variationId) {
            $variationId = (int) $variationId;
            if ($variationId <= 0) {
                continue;
            }

            $listing = $this->listings->findByVariationId($variationId);
            if ($listing?->variationId) {
                if (is_wp_error($this->assertCanDelete($listing))) {
                    continue;
                }

                $deleted = $this->delete((int) $listing->variationId, $userId, ['deletion_source' => 'account_purge']);
                if ($deleted instanceof \WP_Error) {
                    return $deleted;
                }

                continue;
            }

            if ($this->isOrderLinked($variationId)) {
                return new \WP_Error(
                    'sutore_account_delete_order_variation',
                    __('Your account cannot be deleted because one of your products is linked to an order.', 'sutore-marketplace')
                );
            }

            $context = $this->resolveSizeContextFromVariation($variationId);
            $this->events->log('listing_deleted', array_filter([
                'variation_id' => $variationId,
                'parent_product_id' => $context !== null ? $context['parentId'] : null,
                'size_term_id' => $context !== null ? $context['sizeTermId'] : null,
                'deletion_source' => 'orphan_variation',
                'events_retained' => true,
            ], static fn ($value) => $value !== null && $value !== ''), $variationId, $userId, 'admin_only');
            $this->forceDeleteVariation($variationId);

            if ($context !== null) {
                $this->selector->rerunSize($context['parentId'], $context['sizeTermId']);
            }
        }

        return true;
    }

    private function forceDeleteVariation(int $variationId): void
    {
        if ($variationId <= 0) {
            return;
        }

        VariationLifecycleGuard::allowPluginDelete(static function () use ($variationId): void {
            wp_delete_post($variationId, true);
        });
    }

    /** @param array<string, mixed> $options */
    private function resolveDeletionSource(int $userId, Listing $listing, array $options): string
    {
        $explicit = sanitize_key((string) ($options['deletion_source'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        if ((int) $listing->merchantId !== $userId && \SutoreMarketplace\Admin\StaffCapabilities::canManageOps($userId)) {
            return 'admin';
        }

        return 'merchant';
    }

    /** @return array{parentId:int,sizeTermId:int}|null */
    /**
     * Merchant My Listings multi-select workflows.
     *
     * @param list<int|string> $listingIds
     * @return array{updated: int, action: string}|\WP_Error
     */
    public function bulkMerchantAction(array $listingIds, string $action, ?int $userId = null): array|\WP_Error
    {
        $userId = $userId ?: get_current_user_id();
        $auth = ListingPolicy::assertCanManage($userId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        if (!MerchantBulkAction::isValid($action)) {
            return new \WP_Error(
                'sutore_marketplace_bulk_action_invalid',
                __('Invalid bulk action.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $listingIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return new \WP_Error(
                'sutore_marketplace_bulk_ids_required',
                __('Select at least one product.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        if (count($ids) > 100) {
            return new \WP_Error(
                'sutore_marketplace_bulk_too_many',
                __('You can update at most 100 products at once.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        foreach ($ids as $id) {
            $listing = $this->listings->find($id);
            if (!$listing) {
                return new \WP_Error(
                    'sutore_marketplace_not_found',
                    /* translators: %d: listing id */
                    sprintf(__('Product #%d was not found.', 'sutore-marketplace'), $id),
                    ['status' => 404]
                );
            }

            $owns = ListingPolicy::assertOwnsListing($listing, $userId);
            if (is_wp_error($owns)) {
                return $owns;
            }

            $allowed = match ($action) {
                'put_on_sale' => $this->assertCanPutOnSale($listing),
                'remove_from_sale' => $this->assertCanRemoveFromSale($listing),
                'delete' => $this->assertCanDelete($listing),
                'confirm_sale' => $this->assertCanConfirmSale($listing),
                default => new \WP_Error(
                    'sutore_marketplace_bulk_action_invalid',
                    __('Invalid bulk action.', 'sutore-marketplace'),
                    ['status' => 400]
                ),
            };
            if (is_wp_error($allowed)) {
                return new \WP_Error(
                    'sutore_marketplace_bulk_not_applicable',
                    __('This action cannot be applied to every selected product.', 'sutore-marketplace'),
                    ['status' => 409]
                );
            }
        }

        foreach ($ids as $id) {
            $listing = $this->listings->find($id);
            if (!$listing) {
                return new \WP_Error(
                    'sutore_marketplace_not_found',
                    /* translators: %d: listing id */
                    sprintf(__('Product #%d was not found.', 'sutore-marketplace'), $id),
                    ['status' => 404]
                );
            }

            $result = match ($action) {
                'put_on_sale' => $this->putOnSale($id, $userId),
                'remove_from_sale' => $this->removeFromSale($id, $userId),
                'delete' => $this->delete($id, $userId),
                'confirm_sale' => (new \SutoreMarketplace\Modules\Orders\Services\FulfillmentService())
                    ->merchantConfirmSale($id, (int) $listing->merchantId),
                default => new \WP_Error(
                    'sutore_marketplace_bulk_action_invalid',
                    __('Invalid bulk action.', 'sutore-marketplace'),
                    ['status' => 400]
                ),
            };
            if (is_wp_error($result)) {
                return $result;
            }
        }

        return [
            'updated' => count($ids),
            'action' => $action,
        ];
    }

    private function assertCanConfirmSale(Listing $listing): true|\WP_Error
    {
        if ($listing->listingStatus !== ListingStatus::SOLD) {
            return new \WP_Error(
                'sutore_marketplace_cannot_confirm_sale',
                __('This sale cannot be confirmed.', 'sutore-marketplace')
            );
        }

        return true;
    }

    private function resolveSizeContextFromVariation(int $variationId): ?array
    {
        $product = wc_get_product($variationId);
        if (!$product || $product->get_type() !== 'variation') {
            return null;
        }

        $parentId = (int) $product->get_parent_id();
        if ($parentId <= 0) {
            return null;
        }

        foreach ($product->get_attributes() as $taxonomy => $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }

            $term = get_term_by('slug', $slug, (string) $taxonomy);
            if ($term && !is_wp_error($term)) {
                return [
                    'parentId' => $parentId,
                    'sizeTermId' => (int) $term->term_id,
                ];
            }
        }

        return null;
    }
}
