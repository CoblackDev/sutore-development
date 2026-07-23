<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Sourcing\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Sourcing\Repositories\SourcingRepository;
use SutoreMarketplace\Shared\Settings\Settings;

final class SourcingService
{
    public function __construct(
        private readonly SourcingRepository $sourcing = new SourcingRepository(),
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
        private readonly ListingSelector $selector = new ListingSelector(),
        private readonly ListingService $listingService = new ListingService(),
        private readonly FulfillmentRepository $fulfillments = new FulfillmentRepository(),
    ) {
    }

    /**
     * Accept an open sourcing request. A matching merchant listing is reused by
     * default; the merchant may explicitly keep it and create a new pre-order listing.
     *
     * @return array{ok: true, request_id: int, listing_id: int, listing_created: bool}|\WP_Error
     */
    public function accept(
        int $requestId,
        int $merchantId,
        ?int $listingId = null,
        bool $createNewListing = false
    ): array|\WP_Error
    {
        $row = $this->sourcing->find($requestId);
        if (!$row) {
            return new \WP_Error('sutore_sourcing_missing', __('Sourcing request not found.', 'sutore-marketplace'));
        }
        if ($row->status !== 'open') {
            return new \WP_Error('sutore_sourcing_closed', __('Request is not open.', 'sutore-marketplace'));
        }

        $parentId = (int) $row->parent_product_id;
        $sizeTermId = (int) ($row->size_term_id ?? 0);
        if ($parentId <= 0 || $sizeTermId <= 0) {
            return new \WP_Error(
                'sutore_sourcing_incomplete',
                __('Pre-order request is missing product or size.', 'sutore-marketplace')
            );
        }

        $resolved = $this->resolveListingForAccept(
            $row,
            $merchantId,
            $createNewListing ? null : $listingId,
            $createNewListing
        );
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        /** @var array{listing: Listing, created: bool} $resolved */
        $listing = $resolved['listing'];
        $created = $resolved['created'];
        $offerAsking = $this->askingForRequest($row);

        $this->sourcing->update($requestId, [
            'status' => 'accepted',
            'accepted_merchant_id' => $merchantId,
        ]);

        $this->listings->update((int) $listing->id, [
            'listing_status' => ListingStatus::NOT_SALE,
            'is_winner' => 0,
            'asking' => $offerAsking,
            'sourcing_request_id' => $requestId,
        ]);
        $this->syncVariationAsking((int) $listing->variationId, $offerAsking);
        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);
        $this->events->log('sourcing_pre_order', [
            'request_id' => $requestId,
            'listing_id' => (int) $listing->id,
            'listing_created' => $created,
            'asking' => $offerAsking,
        ], (int) $listing->id, $listing->variationId, $merchantId, 'merchant_visible');

        return [
            'ok' => true,
            'request_id' => $requestId,
            'listing_id' => (int) $listing->id,
            'listing_created' => $created,
            'asking' => $offerAsking,
        ];
    }

    public function fulfill(int $requestId): true|\WP_Error
    {
        $row = $this->sourcing->find($requestId);
        if (!$row) {
            return new \WP_Error('sutore_sourcing_missing', __('Sourcing request not found.', 'sutore-marketplace'));
        }

        $this->sourcing->update($requestId, ['status' => 'fulfilled']);
        $this->events->log('sourcing_fulfilled', [
            'request_id' => $requestId,
            'order_id' => (int) $row->order_id,
        ], null, null, (int) ($row->accepted_merchant_id ?: 0), 'admin_only');

        do_action('sutore_marketplace_sourcing_fulfilled', $requestId, $row);

        return true;
    }

    public function cancel(int $requestId): true|\WP_Error
    {
        $listing = $this->listings->findBySourcingRequestId($requestId);
        if ($listing) {
            $wasHeld = ListingStatus::isSourcingHeld($listing);
            $this->listings->update((int) $listing->id, [
                'sourcing_request_id' => null,
                'listing_status' => ListingStatus::NOT_SALE,
            ]);
            if ($wasHeld) {
                $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);
            }
        }

        $this->sourcing->update($requestId, ['status' => 'cancelled']);

        return true;
    }

    /**
     * Staff-created sourcing request (same fields as former Rest create).
     *
     * @param array<string, mixed> $params
     */
    public function createRequest(array $params, int $requestedBy): int|\WP_Error
    {
        return $this->sourcing->create([
            'order_id' => (int) ($params['order_id'] ?? 0),
            'order_item_id' => (int) ($params['order_item_id'] ?? 0),
            'parent_product_id' => (int) ($params['parent_product_id'] ?? 0),
            'size_term_id' => (int) ($params['size_term_id'] ?? 0),
            'status' => 'open',
            'requested_by' => $requestedBy,
            'notes' => sanitize_textarea_field((string) ($params['notes'] ?? '')),
        ]);
    }

    /**
     * @return array{listing: Listing, created: bool}|\WP_Error
     */
    private function resolveListingForAccept(
        object $row,
        int $merchantId,
        ?int $listingId,
        bool $createNewListing = false
    ): array|\WP_Error
    {
        $parentId = (int) $row->parent_product_id;
        $sizeTermId = (int) $row->size_term_id;

        if ($listingId) {
            $listing = $this->listings->find($listingId);
            if (!$listing || (int) $listing->merchantId !== $merchantId) {
                return new \WP_Error(
                    'sutore_sourcing_listing_forbidden',
                    __('This Listing does not belong to you.', 'sutore-marketplace')
                );
            }
            if ((int) $listing->parentProductId !== $parentId || (int) $listing->sizeTermId !== $sizeTermId) {
                return new \WP_Error(
                    'sutore_sourcing_listing_mismatch',
                    __('Listing does not match this pre-order product or size.', 'sutore-marketplace')
                );
            }
            if (ListingStatus::isProcessLocked($listing)) {
                return new \WP_Error(
                    'sutore_sourcing_listing_locked',
                    __('This Listing is already in an order process.', 'sutore-marketplace')
                );
            }

            return ['listing' => $listing, 'created' => false];
        }

        if (!$createNewListing) {
            $existing = $this->findMerchantListingForSize($merchantId, $parentId, $sizeTermId);
            if ($existing) {
                return ['listing' => $existing, 'created' => false];
            }
        }

        $asking = $this->askingForRequest($row);
        $createdListing = $this->listingService->create([
            'parent_product_id' => $parentId,
            'size_term_id' => $sizeTermId,
            'asking' => $asking,
            'fast_shipment' => 0,
            'has_invoice' => 0,
            'no_box' => 0,
            'box_damaged' => 0,
            'missing_accessory' => 0,
            'damaged' => 0,
            'used' => 0,
        ], $merchantId);

        if (is_wp_error($createdListing)) {
            return $createdListing;
        }

        $this->events->log('sourcing_listing_auto_created', [
            'request_id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'asking' => $asking,
            'existing_listing_kept' => $createNewListing,
        ], (int) $createdListing->id, $createdListing->variationId, $merchantId, 'merchant_visible');

        return ['listing' => $createdListing, 'created' => true];
    }

    private function findMerchantListingForSize(int $merchantId, int $parentId, int $sizeTermId): ?Listing
    {
        $owned = $this->listings->query([
            'merchant_id' => $merchantId,
            'parent_product_id' => $parentId,
            'size_term_id' => $sizeTermId,
            'per_page' => 20,
            'page' => 1,
            'orderby' => 'asking',
            'order' => 'ASC',
        ]);

        $fallback = null;
        foreach ($owned['items'] as $candidate) {
            if (ListingStatus::isProcessLocked($candidate)) {
                continue;
            }
            if ($candidate->isWinner && $candidate->listingStatus === 'publish') {
                return $candidate;
            }
            if ($fallback === null && in_array($candidate->listingStatus, ['publish', 'queued', 'pending'], true)) {
                $fallback = $candidate;
            }
        }

        return $fallback;
    }

    /**
     * Asking price offered for this pre-order (original sold listing price when available).
     */
    public function askingForRequest(object $row): int
    {
        $step = Settings::listingPriceStep();

        $orderItemId = (int) ($row->order_item_id ?? 0);
        $orderId = (int) ($row->order_id ?? 0);
        if ($orderId > 0 && $orderItemId > 0) {
            $fulfillment = $this->fulfillments->findByOrderItem($orderId, $orderItemId);
            if ($fulfillment) {
                $original = $this->listings->find((int) $fulfillment->listing_id);
                if ($original && (float) $original->asking > 0) {
                    return max($step, ListingPriceValidator::roundDownToStep((float) $original->asking, $step));
                }
            }
        }

        $lowest = $this->listings->getLowestOnSaleForSize(
            (int) $row->parent_product_id,
            (int) $row->size_term_id
        );
        if ($lowest && (float) $lowest->asking > 0) {
            return max($step, ListingPriceValidator::roundDownToStep((float) $lowest->asking, $step));
        }

        if ($orderId > 0 && $orderItemId > 0) {
            $order = wc_get_order($orderId);
            if ($order) {
                $item = $order->get_item($orderItemId);
                if ($item instanceof \WC_Order_Item_Product) {
                    $line = (float) $item->get_total();
                    if ($line > 0) {
                        return max($step, ListingPriceValidator::roundDownToStep($line, $step));
                    }
                }
            }
        }

        return max($step, $step * 40);
    }

    private function syncVariationAsking(int $variationId, int $asking): void
    {
        \SutoreMarketplace\Modules\Listings\Services\ActivePriceSync::writeVariationAsking($variationId, $asking);
    }
}
