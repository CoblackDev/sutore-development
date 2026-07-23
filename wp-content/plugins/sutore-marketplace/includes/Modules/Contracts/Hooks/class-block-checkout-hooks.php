<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Hooks;

use SutoreMarketplace\Modules\Contracts\Services\ContractAssembler;
use SutoreMarketplace\Modules\Contracts\Services\OrderSnapshot;
use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;
use SutoreMarketplace\Modules\Contracts\Views\CheckoutContractView;

final class BlockCheckoutHooks
{
    public function register(): void
    {
        add_action('woocommerce_blocks_loaded', [$this, 'registerCheckoutField']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'persistSnapshot'], 20);
    }

    public function registerCheckoutField(): void
    {
        if (!ContractSettings::enabled() || !function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id' => ContractSettings::BLOCK_FIELD_ID,
            'label' => __('I have read and accept the sutore Contracts page.', 'sutore-marketplace'),
            'location' => 'order',
            'type' => 'checkbox',
            'required' => true,
            'validate_callback' => static function ($value): bool|\WP_Error {
                if (empty($value)) {
                    return new \WP_Error(
                        'sutore_marketplace_contracts_required',
                        __('You must read and accept the contracts to continue with your order.', 'sutore-marketplace')
                    );
                }

                return true;
            },
        ]);
    }

    public function persistSnapshot(\WC_Order $order): void
    {
        if (!ContractSettings::enabled()) {
            return;
        }

        if (!ContractSettings::isOrderAccepted($order)) {
            return;
        }

        $contracts = ContractAssembler::buildFromOrder($order);
        OrderSnapshot::save($order, $contracts);
        $order->save();
    }
}
