<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Hooks;

use SutoreMarketplace\Frontend\Assets;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Otp\Services\OtpPhoneResolver;

final class AccountFormHooks
{
    public function register(): void
    {
        add_action('woocommerce_before_edit_account_form', [$this, 'hideNativeFormOpen'], 5);
        add_action('woocommerce_after_edit_account_form', [$this, 'hideNativeFormClose'], 5);
        add_action('woocommerce_after_edit_account_form', [$this, 'renderAccountSecurity'], 20);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets'], 25);
    }

    public function hideNativeFormOpen(): void
    {
        echo '<div class="sutore-mp-wc-account-form-hidden">';
    }

    public function hideNativeFormClose(): void
    {
        echo '</div>';
    }

    public function renderAccountSecurity(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        $user = wp_get_current_user();
        $userId = (int) $user->ID;
        $phone = OtpPhoneResolver::forUser($userId);
        $marketingConsent = MerchantMeta::marketingConsent($userId) ? '1' : '0';

        $template = SUTORE_MARKETPLACE_PATH . 'templates/account-security.php';
        if (!is_readable($template)) {
            return;
        }

        include $template;
    }

    public function enqueueAssets(): void
    {
        if (!is_account_page() || !is_wc_endpoint_url('edit-account') || !is_user_logged_in()) {
            return;
        }

        (new Assets())->enqueueAccountSecurity();
    }
}
