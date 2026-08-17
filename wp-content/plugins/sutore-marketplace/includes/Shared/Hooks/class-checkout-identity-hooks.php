<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Modules\Merchants\Services\TcValidator;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Shared\Services\YouthDiscount;
use SutoreMarketplace\Shared\Settings\Settings;

final class CheckoutIdentityHooks
{
    public const CLASSIC_TCKNO_FIELD = 'billing_sutore_marketplace_tckno';
    public const CLASSIC_BIRTH_YEAR_FIELD = 'billing_sutore_marketplace_birth_year';
    public const BLOCKS_TCKNO_ID = 'sutore-marketplace/billing-tckno';
    public const BLOCKS_BIRTH_YEAR_ID = 'sutore-marketplace/billing-birth-year';
    public const USER_META_TCKNO = '_sutore_marketplace_checkout_tckno';
    public const USER_META_BIRTH_YEAR = '_sutore_marketplace_checkout_birth_year';
    public const ORDER_META_TCKNO = '_sutore_marketplace_checkout_tckno';
    public const ORDER_META_BIRTH_YEAR = '_sutore_marketplace_checkout_birth_year';

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
        $yearMax = YouthDiscount::currentYear();

        $fields['billing'][self::CLASSIC_TCKNO_FIELD] = [
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

        if (Settings::youthDiscountEnabled()) {
            $fields['billing'][self::CLASSIC_BIRTH_YEAR_FIELD] = [
                'label' => __('Year of birth', 'sutore-marketplace'),
                'placeholder' => __('YYYY', 'sutore-marketplace'),
                'required' => false,
                'priority' => 26,
                'class' => ['form-row-wide'],
                'type' => 'number',
                'description' => __('Used with your national ID to apply the youth discount.', 'sutore-marketplace'),
                'custom_attributes' => [
                    'inputmode' => 'numeric',
                    'min' => '1900',
                    'max' => (string) $yearMax,
                    'step' => '1',
                ],
            ];
        }

        if (is_user_logged_in()) {
            $userId = get_current_user_id();
            $savedTckno = (string) get_user_meta($userId, self::USER_META_TCKNO, true);
            if ($savedTckno !== '') {
                $fields['billing'][self::CLASSIC_TCKNO_FIELD]['default'] = $savedTckno;
            }
            if (isset($fields['billing'][self::CLASSIC_BIRTH_YEAR_FIELD])) {
                $savedYear = (string) get_user_meta($userId, self::USER_META_BIRTH_YEAR, true);
                if ($savedYear !== '') {
                    $fields['billing'][self::CLASSIC_BIRTH_YEAR_FIELD]['default'] = $savedYear;
                }
            }
        }

        return $fields;
    }

    public function validateCheckout(): void
    {
        $tckno = isset($_POST[self::CLASSIC_TCKNO_FIELD])
            ? preg_replace('/\D/', '', (string) wp_unslash($_POST[self::CLASSIC_TCKNO_FIELD]))
            : '';
        $birthYear = isset($_POST[self::CLASSIC_BIRTH_YEAR_FIELD])
            ? YouthDiscount::normalizeBirthYear((string) wp_unslash($_POST[self::CLASSIC_BIRTH_YEAR_FIELD]))
            : 0;

        if ($tckno === '' && $this->tcknoRequired()) {
            wc_add_notice(__('National ID is required for this order.', 'sutore-marketplace'), 'error');
            return;
        }

        if ($tckno !== '' && !TcValidator::isValid($tckno)) {
            wc_add_notice(__('Please enter a valid national ID number.', 'sutore-marketplace'), 'error');
        }

        $rawYear = isset($_POST[self::CLASSIC_BIRTH_YEAR_FIELD])
            ? trim((string) wp_unslash($_POST[self::CLASSIC_BIRTH_YEAR_FIELD]))
            : '';
        if ($rawYear !== '' && ($birthYear < 1900 || $birthYear > YouthDiscount::currentYear())) {
            wc_add_notice(__('Enter a valid year of birth.', 'sutore-marketplace'), 'error');
        }
    }

    public function saveOrderMeta(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $dirty = false;
        if (isset($_POST[self::CLASSIC_TCKNO_FIELD])) {
            $tckno = preg_replace('/\D/', '', (string) wp_unslash($_POST[self::CLASSIC_TCKNO_FIELD])) ?? '';
            if ($tckno !== '') {
                $order->update_meta_data(self::ORDER_META_TCKNO, sanitize_text_field($tckno));
                $dirty = true;
            }
        }

        if (isset($_POST[self::CLASSIC_BIRTH_YEAR_FIELD])) {
            $birthYear = YouthDiscount::normalizeBirthYear((string) wp_unslash($_POST[self::CLASSIC_BIRTH_YEAR_FIELD]));
            if ($birthYear >= 1900) {
                $order->update_meta_data(self::ORDER_META_BIRTH_YEAR, (string) $birthYear);
                $dirty = true;
            }
        }

        if ($dirty) {
            $order->save();
        }
    }

    /** @param array<string, mixed> $posted */
    public function saveUserMeta(int $customerId, array $posted): void
    {
        if (isset($posted[self::CLASSIC_TCKNO_FIELD])) {
            $tckno = preg_replace('/\D/', '', (string) $posted[self::CLASSIC_TCKNO_FIELD]) ?? '';
            if ($tckno !== '') {
                update_user_meta($customerId, self::USER_META_TCKNO, sanitize_text_field($tckno));
            }
        }

        if (isset($posted[self::CLASSIC_BIRTH_YEAR_FIELD])) {
            $birthYear = YouthDiscount::normalizeBirthYear((string) $posted[self::CLASSIC_BIRTH_YEAR_FIELD]);
            if ($birthYear >= 1900) {
                update_user_meta($customerId, self::USER_META_BIRTH_YEAR, (string) $birthYear);
            }
        }
    }

    public function renderAdminOrderTckno(\WC_Order $order): void
    {
        $tckno = (string) $order->get_meta(self::ORDER_META_TCKNO);
        if ($tckno === '') {
            $tckno = (string) get_user_meta((int) $order->get_customer_id(), self::USER_META_TCKNO, true);
        }
        $birthYear = (string) $order->get_meta(self::ORDER_META_BIRTH_YEAR);
        if ($birthYear === '') {
            $birthYear = (string) get_user_meta((int) $order->get_customer_id(), self::USER_META_BIRTH_YEAR, true);
        }

        if ($tckno !== '') {
            echo '<p><strong>' . esc_html__('National ID', 'sutore-marketplace') . ':</strong> '
                . esc_html($tckno) . '</p>';
        }
        if ($birthYear !== '') {
            echo '<p><strong>' . esc_html__('Year of birth', 'sutore-marketplace') . ':</strong> '
                . esc_html($birthYear) . '</p>';
        }
    }

    /** @param array<string, mixed> $data */
    public function orderPreviewData(array $data, \WC_Order $order): array
    {
        $tckno = (string) $order->get_meta(self::ORDER_META_TCKNO);
        if ($tckno === '') {
            $tckno = (string) get_user_meta((int) $order->get_customer_id(), self::USER_META_TCKNO, true);
        }
        $birthYear = (string) $order->get_meta(self::ORDER_META_BIRTH_YEAR);
        if ($birthYear === '') {
            $birthYear = (string) get_user_meta((int) $order->get_customer_id(), self::USER_META_BIRTH_YEAR, true);
        }

        $data['sutore_marketplace_tckno'] = $tckno;
        $data['sutore_marketplace_birth_year'] = $birthYear;

        return $data;
    }

    public function renderOrderPreviewTckno(): void
    {
        echo '<div><strong>' . esc_html__('National ID', 'sutore-marketplace') . ':</strong> {{data.sutore_marketplace_tckno}}</div>';
        echo '<div><strong>' . esc_html__('Year of birth', 'sutore-marketplace') . ':</strong> {{data.sutore_marketplace_birth_year}}</div>';
    }

    public function registerBlocksField(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id' => self::BLOCKS_TCKNO_ID,
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

        if (!Settings::youthDiscountEnabled()) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id' => self::BLOCKS_BIRTH_YEAR_ID,
            'label' => __('Year of birth', 'sutore-marketplace'),
            'location' => 'address',
            'type' => 'text',
            'required' => false,
            'attributes' => [
                'inputmode' => 'numeric',
                'maxlength' => 4,
            ],
            'validate_callback' => static function ($value): bool|\WP_Error {
                if ($value === null || $value === '') {
                    return true;
                }

                $year = YouthDiscount::normalizeBirthYear((string) $value);
                if ($year < 1900 || $year > YouthDiscount::currentYear()) {
                    return new \WP_Error(
                        'sutore_marketplace_invalid_birth_year',
                        __('Enter a valid year of birth.', 'sutore-marketplace')
                    );
                }

                return true;
            },
        ]);
    }

    public function validateBlocksCheckout(\WC_Order $order, \WP_REST_Request $request): void
    {
        $tckno = $this->extractBlocksTckno($request);
        $birthYear = $this->extractBlocksBirthYear($request);

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

        if ($birthYear > 0 && ($birthYear < 1900 || $birthYear > YouthDiscount::currentYear())) {
            $this->throwBlocksCheckoutError(
                'sutore_marketplace_invalid_birth_year',
                __('Enter a valid year of birth.', 'sutore-marketplace')
            );
        }
    }

    public function saveBlocksOrderMeta(\WC_Order $order, \WP_REST_Request $request): void
    {
        $tckno = $this->extractBlocksTckno($request);
        $birthYear = $this->extractBlocksBirthYear($request);
        $customerId = (int) $order->get_customer_id();

        if ($tckno !== '') {
            $order->update_meta_data(self::ORDER_META_TCKNO, sanitize_text_field($tckno));
            if ($customerId > 0) {
                update_user_meta($customerId, self::USER_META_TCKNO, sanitize_text_field($tckno));
            }
        }

        if ($birthYear >= 1900) {
            $order->update_meta_data(self::ORDER_META_BIRTH_YEAR, (string) $birthYear);
            if ($customerId > 0) {
                update_user_meta($customerId, self::USER_META_BIRTH_YEAR, (string) $birthYear);
            }
        }
    }

    private function extractBlocksTckno(\WP_REST_Request $request): string
    {
        $fields = $request->get_param('additional_fields');
        if (!is_array($fields)) {
            return '';
        }

        return preg_replace('/\D/', '', (string) ($fields[self::BLOCKS_TCKNO_ID] ?? '')) ?? '';
    }

    private function extractBlocksBirthYear(\WP_REST_Request $request): int
    {
        $fields = $request->get_param('additional_fields');
        if (!is_array($fields)) {
            return 0;
        }

        return YouthDiscount::normalizeBirthYear((string) ($fields[self::BLOCKS_BIRTH_YEAR_ID] ?? ''));
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
