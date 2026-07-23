<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Hooks;

use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Modules\Shipping\Services\ChosenShipment;
use SutoreMarketplace\Modules\Shipping\Services\EtaDisplay;
use SutoreMarketplace\Modules\Shipping\Services\RateResolver;
use SutoreMarketplace\Modules\Shipping\Settings\ShippingSettings;
use SutoreMarketplace\Shared\Settings\Settings;

final class CheckoutHooks
{
    public function register(): void
    {
        // Never empty the cart on country change — Blocks then returns needs_shipping=false and hides rates.
        // Soft notices are skipped during address browse; hard-block only at place-order.
        add_action('woocommerce_after_checkout_validation', [$this, 'validateInternationalCheckout'], 10, 2);
        add_action('woocommerce_checkout_validate_order_before_payment', [$this, 'validateInternationalOrderBeforePayment'], 10, 2);
        add_action('woocommerce_checkout_update_order_review', [$this, 'onCheckoutUpdate'], 1);
        add_action('woocommerce_store_api_cart_update_customer_from_request', [$this, 'onStoreApiCustomerUpdate'], 1, 2);
        add_action('woocommerce_store_api_checkout_update_customer_from_request', [$this, 'onStoreApiCustomerUpdate'], 1, 2);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveOrderShipmentMeta']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'saveOrderShipmentMetaFromOrder'], 20, 1);
        add_action('woocommerce_checkout_create_order_shipping_item', [$this, 'attachShippingLineMeta'], 10, 4);
        add_action('woocommerce_after_calculate_totals', [$this, 'ensureChosenShippingMethod'], 20);
        add_filter('woocommerce_cart_shipping_packages', [$this, 'fingerprintPackages'], 20);
        add_filter('woocommerce_package_rates', [$this, 'filterPackageRates'], 100, 2);
        add_filter('woocommerce_get_order_item_totals', [$this, 'customizeEmailShippingRow'], 1000, 3);
        add_filter('woocommerce_get_order_item_totals', [$this, 'hideShippingTotalRow'], 11, 3);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Blocks/Store API: capture address from request and bust shipping session cache.
     *
     * @param \WC_Customer     $customer Customer being updated.
     * @param \WP_REST_Request $request  Store API request.
     */
    public function onStoreApiCustomerUpdate($customer, $request): void
    {
        if ($request instanceof \WP_REST_Request && $customer instanceof \WC_Customer) {
            $shipping = $request->get_param('shipping_address');
            $billing = $request->get_param('billing_address');

            // If shipping lagged behind billing country (Blocks race), force shipping destination.
            if (is_array($billing) && !empty($billing['country'])) {
                $billCountry = strtoupper(sanitize_text_field((string) $billing['country']));
                $shipCountry = is_array($shipping)
                    ? strtoupper(sanitize_text_field((string) ($shipping['country'] ?? '')))
                    : strtoupper(trim((string) $customer->get_shipping_country()));

                if ($billCountry !== '' && $shipCountry !== '' && $billCountry !== $shipCountry) {
                    // Prefer explicit shipping when both sent and diverge? Prefer the request shipping if present.
                    // When use-same-address flips billing first, sync shipping from billing.
                    if (!is_array($shipping) || empty($shipping['country'])) {
                        $customer->set_shipping_country($billCountry);
                        if (isset($billing['state'])) {
                            $customer->set_shipping_state(sanitize_text_field((string) $billing['state']));
                        }
                        $shipping = is_array($shipping) ? $shipping : [];
                        $shipping['country'] = $billCountry;
                        $shipping['state'] = (string) ($billing['state'] ?? '');
                    }
                }
            }

            RateResolver::captureStoreApiDestination(
                is_array($shipping) ? $shipping : null,
                is_array($billing) ? $billing : null
            );
        }

        $this->resetShippingSession();
    }

    public function enqueueAssets(): void
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $checkoutPageId = function_exists('wc_get_page_id') ? wc_get_page_id('checkout') : 0;
        $isBlocks = $checkoutPageId > 0 && has_block('woocommerce/checkout', $checkoutPageId);

        if ($isBlocks) {
            wp_enqueue_script(
                'sutore-marketplace-checkout-shipping-blocks',
                SUTORE_MARKETPLACE_URL . 'assets/js/checkout-shipping-blocks.js',
                ['wp-data', 'wc-blocks-checkout'],
                SUTORE_MARKETPLACE_VERSION,
                true
            );

            return;
        }

        wp_enqueue_style(
            'sutore-marketplace-checkout-shipping',
            SUTORE_MARKETPLACE_URL . 'assets/css/checkout-shipping.css',
            [],
            SUTORE_MARKETPLACE_VERSION
        );
        wp_enqueue_script(
            'sutore-marketplace-checkout-shipping',
            SUTORE_MARKETPLACE_URL . 'assets/js/checkout-shipping.js',
            ['jquery', 'wc-checkout'],
            SUTORE_MARKETPLACE_VERSION,
            true
        );
    }

    /**
     * Bust WC shipping session cache and sync chosen method early on classic checkout refresh.
     */
    public function onCheckoutUpdate(string $postData): void
    {
        $this->resetShippingSession();
    }

    private function resetShippingSession(): void
    {
        if (function_exists('WC') && WC()->shipping()) {
            WC()->shipping()->reset_shipping();
        }
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('shipping_for_package_0', null);
            WC()->session->set('previous_shipping_methods', null);
            // Zone/country change must not keep a stale chosen rate id.
            WC()->session->set('chosen_shipping_methods', []);
        }
    }

    /**
     * Apply live destination onto packages and bust rate hash.
     *
     * Only overwrite destination when classic POST / Store API sent address fields.
     * Otherwise keep WC customer shipping (Blocks already writes that before packages build).
     *
     * @param array<int, array<string, mixed>> $packages
     * @return array<int, array<string, mixed>>
     */
    public function fingerprintPackages(array $packages): array
    {
        $revision = (int) Settings::get('checkout_shipping_revision', 1);
        $live = RateResolver::liveDestination();

        foreach ($packages as $i => $package) {
            if ($live !== null) {
                if ($live['country'] !== '') {
                    $packages[$i]['destination']['country'] = $live['country'];
                }
                // Empty state clears stale TR34 after country/il change.
                $packages[$i]['destination']['state'] = $live['state'];
            }

            $country = strtoupper(trim((string) ($packages[$i]['destination']['country'] ?? '')));
            $state = strtoupper(trim((string) ($packages[$i]['destination']['state'] ?? '')));

            $packages[$i]['sutore_shipping_fingerprint'] = implode('|', [
                $country,
                $state,
                (string) $revision,
                (string) ShippingSettings::expressBaseFee(),
                (string) ShippingSettings::expressPerItemSurcharge(),
                (string) ShippingSettings::fastShippingFee(),
                (string) ShippingSettings::cyprusFee(),
                (string) ShippingSettings::internationalFee(),
            ]);
        }

        return $packages;
    }

    /**
     * Classic checkout place-order hard stop.
     *
     * @param array<string, mixed> $data
     * @param \WP_Error            $errors
     */
    public function validateInternationalCheckout(array $data, \WP_Error $errors): void
    {
        if ($this->hasInternationalIneligibleItems() === []) {
            return;
        }

        $errors->add(
            'sutore_international_ineligible',
            __('Products in your cart cannot be shipped to the selected country. Please update the country or your cart.', 'sutore-marketplace')
        );
    }

    /**
     * Blocks / Store API place-order hard stop.
     *
     * @param \WC_Order $order            Order being paid.
     * @param \WP_Error $validationErrors Error bag.
     */
    public function validateInternationalOrderBeforePayment(\WC_Order $order, \WP_Error $validationErrors): void
    {
        if ($this->hasInternationalIneligibleItems() === []) {
            return;
        }

        $validationErrors->add(
            'sutore_international_ineligible',
            __('Products in your cart cannot be shipped to the selected country. Please update the country or your cart.', 'sutore-marketplace')
        );
    }

    /**
     * @return list<int> Ineligible product IDs when destination is international.
     */
    private function hasInternationalIneligibleItems(): array
    {
        if (!function_exists('WC') || !WC()->cart) {
            return [];
        }

        $country = RateResolver::destinationCountry();
        if ($country === '' || $country === 'TR' || $country === 'CY') {
            return [];
        }

        $ineligible = [];
        foreach (WC()->cart->get_cart() as $values) {
            $product = $values['data'] ?? null;
            if (!$product instanceof \WC_Product || $product->is_type('simple')) {
                continue;
            }
            $lookupId = (int) $product->get_id();
            if (!EtaDisplay::isInternationalEligible($lookupId)) {
                $ineligible[] = $lookupId;
            }
        }

        return $ineligible;
    }

    public function saveOrderShipmentMeta(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $this->persistShipmentMeta($order);
    }

    public function saveOrderShipmentMetaFromOrder(\WC_Order $order): void
    {
        $this->persistShipmentMeta($order);
    }

    private function persistShipmentMeta(\WC_Order $order): void
    {
        $shipmentType = $this->resolveShipmentTypeForOrder();
        if ($shipmentType === '') {
            return;
        }

        $etaDays = ShippingSettings::etaDays($shipmentType);
        $order->update_meta_data(ShipmentMeta::TYPE, $shipmentType);
        $order->update_meta_data(ShipmentMeta::ETA_DAYS, (string) $etaDays);
        $order->update_meta_data(ShipmentMeta::DEADLINE_LABEL, EtaDisplay::formatDeliveryDate($etaDays));
        $order->update_meta_data(ShipmentMeta::DEADLINE_TIMESTAMP, (string) EtaDisplay::deadlineTimestamp($etaDays));
        $order->save();
    }

    /**
     * @param array<string, mixed> $package
     */
    public function attachShippingLineMeta(\WC_Order_Item_Shipping $item, int $packageKey, array $package, \WC_Order $order): void
    {
        $shipmentType = $this->resolveShipmentTypeForOrder();
        if ($shipmentType !== '') {
            $item->add_meta_data(ShipmentMeta::TYPE, $shipmentType, true);
        }
    }

    public function ensureChosenShippingMethod(): void
    {
        if (!function_exists('WC') || !WC()->session || !WC()->cart || !WC()->cart->needs_shipping()) {
            return;
        }

        // Classic checkout AJAX / page only. Blocks chooses rates via Store API.
        if (!is_checkout() && !wp_doing_ajax()) {
            return;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        ChosenShipment::ensureDefault();
    }

    /**
     * Prefer Sutore rates; drop cyprus outside CY and keep only cyprus for CY.
     *
     * @param array<int, \WC_Shipping_Rate> $rates
     * @param array<string, mixed> $package
     * @return array<int, \WC_Shipping_Rate>
     */
    public function filterPackageRates(array $rates, array $package): array
    {
        $country = strtoupper(trim((string) ($package['destination']['country'] ?? '')));
        if ($country === '') {
            $country = RateResolver::destinationCountry($package);
        }

        $sutoreRates = [];

        foreach ($rates as $key => $rate) {
            if (!$rate instanceof \WC_Shipping_Rate) {
                continue;
            }
            if (!str_starts_with((string) $rate->get_method_id(), 'sutore_marketplace')) {
                continue;
            }

            $rateId = (string) $rate->get_id();
            $isCyprus = str_contains($rateId, ':cyprus');
            $isInternational = str_contains($rateId, ':international');

            if ($country === 'CY') {
                if ($isCyprus) {
                    $sutoreRates[$key] = $rate;
                }
                continue;
            }

            if ($country !== '' && $country !== 'TR') {
                if ($isInternational) {
                    $sutoreRates[$key] = $rate;
                }
                continue;
            }

            if (!$isCyprus && !$isInternational) {
                $sutoreRates[$key] = $rate;
            }
        }

        return $sutoreRates !== [] ? $sutoreRates : [];
    }

    /** @param array<string, array<string, string>> $totalRows */
    public function customizeEmailShippingRow(array $totalRows, \WC_Order $order, string $taxDisplay): array
    {
        if (!is_wc_endpoint_url() || !isset($_GET['key'])) {
            $deadline = (string) get_post_meta($order->get_id(), ShipmentMeta::DEADLINE_LABEL, true);
            if ($deadline === '') {
                $deadline = (string) $order->get_meta(ShipmentMeta::DEADLINE_LABEL, true);
            }
            $totalRows['shipping'] = [
                'label' => __('Shipping:', 'sutore-marketplace'),
                'value' => $deadline,
            ];
        }

        return $totalRows;
    }

    /** @param array<string, array<string, string>> $totalRows */
    public function hideShippingTotalRow(array $totalRows, \WC_Order $order, string $taxDisplay): array
    {
        if ((string) get_post_meta($order->get_id(), ShipmentMeta::TYPE, true) !== 'free') {
            unset($totalRows['shipping']);
        }

        return $totalRows;
    }

    private function resolveShipmentTypeForOrder(): string
    {
        if (!empty($_POST['shipping_method'])) {
            $methods = (array) $_POST['shipping_method'];
            $rateId = (string) reset($methods);
            $type = ChosenShipment::parseShipmentType($rateId);
            if ($type !== '') {
                return $type;
            }
        }

        if (!empty($_POST['shipment'])) {
            return sanitize_key((string) $_POST['shipment']);
        }

        return ChosenShipment::get();
    }
}
