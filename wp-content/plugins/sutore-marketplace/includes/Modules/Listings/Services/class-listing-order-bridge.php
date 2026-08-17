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
            return new \WP_Error('sutore_marketplace_not_found', __('Listing not found.', 'sutore-marketplace'));
        }

        if (ListingStatus::isInSaleLifecycle($listing->listingStatus) && $listing->orderId) {
            return new \WP_Error('sutore_marketplace_already_linked', __('Listing is already linked to an order.', 'sutore-marketplace'));
        }

        $this->listings->update($listingId, array_merge([
            'listing_status' => ListingStatus::PAYMENT,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'is_winner' => 0,
            'expire_at' => null,
        ], OrderShipmentSnapshot::columnsForOrder($orderId)));

        $this->lockSaleCommission($listingId);

        $this->detachVariationFromSale($listing->variationId);
        $this->selector->rerunSize($listing->parentProductId, $listing->sizeTermId);

        $this->events->log('listing_payment', [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'listing_status' => ListingStatus::PAYMENT,
            'attachment_mode' => 'payment',
        ], $listing->variationId, $listing->merchantId, 'merchant_visible');

        $this->events->log('order_listing_attached', [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'attachment_mode' => 'payment',
        ], $listing->variationId, $listing->merchantId, 'merchant_visible');

        return true;
    }

    public function markSold(int $listingId, int $orderId, int $orderItemId): true|\WP_Error
    {
        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_not_found', __('Listing not found.', 'sutore-marketplace'));
        }

        $this->listings->update($listingId, array_merge([
            'listing_status' => ListingStatus::SOLD,
            'sold_at' => current_time('mysql'),
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'is_winner' => 0,
            'expire_at' => null,
        ], OrderShipmentSnapshot::columnsForOrder($orderId)));

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
        ], $fresh->variationId, $fresh->merchantId, 'merchant_visible');

        $this->events->log('order_listing_attached', [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'attachment_mode' => 'attached',
            'sold_at' => $soldAt,
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
            return new \WP_Error('sutore_marketplace_not_found', __('Listing not found.', 'sutore-marketplace'));
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
