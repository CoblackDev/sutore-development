<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts;

use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;
use SutoreMarketplace\Modules\Contracts\Views\CheckoutContractView;

final class Module
{
    public static function boot(): void
    {
        (new Hooks\CheckoutHooks())->register();
        (new Hooks\BlockCheckoutHooks())->register();
        (new Hooks\OrderHooks())->register();
        (new Hooks\EmailHooks())->register();
    }

    public static function registerCheckoutAssets(): void
    {
        wp_register_style(
            'sutore-marketplace-contracts',
            SUTORE_MARKETPLACE_URL . 'assets/css/contracts-checkout.css',
            [],
            SUTORE_MARKETPLACE_VERSION
        );

        wp_register_script(
            'sutore-marketplace-contracts',
            SUTORE_MARKETPLACE_URL . 'assets/js/contracts-checkout.js',
            ['jquery'],
            SUTORE_MARKETPLACE_VERSION,
            true
        );
    }

    public static function enqueueCheckoutAssets(): void
    {
        if (!CheckoutContractView::shouldRender()) {
            return;
        }

        self::registerCheckoutAssets();

        wp_localize_script('sutore-marketplace-contracts', 'SutoreMarketplaceContracts', [
            'blockCheckout' => CheckoutContractView::isBlockCheckout(),
            'blockFieldId' => ContractSettings::BLOCK_FIELD_ID,
            'contractsTitle' => ContractSettings::checkboxTitle(),
        ]);

        wp_enqueue_style('sutore-marketplace-contracts');
        wp_enqueue_script('sutore-marketplace-contracts');
    }
}
