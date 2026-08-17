<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Hooks;

use SutoreMarketplace\Modules\Contracts\Services\ContractAssembler;
use SutoreMarketplace\Modules\Contracts\Services\OrderSnapshot;
use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;

final class OrderHooks
{
    public function register(): void
    {
        if (!ContractSettings::enabled()) {
            return;
        }

        add_action('woocommerce_checkout_update_order_meta', [$this, 'persistSnapshot'], 20, 2);
    }

    public function persistSnapshot(int $orderId, array $data): void
    {
        unset($data);

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if (!ContractSettings::isAccepted() && !ContractSettings::isOrderAccepted($order)) {
            return;
        }

        $contracts = ContractAssembler::buildFromOrder($order);
        if ($contracts['pre_information'] === '' && $contracts['distance_sales'] === '') {
            $contracts = ContractAssembler::buildFromCart(false);
        }

        OrderSnapshot::save($order, $contracts);
        $order->save();
    }
}
