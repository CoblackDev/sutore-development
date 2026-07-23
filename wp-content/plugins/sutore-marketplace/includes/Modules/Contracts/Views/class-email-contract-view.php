<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Views;

use SutoreMarketplace\Modules\Contracts\Services\OrderSnapshot;

final class EmailContractView
{
    public static function render(\WC_Order $order): void
    {
        $contracts = OrderSnapshot::resolveForEmail($order);
        if ($contracts['pre_information'] === '' && $contracts['distance_sales'] === '') {
            return;
        }

        include SUTORE_MARKETPLACE_PATH . 'templates/email-contracts.php';
    }
}
