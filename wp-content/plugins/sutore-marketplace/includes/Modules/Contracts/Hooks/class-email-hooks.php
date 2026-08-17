<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Hooks;

use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;
use SutoreMarketplace\Modules\Contracts\Views\EmailContractView;

final class EmailHooks
{
    public function register(): void
    {
        if (!ContractSettings::enabled()) {
            return;
        }

        add_action('woocommerce_email_customer_details', [$this, 'renderContracts'], 15, 4);
    }

    public function renderContracts(\WC_Order $order, bool $sentToAdmin, bool $plainText, $email): void
    {
        unset($sentToAdmin, $email);

        if ($plainText) {
            return;
        }

        EmailContractView::render($order);
    }
}
