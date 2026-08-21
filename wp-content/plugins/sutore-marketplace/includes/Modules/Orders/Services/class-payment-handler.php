<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;

final class PaymentHandler
{
    /** @var array<int, true> */
    private static array $startedInRequest = [];

    private const META_SALE_STARTED = '_sutore_mp_sale_started';

    public function __construct(
        private readonly FulfillmentService $fulfillment = new FulfillmentService(),
        private readonly ListingRepository $listings = new ListingRepository(),
    ) {
    }

    public function onPaymentComplete(int $orderId): void
    {
        $this->startMarketplaceSale($orderId, true);
    }

    /**
     * COD / similar gateways move to processing without payment_complete.
     * Treat processing as paid claim (same as payment_complete).
     */
    public function onOrderConfirmed(int $orderId): void
    {
        $this->startMarketplaceSale($orderId, true);
    }

    /**
     * BACS / cheque style on-hold: reserve the listing (payment status) without
     * treating funds as received. Later payment_complete / processing advances to sold.
     */
    public function onOrderOnHold(int $orderId): void
    {
        $this->startMarketplaceSale($orderId, false);
    }

    private function startMarketplaceSale(int $orderId, bool $fundsReceived): void
    {
        if (isset(self::$startedInRequest[$orderId])) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        if ($order->get_meta(self::META_SALE_STARTED) === 'yes') {
            return;
        }

        self::$startedInRequest[$orderId] = true;

        if ($fundsReceived) {
            Notifications::sendEvent(
                'payment_received_customer',
                (string) $order->get_billing_phone(),
                Notifications::baseVars($order, __('Your order', 'sutore-marketplace'))
            );
        }

        $hadMarketplace = false;
        $failed = false;

        foreach ($order->get_items() as $itemId => $item) {
            $variationId = (int) $item->get_variation_id();
            if (!$variationId) {
                $variationId = (int) $item->get_product_id();
            }
            $listing = $this->listings->findByVariationId($variationId);
            if (!$listing || !$listing->variationId) {
                continue;
            }

            $hadMarketplace = true;
            $result = $fundsReceived
                ? $this->fulfillment->onPaymentComplete($listing, $orderId, (int) $itemId)
                : $this->fulfillment->onPaymentReserved($listing, $orderId, (int) $itemId);

            if (is_wp_error($result)) {
                $failed = true;
                $order->add_order_note(sprintf(
                    /* translators: 1: variation id, 2: error message */
                    __('Marketplace claim failed for product #%1$d: %2$s', 'sutore-marketplace'),
                    (int) $listing->variationId,
                    $result->get_error_message()
                ));
            }
        }

        if ($hadMarketplace && !$failed) {
            $order->update_meta_data(self::META_SALE_STARTED, 'yes');
            $order->save();
        }
    }

    public function onPrePayment(int $orderId): void
    {
        unset($orderId);
    }
}
