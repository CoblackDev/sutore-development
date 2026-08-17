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
        $this->startMarketplaceSale($orderId);
    }

    /**
     * Gateways like COD / BACS move the order to processing/on-hold without
     * calling woocommerce_payment_complete. Those paths must still reserve the
     * sold listing and advance the sale queue.
     */
    public function onOrderConfirmed(int $orderId): void
    {
        $this->startMarketplaceSale($orderId);
    }

    private function startMarketplaceSale(int $orderId): void
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

        Notifications::sendEvent(
            'payment_received_customer',
            (string) $order->get_billing_phone(),
            Notifications::baseVars($order, __('Your order', 'sutore-marketplace'))
        );

        foreach ($order->get_items() as $itemId => $item) {
            $variationId = (int) $item->get_variation_id();
            if (!$variationId) {
                $variationId = (int) $item->get_product_id();
            }
            $listing = $this->listings->findByVariationId($variationId);
            if (!$listing || !$listing->variationId) {
                continue;
            }

            $this->fulfillment->onPaymentComplete($listing, $orderId, (int) $itemId);
        }

        $order->update_meta_data(self::META_SALE_STARTED, 'yes');
        $order->save();
    }

    public function onPrePayment(int $orderId): void
    {
        unset($orderId);
    }
}
