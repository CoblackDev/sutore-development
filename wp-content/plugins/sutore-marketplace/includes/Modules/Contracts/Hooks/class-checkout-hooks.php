<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Hooks;

use SutoreMarketplace\Modules\Contracts\Module;
use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;
use SutoreMarketplace\Modules\Contracts\Views\CheckoutContractView;

final class CheckoutHooks
{
    public function register(): void
    {
        add_action('woocommerce_review_order_before_submit', [CheckoutContractView::class, 'renderBlock'], 5);
        add_action('woocommerce_checkout_after_terms_and_conditions', [CheckoutContractView::class, 'renderCheckbox'], 20);
        add_action('woocommerce_after_checkout_form', [CheckoutContractView::class, 'renderBlock'], 5);
        add_action('wp_footer', [CheckoutContractView::class, 'renderDialog'], 5);
        add_action('woocommerce_checkout_process', [$this, 'validateAcceptance']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function validateAcceptance(): void
    {
        if (!ContractSettings::enabled() || CheckoutContractView::isBlockCheckout()) {
            return;
        }

        if (!ContractSettings::isAccepted()) {
            wc_add_notice(
                __('You must read and accept the contracts to continue with your order.', 'sutore-marketplace'),
                'error'
            );
        }
    }

    public function enqueueAssets(): void
    {
        if (!CheckoutContractView::shouldRender()) {
            return;
        }

        Module::enqueueCheckoutAssets();
    }
}
