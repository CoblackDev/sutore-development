<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Shared\Services\YouthDiscount;

final class YouthDiscountHooks
{
    public function register(): void
    {
        add_action('woocommerce_checkout_update_order_review', [$this, 'captureClassicReview'], 5);
        add_action('woocommerce_checkout_process', [$this, 'captureClassicCheckout'], 5);
        add_action('woocommerce_store_api_checkout_update_customer_from_request', [$this, 'captureBlocksCustomer'], 5, 2);
        add_action('woocommerce_cart_calculate_fees', [$this, 'addCartFee'], 30);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveClassicOrderMeta'], 30);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'saveBlocksOrderMeta'], 30, 2);
    }

    public function captureClassicReview(string $postedData): void
    {
        $posted = [];
        parse_str($postedData, $posted);
        if ($posted === []) {
            $posted = $_POST;
        }
        YouthDiscount::captureFromPosted(is_array($posted) ? $posted : [], false);
    }

    public function captureClassicCheckout(): void
    {
        YouthDiscount::captureFromPosted($_POST, true);
    }

    public function captureBlocksCustomer(\WC_Customer $customer, \WP_REST_Request $request): void
    {
        $fields = $request->get_param('additional_fields');
        YouthDiscount::captureFromBlocks($customer, is_array($fields) ? $fields : [], false);
    }

    public function addCartFee($cart): void
    {
        if (is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $amount = YouthDiscount::amountForCart($cart);
        if ($amount < 0.01) {
            return;
        }

        $cart->add_fee(YouthDiscount::feeLabel(), -1 * $amount, false);
    }

    public function saveClassicOrderMeta(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }

        YouthDiscount::attachOrderMeta($order, $this->feeAmountFromOrder($order));
        $order->save();
    }

    public function saveBlocksOrderMeta(\WC_Order $order, \WP_REST_Request $request): void
    {
        $fields = $request->get_param('additional_fields');
        $customer = function_exists('WC') && WC()->customer instanceof \WC_Customer ? WC()->customer : new \WC_Customer($order->get_customer_id());
        YouthDiscount::captureFromBlocks($customer, is_array($fields) ? $fields : [], false);
        YouthDiscount::attachOrderMeta($order, $this->feeAmountFromOrder($order));
    }

    private function feeAmountFromOrder(\WC_Order $order): float
    {
        $amount = 0.0;
        $label = YouthDiscount::feeLabel();
        foreach ($order->get_fees() as $fee) {
            if ($fee->get_name() !== $label) {
                continue;
            }
            $amount += abs((float) $fee->get_total());
        }

        if ($amount > 0) {
            return round($amount, 2);
        }

        if (function_exists('WC') && WC()->cart) {
            return YouthDiscount::amountForCart(WC()->cart);
        }

        return 0.0;
    }
}
