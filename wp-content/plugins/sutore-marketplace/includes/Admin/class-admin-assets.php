<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

final class AdminAssets
{
    public const SCRIPT = 'sutore-marketplace-admin';

    public static function enqueue(): void
    {
        if (!StaffCapabilities::canManageOps()) {
            return;
        }

        wp_enqueue_style(
            'sutore-marketplace-admin',
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-admin.css',
            [],
            SUTORE_MARKETPLACE_VERSION
        );
        wp_enqueue_script(
            self::SCRIPT,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-admin.js',
            ['jquery', 'sutore-marketplace-core'],
            SUTORE_MARKETPLACE_VERSION,
            true
        );
        wp_localize_script(self::SCRIPT, 'SutoreMarketplaceAdmin', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'error' => __('Error', 'sutore-marketplace'),
                'updated' => __('Updated.', 'sutore-marketplace'),
                'searchNameOrSku' => __('Search by product name or SKU…', 'sutore-marketplace'),
                'noMatchingProducts' => __('No matching products.', 'sutore-marketplace'),
                'selectProductFirst' => __('Select a product first', 'sutore-marketplace'),
                /* translators: %s: product title and SKU */
                'selectedProduct' => __('Selected: %s', 'sutore-marketplace'),
                'remove' => __('Remove', 'sutore-marketplace'),
                'pickProduct' => __('Select a catalog product.', 'sutore-marketplace'),
                'pickVariation' => __('Select a variation.', 'sutore-marketplace'),
            ],
        ]);
    }

    public static function enqueueCampaigns(): void
    {
        self::enqueue();
        wp_enqueue_script(
            'sutore-marketplace-campaigns-admin',
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-campaigns-admin.js',
            [self::SCRIPT, 'jquery', 'sutore-marketplace-core'],
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
            'coverBusy' => __('No eligible products: %d matching product(s) already have a campaign offer or active campaign.', 'sutore-marketplace'),
            /* translators: %d: number of additional products not shown in the sample list */
            'moreTpl' => __('…and %d more.', 'sutore-marketplace'),
            'truncated' => __('Audience scan is capped at 2000 matching products; the real audience may be larger.', 'sutore-marketplace'),
        ]);
    }
}
