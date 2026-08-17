<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders;

use SutoreMarketplace\Modules\Orders\Hooks\CronHooks;
use SutoreMarketplace\Modules\Orders\Hooks\CustomerDisplayHooks;
use SutoreMarketplace\Modules\Orders\Hooks\ListingIntegration;
use SutoreMarketplace\Modules\Orders\Hooks\OrderItemPricingMetaHooks;
use SutoreMarketplace\Modules\Orders\Hooks\WooCommerceHooks;
use SutoreMarketplace\Modules\Orders\Rest\FulfillmentsController;
use SutoreMarketplace\Modules\Orders\Rest\StaffOrdersController;
use SutoreMarketplace\Modules\Orders\Settings\Settings;

final class Module
{
    public static function boot(): void
    {
        Settings::ensureDefaults();

        (new WooCommerceHooks())->register();
        (new CronHooks())->register();
        (new CustomerDisplayHooks())->register();
        (new ListingIntegration())->register();
        (new OrderItemPricingMetaHooks())->register();
        (new FulfillmentsController())->register();
        (new StaffOrdersController())->register();
    }

    public static function activate(): void
    {
        Settings::ensureDefaults();
        CronHooks::schedule();
    }

    public static function deactivate(): void
    {
        CronHooks::unschedule();
    }

    public static function registerAssets(): void
    {
        wp_register_style(
            'sutore-marketplace-fulfillment',
            SUTORE_MARKETPLACE_URL . 'assets/fulfillment/fulfillment.css',
            ['sutore-marketplace-core'],
            SUTORE_MARKETPLACE_VERSION
        );
        wp_register_script(
            'sutore-marketplace-fulfillment',
            SUTORE_MARKETPLACE_URL . 'assets/fulfillment/fulfillment-actions.js',
            ['jquery', 'sutore-marketplace-core'],
            SUTORE_MARKETPLACE_VERSION,
            true
        );
    }

    public static function enqueueAssets(): void
    {
        wp_localize_script('sutore-marketplace-fulfillment', 'SutoreMarketplaceFulfillment', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'confirmSale' => __('Confirm Sale', 'sutore-marketplace'),
                'ship' => __('Ship', 'sutore-marketplace'),
                'track' => __('Tracking', 'sutore-marketplace'),
                'details' => __('Detail', 'sutore-marketplace'),
                'shipmentCode' => __('Shipping Tracking No', 'sutore-marketplace'),
                'shipmentHint' => sprintf(
                    __('Deliver your product in a double box to Yurtici Kargo (%2$s) within %1$d hours.', 'sutore-marketplace'),
                    (int) (Settings::cargoDeadlineSecondsForShipmentType('standard') / HOUR_IN_SECONDS),
                    Settings::yurticiCustomerCode()
                ),
                'confirmSaleTitle' => __('Confirm this sale?', 'sutore-marketplace'),
                'confirmSaleBody' => __('After confirming, you must hand the product over for shipping within the specified time.', 'sutore-marketplace'),
                'order' => __('Order', 'sutore-marketplace'),
                'status' => __('Status', 'sutore-marketplace'),
                'price' => __('Price', 'sutore-marketplace'),
                'payout' => __('Net payout', 'sutore-marketplace'),
                'confirmDeadline' => __('Confirmation deadline', 'sutore-marketplace'),
                'cargoDeadline' => __('Shipping deadline', 'sutore-marketplace'),
                'close' => __('Close', 'sutore-marketplace'),
                'submit' => __('Submit', 'sutore-marketplace'),
                'error' => __('Error', 'sutore-marketplace'),
                'yes' => __('Yes', 'sutore-marketplace'),
                'cancel' => __('Cancel', 'sutore-marketplace'),
            ],
        ]);
        wp_enqueue_style('sutore-marketplace-fulfillment');
        wp_enqueue_script('sutore-marketplace-fulfillment');
    }
}
