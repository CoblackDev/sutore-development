<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Orders\Support\OrderShipmentSnapshot;
use SutoreMarketplace\Modules\Tasks\Domain\OpportunityTemplate;
use SutoreMarketplace\Modules\Tasks\Services\TaskProgressService;

/**
 * Listing ↔ sipariş bağlantısı (fulfillment modülü için).
 */
final class ListingOrderBridge
{
    /** Statuses that may be claimed into a paid/reserved sale. */
    private const CLAIMABLE = [
        ListingStatus::PUBLISH,
        ListingStatus::QUEUED,
    ];

    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
        private readonly ListingSelector $selector = new ListingSelector(),
    ) {
    }

    public function findByVariationId(int $variationId): ?\SutoreMarketplace\Modules\Listings\Domain\Listing
    {
        return $this->listings->findByVariationId($variationId);
    }

    public function find(int $listingId): ?\SutoreMarketplace\Modules\Listings\Domain\Listing
    {
        return $this->listings->find($listingId);
    }

    public function markPaymentPending(int $listingId, int $orderId, int $orderItemId): true|\WP_Error
    {
        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_not_found', __('Product not found.', 'sutore-marketplace'));
        }

        if (
            $listing->listingStatus === ListingStatus::PAYMENT
            && (int) $listing->orderId === $orderId
            && (int) $listing->orderItemId === $orderItemId
        ) {
            return true;
        }

        if (ListingStatus::isInSaleLifecycle($listing->listingStatus) && $listing->orderId) {
            if ((int) $listing->orderId === $orderId) {
                return true;
            }

            return new \WP_Error(
                'sutore_marketplace_already_linked',
                __('Product is already linked to an order.', 'sutore-marketplace')
            );
        }

        $patch = array_merge([
            'listing_status' => ListingStatus::PAYMENT,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'is_winner' => 0,
            'expire_at' => null,
        ], OrderShipmentSnapshot::columnsForOrder($orderId));

        $operationId = 'claim:payment:' . $orderId . ':' . $orderItemId . ':' . $listingId;
        $result = $this->listings->transition($listingId, self::CLAIMABLE, $patch, $operationId, true);
        if ($result->isAlreadyDone()) {
            return true;
        }
        if (!$result->isChanged()) {
            $fresh = $this->listings->find($listingId);
            if (
                $fresh
                && $fresh->listingStatus === ListingStatus::PAYMENT
                && (int) $fresh->orderId === $orderId
            ) {
                return true;
            }

            return new \WP_Error(
                'sutore_marketplace_already_linked',
                __('Product is already linked to an order.', 'sutore-marketplace')
            );
        }

        $this->lockSaleCommission($listingId);
        $this->detachVariationFromSale($listing->variationId);
        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        $this->events->log('listing_payment', [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'listing_status' => ListingStatus::PAYMENT,
            'attachment_mode' => 'payment',
            'operation_id' => $result->operationId(),
        ], $listing->variationId, $listing->merchantId, 'merchant_visible');

        $this->events->log('order_listing_attached', [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'attachment_mode' => 'payment',
            'operation_id' => $result->operationId(),
        ], $listing->variationId, $listing->merchantId, 'merchant_visible');

        return true;
    }

    public function markSold(int $listingId, int $orderId, int $orderItemId): true|\WP_Error
    {
        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_not_found', __('Product not found.', 'sutore-marketplace'));
        }

        if (
            $listing->listingStatus === ListingStatus::SOLD
            && (int) $listing->orderId === $orderId
            && (int) ($listing->orderItemId ?? 0) === $orderItemId
        ) {
            return true;
        }

        // Admin confirm / paid path: payment → sold for the same order.
        if ($listing->listingStatus === ListingStatus::PAYMENT) {
            if ((int) $listing->orderId !== $orderId) {
                return new \WP_Error(
                    'sutore_marketplace_already_linked',
                    __('Product is already linked to an order.', 'sutore-marketplace')
                );
            }

            $operationId = 'claim:sold:payment:' . $orderId . ':' . $orderItemId . ':' . $listingId;
            $result = $this->listings->transition($listingId, ListingStatus::PAYMENT, array_merge([
                'listing_status' => ListingStatus::SOLD,
                'sold_at' => current_time('mysql'),
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'is_winner' => 0,
                'expire_at' => null,
            ], OrderShipmentSnapshot::columnsForOrder($orderId)), $operationId);

            if ($result->isAlreadyDone()) {
                return true;
            }
            if (!$result->isChanged()) {
                $fresh = $this->listings->find($listingId);
                if ($fresh && $fresh->listingStatus === ListingStatus::SOLD && (int) $fresh->orderId === $orderId) {
                    return true;
                }

                return new \WP_Error(
                    'sutore_marketplace_claim_conflict',
                    __('This product could not be marked sold.', 'sutore-marketplace')
                );
            }

            return $this->afterSoldSideEffects($listingId, $orderId, $orderItemId, $listing, $result->operationId());
        }

        if (ListingStatus::isInSaleLifecycle($listing->listingStatus) && $listing->orderId) {
            if ((int) $listing->orderId === $orderId) {
                return true;
            }

            return new \WP_Error(
                'sutore_marketplace_already_linked',
                __('Product is already linked to an order.', 'sutore-marketplace')
            );
        }

        $patch = array_merge([
            'listing_status' => ListingStatus::SOLD,
            'sold_at' => current_time('mysql'),
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'is_winner' => 0,
            'expire_at' => null,
        ], OrderShipmentSnapshot::columnsForOrder($orderId));

        $operationId = 'claim:sold:' . $orderId . ':' . $orderItemId . ':' . $listingId;
        $result = $this->listings->transition($listingId, self::CLAIMABLE, $patch, $operationId, true);
        if ($result->isAlreadyDone()) {
            return true;
        }
        if (!$result->isChanged()) {
            $fresh = $this->listings->find($listingId);
            if ($fresh && $fresh->listingStatus === ListingStatus::SOLD && (int) $fresh->orderId === $orderId) {
                return true;
            }

            return new \WP_Error(
                'sutore_marketplace_already_linked',
                __('Product is already linked to an order.', 'sutore-marketplace')
            );
        }

        return $this->afterSoldSideEffects($listingId, $orderId, $orderItemId, $listing, $result->operationId());
    }

    /**
     * @param \SutoreMarketplace\Modules\Listings\Domain\Listing $listing
     */
    private function afterSoldSideEffects(
        int $listingId,
        int $orderId,
        int $orderItemId,
        $listing,
        string $operationId = ''
    ): true|\WP_Error {
        $this->lockSaleCommission($listingId);
        $this->detachVariationFromSale($listing->variationId);
        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        $fresh = $this->listings->find($listingId) ?: $listing;
        $soldAt = $fresh->soldAt ?? current_time('mysql');
        $this->events->log('listing_sold', [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'sold_at' => $soldAt,
            'listing_status' => ListingStatus::SOLD,
            'operation_id' => $operationId,
        ], $fresh->variationId, $fresh->merchantId, 'merchant_visible');

        $this->events->log('order_listing_attached', [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'attachment_mode' => 'attached',
            'sold_at' => $soldAt,
            'operation_id' => $operationId,
        ], $fresh->variationId, $fresh->merchantId, 'merchant_visible');

        (new TaskProgressService())->incrementByTemplate($fresh->merchantId, OpportunityTemplate::GROWTH_MONTHLY_SALES);
        (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorLevelService())->evaluateConfirmed($fresh->merchantId);
        (new \SutoreMarketplace\Modules\Merchants\Services\ReferralService())->onFirstSale($fresh->merchantId, $fresh->variationId);
        (new \SutoreMarketplace\Modules\Invoices\Services\InvoiceIssuer())->syncCustomerFeesForOrder($orderId);

        return true;
    }

    /**
     * @param array<string, mixed> $context Extra event payload (e.g. actor_user_id, actor_login, order_id).
     */
    public function releaseFromOrder(int $listingId, string $newStatus = ListingStatus::ORDER_DETACHED, array $context = []): true|\WP_Error
    {
        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_not_found', __('Product not found.', 'sutore-marketplace'));
        }

        if (!ListingStatus::isValid($newStatus)) {
            $newStatus = ListingStatus::ORDER_DETACHED;
        }

        $orderId = (int) ($context['order_id'] ?? $listing->orderId ?? 0);

        $this->listings->update($listingId, array_merge([
            'listing_status' => $newStatus,
            'order_id' => null,
            'order_item_id' => null,
            'sold_at' => null,
            'sale_commission_percent' => null,
            'is_winner' => 0,
        ], OrderShipmentSnapshot::clearedColumns()));

        $this->events->log('order_listing_detached', array_merge([
            'new_status' => $newStatus,
            'order_id' => $context['order_id'] ?? $listing->orderId,
            'order_item_id' => $context['order_item_id'] ?? $listing->orderItemId,
            'reason' => $context['reason'] ?? 'released',
        ], $context), $listing->variationId, $listing->merchantId, 'merchant_visible');

        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        if ($orderId > 0) {
            (new \SutoreMarketplace\Modules\Invoices\Services\InvoiceIssuer())->syncCustomerFeesForOrder($orderId);
        }

        return true;
    }

    /**
     * Atomically claim a pre-order board row before order swap.
     *
     * @return array{order_id:int,order_item_id:int,parent_product_id:int,size_term_id:int,merchant_id:int}|\WP_Error
     */
    public function claimPreOrderForSwap(int $preOrderListingId): array|\WP_Error
    {
        $listing = $this->listings->find($preOrderListingId);
        if (!$listing || $listing->listingStatus !== ListingStatus::PRE_ORDER) {
            return new \WP_Error('sutore_pre_order_missing', __('Pre-order not found.', 'sutore-marketplace'));
        }

        $orderId = (int) ($listing->orderId ?? 0);
        $orderItemId = (int) ($listing->orderItemId ?? 0);
        if ($orderId <= 0 || $orderItemId <= 0) {
            return new \WP_Error(
                'sutore_pre_order_not_linked',
                __('Pre-order is not linked to an order.', 'sutore-marketplace')
            );
        }

        $operationId = 'preorder:claim:' . $preOrderListingId . ':' . $orderId . ':' . $orderItemId;
        $result = $this->listings->transition($preOrderListingId, ListingStatus::PRE_ORDER, [
            'listing_status' => ListingStatus::ORDER_DETACHED,
            'order_id' => null,
            'order_item_id' => null,
            'sold_at' => null,
            'confirm_deadline_at' => null,
            'seller_confirmed_at' => null,
            'cargo_deadline_at' => null,
            'merchant_shipped_at' => null,
            'merchant_shipment_code' => null,
            'confirm_notice_sent' => 0,
            'confirm_punished' => 0,
            'merchant_snapshot' => null,
            'is_winner' => 0,
        ], $operationId);

        if ($result->isAlreadyDone()) {
            return [
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'parent_product_id' => (int) $listing->parentProductId,
                'size_term_id' => (int) $listing->sizeTermId,
                'merchant_id' => (int) $listing->merchantId,
                'asking' => (int) $listing->asking,
                'operation_id' => $result->operationId(),
            ];
        }

        if (!$result->isChanged()) {
            return new \WP_Error(
                'sutore_pre_order_claimed',
                __('This pre-order was already accepted by another seller.', 'sutore-marketplace')
            );
        }

        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        return [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'parent_product_id' => (int) $listing->parentProductId,
            'size_term_id' => (int) $listing->sizeTermId,
            'merchant_id' => (int) $listing->merchantId,
            'asking' => (int) $listing->asking,
        ];
    }

    private function lockSaleCommission(int $listingId): void
    {
        $listing = $this->listings->find($listingId);
        if ($listing) {
            (new \SutoreMarketplace\Modules\Merchants\Services\CommissionResolver())->lockForSale($listing);
        }
    }

    private function detachVariationFromSale(int $variationId): void
    {
        $product = wc_get_product($variationId);
        if (!$product) {
            return;
        }
        $product->set_status('draft');
        $product->set_stock_status('outofstock');
        $product->set_stock_quantity(0);
        $product->save();
    }
}
