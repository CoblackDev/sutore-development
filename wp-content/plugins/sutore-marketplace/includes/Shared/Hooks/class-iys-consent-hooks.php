<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Sms\IysConsentService;

/**
 * IYS ONAY/RET for marketplace and sibling plugins (login, product page).
 *
 * do_action('sutore_marketplace_marketing_opt_in', int $userId, string $email, string $phone);
 * do_action('sutore_marketplace_marketing_opt_out', int $userId, string $email, string $phone);
 *
 * Pass 0 as $userId for guests (email-only size request). Logged-in / new-user
 * registration must pass the real user id so consent is stored on the profile.
 */
final class IysConsentHooks
{
    public function register(): void
    {
        add_action('sutore_marketplace_marketing_opt_in', [$this, 'onOptIn'], 10, 3);
        add_action('sutore_marketplace_marketing_opt_out', [$this, 'onOptOut'], 10, 3);
    }

    public function onOptIn(int $userId, string $email, string $phone): void
    {
        if ($userId > 0) {
            MerchantMeta::setMarketingConsent($userId, true);
        }
        (new IysConsentService())->grant([$email, $phone]);
    }

    public function onOptOut(int $userId, string $email, string $phone): void
    {
        if ($userId > 0) {
            MerchantMeta::setMarketingConsent($userId, false);
        }
        (new IysConsentService())->revoke([$email, $phone]);
    }
}
