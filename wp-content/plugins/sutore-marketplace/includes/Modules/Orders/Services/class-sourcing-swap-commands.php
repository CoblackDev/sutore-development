<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Services\PayoutLineService;
use SutoreMarketplace\Modules\Orders\Domain\StaffBulkAction;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class SourcingSwapCommands
{
    public function __construct(
        private readonly FulfillmentCommandSupport $support,
        private readonly FulfillmentRepository $repo,
        private readonly PaymentReservationCommands $payment,
    ) {
    }

    public function swapMerchant(
        int $listingId,
        int $newListingId,
        string $staffNote = '',
        bool $returnToQueue = false
    ): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['swap']) || !Settings::swapAllowedFor((string) $row->fulfillment_status)) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Seller cannot be changed in this status.', 'sutore-marketplace'));
        }

        $bridge = $this->support->bridge();
        $oldListing = $bridge->find($listingId);
        $newListing = $bridge->find($newListingId);
        if (!$newListing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('No new product found.', 'sutore-marketplace'));
        }

        if ((int) $newListing->variationId === $listingId) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Replacement product must be different from the current product.', 'sutore-marketplace')
            );
        }

        $sameParent = $oldListing
            && (int) $newListing->parentProductId === (int) $oldListing->parentProductId;
        $note = sanitize_textarea_field($staffNote);
        if (!$sameParent) {
            if (trim($note) === '') {
                return new \WP_Error(
                    'sutore_marketplace_staff_note_required',
                    __('A staff note is required when replacing with a different product.', 'sutore-marketplace')
                );
            }
        } elseif ($note === '') {
            $note = __('Seller swapped by staff.', 'sutore-marketplace');
        }

        if ($newListing->listingStatus !== 'publish' || !$newListing->isWinner) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Replacement product must be the active queue winner.', 'sutore-marketplace')
            );
        }

        if ((int) $newListing->orderId > 0 || $this->repo->findActiveByVariationId((int) $newListing->variationId)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_listing',
                __('Replacement product is already linked to an active sale.', 'sutore-marketplace')
            );
        }

        $order = wc_get_order((int) $row->order_id);
        if (!$order) {
            return new \WP_Error('sutore_marketplace_fulfillment_order', __('Order not found.', 'sutore-marketplace'));
        }

        $oldLineTotal = 0.0;
        $oldOrderItemId = (int) ($row->order_item_id ?? 0);
        if ($oldOrderItemId > 0) {
            $oldItem = $order->get_item($oldOrderItemId);
            if ($oldItem instanceof \WC_Order_Item_Product) {
                $oldLineTotal = (float) $oldItem->get_total();
            }
        }
        if ($oldLineTotal <= 0 && $oldListing) {
            $oldLineTotal = MarketplacePricing::customerPrice($oldListing);
        }

        $split = $this->payment->splitFromOrder($listingId, false, 'swap_out', $note, false);
        if (is_wp_error($split)) {
            return $split;
        }

        // splitFromOrder may mutate order state — reload before adding the replacement.
        $order = wc_get_order((int) $row->order_id);
        if (!$order) {
            return new \WP_Error('sutore_marketplace_fulfillment_order', __('Order not found.', 'sutore-marketplace'));
        }

        $newProduct = wc_get_product($newListing->variationId);
        if (!$newProduct) {
            return new \WP_Error('sutore_marketplace_fulfillment_product', __('Product not found.', 'sutore-marketplace'));
        }

        $newItemId = $order->add_product($newProduct, 1);
        if (!$newItemId) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('Could not add the product to the order.', 'sutore-marketplace')
            );
        }

        $newItem = $order->get_item($newItemId);
        if ($newItem instanceof \WC_Order_Item_Product) {
            (new \SutoreMarketplace\Modules\Orders\Hooks\OrderItemPricingMetaHooks())->applyMarketplaceLineTotals($newItem);
            $newItem->save();
        }

        $newCustomer = MarketplacePricing::customerPrice($newListing);
        $priceDiff = round($newCustomer - $oldLineTotal, 2);
        if (abs($priceDiff) >= 0.01) {
            $prevDiff = (float) $order->get_meta('_sutore_mp_price_difference', true);
            $order->update_meta_data('_sutore_mp_price_difference', round($prevDiff + $priceDiff, 2));
        }

        $order->calculate_totals();
        if (method_exists($order, 'recalculate_coupons')) {
            $order->recalculate_coupons();
        }
        $order->save();

        $actor = $this->support->resolveActor();
        $swapPayload = [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'old_variation_id' => $listingId,
            'new_variation_id' => $newListingId,
            'old_merchant_id' => (int) $row->merchant_id,
            'new_merchant_id' => (int) $newListing->merchantId,
            'old_parent_product_id' => $oldListing ? (int) $oldListing->parentProductId : 0,
            'new_parent_product_id' => (int) $newListing->parentProductId,
            'old_size_term_id' => $oldListing ? (int) $oldListing->sizeTermId : 0,
            'new_size_term_id' => (int) $newListing->sizeTermId,
            'same_parent' => $sameParent,
            'price_difference' => $priceDiff,
            'staff_note' => $note,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ];

        $this->support->logListingEvent('order_listing_swapped', $newListing, array_merge($swapPayload, [
            'role' => 'incoming',
        ]), $row);

        $oldListing = $bridge->find($listingId);
        $this->support->logListingEvent('order_listing_swapped', $oldListing, array_merge($swapPayload, [
            'role' => 'outgoing',
        ]), $row);

        $attached = $this->payment->onPaymentComplete($newListing, (int) $row->order_id, (int) $newItemId);
        if (is_wp_error($attached)) {
            return $attached;
        }

        if ($returnToQueue) {
            return new \WP_Error(
                'sutore_marketplace_detach_no_relist',
                __('Order-detached products cannot be put back on sale. Create a new product instead.', 'sutore-marketplace')
            );
        }

        return true;
    }

    /**
     * Move a linked sale to the open pre-order board (order link retained).
     */
    public function markAsPreOrder(int $listingId, string $reason = 'staff'): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        if (!in_array((string) $row->fulfillment_status, [ListingStatus::PAYMENT, ListingStatus::SOLD], true)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Only sales awaiting confirmation can be marked as pre-order.', 'sutore-marketplace')
            );
        }

        if (!$this->payment->isLinkedToOrder($row)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_not_linked',
                __('This product is not linked to an order.', 'sutore-marketplace')
            );
        }

        $listing = $this->support->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $andEquals = $reason === 'confirm_deadline' ? ['confirm_punished' => 0] : [];
        $claimed = $this->repo->claimWhile(
            $listingId,
            [ListingStatus::PAYMENT, ListingStatus::SOLD],
            $andEquals,
            [
                'fulfillment_status' => ListingStatus::PRE_ORDER,
                'confirm_deadline_at' => null,
                'seller_confirmed_at' => null,
                'cargo_deadline_at' => null,
                'merchant_shipped_at' => null,
                'merchant_shipment_code' => null,
                'sutore_shipment_code' => null,
                'confirm_notice_sent' => 0,
                'confirm_punished' => $reason === 'confirm_deadline' ? 1 : (int) $row->confirm_punished,
                'cargo_notice_sent' => 0,
                'cargo_expired_flag' => 0,
                'merchant_snapshot' => null,
                'is_winner' => 0,
            ]
        );
        if (!$claimed) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Sale status changed. Refresh and try again.', 'sutore-marketplace')
            );
        }

        $this->support->logListingEvent('listing_pre_order', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'reason' => $reason,
        ], $row);

        if ($reason === 'staff') {
            $order = wc_get_order((int) $row->order_id);
            if ($order) {
                $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
                (new AskMerchants())->notifyForSize(
                    $listing->parentProductId,
                    $listing->sizeTermId,
                    $listing->asking,
                    $title
                );
            }
        }

        return true;
    }

    /**
     * Staff: pre-order could not be sourced — detach, refund the line if paid, notify the customer.
     *
     * @param array{staff_note?:string} $args
     */
    public function closeUnsourcedPreOrder(int $listingId, array $args = []): true|\WP_Error
    {
        $note = $this->support->requireStaffNote($args);
        if (is_wp_error($note)) {
            return $note;
        }

        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        if ((string) $row->fulfillment_status !== ListingStatus::PRE_ORDER) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Only open pre-orders can be marked as could not be sourced.', 'sutore-marketplace')
            );
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['close_pre_order'])) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Invalid status.', 'sutore-marketplace'));
        }

        $actor = $this->support->resolveActor();
        $listing = $this->support->bridge()->find($listingId);
        $orderId = (int) $row->order_id;
        $itemId = (int) $row->order_item_id;
        $order = $orderId > 0 ? wc_get_order($orderId) : false;

        if ($order instanceof \WC_Order && $itemId > 0) {
            $this->support->refundPaidLineThenRemove(
                $order,
                $itemId,
                $listing,
                $actor,
                $listingId,
                __('Pre-order could not be sourced.', 'sutore-marketplace')
            );
        }

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::ORDER_DETACHED,
            'order_item_id' => 0,
        ]);
        $this->support->bridge()->releaseFromOrder($listingId, ListingStatus::ORDER_DETACHED, [
            'reason' => 'unsourced',
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'variation_id' => $listingId,
            'staff_note' => $note,
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ]);

        if ($listing) {
            $this->support->logListingEvent('listing_left_sale', $listing, [
                'variation_id' => $listingId,
                'order_id' => $orderId,
                'reason' => 'unsourced',
                'staff_note' => $note,
            ], $row);
        }

        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if ($order instanceof \WC_Order && $listing) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->support->templateVars($order, $listing, $title);
            Notifications::sendEvent('pre_order_unsourced_customer', (string) $order->get_billing_phone(), $vars);
            $this->support->cancelOrderIfNoOpenItems($order);
        }

        WebhookNotifier::dispatch('fulfillment.split', [
            'variation_id' => $listingId,
            'order_id' => $orderId,
            'reason' => 'unsourced',
            'actor_user_id' => $actor['user_id'],
            'actor_login' => $actor['login'],
        ]);

        return true;
    }

    /**
     * Merchant accepts a pre-order listing — immediate order swap (no staff step).
     */
    public function acceptPreOrderSwap(int $preOrderListingId, int $newListingId, int $acceptingMerchantId): true|\WP_Error
    {
        $newListing = $this->support->bridge()->find($newListingId);
        if (!$newListing) {
            return new \WP_Error('sutore_pre_order_listing', __('Replacement product not found.', 'sutore-marketplace'));
        }

        if ((int) $newListing->merchantId !== $acceptingMerchantId) {
            return new \WP_Error(
                'sutore_pre_order_forbidden',
                __('This product does not belong to you.', 'sutore-marketplace')
            );
        }

        if ($newListingId !== $preOrderListingId && ListingStatus::isProcessLocked($newListing)) {
            return new \WP_Error(
                'sutore_pre_order_listing_locked',
                __('This product is already in an order process.', 'sutore-marketplace')
            );
        }

        $preOrder = $this->support->bridge()->find($preOrderListingId);
        if (!$preOrder) {
            return new \WP_Error(
                'sutore_pre_order_missing',
                __('Pre-order product not found.', 'sutore-marketplace')
            );
        }

        $claimed = $this->support->bridge()->claimPreOrderForSwap($preOrderListingId);
        if (is_wp_error($claimed)) {
            return $claimed;
        }

        $orderId = (int) $claimed['order_id'];
        $oldOrderItemId = (int) $claimed['order_item_id'];
        $order = wc_get_order($orderId);
        if (!$order) {
            return new \WP_Error('sutore_marketplace_fulfillment_order', __('Order not found.', 'sutore-marketplace'));
        }

        $oldLineTotal = 0.0;
        if ($oldOrderItemId > 0) {
            $oldItem = $order->get_item($oldOrderItemId);
            if ($oldItem instanceof \WC_Order_Item_Product) {
                $oldLineTotal = (float) $oldItem->get_total();
            }
        }
        if ($oldLineTotal <= 0) {
            $oldLineTotal = MarketplacePricing::customerPrice($preOrder);
        }

        if ($oldOrderItemId > 0 && $order->get_item($oldOrderItemId)) {
            $order->remove_item($oldOrderItemId);
        }

        // Reload replacement after claim (same-listing path leaves the row ORDER_DETACHED).
        $newListing = $this->support->bridge()->find($newListingId);
        if (!$newListing) {
            return new \WP_Error('sutore_pre_order_listing', __('Replacement product not found.', 'sutore-marketplace'));
        }

        $newProduct = wc_get_product($newListing->variationId);
        if (!$newProduct) {
            return new \WP_Error('sutore_marketplace_fulfillment_product', __('Product not found.', 'sutore-marketplace'));
        }

        $newItemId = $order->add_product($newProduct, 1);
        if (!$newItemId) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_order',
                __('Could not add the product to the order.', 'sutore-marketplace')
            );
        }

        $newItem = $order->get_item($newItemId);
        if ($newItem instanceof \WC_Order_Item_Product) {
            (new \SutoreMarketplace\Modules\Orders\Hooks\OrderItemPricingMetaHooks())->applyMarketplaceLineTotals($newItem);
            $newItem->save();
        }

        $newCustomer = MarketplacePricing::customerPrice($newListing);
        $priceDiff = round($newCustomer - $oldLineTotal, 2);
        if (abs($priceDiff) >= 0.01) {
            $prevDiff = (float) $order->get_meta('_sutore_mp_price_difference', true);
            $order->update_meta_data('_sutore_mp_price_difference', round($prevDiff + $priceDiff, 2));
        }

        $order->calculate_totals();
        if (method_exists($order, 'recalculate_coupons')) {
            $order->recalculate_coupons();
        }
        $order->save();

        $title = Notifications::productTitle($newListing->variationId, $newListing->variationId, $newListing->parentProductId);
        $vars = $this->support->templateVars($order, $newListing, $title);
        Notifications::sendEvent('pre_order_swapped_customer', (string) $order->get_billing_phone(), $vars, true);

        $swapPayload = [
            'variation_id' => $preOrderListingId,
            'order_id' => $orderId,
            'old_variation_id' => $preOrderListingId,
            'new_variation_id' => $newListingId,
            'old_merchant_id' => (int) $preOrder->merchantId,
            'new_merchant_id' => (int) $newListing->merchantId,
            'price_difference' => $priceDiff,
        ];
        $this->support->logListingEvent('order_listing_swapped', $newListing, array_merge($swapPayload, ['role' => 'incoming']));
        $this->support->logListingEvent('order_listing_swapped', $preOrder, array_merge($swapPayload, ['role' => 'outgoing']));

        $attached = $this->payment->onPaymentComplete($newListing, $orderId, (int) $newItemId);
        if (is_wp_error($attached)) {
            return $attached;
        }

        WebhookNotifier::dispatch('pre_order.accepted', [
            'pre_order_variation_id' => $preOrderListingId,
            'new_variation_id' => $newListingId,
            'order_id' => $orderId,
            'merchant_id' => $acceptingMerchantId,
        ]);

        return true;
    }

    /**
     * Eligible replacement listings for staff “Change Seller”.
     *
     * Default (no search): same parent product, any size, publish + winner, not on an order.
     * With search: any eligible winner matching product title / SKU / listing id.
     *
     * @param array{search?:string,per_page?:int} $args
     * @return array{items:list<array<string,mixed>>,total:int,scope:string,parent_product_id:int}|\WP_Error
     */
    public function listSwapCandidates(int $listingId, array $args = []): array|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        $caps = ListingStatus::staffCapabilities((string) $row->fulfillment_status);
        if (empty($caps['swap']) || !Settings::swapAllowedFor((string) $row->fulfillment_status)) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Seller cannot be changed in this status.', 'sutore-marketplace'));
        }

        $current = $this->support->bridge()->find($listingId);
        $parentId = $current ? (int) $current->parentProductId : 0;
        $search = sanitize_text_field((string) ($args['search'] ?? ''));
        $perPage = min(50, max(1, (int) ($args['per_page'] ?? 30)));
        $scope = $search !== '' ? 'search' : 'same_product';

        $queryArgs = [
            'status' => 'winner',
            'per_page' => $perPage,
            'page' => 1,
            'orderby' => 'asking',
            'order' => 'ASC',
        ];
        if ($search !== '') {
            $queryArgs['search'] = $search;
        } elseif ($parentId > 0) {
            $queryArgs['parent_product_id'] = $parentId;
        } else {
            return [
                'items' => [],
                'total' => 0,
                'scope' => $scope,
                'parent_product_id' => $parentId,
            ];
        }

        $result = (new ListingRepository())->query($queryArgs);
        $candidateIds = [];
        foreach ($result['items'] as $listing) {
            $candidateIds[] = (int) $listing->variationId;
        }
        $activeMap = $this->repo->findActiveByVariationIds($candidateIds);

        $items = [];
        foreach ($result['items'] as $listing) {
            if ((int) $listing->variationId === $listingId) {
                continue;
            }
            if ((int) $listing->orderId > 0 || isset($activeMap[(int) $listing->variationId])) {
                continue;
            }
            if ($scope === 'same_product' && $parentId > 0 && (int) $listing->parentProductId !== $parentId) {
                continue;
            }
            $presented = $this->presentSwapCandidate($listing, $parentId);
            if ($presented !== null) {
                $items[] = $presented;
            }
        }

        return [
            'items' => $items,
            'total' => count($items),
            'scope' => $scope,
            'parent_product_id' => $parentId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentSwapCandidate(Listing $listing, int $currentParentId): ?array
    {
        $listingId = (int) $listing->variationId;
        if ($listingId <= 0) {
            return null;
        }

        $user = get_userdata((int) $listing->merchantId);
        $merchantName = $user ? (string) $user->display_name : ('#' . (int) $listing->merchantId);
        $sizeLabel = ProductSizeLookup::labelForTerm((int) $listing->parentProductId, (int) $listing->sizeTermId);
        if ($sizeLabel === '') {
            $sizeLabel = ProductSizeLookup::labelForTermId((int) $listing->sizeTermId);
        }
        $asking = (float) $listing->asking;
        $customerPrice = MarketplacePricing::customerPrice($listing);
        $fees = MarketplacePricing::feeBreakdownForListing($listing);
        // Staff order screens compare the fee-inclusive customer price (hizmet + güvence),
        // matching the amount charged on the WooCommerce order line.
        $priceDisplay = MarketplacePricing::formatTl($customerPrice);
        $productTitle = Notifications::productTitle(
            $listingId,
            (int) $listing->variationId,
            (int) $listing->parentProductId,
            (int) $listing->sizeTermId
        );
        $sameParent = $currentParentId > 0 && (int) $listing->parentProductId === $currentParentId;

        if ($sameParent) {
            $label = sprintf(
                /* translators: 1: merchant name, 2: size label, 3: price, 4: variation id */
                __('%1$s · Size %2$s · %3$s · Var #%4$d', 'sutore-marketplace'),
                $merchantName,
                $sizeLabel !== '' ? $sizeLabel : '—',
                $priceDisplay,
                (int) $listing->variationId
            );
        } else {
            $label = sprintf(
                /* translators: 1: product title, 2: merchant name, 3: size label, 4: price, 5: variation id */
                __('%1$s · %2$s · Size %3$s · %4$s · Var #%5$d', 'sutore-marketplace'),
                $productTitle !== '' ? $productTitle : ('#' . (int) $listing->parentProductId),
                $merchantName,
                $sizeLabel !== '' ? $sizeLabel : '—',
                $priceDisplay,
                (int) $listing->variationId
            );
        }

        return [
            'id' => (int) $listing->variationId,
            'label' => $label,
            'merchant_id' => (int) $listing->merchantId,
            'merchant_name' => $merchantName,
            'parent_product_id' => (int) $listing->parentProductId,
            'size_term_id' => (int) $listing->sizeTermId,
            'size_label' => $sizeLabel,
            'variation_id' => (int) $listing->variationId,
            'asking' => $asking,
            'asking_display' => MarketplacePricing::formatTl($asking),
            'customer_price' => $customerPrice,
            'customer_price_display' => $priceDisplay,
            'hizmet_fee' => (float) $fees['hizmet'],
            'guvence_fee' => (float) $fees['guvence'],
            'product_title' => $productTitle,
            'thumbnail' => ProductThumbnail::url((int) $listing->variationId),
            'same_parent' => $sameParent,
        ];
    }
}