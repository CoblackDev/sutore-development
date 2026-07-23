<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Modules\Merchants\Services\TcValidator;
use SutoreMarketplace\Shared\Settings\Settings;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;

final class CheckoutIdentityHooks
{
    private const CLASSIC_FIELD = 'billing_sutore_marketplace_tckno';
    private const USER_META = '_sutore_marketplace_checkout_tckno';
    private const ORDER_META = '_sutore_marketplace_checkout_tckno';

    public function register(): void
    {
        add_filter('woocommerce_checkout_fields', [$this, 'checkoutFields'], 1001);
        add_action('woocommerce_checkout_process', [$this, 'validateCheckout']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveOrderMeta']);
        add_action('woocommerce_checkout_update_user_meta', [$this, 'saveUserMeta'], 10, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'renderAdminOrderTckno']);
        add_filter('woocommerce_admin_order_preview_get_order_details', [$this, 'orderPreviewData'], 10, 2);
        add_action('woocommerce_admin_order_preview_end', [$this, 'renderOrderPreviewTckno']);
        add_action('woocommerce_blocks_loaded', [$this, 'registerBlocksField']);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'validateBlocksCheckout'], 5, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'saveBlocksOrderMeta'], 10, 2);
    }

    /** @param array<string, mixed> $fields */
    public function checkoutFields(array $fields): array
    {
        $required = $this->tcknoRequired();

        $fields['billing'][self::CLASSIC_FIELD] = [
            'label' => $required
                ? __('National ID (required)', 'sutore-marketplace')
                : __('National ID (optional)', 'sutore-marketplace'),
            'placeholder' => __('National ID number', 'sutore-marketplace'),
            'required' => $required,
            'priority' => 25,
            'class' => ['form-row-wide'],
            'type' => 'text',
            'custom_attributes' => [
                'inputmode' => 'numeric',
                'pattern' => '[0-9]{11}',
                'maxlength' => '11',
            ],
        ];

        if (is_user_logged_in()) {
            $userId = get_current_user_id();
            $saved = (string) get_user_meta($userId, self::USER_META, true);
            if ($saved !== '') {
                $fields['billing'][self::CLASSIC_FIELD]['default'] = $saved;
            }
        }

        return $fields;
    }

    public function validateCheckout(): void
    {
        $tckno = isset($_POST[self::CLASSIC_FIELD])
            ? preg_replace('/\D/', '', (string) wp_unslash($_POST[self::CLASSIC_FIELD]))
            : '';

        if ($tckno === '' && $this->tcknoRequired()) {
            wc_add_notice(__('National ID is required for this order.', 'sutore-marketplace'), 'error');
            return;
        }

        if ($tckno !== '' && !TcValidator::isValid($tckno)) {
            wc_add_notice(__('Please enter a valid national ID number.', 'sutore-marketplace'), 'error');
        }
    }

    public function saveOrderMeta(int $orderId): void
    {
        if (!isset($_POST[self::CLASSIC_FIELD])) {
            return;
        }

        $tckno = preg_replace('/\D/', '', (string) wp_unslash($_POST[self::CLASSIC_FIELD])) ?? '';
        if ($tckno === '') {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $order->update_meta_data(self::ORDER_META, sanitize_text_field($tckno));
        $order->save();
    }

    /** @param array<string, mixed> $posted */
    public function saveUserMeta(int $customerId, array $posted): void
    {
        if (!isset($posted[self::CLASSIC_FIELD])) {
            return;
        }

        $tckno = preg_replace('/\D/', '', (string) $posted[self::CLASSIC_FIELD]) ?? '';
        if ($tckno !== '') {
            update_user_meta($customerId, self::USER_META, sanitize_text_field($tckno));
        }
    }

    public function renderAdminOrderTckno(\WC_Order $order): void
    {
        $tckno = (string) $order->get_meta(self::ORDER_META);
        if ($tckno === '') {
            $tckno = (string) get_user_meta((int) $order->get_customer_id(), self::USER_META, true);
        }

        if ($tckno === '') {
            return;
        }

        echo '<p><strong>' . esc_html__('National ID', 'sutore-marketplace') . ':</strong> '
            . esc_html($tckno) . '</p>';
    }

    /** @param array<string, mixed> $data */
    public function orderPreviewData(array $data, \WC_Order $order): array
    {
        $tckno = (string) $order->get_meta(self::ORDER_META);
        if ($tckno === '') {
            $tckno = (string) get_user_meta((int) $order->get_customer_id(), self::USER_META, true);
        }

        $data['sutore_marketplace_tckno'] = $tckno;

        return $data;
    }

    public function renderOrderPreviewTckno(): void
    {
        echo '<div><strong>' . esc_html__('National ID', 'sutore-marketplace') . ':</strong> {{data.sutore_marketplace_tckno}}</div>';
    }

    public function registerBlocksField(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id' => 'sutore-marketplace/billing-tckno',
            'label' => __('National ID', 'sutore-marketplace'),
            'location' => 'address',
            'type' => 'text',
            'required' => false,
            'attributes' => [
                'inputmode' => 'numeric',
                'maxlength' => 11,
            ],
            'validate_callback' => static function ($value): bool|\WP_Error {
                if ($value === null || $value === '') {
                    return true;
                }

                $tckno = preg_replace('/\D/', '', (string) $value) ?? '';
                if ($tckno !== '' && !TcValidator::isValid($tckno)) {
                    return new \WP_Error(
                        'sutore_marketplace_invalid_tckno',
                        __('Please enter a valid national ID number.', 'sutore-marketplace')
                    );
                }

                return true;
            },
        ]);
    }

    public function validateBlocksCheckout(\WC_Order $order, \WP_REST_Request $request): void
    {
        $tckno = $this->extractBlocksTckno($request);

        if ($tckno === '' && $this->tcknoRequired()) {
            $this->throwBlocksCheckoutError(
                'sutore_marketplace_tckno_required',
                __('National ID is required for this order.', 'sutore-marketplace')
            );
        }

        if ($tckno !== '' && !TcValidator::isValid($tckno)) {
            $this->throwBlocksCheckoutError(
                'sutore_marketplace_invalid_tckno',
                __('Please enter a valid national ID number.', 'sutore-marketplace')
            );
        }
    }

    public function saveBlocksOrderMeta(\WC_Order $order, \WP_REST_Request $request): void
    {
        $tckno = $this->extractBlocksTckno($request);
        if ($tckno === '') {
            return;
        }

        $order->update_meta_data(self::ORDER_META, sanitize_text_field($tckno));

        $customerId = (int) $order->get_customer_id();
        if ($customerId > 0) {
            update_user_meta($customerId, self::USER_META, sanitize_text_field($tckno));
        }
    }

    private function extractBlocksTckno(\WP_REST_Request $request): string
    {
        $fields = $request->get_param('additional_fields');
        if (!is_array($fields)) {
            return '';
        }

        $raw = $fields['sutore-marketplace/billing-tckno'] ?? '';

        return preg_replace('/\D/', '', (string) $raw) ?? '';
    }

    private function throwBlocksCheckoutError(string $code, string $message): void
    {
        if (class_exists(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class)) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException($code, $message, 400);
        }

        throw new \WC_REST_Exception($code, $message, 400);
    }

    private function tcknoRequired(): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if ($product instanceof \WC_Product && ImportedProductService::isVariationImported((int) $product->get_id())) {
                return true;
            }
        }

        $threshold = Settings::checkoutTcknoCartTotalThreshold();
        if ($threshold > 0 && (float) WC()->cart->get_total('edit') > $threshold) {
            return true;
        }

        return false;
    }
}
