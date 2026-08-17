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

    public static function enqueueCampaigns(): void
    {
        self::enqueue();
        wp_enqueue_script(
            'sutore-marketplace-campaigns-admin',
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-campaigns-admin.js',
            [self::SCRIPT],
            SUTORE_MARKETPLACE_VERSION,
            true
        );
        wp_localize_script('sutore-marketplace-campaigns-admin', 'SutoreMpCampaignPreviewI18n', [
            'loading' => __('Counting matching products…', 'sutore-marketplace'),
            'error' => __('Could not refresh audience preview.', 'sutore-marketplace'),
            /* translators: 1: listing/product count, 2: merchant count */
            'coverTpl' => __('This campaign will cover %1$d products (%2$d merchants).', 'sutore-marketplace'),
            'coverZero' => __('No products match the current targeting.', 'sutore-marketplace'),
            /* translators: %d: count of matching listings already in a campaign */
            'coverBusy' => __('No eligible products: %d matching listing(s) already have a campaign offer or active campaign.', 'sutore-marketplace'),
            /* translators: %d: number of additional products not shown in the sample list */
            'moreTpl' => __('…and %d more.', 'sutore-marketplace'),
            'truncated' => __('Audience scan is capped at 2000 matching listings; the real audience may be larger.', 'sutore-marketplace'),
        ]);
    }
}
