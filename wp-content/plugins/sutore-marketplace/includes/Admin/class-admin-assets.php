<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

final class AdminAssets
{
    public const SCRIPT = 'sutore-marketplace-admin';

    public static function enqueue(): void
    {
        if (!current_user_can(AdminMenu::CAP)) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-admin.js',
            [],
            SUTORE_MARKETPLACE_VERSION,
            true
        );
        wp_localize_script(self::SCRIPT, 'SutoreMarketplaceAdmin', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'error' => __('Error', 'sutore-marketplace'),
                'updated' => __('Updated.', 'sutore-marketplace'),
            ],
        ]);
    }
}
