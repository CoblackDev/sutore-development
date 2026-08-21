<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;

/**
 * Sale / fulfillment lifecycle service (facade).
 *
 * Since the fulfillments table has been eliminated, every "$fulfillmentId"
 * argument in this class is the listing variation id. The repository still
 * returns rows shaped like the historical fulfillment rows (id = variation_id,
 * fulfillment_status mirrors listing_status) so REST / JS consumers stay
 * unchanged. Repository writes go straight to the listing row: setting
 * `fulfillment_status` in the payload is translated to `listing_status`.
 */
final class FulfillmentService
{
    private readonly FulfillmentCommandSupport $support;
    private readonly PaymentReservationCommands $payment;
    private readonly MerchantFulfillmentCommands $merchant;
    private readonly StaffFulfillmentCommands $staff;
    private readonly SourcingSwapCommands $sourcing;
    private readonly PayoutCommands $payout;

    public function __construct(
        private readonly FulfillmentRepository $repo = new FulfillmentRepository(),
    ) {
        $this->support = new FulfillmentCommandSupport($this->repo);
        $this->payment = new PaymentReservationCommands($this->support, $this->repo);
        $this->payout = new PayoutCommands($this->support, $this->repo);
        $this->sourcing = new SourcingSwapCommands($this->support, $this->repo, $this->payment);
        $this->merchant = new MerchantFulfillmentCommands($this->support, $this->repo, $this->sourcing);
        $this->staff = new StaffFulfillmentCommands($this->support, $this->repo, $this->payment, $this->payout, $this->sourcing);
    }


    public function onPaymentComplete(Listing $listing, int $orderId, int $orderItemId): true|\WP_Error
    {
        return $this->payment->onPaymentComplete(...func_get_args());
    }

    /**
     * Reserve listing for unpaid on-hold gateways (BACS etc.) without “paid” side effects.
     */
    public function onPaymentReserved(Listing $listing, int $orderId, int $orderItemId): true|\WP_Error
    {
        return $this->payment->onPaymentReserved(...func_get_args());
    }

    public function adminConfirmPayment(int $listingId): true|\WP_Error
    {
        return $this->payment->adminConfirmPayment(...func_get_args());
    }

    /**
     * Staff: add a market listing to a WooCommerce order.
     * Paid orders (processing/completed) start as sold; unpaid (pending/on-hold) wait for payment.
     *
     * @param array{staff_note?:string,allow_open_orders?:bool} $args
     */
    public function attachToOrder(int $listingId, int $orderId, array $args = []): true|\WP_Error
    {
        return $this->payment->attachToOrder(...func_get_args());
    }

    /**
     * Processing WooCommerce orders for the staff “add to order” dropdown.
     *
     * Lists processing orders (newest first). When variation_id is provided, orders that
     * already contain the same parent product are sorted to the top.
     *
     * @param array{variation_id?:int,search?:string} $args
     * @return list<array{id:int,label:string,status:string,total_display:string,contains_same_product:bool}>
     */
    public function listProcessingOrdersForAttach(int $limit = 50, array $args = []): array
    {
        return $this->payment->listProcessingOrdersForAttach(...func_get_args());
    }

    public function splitFromOrder(
        int $listingId,
        bool $notifyCustomer = true,
        string $detachReason = 'split',
        string $staffNote = '',
        bool $returnToQueue = false
    ): true|\WP_Error
    {
        return $this->payment->splitFromOrder(...func_get_args());
    }

    /**
     * True when the sale still has a live WooCommerce order line item.
     */
    public function isLinkedToOrder(object $row): bool
    {
        return $this->payment->isLinkedToOrder(...func_get_args());
    }

    public function onWooCommerceOrderCancelled(int $orderId): void
    {
        $this->payment->onWooCommerceOrderCancelled(...func_get_args());
    }

    public function merchantConfirmSale(int $listingId, int $merchantId): true|\WP_Error
    {
        return $this->merchant->merchantConfirmSale(...func_get_args());
    }

    public function merchantSubmitShipment(int $listingId, int $merchantId, string $shipmentCode): true|\WP_Error
    {
        return $this->merchant->merchantSubmitShipment(...func_get_args());
    }

    public function merchantCancelSale(int $listingId, int $merchantId): true|\WP_Error
    {
        return $this->merchant->merchantCancelSale(...func_get_args());
    }

    /** @return array<string, mixed> */
    public function merchantDetails(int $listingId, int $merchantId): array|\WP_Error
    {
        return $this->merchant->merchantDetails(...func_get_args());
    }

    public function processDeadline(object $row): void
    {
        $this->merchant->processDeadline(...func_get_args());
    }

    /**
     * @param array{staff_note?:string,sutore_shipment_code?:string} $args
     */
    public function markArrivedAtSutore(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->staff->markArrivedAtSutore(...func_get_args());
    }

    /**
     * @param array{staff_note?:string} $args
     */
    public function markVerified(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->staff->markVerified(...func_get_args());
    }

    /**
     * @param array{staff_note?:string} $args
     */
    public function markReadyToShip(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->staff->markReadyToShip(...func_get_args());
    }

    /**
     * @param array{staff_note?:string,sutore_shipment_code?:string} $args
     */
    public function markShippedToCustomer(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->staff->markShippedToCustomer(...func_get_args());
    }

    /**
     * @param array{staff_note?:string} $args
     */
    public function markDeliveredToCustomer(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->staff->markDeliveredToCustomer(...func_get_args());
    }

    /**
     * When every marketplace listing still linked to the order is delivered,
     * move the WooCommerce order to completed (triggers customer SMS via hook).
     */
    public function maybeCompleteOrderWhenAllDelivered(int $orderId): void
    {
        $this->staff->maybeCompleteOrderWhenAllDelivered(...func_get_args());
    }

    /**
     * @param array{staff_note:string} $args
     */
    public function hubRejectFulfillment(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->staff->hubRejectFulfillment(...func_get_args());
    }

    /**
     * @param array{staff_note:string} $args
     */
    public function markNotForSale(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->staff->markNotForSale(...func_get_args());
    }

    /**
     * @param array{staff_note:string} $args
     */
    public function chargebackFulfillment(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->staff->chargebackFulfillment(...func_get_args());
    }

    public function putListingOnSale(int $listingId): true|\WP_Error
    {
        return $this->staff->putListingOnSale(...func_get_args());
    }

    /**
     * Remove a pre-sale market listing from sale (not an in-order intervention).
     *
     * @param array{staff_note?:string} $args
     */
    public function removeListingFromSale(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->staff->removeListingFromSale(...func_get_args());
    }

    public function approveListing(int $listingId): true|\WP_Error
    {
        return $this->staff->approveListing(...func_get_args());
    }

    public function deleteListing(int $listingId): true|\WP_Error
    {
        return $this->staff->deleteListing(...func_get_args());
    }

    /**
     * Dispatch a single staff workflow action (shared by single + bulk REST).
     *
     * @param array<string, mixed> $params
     */
    public function runStaffWorkflowAction(int $listingId, string $action, array $params = []): true|\WP_Error
    {
        return $this->staff->runStaffWorkflowAction(...func_get_args());
    }

    /**
     * Apply one no-input workflow to every listing. Validates intersection first.
     *
     * @param list<int> $listingIds
     * @param array<string, mixed> $params
     * @return array{updated:int, action:string}|\WP_Error
     */
    public function bulkStaffWorkflowAction(array $listingIds, string $action, array $params = []): array|\WP_Error
    {
        return $this->staff->bulkStaffWorkflowAction(...func_get_args());
    }

    public function markListingImported(int $listingId): true|\WP_Error
    {
        return $this->staff->markListingImported(...func_get_args());
    }

    public function unmarkListingImported(int $listingId): true|\WP_Error
    {
        return $this->staff->unmarkListingImported(...func_get_args());
    }

    public function swapMerchant(
        int $listingId,
        int $newListingId,
        string $staffNote = '',
        bool $returnToQueue = false
    ): true|\WP_Error
    {
        return $this->sourcing->swapMerchant(...func_get_args());
    }

    /**
     * Move a linked sale to the open pre-order board (order link retained).
     */
    public function markAsPreOrder(int $listingId, string $reason = 'staff'): true|\WP_Error
    {
        return $this->sourcing->markAsPreOrder(...func_get_args());
    }

    /**
     * Staff: pre-order could not be sourced — detach, refund the line if paid, notify the customer.
     *
     * @param array{staff_note?:string} $args
     */
    public function closeUnsourcedPreOrder(int $listingId, array $args = []): true|\WP_Error
    {
        return $this->sourcing->closeUnsourcedPreOrder(...func_get_args());
    }

    /**
     * Merchant accepts a pre-order listing — immediate order swap (no staff step).
     */
    public function acceptPreOrderSwap(int $preOrderListingId, int $newListingId, int $acceptingMerchantId): true|\WP_Error
    {
        return $this->sourcing->acceptPreOrderSwap(...func_get_args());
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
        return $this->sourcing->listSwapCandidates(...func_get_args());
    }

    public function markMerchantPayout(int $listingId, string $paymentRef = ''): true|\WP_Error
    {
        return $this->payout->markMerchantPayout(...func_get_args());
    }

    /**
     * @param array<string, mixed> $params
     */
    public function adjustPayoutCommission(int $listingId, array $params): true|\WP_Error
    {
        return $this->payout->adjustPayoutCommission(...func_get_args());
    }

    /**
     * @param array<string, mixed> $params
     */
    public function setListingCommission(int $listingId, array $params): true|\WP_Error
    {
        return $this->payout->setListingCommission(...func_get_args());
    }

}