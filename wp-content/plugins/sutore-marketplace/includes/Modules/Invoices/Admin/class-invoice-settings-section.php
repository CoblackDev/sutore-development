<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Admin;

use SutoreMarketplace\Modules\Invoices\Settings\InvoiceSettings;

final class InvoiceSettingsSection
{
    /** @param array<string, mixed> $settings */
    public function render(array $settings): void
    {
        $invoices = is_array($settings['invoices'] ?? null)
            ? array_merge(InvoiceSettings::defaults(), $settings['invoices'])
            : InvoiceSettings::defaults();

        echo '<p class="description">' . esc_html__(
            'Issues Paraşüt e-Archive sales invoices (not credit or return invoices). The customer invoice is created once every remaining item on the order is verified or removed: Hizmet Bedeli and Güvence Bedeli lines per product. Seller commission (Komisyon Bedeli) is issued when payout is marked paid. Failures are queued and retried; they do not block the sale or payout.',
            'sutore-marketplace'
        ) . '</p>';

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Enable invoicing', 'sutore-marketplace') . '</th><td>';
        echo '<label><input type="checkbox" name="invoices_enabled" value="1" ' . checked(!empty($invoices['enabled']), true, false) . ' /> ';
        echo esc_html__('Create e-Archive invoices through Paraşüt', 'sutore-marketplace') . '</label>';
        if (InvoiceSettings::enabled()) {
            echo '<p class="description"><strong>' . esc_html__('Status:', 'sutore-marketplace') . '</strong> '
                . esc_html__('Configured and enabled', 'sutore-marketplace') . '</p>';
        } elseif (InvoiceSettings::isConfigured()) {
            echo '<p class="description">' . esc_html__('Credentials are saved. Enable invoicing to start issuing documents.', 'sutore-marketplace') . '</p>';
        } else {
            echo '<p class="description" style="color:#b45309;"><strong>' . esc_html__('Status:', 'sutore-marketplace') . '</strong> '
                . esc_html__('Not configured — invoices will not be issued.', 'sutore-marketplace') . '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="invoices_company_id">' . esc_html__('Company ID', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input type="text" name="invoices_company_id" id="invoices_company_id" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr((string) ($invoices['company_id'] ?? ''))
        );
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="invoices_client_id">' . esc_html__('Client ID', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input type="text" name="invoices_client_id" id="invoices_client_id" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr((string) ($invoices['client_id'] ?? ''))
        );
        echo '</td></tr>';

        $hasSecret = InvoiceSettings::hasStoredSecret('client_secret');
        echo '<tr><th scope="row"><label for="invoices_client_secret">' . esc_html__('Client secret', 'sutore-marketplace') . '</label></th><td>';
        echo '<input type="password" name="invoices_client_secret" id="invoices_client_secret" value="" class="regular-text" autocomplete="new-password" placeholder="'
            . esc_attr($hasSecret ? __('Enter a new value to change', 'sutore-marketplace') : '') . '" />';
        if ($hasSecret) {
            echo '<p class="description">' . esc_html__('A saved secret already exists. Leave blank to keep it.', 'sutore-marketplace') . '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="invoices_username">' . esc_html__('Username', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input type="text" name="invoices_username" id="invoices_username" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr((string) ($invoices['username'] ?? ''))
        );
        echo '</td></tr>';

        $hasPassword = InvoiceSettings::hasStoredSecret('password');
        echo '<tr><th scope="row"><label for="invoices_password">' . esc_html__('Password', 'sutore-marketplace') . '</label></th><td>';
        echo '<input type="password" name="invoices_password" id="invoices_password" value="" class="regular-text" autocomplete="new-password" placeholder="'
            . esc_attr($hasPassword ? __('Enter a new value to change', 'sutore-marketplace') : '') . '" />';
        if ($hasPassword) {
            echo '<p class="description">' . esc_html__('A saved password already exists. Leave blank to keep it.', 'sutore-marketplace') . '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="invoices_vat_rate">' . esc_html__('VAT rate (%)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="invoices_vat_rate" type="number" id="invoices_vat_rate" value="%s" class="small-text" min="0" max="100" step="0.01" />',
            esc_attr((string) ($invoices['vat_rate'] ?? 20))
        );
        echo '<p class="description">' . esc_html__('Stored fees are treated as VAT-inclusive. Line unit prices are sent net of this rate.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="invoices_shop_url">' . esc_html__('Shop URL (internet sale)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input type="url" name="invoices_shop_url" id="invoices_shop_url" value="%s" class="regular-text" />',
            esc_attr((string) ($invoices['shop_url'] ?? ''))
        );
        echo '<p class="description">' . esc_html__('Used on the e-Archive internet sale record. Leave blank to use the site home URL.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
    }

    /** @return array<string, mixed> */
    public function buildSavePatch(): array
    {
        return [
            'invoices' => InvoiceSettings::sanitizeFromInput($_POST),
        ];
    }
}
