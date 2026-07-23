<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Services;

use SutoreMarketplace\Modules\Shipping\Settings\ShippingSettings;

final class RateResolver
{
    /**
     * Live destination from Store API / classic checkout for the current request.
     * null = no live address signal (trust package/customer shipping).
     *
     * @var array{country:string,state:string}|null
     */
    private static ?array $capturedLiveDestination = null;

    /**
     * Capture destination from Blocks Store API request body.
     *
     * Shipping address is authoritative for rates; fall back to billing when shipping omitted.
     *
     * @param array<string, mixed>|null $shipping
     * @param array<string, mixed>|null $billing
     */
    public static function captureStoreApiDestination(?array $shipping, ?array $billing): void
    {
        $source = null;
        if (is_array($shipping) && (isset($shipping['country']) || isset($shipping['state']))) {
            $source = $shipping;
        } elseif (is_array($billing) && (isset($billing['country']) || isset($billing['state']))) {
            $source = $billing;
        }

        if ($source === null) {
            return;
        }

        self::$capturedLiveDestination = [
            'country' => strtoupper(sanitize_text_field((string) ($source['country'] ?? ''))),
            'state' => strtoupper(sanitize_text_field((string) ($source['state'] ?? ''))),
        ];
    }

    /**
     * Live checkout destination for this request, if any.
     *
     * Classic POST / Store API only — never invent from customer billing.
     *
     * @return array{country:string,state:string}|null
     */
    public static function liveDestination(): ?array
    {
        if (self::$capturedLiveDestination !== null) {
            return self::$capturedLiveDestination;
        }

        if (
            isset($_POST['country'])
            || isset($_POST['s_country'])
            || isset($_POST['state'])
            || isset($_POST['s_state'])
        ) {
            return [
                'country' => self::countryFromClassicTopLevel(),
                'state' => self::stateFromClassicTopLevel(),
            ];
        }

        if (!empty($_POST['post_data']) && is_string($_POST['post_data'])) {
            $posted = [];
            parse_str(wp_unslash($_POST['post_data']), $posted);
            if (
                isset($posted['billing_country'])
                || isset($posted['shipping_country'])
                || isset($posted['billing_state'])
                || isset($posted['shipping_state'])
            ) {
                return [
                    'country' => self::countryFromPostedForm($posted),
                    'state' => self::stateFromPostedForm($posted),
                ];
            }
        }

        return null;
    }

    /**
     * Resolve checkout/destination country for rate gating (CY vs domestic).
     *
     * Priority: live checkout/Store API → package destination → customer shipping → billing.
     * Shipping address is the WC rate destination; do not prefer stale billing over shipping.
     *
     * @param array<string, mixed>|null $package WooCommerce shipping package (optional).
     */
    public static function destinationCountry(?array $package = null): string
    {
        $live = self::liveDestination();
        if ($live !== null && $live['country'] !== '') {
            return $live['country'];
        }

        if (is_array($package)) {
            $fromPackage = strtoupper(trim((string) ($package['destination']['country'] ?? '')));
            if ($fromPackage !== '') {
                return $fromPackage;
            }
        }

        if (function_exists('WC') && WC()->customer) {
            $shipping = strtoupper(trim((string) WC()->customer->get_shipping_country()));
            if ($shipping !== '') {
                return $shipping;
            }

            return strtoupper(trim((string) WC()->customer->get_billing_country()));
        }

        return '';
    }

    /**
     * Resolve destination state code (e.g. TR34) for fast/express gating.
     *
     * @param array<string, mixed>|null $package
     */
    public static function destinationState(?array $package = null): string
    {
        $live = self::liveDestination();
        if ($live !== null) {
            // Empty state is valid (country change away from TR) — do not fall through to stale TR34.
            return $live['state'];
        }

        if (is_array($package)) {
            $fromPackage = strtoupper(trim((string) ($package['destination']['state'] ?? '')));
            if ($fromPackage !== '') {
                return $fromPackage;
            }
        }

        if (function_exists('WC') && WC()->customer) {
            $shipping = strtoupper(trim((string) WC()->customer->get_shipping_state()));
            if ($shipping !== '') {
                return $shipping;
            }

            return strtoupper(trim((string) WC()->customer->get_billing_state()));
        }

        return '';
    }

    private static function isShippingToDifferentAddress(array $posted = []): bool
    {
        if ($posted !== []) {
            return !empty($posted['ship_to_different_address']);
        }

        if (!empty($_POST['ship_to_different_address'])) {
            return true;
        }

        if (!empty($_POST['post_data']) && is_string($_POST['post_data'])) {
            $parsed = [];
            parse_str(wp_unslash($_POST['post_data']), $parsed);

            return !empty($parsed['ship_to_different_address']);
        }

        return false;
    }

    private static function countryFromClassicTopLevel(): string
    {
        if (self::isShippingToDifferentAddress() && isset($_POST['s_country']) && (string) $_POST['s_country'] !== '') {
            return strtoupper(sanitize_text_field(wp_unslash((string) $_POST['s_country'])));
        }
        if (isset($_POST['country']) && (string) $_POST['country'] !== '') {
            return strtoupper(sanitize_text_field(wp_unslash((string) $_POST['country'])));
        }
        if (isset($_POST['s_country']) && (string) $_POST['s_country'] !== '') {
            return strtoupper(sanitize_text_field(wp_unslash((string) $_POST['s_country'])));
        }

        return '';
    }

    private static function stateFromClassicTopLevel(): string
    {
        if (self::isShippingToDifferentAddress() && isset($_POST['s_state'])) {
            return strtoupper(sanitize_text_field(wp_unslash((string) $_POST['s_state'])));
        }
        if (isset($_POST['state'])) {
            return strtoupper(sanitize_text_field(wp_unslash((string) $_POST['state'])));
        }
        if (isset($_POST['s_state'])) {
            return strtoupper(sanitize_text_field(wp_unslash((string) $_POST['s_state'])));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $posted
     */
    private static function countryFromPostedForm(array $posted): string
    {
        if (self::isShippingToDifferentAddress($posted) && !empty($posted['shipping_country'])) {
            return strtoupper(sanitize_text_field((string) $posted['shipping_country']));
        }
        if (!empty($posted['billing_country'])) {
            return strtoupper(sanitize_text_field((string) $posted['billing_country']));
        }
        if (!empty($posted['shipping_country'])) {
            return strtoupper(sanitize_text_field((string) $posted['shipping_country']));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $posted
     */
    private static function stateFromPostedForm(array $posted): string
    {
        if (self::isShippingToDifferentAddress($posted) && isset($posted['shipping_state'])) {
            return strtoupper(sanitize_text_field((string) $posted['shipping_state']));
        }
        if (isset($posted['billing_state'])) {
            return strtoupper(sanitize_text_field((string) $posted['billing_state']));
        }
        if (isset($posted['shipping_state'])) {
            return strtoupper(sanitize_text_field((string) $posted['shipping_state']));
        }

        return '';
    }

    /**
     * @return list<array{id:string,label:string,cost:float,eta_days:int,method_title:string,element_id:string}>
     */
    public static function checkoutRates(?CartContext $context = null, ?string $destinationCountry = null, ?string $destinationState = null): array
    {
        $context ??= CartContext::fromCart();
        $country = strtoupper(trim((string) ($destinationCountry ?? self::destinationCountry())));
        $state = strtoupper(trim((string) ($destinationState ?? self::destinationState())));
        $isTr34 = $state === 'TR34';
        $fastVisible = ShippingSettings::expressEverywhereEnabled() || $isTr34;
        $rates = [];

        if ($context->hasImportedItems()) {
            $rates[] = self::rate('imported_free', __('Free', 'sutore-marketplace'), 0.0, ShippingSettings::etaDays('imported_free'), __('Free Shipping', 'sutore-marketplace'), 'shipment_free');

            return apply_filters('sutore_marketplace_checkout_shipping_rates', $rates, $context);
        }

        // CY → cyprus only.
        if ($country === 'CY') {
            $rates[] = self::rate(
                'cyprus',
                __('Cyprus', 'sutore-marketplace'),
                ShippingSettings::cyprusFee(),
                ShippingSettings::etaDays('cyprus'),
                __('Cyprus Shipping', 'sutore-marketplace'),
                'shipment_cyprus'
            );

            return apply_filters('sutore_marketplace_checkout_shipping_rates', $rates, $context);
        }

        // Any other non-TR country → international only.
        if ($country !== '' && $country !== 'TR') {
            $rates[] = self::rate(
                'international',
                __('International Shipping', 'sutore-marketplace'),
                ShippingSettings::internationalFee(),
                ShippingSettings::etaDays('international'),
                __('International Shipping', 'sutore-marketplace'),
                'shipment_international'
            );

            return apply_filters('sutore_marketplace_checkout_shipping_rates', $rates, $context);
        }

        // TR (or empty default) → domestic options.
        $rates[] = self::rate('free', __('Free', 'sutore-marketplace'), 0.0, ShippingSettings::etaDays('free'), __('Free Shipping', 'sutore-marketplace'), 'shipment_free');
        if ($fastVisible) {
            if ($context->customerHasCompletedOrder() && ShippingSettings::fastCampaignActive()) {
                $rates[] = self::rate(
                    'fast',
                    sprintf(__('Fast (₺%s, special offer)', 'sutore-marketplace'), number_format_i18n(ShippingSettings::fastCampaignPrice(), 0)),
                    ShippingSettings::fastCampaignPrice(),
                    ShippingSettings::etaDays('fast'),
                    __('Fast Shipping', 'sutore-marketplace'),
                    'shipment_fast_free'
                );
            } elseif ($context->itemCount() < ShippingSettings::freeFastCartThreshold()) {
                $rates[] = self::rate(
                    'fast',
                    __('Fast Shipping', 'sutore-marketplace'),
                    ShippingSettings::fastShippingFee(),
                    ShippingSettings::etaDays('fast'),
                    __('Fast Shipping', 'sutore-marketplace'),
                    'shipment_fast'
                );
            } else {
                $rates[] = self::rate(
                    'fast',
                    __('Fast (free for 4+ items)', 'sutore-marketplace'),
                    0.0,
                    ShippingSettings::etaDays('fast'),
                    __('Fast Shipping', 'sutore-marketplace'),
                    'shipment_fast'
                );
            }
        }

        if ($isTr34 && $context->allItemsExpressEligible()) {
            $rates[] = self::rate(
                'express',
                __('Express Shipping', 'sutore-marketplace'),
                $context->expressFee(),
                ShippingSettings::etaDays('express'),
                __('Express Shipping', 'sutore-marketplace'),
                'shipment_express'
            );
        }

        return apply_filters('sutore_marketplace_checkout_shipping_rates', $rates, $context);
    }

    /** @return array{id:string,label:string,cost:float,eta_days:int,method_title:string,element_id:string} */
    private static function rate(string $id, string $label, float $cost, int $etaDays, string $methodTitle, string $elementId = ''): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'cost' => $cost,
            'eta_days' => $etaDays,
            'method_title' => $methodTitle,
            'element_id' => $elementId,
        ];
    }

    /**
     * @return array{id:string,label:string,cost:float,eta_days:int,method_title:string,element_id:string}
     */
    public static function resolveCheckoutMethod(?string $method = null, ?CartContext $context = null): array
    {
        $context ??= CartContext::fromCart();
        $rates = self::checkoutRates($context);

        if ($rates === []) {
            return self::rate('free', __('Free', 'sutore-marketplace'), 0.0, ShippingSettings::etaDays('free'), __('Free Shipping', 'sutore-marketplace'), 'shipment_free');
        }

        if ($method !== null && $method !== '') {
            foreach ($rates as $rate) {
                if ($rate['id'] === $method) {
                    return $rate;
                }
            }
        }

        return $rates[0];
    }
}
