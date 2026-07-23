<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

final class ImportedProductsPage
{
    private const PAGE_SLUG = 'sutore-marketplace-imported';
    private const SCRIPT_HANDLE = 'sutore-marketplace-imported-products';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        unset($hookSuffix);

        if (
            !current_user_can(AdminMenu::CAP)
            || sanitize_key((string) ($_GET['page'] ?? '')) !== self::PAGE_SLUG
        ) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-imported-products.js',
            [],
            SUTORE_MARKETPLACE_VERSION,
            true
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'SutoreMarketplaceImportedProducts', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/admin/imported-products')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'working' => __('Saving…', 'sutore-marketplace'),
                'markImported' => __('Mark as imported', 'sutore-marketplace'),
                'requestFailed' => __('The imported products could not be updated.', 'sutore-marketplace'),
            ],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can(AdminMenu::CAP)) {
            return;
        }

        echo '<div class="wrap sutore-mp-imported-products">';
        echo '<h1>' . esc_html__('Imported products', 'sutore-marketplace') . '</h1>';
        echo '<p class="description">' . esc_html__(
            'Mark WooCommerce variations as imported products. Imported products receive the configured free-shipping delivery time and always require a national ID at checkout.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<form class="sutore-mp-imported-products__form" action="#">';
        echo '<table class="form-table" role="presentation"><tbody><tr>';
        echo '<th scope="row"><label for="sutore_mp_imported_variation_ids">'
            . esc_html__('Variation IDs', 'sutore-marketplace') . '</label></th>';
        echo '<td><textarea id="sutore_mp_imported_variation_ids" rows="8" class="large-text code" placeholder="12345&#10;67890&#10;112233"></textarea>';
        echo '<p class="description">' . esc_html__(
            'Enter one WooCommerce variation ID per line. Parent and simple product IDs are rejected.',
            'sutore-marketplace'
        ) . '</p></td>';
        echo '</tr></tbody></table>';
        submit_button(__('Mark as imported', 'sutore-marketplace'), 'primary', 'submit', false);
        echo '<div class="sutore-mp-imported-products__result" aria-live="polite"></div>';
        echo '</form></div>';
    }
}
