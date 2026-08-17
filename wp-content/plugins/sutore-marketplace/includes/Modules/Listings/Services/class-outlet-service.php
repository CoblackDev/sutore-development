<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;
use SutoreMarketplace\Modules\Listings\Domain\ListingOutletPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\OutletItem;
use SutoreMarketplace\Modules\Listings\Domain\OutletOptin;
use SutoreMarketplace\Modules\Listings\Domain\OutletOptinStatus;
use SutoreMarketplace\Modules\Listings\Domain\OutletWindow;
use SutoreMarketplace\Modules\Listings\Domain\OutletWindowStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Repositories\OutletItemRepository;
use SutoreMarketplace\Modules\Listings\Repositories\OutletOptinRepository;
use SutoreMarketplace\Modules\Listings\Repositories\OutletWindowRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Shared\Settings\Settings;

final class OutletService
{
    public function __construct(
        private readonly OutletWindowRepository $windows = new OutletWindowRepository(),
        private readonly OutletItemRepository $items = new OutletItemRepository(),
        private readonly OutletOptinRepository $optins = new OutletOptinRepository(),
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
        private readonly ListingService $listingService = new ListingService(),
        private readonly NotificationService $notifications = new NotificationService(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createWindow(array $input): int|\WP_Error
    {
        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        if ($name === '') {
            return new \WP_Error('sutore_outlet_name', __('Outlet window name is required.', 'sutore-marketplace'));
        }

        $startsAt = CampaignDatetime::normalizeInput($input['starts_at'] ?? null);
        $endsAt = CampaignDatetime::normalizeInput($input['ends_at'] ?? null);
        $dates = $this->assertWindowDates($startsAt, $endsAt);
        if (is_wp_error($dates)) {
            return $dates;
        }

        return $this->windows->create([
            'name' => $name,
            'status' => OutletWindowStatus::DRAFT,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'notes' => sanitize_textarea_field((string) ($input['notes'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function addItem(int $windowId, array $input): int|\WP_Error
    {
        $window = $this->windows->find($windowId);
        if (!$window) {
            return new \WP_Error('sutore_outlet_missing', __('Outlet window not found.', 'sutore-marketplace'));
        }
        if ($window->status === OutletWindowStatus::ENDED) {
            return new \WP_Error(
                'sutore_outlet_ended',
                __('Items cannot be added to an ended outlet window.', 'sutore-marketplace')
            );
        }

        $parentId = (int) ($input['parent_product_id'] ?? 0);
        $sizeTermId = (int) ($input['size_term_id'] ?? 0);
        if ($parentId <= 0 || $sizeTermId <= 0) {
            return new \WP_Error(
                'sutore_outlet_item',
                __('Select a product and size.', 'sutore-marketplace')
            );
        }

        $parent = wc_get_product($parentId);
        if (!$parent || $parent->get_type() !== 'variable') {
            return new \WP_Error('sutore_outlet_parent', __('Select a valid parent product.', 'sutore-marketplace'));
        }

        $prices = $this->assertPrices($input['customer_sale'] ?? 0, $input['seller_net'] ?? 0);
        if (is_wp_error($prices)) {
            return $prices;
        }

        if ($this->items->findDuplicate($windowId, $parentId, $sizeTermId)) {
            return new \WP_Error(
                'sutore_outlet_item_exists',
                __('This product and size are already on this outlet window.', 'sutore-marketplace')
            );
        }

        return $this->items->create([
            'window_id' => $windowId,
            'parent_product_id' => $parentId,
            'size_term_id' => $sizeTermId,
            'customer_sale' => $prices['customer_sale'],
            'seller_net' => $prices['seller_net'],
        ]);
    }

    public function deleteItem(int $windowId, int $itemId): true|\WP_Error
    {
        $item = $this->items->find($itemId);
        if (!$item || $item->windowId !== $windowId) {
            return new \WP_Error('sutore_outlet_item_missing', __('Outlet item not found.', 'sutore-marketplace'));
        }

        $live = $this->optins->findLiveByWindowItems([$itemId]);
        if ($live !== []) {
            return new \WP_Error(
                'sutore_outlet_item_live',
                __('This item already has live outlet listings, so it cannot be removed.', 'sutore-marketplace')
            );
        }

        $this->items->delete($itemId);

        return true;
    }

    public function publish(int $windowId): true|\WP_Error
    {
        $window = $this->windows->find($windowId);
        if (!$window) {
            return new \WP_Error('sutore_outlet_missing', __('Outlet window not found.', 'sutore-marketplace'));
        }
        if ($window->status !== OutletWindowStatus::DRAFT && $window->status !== OutletWindowStatus::SCHEDULED) {
            return new \WP_Error(
                'sutore_outlet_status',
                __('Only draft or scheduled outlet windows can be published.', 'sutore-marketplace')
            );
        }
        if ($this->items->findByWindow($windowId) === []) {
            return new \WP_Error(
                'sutore_outlet_empty',
                __('Add at least one product before publishing this outlet window.', 'sutore-marketplace')
            );
        }
        if (CampaignDatetime::isPast($window->endsAt)) {
            return new \WP_Error('sutore_outlet_ended', __('Outlet window has already ended.', 'sutore-marketplace'));
        }

        if (CampaignDatetime::isPast($window->startsAt) || CampaignDatetime::toTimestamp($window->startsAt) <= time()) {
            $opened = $this->openWindow($window);
            if (is_wp_error($opened)) {
                return $opened;
            }

            return true;
        }

        $this->windows->update($windowId, ['status' => OutletWindowStatus::SCHEDULED]);

        return true;
    }

    public function endWindow(int $windowId): true|\WP_Error
    {
        $window = $this->windows->find($windowId);
        if (!$window) {
            return new \WP_Error('sutore_outlet_missing', __('Outlet window not found.', 'sutore-marketplace'));
        }
        if ($window->status === OutletWindowStatus::ENDED) {
            return true;
        }

        $this->closeWindow($window);

        return true;
    }

    /**
     * @return array{ok: true, optin_id: int, listing_created: bool, variation_id: ?int}|\WP_Error
     */
    public function optIn(int $itemId, int $merchantId): array|\WP_Error
    {
        $item = $this->items->find($itemId);
        if (!$item) {
            return new \WP_Error('sutore_outlet_item_missing', __('Outlet item not found.', 'sutore-marketplace'));
        }

        $window = $this->windows->find($item->windowId);
        if (!$window || !OutletWindowStatus::isOpenForOptIn($window->status)) {
            return new \WP_Error(
                'sutore_outlet_closed',
                __('This outlet window is not open for opt-in.', 'sutore-marketplace')
            );
        }
        if (CampaignDatetime::isPast($window->endsAt)) {
            return new \WP_Error('sutore_outlet_ended', __('Outlet window has already ended.', 'sutore-marketplace'));
        }

        $existing = $this->optins->findForItemMerchant($itemId, $merchantId);
        if ($existing && in_array($existing->status, [OutletOptinStatus::PENDING, OutletOptinStatus::LIVE], true)) {
            return new \WP_Error(
                'sutore_outlet_optin_exists',
                __('You have already joined this outlet item.', 'sutore-marketplace')
            );
        }

        if ($existing && $existing->status === OutletOptinStatus::CANCELLED) {
            $this->optins->update($existing->id, [
                'status' => OutletOptinStatus::PENDING,
                'variation_id' => null,
            ]);
            $optinId = $existing->id;
        } else {
            $optinId = $this->optins->create([
                'item_id' => $itemId,
                'merchant_id' => $merchantId,
                'variation_id' => null,
                'status' => OutletOptinStatus::PENDING,
            ]);
        }

        $this->events->log('outlet_optin_accepted', [
            'window_id' => $window->id,
            'item_id' => $itemId,
            'optin_id' => $optinId,
            'customer_sale' => $item->customerSale,
            'seller_net' => $item->sellerNet,
        ], null, $merchantId, 'merchant_visible');

        $listingCreated = false;
        $variationId = null;
        if ($window->status === OutletWindowStatus::ACTIVE) {
            $optin = $this->optins->find($optinId);
            if ($optin) {
                $created = $this->createListingForOptin($window, $item, $optin);
                if (is_wp_error($created)) {
                    return $created;
                }
                $listingCreated = $created['created'];
                $variationId = $created['variation_id'];
            }
        }

        return [
            'ok' => true,
            'optin_id' => $optinId,
            'listing_created' => $listingCreated,
            'variation_id' => $variationId,
        ];
    }

    public function cancelOptIn(int $optinId, int $merchantId): true|\WP_Error
    {
        $optin = $this->optins->find($optinId);
        if (!$optin || $optin->merchantId !== $merchantId) {
            return new \WP_Error('sutore_outlet_optin_missing', __('Outlet opt-in not found.', 'sutore-marketplace'));
        }
        if ($optin->status !== OutletOptinStatus::PENDING) {
            return new \WP_Error(
                'sutore_outlet_optin_status',
                __('Only a waiting opt-in can be cancelled.', 'sutore-marketplace')
            );
        }

        $this->optins->update($optinId, [
            'status' => OutletOptinStatus::CANCELLED,
            'variation_id' => null,
        ]);

        $this->events->log('outlet_optin_cancelled', [
            'optin_id' => $optinId,
            'item_id' => $optin->itemId,
        ], null, $merchantId, 'merchant_visible');

        return true;
    }

    public function markExpiredForListing(int $variationId): void
    {
        $optin = $this->optins->findLiveByVariationId($variationId);
        if (!$optin) {
            return;
        }

        $this->optins->update($optin->id, ['status' => OutletOptinStatus::EXPIRED]);
    }

    public function liveOptinForListing(int $variationId): ?OutletOptin
    {
        return $this->optins->findLiveByVariationId($variationId);
    }

    public function assertListingUnlocked(\SutoreMarketplace\Modules\Listings\Domain\Listing $listing): true|\WP_Error
    {
        if (!$listing->variationId) {
            return true;
        }

        return ListingOutletPolicy::assertUnlocked(
            $this->optins->findLiveByVariationId((int) $listing->variationId)
        );
    }

    public function runPass(): int
    {
        $changed = 0;
        foreach ($this->windows->findScheduledDue(40) as $window) {
            $opened = $this->openWindow($window);
            if (!is_wp_error($opened)) {
                $changed++;
            }
        }
        foreach ($this->windows->findActivePastEnd(40) as $window) {
            $this->closeWindow($window);
            $changed++;
        }

        return $changed;
    }

    public function countJoinableForMerchant(int $merchantId): int
    {
        return $this->optins->countJoinableForMerchant($merchantId);
    }

    /**
     * @return true|\WP_Error
     */
    private function openWindow(OutletWindow $window): true|\WP_Error
    {
        $this->windows->update($window->id, ['status' => OutletWindowStatus::ACTIVE]);
        $items = $this->items->findByWindow($window->id);
        $itemIds = array_map(static fn (OutletItem $item): int => $item->id, $items);
        $itemMap = [];
        foreach ($items as $item) {
            $itemMap[$item->id] = $item;
        }

        foreach ($this->optins->findPendingByWindowItems($itemIds) as $optin) {
            $item = $itemMap[$optin->itemId] ?? null;
            if (!$item) {
                continue;
            }
            $created = $this->createListingForOptin($window, $item, $optin);
            if (is_wp_error($created)) {
                continue;
            }
        }

        return true;
    }

    private function closeWindow(OutletWindow $window): void
    {
        $items = $this->items->findByWindow($window->id);
        $itemIds = array_map(static fn (OutletItem $item): int => $item->id, $items);

        foreach ($this->optins->findLiveByWindowItems($itemIds) as $optin) {
            if (!$optin->variationId) {
                $this->optins->update($optin->id, ['status' => OutletOptinStatus::EXPIRED]);
                continue;
            }
            $listing = $this->listings->find((int) $optin->variationId);
            if ($listing && in_array($listing->listingStatus, ListingStatus::removableFromSale(), true)) {
                $this->listingService->expire($listing);
                continue;
            }
            if (!$listing) {
                $this->optins->update($optin->id, ['status' => OutletOptinStatus::EXPIRED]);
            }
        }

        foreach ($this->optins->findPendingByWindowItems($itemIds) as $optin) {
            $this->optins->update($optin->id, ['status' => OutletOptinStatus::EXPIRED]);
        }

        $this->windows->update($window->id, ['status' => OutletWindowStatus::ENDED]);
        Settings::bumpPricingRevision();
    }

    /**
     * @return array{created: bool, variation_id: int}|\WP_Error
     */
    private function createListingForOptin(OutletWindow $window, OutletItem $item, OutletOptin $optin): array|\WP_Error
    {
        if ($optin->status === OutletOptinStatus::LIVE && $optin->variationId) {
            return ['created' => false, 'variation_id' => (int) $optin->variationId];
        }

        $asking = (int) round($item->sellerNet);
        $created = $this->listingService->create([
            'parent_product_id' => $item->parentProductId,
            'size_term_id' => $item->sizeTermId,
            'asking' => $asking,
            'fast_shipment' => 0,
            'has_invoice' => 0,
            'no_box' => 0,
            'box_damaged' => 0,
            'missing_accessory' => 0,
            'damaged' => 0,
        ], $optin->merchantId, [
            'expire_at' => $window->endsAt,
            'force_publish' => true,
        ]);

        if (is_wp_error($created)) {
            return $created;
        }

        $variationId = (int) $created->variationId;
        $this->optins->update($optin->id, [
            'status' => OutletOptinStatus::LIVE,
            'variation_id' => $variationId,
        ]);

        $this->events->log('outlet_listing_created', [
            'window_id' => $window->id,
            'item_id' => $item->id,
            'optin_id' => $optin->id,
            'customer_sale' => $item->customerSale,
            'seller_net' => $item->sellerNet,
            'expire_at' => $window->endsAt,
        ], $variationId, $optin->merchantId, 'merchant_visible');

        $product = get_the_title($item->parentProductId) ?: __('Product', 'sutore-marketplace');
        $this->notifications->dispatch($optin->merchantId, NotificationType::OUTLET_LISTING_LIVE, [
            'product' => $product,
            'variation_id' => $variationId,
            'customer_sale' => $item->customerSale,
            'seller_net' => $item->sellerNet,
            'ends_at' => $window->endsAt,
        ], 0);

        Settings::bumpPricingRevision();

        return ['created' => true, 'variation_id' => $variationId];
    }

    /**
     * @return array{customer_sale: int, seller_net: int}|\WP_Error
     */
    private function assertPrices(mixed $customerSaleRaw, mixed $sellerNetRaw): array|\WP_Error
    {
        $saleCheck = ListingPriceValidator::assertStepMultiple($customerSaleRaw);
        if (is_wp_error($saleCheck)) {
            return $saleCheck;
        }
        $netCheck = ListingPriceValidator::assertStepMultiple($sellerNetRaw);
        if (is_wp_error($netCheck)) {
            return $netCheck;
        }

        $customerSale = (int) ListingPriceValidator::normalizeAsking($customerSaleRaw);
        $sellerNet = (int) ListingPriceValidator::normalizeAsking($sellerNetRaw);
        if ($customerSale < $sellerNet) {
            return new \WP_Error(
                'sutore_outlet_prices',
                __('Customer sale price cannot be lower than the seller asking.', 'sutore-marketplace')
            );
        }

        return [
            'customer_sale' => $customerSale,
            'seller_net' => $sellerNet,
        ];
    }

    /**
     * @return true|\WP_Error
     */
    private function assertWindowDates(?string $startsAt, ?string $endsAt): true|\WP_Error
    {
        if (!$startsAt || !$endsAt) {
            return new \WP_Error(
                'sutore_outlet_dates',
                __('Outlet start and end dates are required.', 'sutore-marketplace')
            );
        }

        $startsTs = CampaignDatetime::toTimestamp($startsAt);
        $endsTs = CampaignDatetime::toTimestamp($endsAt);
        if ($startsTs === null || $endsTs === null || $endsTs <= $startsTs) {
            return new \WP_Error(
                'sutore_outlet_dates',
                __('Outlet end must be after start.', 'sutore-marketplace')
            );
        }

        $days = (int) ceil(max(0, $endsTs - $startsTs) / DAY_IN_SECONDS);
        if ($days > 90) {
            return new \WP_Error(
                'sutore_outlet_duration',
                __('Outlet window cannot exceed 90 days.', 'sutore-marketplace')
            );
        }

        return true;
    }
}
