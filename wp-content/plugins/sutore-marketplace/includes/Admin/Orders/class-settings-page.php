<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin\Orders;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Orders\Hooks\CronHooks;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Orders\Settings\SmsTemplates;

final class SettingsPage
{
    /** @return array<string, string> */
    public function subTabs(): array
    {
        return [
            'deadlines' => __('Durations', 'sutore-marketplace'),
            'notifications' => __('Notifications', 'sutore-marketplace'),
            'cargo' => __('Shipping', 'sutore-marketplace'),
            'operations' => __('Operations', 'sutore-marketplace'),
            'templates' => __('SMS templates', 'sutore-marketplace'),
            'advanced' => __('Advanced', 'sutore-marketplace'),
        ];
    }

    public function renderSubNav(string $activeSubTab, string $baseUrl): void
    {
        echo '<h3 class="nav-tab-wrapper wp-clearfix" style="margin-top:1rem">';
        foreach ($this->subTabs() as $key => $label) {
            $url = add_query_arg('orders_tab', $key, $baseUrl);
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($url),
                $activeSubTab === $key ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</h3>';
    }

    public function renderFields(string $subTab): void
    {
        Settings::ensureDefaults();
        $s = Settings::all();

        match ($subTab) {
            'notifications' => $this->tabNotifications($s),
            'cargo' => $this->tabCargo($s),
            'operations' => $this->tabOperations($s),
            'templates' => $this->tabTemplates($s),
            'advanced' => $this->tabAdvanced($s),
            default => $this->tabDeadlines($s),
        };

        echo '<input type="hidden" name="orders_tab" value="' . esc_attr($subTab) . '" />';
    }

    public function save(string $subTab): void
    {
        $patch = [];
        $beforeSchedule = Settings::deadlineCronSchedule();

        if ($subTab === 'deadlines') {
            foreach ([
                'confirm_deadline_hours', 'confirm_deadline_hours_verified', 'confirm_deadline_hours_premium',
                'confirm_grace_hours', 'cargo_deadline_hours_standard', 'cargo_deadline_hours_express',
                'cargo_deadline_hours_international', 'cargo_reminder_hours', 'return_window_days',
            ] as $key) {
                $patch[$key] = max(0, (int) ($_POST[$key] ?? Settings::get($key)));
            }
            $patch['use_business_days_for_deadlines'] = !empty($_POST['use_business_days_for_deadlines']);
        } elseif ($subTab === 'notifications') {
            $patch['admin_notification_phones'] = sanitize_textarea_field((string) ($_POST['admin_notification_phones'] ?? ''));
            $patch['express_notification_phone'] = sanitize_text_field((string) ($_POST['express_notification_phone'] ?? ''));
            $patch['sms_enabled'] = !empty($_POST['sms_enabled']);
            $events = [];
            foreach (array_keys(SmsTemplates::defaultEventFlags()) as $key) {
                $events[$key] = !empty($_POST['sms_events'][$key]);
            }
            $patch['sms_events'] = $events;
            $patch['merchant_notifications_enabled'] = !empty($_POST['merchant_notifications_enabled']);
            $merchantEvents = [];
            foreach (array_keys(NotificationType::defaultEventFlags()) as $key) {
                $merchantEvents[$key] = !empty($_POST['merchant_notification_events'][$key]);
            }
            $patch['merchant_notification_events'] = $merchantEvents;
        } elseif ($subTab === 'cargo') {
            $patch['yurtici_customer_code'] = sanitize_text_field((string) ($_POST['yurtici_customer_code'] ?? ''));
            $patch['shipment_code_pattern'] = sanitize_text_field((string) ($_POST['shipment_code_pattern'] ?? ''));
            $patch['express_block_carrier_shipment'] = !empty($_POST['express_block_carrier_shipment']);
            $patch['international_invoice_required'] = !empty($_POST['international_invoice_required']);
        } elseif ($subTab === 'operations') {
            $patch['require_admin_payment_confirm'] = !empty($_POST['require_admin_payment_confirm']);
            $patch['auto_sourcing_on_suspend'] = !empty($_POST['auto_sourcing_on_suspend']);
            $patch['auto_sourcing_on_split'] = !empty($_POST['auto_sourcing_on_split']);
            $patch['sourcing_price_tolerance_percent'] = max(0, (float) ($_POST['sourcing_price_tolerance_percent'] ?? 10));
            $patch['default_fulfillment_filter'] = sanitize_key((string) ($_POST['default_fulfillment_filter'] ?? 'payment'));
            $patch['swap_allowed_statuses'] = sanitize_text_field((string) ($_POST['swap_allowed_statuses'] ?? ''));
            $patch['allow_manual_order_link'] = !empty($_POST['allow_manual_order_link']);
        } elseif ($subTab === 'templates' && !empty($_POST['sms_templates']) && is_array($_POST['sms_templates'])) {
            $tpl = [];
            foreach (SmsTemplates::defaultTemplates() as $key => $default) {
                $tpl[$key] = sanitize_textarea_field((string) ($_POST['sms_templates'][$key] ?? $default));
            }
            $patch['sms_templates'] = $tpl;
        } elseif ($subTab === 'advanced') {
            $patch['deadline_cron_schedule'] = sanitize_key((string) ($_POST['deadline_cron_schedule'] ?? 'twicedaily'));
            $patch['suspension_threshold'] = max(0, (int) ($_POST['suspension_threshold'] ?? 0));
            $patch['webhook_url'] = esc_url_raw((string) ($_POST['webhook_url'] ?? ''));
            $secret = sanitize_text_field((string) ($_POST['webhook_secret'] ?? ''));
            $patch['webhook_secret'] = \SutoreMarketplace\Shared\Security\SecretBox::resolveForSave(
                $secret,
                (string) Settings::get('webhook_secret', '')
            );
            $patch['event_retention_days'] = max(1, (int) ($_POST['event_retention_days'] ?? 365));
        }

        if ($patch === []) {
            return;
        }

        Settings::update($patch);

        if ($subTab === 'advanced' && Settings::deadlineCronSchedule() !== $beforeSchedule) {
            CronHooks::reschedule();
        }
    }

    private function tabDeadlines(array $s): void
    {
        echo '<table class="form-table"><tbody>';
        $this->numberRow('confirm_deadline_hours', __('Merchant confirmation time (hours)', 'sutore-marketplace'), $s);
        $this->numberRow('confirm_deadline_hours_verified', __('Confirmed merchant confirmation time (0=default)', 'sutore-marketplace'), $s);
        $this->numberRow('confirm_deadline_hours_premium', __('Premium merchant confirmation time (0=default)', 'sutore-marketplace'), $s);
        $this->numberRow('confirm_grace_hours', __('Confirmation grace / before penalty (hours)', 'sutore-marketplace'), $s);
        $this->numberRow('cargo_deadline_hours_standard', __('Shipping time — standard (hours)', 'sutore-marketplace'), $s);
        $this->numberRow('cargo_deadline_hours_express', __('Shipping time — express (hours)', 'sutore-marketplace'), $s);
        $this->numberRow('cargo_deadline_hours_international', __('Shipping time — international (hours)', 'sutore-marketplace'), $s);
        $this->numberRow('cargo_reminder_hours', __('Shipping reminder — before deadline (hours)', 'sutore-marketplace'), $s);
        $this->numberRow('return_window_days', __('Return / dispute window after delivery (days)', 'sutore-marketplace'), $s);
        $this->checkboxRow('use_business_days_for_deadlines', __('Business day calculation (weekends excluded)', 'sutore-marketplace'), $s);
        echo '</tbody></table>';
    }

    private function tabNotifications(array $s): void
    {
        echo '<table class="form-table"><tbody>';
        $this->textareaRow('admin_notification_phones', __('Admin notification numbers', 'sutore-marketplace'), $s);
        $this->textRow('express_notification_phone', __('Express notification number', 'sutore-marketplace'), $s);
        $this->checkboxRow('sms_enabled', __('SMS enabled', 'sutore-marketplace'), $s);
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__(
            'Netgsm credentials and OTP settings are under Marketplace Settings → SMS.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<h2>' . esc_html__('SMS events', 'sutore-marketplace') . '</h2><table class="form-table"><tbody>';
        $events = (array) ($s['sms_events'] ?? []);
        foreach (SmsTemplates::defaultEventFlags() as $key => $default) {
            $label = SmsTemplates::eventLabel($key);
            $checked = !empty($events[$key]);
            echo '<tr><th>' . esc_html($label) . '</th><td>';
            echo '<input type="checkbox" name="sms_events[' . esc_attr($key) . ']" value="1" ' . checked($checked, true, false) . ' /></td></tr>';
        }
        echo '</tbody></table>';
        $this->renderMerchantNotificationSettings($s);
    }

    private function renderMerchantNotificationSettings(array $s): void
    {
        echo '<h2>' . esc_html__('Merchant panel notifications', 'sutore-marketplace') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'In-app notifications merchants see on My Account → Notifications. Disabled events are not recorded.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('Panel notifications', 'sutore-marketplace') . '</th><td>';
        echo '<label><input type="checkbox" name="merchant_notifications_enabled" value="1" '
            . checked(!empty($s['merchant_notifications_enabled']), true, false) . ' /> '
            . esc_html__('Active', 'sutore-marketplace') . '</label></td></tr>';
        echo '</tbody></table>';

        $events = (array) ($s['merchant_notification_events'] ?? NotificationType::defaultEventFlags());
        foreach (NotificationType::typesByCategory() as $category => $types) {
            $categoryLabel = NotificationType::categoryLabels()[$category] ?? $category;
            echo '<h3>' . esc_html($categoryLabel) . '</h3>';
            echo '<table class="form-table"><tbody>';
            foreach ($types as $type) {
                $label = NotificationType::eventLabel($type);
                $checked = !empty($events[$type]);
                echo '<tr><th>' . esc_html($label) . '</th><td>';
                echo '<input type="checkbox" name="merchant_notification_events[' . esc_attr($type) . ']" value="1" '
                    . checked($checked, true, false) . ' /></td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    private function tabCargo(array $s): void
    {
        echo '<table class="form-table"><tbody>';
        $this->textRow('yurtici_customer_code', __('Domestic customer code', 'sutore-marketplace'), $s);
        $this->textRow('shipment_code_pattern', __('Shipping tracking regex', 'sutore-marketplace'), $s);
        $this->checkboxRow('express_block_carrier_shipment', __('Express for-sale shipping ban warning', 'sutore-marketplace'), $s);
        $this->checkboxRow('international_invoice_required', __('Invoice warning for international sales', 'sutore-marketplace'), $s);
        echo '</tbody></table>';
    }

    private function tabOperations(array $s): void
    {
        echo '<table class="form-table"><tbody>';
        $this->checkboxRow('require_admin_payment_confirm', __('Admin approval required after payment', 'sutore-marketplace'), $s);
            $this->checkboxRow('auto_sourcing_on_suspend', __('Automatically open pre-order when taken off sale', 'sutore-marketplace'), $s);
        $this->checkboxRow('auto_sourcing_on_split', __('Open automatic pre-order when unlinked from order', 'sutore-marketplace'), $s);
        $this->numberRow('sourcing_price_tolerance_percent', __('Alternative seller price tolerance (%)', 'sutore-marketplace'), $s);
        $this->textRow('default_fulfillment_filter', __('Staff list default filter', 'sutore-marketplace'), $s);
        $this->textRow('swap_allowed_statuses', __('Swap-allowed statuses (comma-separated)', 'sutore-marketplace'), $s);
        $this->checkboxRow('allow_manual_order_link', __('Manual order linking', 'sutore-marketplace'), $s);
        echo '</tbody></table>';
    }

    private function tabTemplates(array $s): void
    {
        echo '<p class="description">' . esc_html__('Placeholder:', 'sutore-marketplace') . ' '
            . esc_html(implode(', ', SmsTemplates::placeholders())) . '</p>';
        echo '<table class="form-table"><tbody>';
        foreach (SmsTemplates::templateKeys() as $key) {
            $val = SmsTemplates::effectiveTemplate($key);
            echo '<tr><th><label for="tpl_' . esc_attr($key) . '">' . esc_html(SmsTemplates::eventLabel($key)) . '</label></th>';
            echo '<td><textarea name="sms_templates[' . esc_attr($key) . ']" id="tpl_' . esc_attr($key) . '" rows="2" class="large-text">'
                . esc_textarea($val) . '</textarea></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function tabAdvanced(array $s): void
    {
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('Cron frequency', 'sutore-marketplace') . '</th><td><select name="deadline_cron_schedule">';
        foreach ([
            'hourly' => __('Hourly', 'sutore-marketplace'),
            'twicedaily' => __('Twice daily', 'sutore-marketplace'),
            'daily' => __('Daily', 'sutore-marketplace'),
        ] as $k => $label) {
            echo '<option value="' . esc_attr($k) . '" ' . selected($s['deadline_cron_schedule'] ?? 'twicedaily', $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        $this->numberRow('suspension_threshold', __('Suspension threshold (0=off)', 'sutore-marketplace'), $s);
        $this->textRow('webhook_url', __('Webhook URL', 'sutore-marketplace'), $s);
        $this->passwordRow('webhook_secret', __('Webhook secret', 'sutore-marketplace'), $s);
        $this->numberRow('event_retention_days', __('Event retention (days)', 'sutore-marketplace'), $s);
        echo '</tbody></table>';
    }

    private function numberRow(string $name, string $label, array $s): void
    {
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input type="number" min="0" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="'
            . esc_attr((string) ($s[$name] ?? '')) . '" class="small-text" /></td></tr>';
    }

    private function textRow(string $name, string $label, array $s): void
    {
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input type="text" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="'
            . esc_attr((string) ($s[$name] ?? '')) . '" class="regular-text" /></td></tr>';
    }

    private function passwordRow(string $name, string $label, array $s): void
    {
        $hasValue = trim((string) ($s[$name] ?? '')) !== '';
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input type="password" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="" class="regular-text" autocomplete="new-password" placeholder="'
            . esc_attr($hasValue ? __('Enter a new value to change', 'sutore-marketplace') : '') . '" />';
        if ($hasValue) {
            echo '<p class="description">' . esc_html__('A saved secret already exists. Leave blank to keep it.', 'sutore-marketplace') . '</p>';
        }
        echo '</td></tr>';
    }

    private function textareaRow(string $name, string $label, array $s): void
    {
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td><textarea name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" rows="3" class="large-text">'
            . esc_textarea((string) ($s[$name] ?? '')) . '</textarea></td></tr>';
    }

    private function checkboxRow(string $name, string $label, array $s): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" '
            . checked(!empty($s[$name]), true, false) . ' /> ' . esc_html__('Active', 'sutore-marketplace') . '</label></td></tr>';
    }
}
