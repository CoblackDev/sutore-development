<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Views;

use SutoreMarketplace\Modules\Contracts\Services\ContractAssembler;
use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;

final class CheckoutContractView
{
    private static bool $dialogRendered = false;

    private static bool $checkboxRendered = false;

    public static function renderDialog(): void
    {
        if (!self::shouldRender() || self::$dialogRendered) {
            return;
        }

        self::$dialogRendered = true;

        $contracts = ContractAssembler::buildFromCart(true);
        $title = ContractSettings::checkboxTitle();

        include SUTORE_MARKETPLACE_PATH . 'templates/checkout-contracts.php';
    }

    public static function renderCheckbox(): void
    {
        if (!self::shouldRender() || self::$checkboxRendered || self::isBlockCheckout()) {
            return;
        }

        self::$checkboxRendered = true;

        $title = ContractSettings::checkboxTitle();
        $field = ContractSettings::CHECKOUT_FIELD;

        include SUTORE_MARKETPLACE_PATH . 'templates/checkout-contracts-checkbox.php';
    }

    /** Dialog + checkbox together — classic checkout fallback. */
    public static function renderBlock(): void
    {
        self::renderDialog();
        self::renderCheckbox();
    }

    public static function isBlockCheckout(): bool
    {
        if (!class_exists(\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class)) {
            return false;
        }

        return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
    }

    public static function shouldRender(): bool
    {
        if (!ContractSettings::enabled()) {
            return false;
        }

        if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url()) {
            return false;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }

        return true;
    }
}
