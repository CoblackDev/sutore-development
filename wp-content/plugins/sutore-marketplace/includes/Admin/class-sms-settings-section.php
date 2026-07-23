<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

use SutoreMarketplace\Shared\Settings\Settings;
use SutoreMarketplace\Shared\Sms\Settings\NetgsmSettings;

final class SmsSettingsSection
{
    /** @param array<string, mixed> $settings */
    public function render(array $settings): void
    {
        echo '<p class="description">' . esc_html__(
            'Shared SMS infrastructure for account OTP verification and order notifications. Order-specific SMS events and templates are configured under Settings → Order Flow.',
            'sutore-marketplace'
        ) . '</p>';

        $this->renderNetgsm($settings);
        $this->renderOtp($settings);
    }

    /** @return array<string, mixed> */
    public function buildSavePatch(): array
    {
        $encoding = strtoupper(sanitize_text_field((string) ($_POST['netgsm_encoding'] ?? 'TR')));

        return [
            'netgsm_usercode' => sanitize_text_field((string) ($_POST['netgsm_usercode'] ?? '')),
            'netgsm_header' => sanitize_text_field((string) ($_POST['netgsm_header'] ?? 'SUTORE')),
            'netgsm_encoding' => in_array($encoding, ['TR', 'EN'], true) ? $encoding : 'TR',
            'netgsm_password' => NetgsmSettings::resolvePasswordForSave(
                sanitize_text_field((string) ($_POST['netgsm_password'] ?? ''))
            ),
            'otp_enabled' => !empty($_POST['otp_enabled']),
            'otp_ttl_seconds' => max(60, (int) ($_POST['otp_ttl_seconds'] ?? 300)),
            'otp_ui_timer_seconds' => max(30, (int) ($_POST['otp_ui_timer_seconds'] ?? 120)),
            'otp_max_attempts' => max(1, (int) ($_POST['otp_max_attempts'] ?? 3)),
            'otp_rate_limit_window_seconds' => max(60, (int) ($_POST['otp_rate_limit_window_seconds'] ?? 900)),
            'otp_code_length' => max(4, min(8, (int) ($_POST['otp_code_length'] ?? 6))),
            'otp_sms_template' => sanitize_textarea_field((string) ($_POST['otp_sms_template'] ?? '')),
            'sms_simulation_mode' => !empty($_POST['sms_simulation_mode']),
        ];
    }

    /** @param array<string, mixed> $settings */
    private function renderNetgsm(array $settings): void
    {
        $configured = NetgsmSettings::isConfigured();

        echo '<h2>' . esc_html__('Netgsm provider', 'sutore-marketplace') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'API credentials for all outbound SMS.',
            'sutore-marketplace'
        ) . '</p>';

        if ($configured) {
            echo '<p class="description"><strong>' . esc_html__('Status:', 'sutore-marketplace') . '</strong> '
                . esc_html__('Configured', 'sutore-marketplace') . '</p>';
        } else {
            echo '<p class="description" style="color:#b45309;"><strong>' . esc_html__('Status:', 'sutore-marketplace') . '</strong> '
                . esc_html__('Not configured — OTP and order SMS will not be sent.', 'sutore-marketplace') . '</p>';
        }

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="netgsm_usercode">' . esc_html__('User code', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input type="text" name="netgsm_usercode" id="netgsm_usercode" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr((string) ($settings['netgsm_usercode'] ?? ''))
        );
        echo '</td></tr>';

        $hasPassword = NetgsmSettings::hasStoredPassword();
        echo '<tr><th scope="row"><label for="netgsm_password">' . esc_html__('Password', 'sutore-marketplace') . '</label></th><td>';
        echo '<input type="password" name="netgsm_password" id="netgsm_password" value="" class="regular-text" autocomplete="new-password" placeholder="'
            . esc_attr($hasPassword ? __('Enter a new value to change', 'sutore-marketplace') : '') . '" />';
        if ($hasPassword) {
            echo '<p class="description">' . esc_html__('A saved password already exists. Leave blank to keep it.', 'sutore-marketplace') . '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="netgsm_header">' . esc_html__('Sender header', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input type="text" name="netgsm_header" id="netgsm_header" value="%s" class="regular-text" maxlength="11" />',
            esc_attr((string) ($settings['netgsm_header'] ?? 'SUTORE'))
        );
        echo '<p class="description">' . esc_html__('Approved Netgsm message header (msgheader).', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';

        $encoding = strtoupper((string) ($settings['netgsm_encoding'] ?? 'TR'));
        echo '<tr><th scope="row"><label for="netgsm_encoding">' . esc_html__('Encoding', 'sutore-marketplace') . '</label></th><td>';
        echo '<select name="netgsm_encoding" id="netgsm_encoding">';
        foreach (['TR' => __('Turkish', 'sutore-marketplace'), 'EN' => __('English', 'sutore-marketplace')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($encoding, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('SMS simulation', 'sutore-marketplace') . '</th><td>';
        echo '<label><input type="checkbox" name="sms_simulation_mode" value="1" ' . checked(!empty($settings['sms_simulation_mode']), true, false) . ' /> ';
        echo esc_html__('Simulate SMS delivery (no Netgsm API call)', 'sutore-marketplace') . '</label>';
        echo '<p class="description">' . esc_html__(
            'For local/staging when Netgsm IP whitelist is not ready. OTP codes are shown in the verification modal instead of being sent by SMS. Order SMS are skipped. Disable before production.',
            'sutore-marketplace'
        ) . '</p>';
        if (!empty($settings['sms_simulation_mode'])) {
            echo '<p class="description" style="color:#b45309;"><strong>' . esc_html__('Warning:', 'sutore-marketplace') . '</strong> '
                . esc_html__('Simulation mode is active.', 'sutore-marketplace') . '</p>';
        }
        echo '</td></tr>';
        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $settings */
    private function renderOtp(array $settings): void
    {
        echo '<h2>' . esc_html__('Account verification (OTP)', 'sutore-marketplace') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'SMS one-time codes for merchant profile save, account details, password change, and account deletion.',
            'sutore-marketplace'
        ) . '</p>';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('OTP enabled', 'sutore-marketplace') . '</th><td>';
        echo '<label><input type="checkbox" name="otp_enabled" value="1" ' . checked(!empty($settings['otp_enabled']), true, false) . ' /> ';
        echo esc_html__('Require SMS verification for sensitive account actions', 'sutore-marketplace') . '</label>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="otp_ttl_seconds">' . esc_html__('Code validity (seconds)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="otp_ttl_seconds" type="number" id="otp_ttl_seconds" value="%s" class="small-text" min="60" step="1" />',
            esc_attr((string) ($settings['otp_ttl_seconds'] ?? 300))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="otp_ui_timer_seconds">' . esc_html__('UI countdown (seconds)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="otp_ui_timer_seconds" type="number" id="otp_ui_timer_seconds" value="%s" class="small-text" min="30" step="1" />',
            esc_attr((string) ($settings['otp_ui_timer_seconds'] ?? 120))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="otp_max_attempts">' . esc_html__('Max failed attempts', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="otp_max_attempts" type="number" id="otp_max_attempts" value="%s" class="small-text" min="1" step="1" />',
            esc_attr((string) ($settings['otp_max_attempts'] ?? 3))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="otp_rate_limit_window_seconds">' . esc_html__('Rate limit window (seconds)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="otp_rate_limit_window_seconds" type="number" id="otp_rate_limit_window_seconds" value="%s" class="small-text" min="60" step="1" />',
            esc_attr((string) ($settings['otp_rate_limit_window_seconds'] ?? 900))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="otp_code_length">' . esc_html__('Code length', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="otp_code_length" type="number" id="otp_code_length" value="%s" class="small-text" min="4" max="8" step="1" />',
            esc_attr((string) ($settings['otp_code_length'] ?? 6))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="otp_sms_template">' . esc_html__('OTP message template', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<textarea name="otp_sms_template" id="otp_sms_template" rows="2" class="large-text" placeholder="%s">%s</textarea>',
            esc_attr(__('Sutore.com verification code: {code}. Do not share this code with anyone.', 'sutore-marketplace')),
            esc_textarea((string) ($settings['otp_sms_template'] ?? ''))
        );
        echo '<p class="description">' . esc_html__('Use {code} as the placeholder. Leave empty for the default message.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
    }
}
