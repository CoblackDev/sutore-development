<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Sourcing\Services;

use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Shared\Settings\Settings;

final class SourcingService
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
        private readonly ListingSelector $selector = new ListingSelector(),
        private readonly ListingService $listingService = new ListingService(),
        private readonly FulfillmentService $fulfillments = new FulfillmentService(),
    ) {
    }

    /**
     * Accept a pre-order board listing — immediate order swap.
     *
     * @return array{ok: true, variation_id: int, listing_created: bool, asking: int}|\WP_Error
     */
    public function accept(
        int $preOrderVariationId,
        int $merchantId,
        ?int $listingId = null,
        bool $createNewListing = false
    ): array|\WP_Error {
        $preOrder = $this->listings->find($preOrderVariationId);
        if (!$preOrder || $preOrder->listingStatus !== ListingStatus::PRE_ORDER) {
            return new \WP_Error('sutore_pre_order_missing', __('Pre-order not found.', 'sutore-marketplace'));
        }

        $resolved = $this->resolveListingForAccept(
            $preOrder,
            $merchantId,
            $createNewListing ? null : $listingId,
            $createNewListing
        );
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        /** @var array{listing: Listing, created: bool} $resolved */
        $replacement = $resolved['listing'];
        $created = $resolved['created'];
        $offerAsking = $this->askingForPreOrder($preOrder);

        if (!$created && (int) $replacement->asking !== $offerAsking) {
            $this->listings->update((int) $replacement->variationId, ['asking' => $offerAsking]);
            $this->syncVariationAsking((int) $replacement->variationId, $offerAsking);
            $this->selector->rerunSize($replacement->parentProductId, $replacement->sizeTermId);
        }

        $swapped = $this->fulfillments->acceptPreOrderSwap(
            $preOrderVariationId,
            (int) $replacement->variationId,
            $merchantId
        );
        if (is_wp_error($swapped)) {
            return $swapped;
        }

        $this->events->logForListing(
            ListingEventType::PRE_ORDER_ACCEPTED,
            $replacement,
            [
                'pre_order_variation_id' => $preOrderVariationId,
                'variation_id' => (int) $replacement->variationId,
                'listing_created' => $created,
                'asking' => $offerAsking,
            ],
            $merchantId,
            'merchant_visible'
        );

        $this->events->logForListing(
            ListingEventType::SOURCING_FULFILLED,
            $replacement,
            [
                'pre_order_variation_id' => $preOrderVariationId,
                'variation_id' => (int) $replacement->variationId,
                'asking' => $offerAsking,
            ],
            $merchantId,
            'merchant_visible'
        );

        (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())->refreshMerchant($merchantId);
        (new \SutoreMarketplace\Modules\Tasks\Services\TaskProgressService())
            ->incrementByTemplate($merchantId, \SutoreMarketplace\Modules\Tasks\Domain\OpportunityTemplate::ENGAGEMENT_SOURCING);

        return [
            'ok' => true,
            'variation_id' => (int) $replacement->variationId,
            'listing_created' => $created,
            'asking' => $offerAsking,
        ];
    }

    /**
     * @return array{listing: Listing, created: bool}|\WP_Error
     */
    private function resolveListingForAccept(
        Listing $preOrder,
        int $merchantId,
        ?int $listingId,
        bool $createNewListing = false
    ): array|\WP_Error {
        $parentId = (int) $preOrder->parentProductId;
        $sizeTermId = (int) $preOrder->sizeTermId;

        if ($listingId) {
            $listing = $this->listings->find($listingId);
            if (!$listing || (int) $listing->merchantId !== $merchantId) {
                return new \WP_Error(
                    'sutore_pre_order_listing_forbidden',
                    __('This product does not belong to you.', 'sutore-marketplace')
                );
            }
            if ((int) $listing->parentProductId !== $parentId || (int) $listing->sizeTermId !== $sizeTermId) {
                return new \WP_Error(
                    'sutore_pre_order_listing_mismatch',
                    __('Product does not match this pre-order product or size.', 'sutore-marketplace')
                );
            }
            if ($listing->variationId !== $preOrder->variationId && ListingStatus::isProcessLocked($listing)) {
                return new \WP_Error(
                    'sutore_pre_order_listing_locked',
                    __('This product is already in an order process.', 'sutore-marketplace')
                );
            }

            return ['listing' => $listing, 'created' => false];
        }

        if ((int) $preOrder->merchantId === $merchantId) {
            return ['listing' => $preOrder, 'created' => false];
        }

        if (!$createNewListing) {
            $existing = $this->findMerchantListingForSize($merchantId, $parentId, $sizeTermId);
            if ($existing) {
                return ['listing' => $existing, 'created' => false];
            }
        }

        $asking = $this->askingForPreOrder($preOrder);
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
        ], $merchantId, ['defer_selector' => true]);

        if (is_wp_error($createdListing)) {
            return $createdListing;
        }

        $this->events->log('pre_order_listing_auto_created', [
            'pre_order_variation_id' => $preOrder->variationId,
            'order_id' => $preOrder->orderId,
            'asking' => $asking,
            'existing_listing_kept' => $createNewListing,
        ], $createdListing->variationId, $merchantId, 'merchant_visible');

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

    public function askingForPreOrder(Listing $preOrder): int
    {
        $step = Settings::listingPriceStep();

        if ((float) $preOrder->asking > 0) {
            return max($step, ListingPriceValidator::roundDownToStep((float) $preOrder->asking, $step));
        }

        $orderId = (int) ($preOrder->orderId ?? 0);
        $orderItemId = (int) ($preOrder->orderItemId ?? 0);
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

        $lowest = $this->listings->getLowestOnSaleForSize(
            (int) $preOrder->parentProductId,
            (int) $preOrder->sizeTermId
        );
        if ($lowest && (float) $lowest->asking > 0) {
            return max($step, ListingPriceValidator::roundDownToStep((float) $lowest->asking, $step));
        }

        return max($step, $step * 40);
    }

    private function syncVariationAsking(int $variationId, int $asking): void
    {
        \SutoreMarketplace\Modules\Listings\Services\ActivePriceSync::writeVariationAsking($variationId, $asking);
    }
}
