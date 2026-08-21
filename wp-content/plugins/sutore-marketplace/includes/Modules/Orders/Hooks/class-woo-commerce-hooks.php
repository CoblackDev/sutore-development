<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Hooks;

use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Modules\Orders\Services\Notifications;
use SutoreMarketplace\Modules\Orders\Services\OrderItemSellerDisplay;
use SutoreMarketplace\Modules\Orders\Services\PaymentHandler;

final class WooCommerceHooks
{
    public function register(): void
    {
        add_action('woocommerce_payment_complete', [$this, 'onPaymentComplete'], 10, 1);
        add_action('woocommerce_pre_payment_complete', [$this, 'onPrePayment'], 10, 1);
        // COD etc. may never call payment_complete — processing means funds accepted.
        add_action('woocommerce_order_status_processing', [$this, 'onOrderConfirmed'], 10, 1);
        // BACS / cheque on-hold: reserve listing only (not “payment received”).
        add_action('woocommerce_order_status_on-hold', [$this, 'onOrderOnHold'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'onOrderCompleted'], 10, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'onOrderRefunded'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'onOrderCancelled'], 10, 1);
        add_action('woocommerce_after_order_itemmeta', [$this, 'renderAdminSellerMeta'], 10, 3);
    }

    /**
     * @param mixed $product
     */
    public function renderAdminSellerMeta(int $itemId, \WC_Order_Item $item, $product): void
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $rows = OrderItemSellerDisplay::adminRowsForItem($item);
        if ($rows === []) {
            return;
        }

        $html = [];
        foreach ($rows as $row) {
            $html[] = '<strong>' . esc_html($row['label']) . ':</strong> ' . esc_html($row['value']);
        }

        echo '<div class="sutore-mp-order-item-seller" style="margin-top:8px;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;">';
        echo '<div style="font-weight:600;margin-bottom:4px;">' . esc_html__('Seller / shipping', 'sutore-marketplace') . '</div>';
        echo '<div style="line-height:1.5;">' . implode('<br />', $html) . '</div>';
        echo '</div>';
    }

    public function onPaymentComplete(int $orderId): void
    {
        (new PaymentHandler())->onPaymentComplete($orderId);
    }

    public function onOrderConfirmed(int $orderId): void
    {
        (new PaymentHandler())->onOrderConfirmed($orderId);
    }

    public function onOrderOnHold(int $orderId): void
    {
        (new PaymentHandler())->onOrderOnHold($orderId);
    }

    public function onPrePayment(int $orderId): void
    {
        (new PaymentHandler())->onPrePayment($orderId);
    }

    public function onOrderCompleted(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }
        Notifications::sendEvent(
            'order_completed',
            (string) $order->get_billing_phone(),
            Notifications::baseVars($order, __('Your order', 'sutore-marketplace'))
        );
    }

    public function onOrderRefunded(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }
        Notifications::sendEvent(
            'order_refunded',
            (string) $order->get_billing_phone(),
            Notifications::baseVars($order, __('Your order', 'sutore-marketplace'))
        );
    }

    public function onOrderCancelled(int $orderId): void
    {
        (new FulfillmentService())->onWooCommerceOrderCancelled($orderId);
    }
}
