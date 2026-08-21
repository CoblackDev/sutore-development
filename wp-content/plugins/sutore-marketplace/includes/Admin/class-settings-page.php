<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

use SutoreMarketplace\Admin\Orders\SettingsPage as FulfillmentSettingsPage;
use SutoreMarketplace\Modules\Invoices\Admin\InvoiceSettingsSection;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Settings\Settings;

final class SettingsPage
{
    private ?FulfillmentSettingsPage $fulfillmentSettings = null;
    private ?SmsSettingsSection $smsSettings = null;
    private ?InvoiceSettingsSection $invoiceSettings = null;
    private ?BehaviorSettingsSection $behaviorSettings = null;

    public function render(): void
    {
        if (!StaffCapabilities::canManageSettings()) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Settings saved.', 'sutore-marketplace')
                . '</p></div>';
        }

        settings_errors('sutore_marketplace');

        $s = Settings::all();
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'pricing'));
        $tabs = [
            'pricing' => __('Pricing', 'sutore-marketplace'),
            'listing' => __('Products', 'sutore-marketplace'),
            'behavior' => __('Behavior', 'sutore-marketplace'),
            'operations' => __('Operations', 'sutore-marketplace'),
            'sms' => __('SMS', 'sutore-marketplace'),
            'invoices' => __('Invoices', 'sutore-marketplace'),
            'orders' => __('Order Flow', 'sutore-marketplace'),
            'shipping' => __('Shipping', 'sutore-marketplace'),
            'coupons' => __('Coupons', 'sutore-marketplace'),
            'contracts' => __('Contracts', 'sutore-marketplace'),
            'campaigns' => __('Campaigns', 'sutore-marketplace'),
        ];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Sutore Marketplace Settings', 'sutore-marketplace') . '</h1>';
        echo '<h2 class="nav-tab-wrapper wp-clearfix">';
        foreach ($tabs as $key => $label) {
            $url = admin_url('admin.php?page=sutore-marketplace-settings&tab=' . $key);
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($url),
                $tab === $key ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</h2>';

        $group = $tab === 'orders' ? SettingsApi::GROUP_ORDERS : SettingsApi::GROUP_GENERAL;
        $optionKey = $tab === 'orders'
            ? \SutoreMarketplace\Modules\Orders\Settings\Settings::OPTION
            : Settings::OPTION_KEY;

        echo '<form method="post" action="options.php">';
        settings_fields($group);
        printf(
            '<input type="hidden" name="%s[__tab]" value="%s" />',
            esc_attr($optionKey),
            esc_attr($tab)
        );
        echo '<input type="hidden" name="settings_tab" value="' . esc_attr($tab) . '" />';

        if ($tab === 'listing') {
            $this->listingFields($s);
        } elseif ($tab === 'behavior') {
            $this->behaviorSettingsSection()->render($s);
        } elseif ($tab === 'operations') {
            $this->operationsFields($s);
        } elseif ($tab === 'sms') {
            $this->smsSettingsSection()->render($s);
        } elseif ($tab === 'invoices') {
            $this->invoiceSettingsSection()->render($s);
        } elseif ($tab === 'orders') {
            $this->ordersFields();
        } elseif ($tab === 'shipping') {
            $this->shippingFields($s);
        } elseif ($tab === 'coupons') {
            $this->couponBehaviorFields($s);
        } elseif ($tab === 'contracts') {
            $this->contractsFields($s);
        } elseif ($tab === 'campaigns') {
            $this->campaignFields($s);
        } else {
            $this->pricingFields($s);
        }

        submit_button(__('Save changes', 'sutore-marketplace'));
        echo '</form></div>';
    }

    private function ordersFields(): void
    {
        $fulfillment = $this->fulfillmentSettings();
        $subTabs = $fulfillment->subTabs();
        $ordersTab = sanitize_key((string) ($_GET['orders_tab'] ?? 'deadlines'));
        if (!isset($subTabs[$ordersTab])) {
            $ordersTab = 'deadlines';
        }

        $baseUrl = admin_url('admin.php?page=sutore-marketplace-settings&tab=orders');
        $fulfillment->renderSubNav($ordersTab, $baseUrl);
        $fulfillment->renderFields($ordersTab);
    }

    private function pricingFields(array $s): void
    {
        echo '<table class="form-table" role="presentation"><tbody>';

        $fields = [
            'listing_price_step' => [__('Price step (TL)', 'sutore-marketplace'), '1', 'small-text'],
            'usd_try_exchange_rate' => [__('USD → TRY rate', 'sutore-marketplace'), '0.01', 'regular-text'],
            'service_fee_amount' => [__('Service fee', 'sutore-marketplace'), '1', 'regular-text'],
            'assurance_fee_percent' => [__('Guarantee fee (%)', 'sutore-marketplace'), '0.1', 'regular-text'],
        ];

        foreach ($fields as $name => [$label, $step, $class]) {
            echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
            printf(
                '<input name="%1$s" type="number" id="%1$s" value="%2$s" class="%3$s" min="0" step="%4$s" />',
                esc_attr($name),
                esc_attr((string) $s[$name]),
                esc_attr($class),
                esc_attr($step)
            );
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private function listingFields(array $s): void
    {
        echo '<table class="form-table" role="presentation"><tbody>';
        $defaultDuration = (int) ($s['listing_expire_duration_days'] ?? 45);
        $maxByLevel = is_array($s['listing_duration_max_by_level'] ?? null)
            ? $s['listing_duration_max_by_level']
            : Settings::defaults()['listing_duration_max_by_level'];
        echo '<tr><th scope="row"><label for="listing_expire_duration_days">' . esc_html__('Default sale duration (days)', 'sutore-marketplace') . '</label></th><td>';
        echo '<select name="listing_expire_duration_days" id="listing_expire_duration_days">';
        foreach (\SutoreMarketplace\Modules\Listings\Domain\ListingDuration::ALLOWED_DAYS as $days) {
            printf(
                '<option value="%1$d" %2$s>%3$s</option>',
                $days,
                selected($defaultDuration, $days, false),
                esc_html(\SutoreMarketplace\Modules\Listings\Domain\ListingDuration::optionLabel($days))
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Pre-selected duration in the product form when sellers create a product.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Maximum duration by seller level', 'sutore-marketplace') . '</th><td>';
        foreach (
            [
                MerchantLevels::NORMAL,
                MerchantLevels::VERIFIED,
                MerchantLevels::PREMIUM,
            ] as $level
        ) {
            $label = MerchantLevels::labelForStatus($level);
            $value = (int) ($maxByLevel[$level] ?? Settings::defaults()['listing_duration_max_by_level'][$level]);
            echo '<p><label for="listing_duration_max_' . esc_attr($level) . '">' . esc_html($label) . '</label> ';
            echo '<select name="listing_duration_max_by_level[' . esc_attr($level) . ']" id="listing_duration_max_' . esc_attr($level) . '">';
            foreach (\SutoreMarketplace\Modules\Listings\Domain\ListingDuration::ALLOWED_DAYS as $days) {
                printf(
                    '<option value="%1$d" %2$s>%3$s</option>',
                    $days,
                    selected($value, $days, false),
                    esc_html(\SutoreMarketplace\Modules\Listings\Domain\ListingDuration::optionLabel($days))
                );
            }
            echo '</select></p>';
        }
        echo '<p class="description">' . esc_html__('Caps which duration options each seller level can choose (2 / 7 / 30 / 45 / 60 days).', 'sutore-marketplace') . '</p>';
        echo '</td></tr></tbody></table>';
    }

    private function operationsFields(array $s): void
    {
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="cart_max_quantity">' . esc_html__('Cart max quantity', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="cart_max_quantity" type="number" id="cart_max_quantity" value="%s" class="small-text" min="1" step="1" />',
            esc_attr((string) ($s['cart_max_quantity'] ?? 8))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="checkout_tckno_cart_total_threshold">' . esc_html__('National ID cart total threshold (TL)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="checkout_tckno_cart_total_threshold" type="number" id="checkout_tckno_cart_total_threshold" value="%s" class="regular-text" min="0" step="1" />',
            esc_attr((string) ($s['checkout_tckno_cart_total_threshold'] ?? 80000))
        );
        echo '<p class="description">' . esc_html__(
            'Require national ID at checkout when the cart total exceeds this amount. Imported products always require national ID. Set 0 to disable the cart total rule.',
            'sutore-marketplace'
        ) . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="auto_active_merchant_statuses">' . esc_html__('Automatic for-sale release levels', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="auto_active_merchant_statuses" type="text" id="auto_active_merchant_statuses" value="%s" class="regular-text" />',
            esc_attr((string) ($s['auto_active_merchant_statuses'] ?? 'verified,premium'))
        );
        echo '<p class="description">' . esc_html__('Comma-separated: verified, premium', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="fast_shipment_city">' . esc_html__('Fast shipping city code', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="fast_shipment_city" type="text" id="fast_shipment_city" value="%s" class="regular-text" />',
            esc_attr((string) ($s['fast_shipment_city'] ?? 'TR34'))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="fast_shipment_levels">' . esc_html__('Fast shipping seller levels', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="fast_shipment_levels" type="text" id="fast_shipment_levels" value="%s" class="regular-text" />',
            esc_attr((string) ($s['fast_shipment_levels'] ?? 'verified,premium'))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="catalog_product_request_levels">' . esc_html__('Catalog product request levels', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="catalog_product_request_levels" type="text" id="catalog_product_request_levels" value="%s" class="regular-text" />',
            esc_attr((string) ($s['catalog_product_request_levels'] ?? 'verified,premium'))
        );
        echo '<p class="description">' . esc_html__('Comma-separated seller levels that may request a missing catalog product (SKU or link) from the product form. Default: verified,premium', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        $tcMode = (string) ($s['tc_verification_mode'] ?? '');
        echo '<tr><th scope="row"><label for="tc_verification_mode">' . esc_html__('TC verification mode', 'sutore-marketplace') . '</label></th><td>';
        echo '<select name="tc_verification_mode" id="tc_verification_mode">';
        foreach ([
            '' => __('Automatic (local=algorithm, prod=NVI)', 'sutore-marketplace'),
            'nvi' => __('NVI KPS (SOAP)', 'sutore-marketplace'),
            'algorithm' => __('Algorithm only (development)', 'sutore-marketplace'),
            'manual' => __('Manual approval', 'sutore-marketplace'),
        ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($tcMode, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('The NVI public service may be closed to programmatic access after September 2025. Use "algorithm" mode for local development; set a corporate KPS endpoint for production.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="tc_verification_nvi_endpoint">' . esc_html__('NVI / KPS endpoint', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="tc_verification_nvi_endpoint" type="url" id="tc_verification_nvi_endpoint" value="%s" class="regular-text" />',
            esc_attr((string) ($s['tc_verification_nvi_endpoint'] ?? 'https://tckimlik.nvi.gov.tr/Service/KPSPublic.asmx'))
        );
        echo '<p class="description">' . esc_html__('Enter the endpoint URL here when you have corporate KPS access.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Youth discount', 'sutore-marketplace') . '</th><td>';
        echo '<label><input name="youth_discount_enabled" type="checkbox" value="1" '
            . checked(!empty($s['youth_discount_enabled']), true, false) . ' /> ';
        echo esc_html__('Enable youth discount', 'sutore-marketplace') . '</label>';
        echo '<p class="description">' . esc_html__(
            'Verified customers below the maximum age see an automatic cart fee (not a coupon). Seller price and seller net (price minus commission) stay unchanged. The discount is capped by remaining service fee + guarantee fee + commission.',
            'sutore-marketplace'
        ) . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="youth_discount_max_age">' . esc_html__('Maximum age', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="youth_discount_max_age" type="number" id="youth_discount_max_age" value="%s" class="small-text" min="1" max="120" step="1" />',
            esc_attr((string) ($s['youth_discount_max_age'] ?? 26))
        );
        echo '<p class="description">' . esc_html__(
            'Customers younger than this age qualify. Age is current year minus verified birth year.',
            'sutore-marketplace'
        ) . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="youth_discount_percent">' . esc_html__('Discount percent', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="youth_discount_percent" type="number" id="youth_discount_percent" value="%s" class="small-text" min="0" max="100" step="0.01" />',
            esc_attr((string) ($s['youth_discount_percent'] ?? 20))
        );
        echo '</td></tr>';
        $referral = is_array($s['referral'] ?? null)
            ? $s['referral']
            : \SutoreMarketplace\Modules\Merchants\Settings\ReferralSettings::defaults();
        $referralDefaults = \SutoreMarketplace\Modules\Merchants\Settings\ReferralSettings::defaults();
        echo '<tr><th scope="row">' . esc_html__('Seller referral', 'sutore-marketplace') . '</th><td>';
        echo '<p class="description">' . esc_html__(
            'Invite codes grant a time-limited commission discount (points off the seller level). The invited seller receives it at registration; the inviter receives it when that seller’s first sale is confirmed.',
            'sutore-marketplace'
        ) . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="referral_invitee_points_off">' . esc_html__('Invitee points off', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="referral_invitee_points_off" type="number" id="referral_invitee_points_off" value="%s" class="small-text" min="0" max="100" step="0.01" />',
            esc_attr((string) ($referral['invitee_points_off'] ?? $referralDefaults['invitee_points_off']))
        );
        echo '<p class="description">' . esc_html__('Commission points subtracted from the invited seller’s level rate at registration.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="referral_invitee_duration_days">' . esc_html__('Invitee duration (days)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="referral_invitee_duration_days" type="number" id="referral_invitee_duration_days" value="%s" class="small-text" min="1" step="1" />',
            esc_attr((string) ($referral['invitee_duration_days'] ?? $referralDefaults['invitee_duration_days']))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="referral_inviter_points_off">' . esc_html__('Inviter points off', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="referral_inviter_points_off" type="number" id="referral_inviter_points_off" value="%s" class="small-text" min="0" max="100" step="0.01" />',
            esc_attr((string) ($referral['inviter_points_off'] ?? $referralDefaults['inviter_points_off']))
        );
        echo '<p class="description">' . esc_html__('Commission points subtracted from the inviting seller’s level rate after the invitee’s first confirmed sale.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="referral_inviter_duration_days">' . esc_html__('Inviter duration (days)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="referral_inviter_duration_days" type="number" id="referral_inviter_duration_days" value="%s" class="small-text" min="1" step="1" />',
            esc_attr((string) ($referral['inviter_duration_days'] ?? $referralDefaults['inviter_duration_days']))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="referral_inviter_max_rewards_per_period">' . esc_html__('Inviter reward cap per period', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="referral_inviter_max_rewards_per_period" type="number" id="referral_inviter_max_rewards_per_period" value="%s" class="small-text" min="0" step="1" />',
            esc_attr((string) ($referral['inviter_max_rewards_per_period'] ?? $referralDefaults['inviter_max_rewards_per_period']))
        );
        echo '<p class="description">' . esc_html__('Maximum inviter rewards per rolling period. 0 blocks inviter rewards.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="referral_period_days">' . esc_html__('Reward period (days)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="referral_period_days" type="number" id="referral_period_days" value="%s" class="small-text" min="1" step="1" />',
            esc_attr((string) ($referral['period_days'] ?? $referralDefaults['period_days']))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="international_commitment_text">' . esc_html__('International commitment text', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<textarea name="international_commitment_text" id="international_commitment_text" rows="3" class="large-text" placeholder="%s">%s</textarea>',
            esc_attr(__(
                'I agree to provide invoice and customs documents for international shipping.',
                'sutore-marketplace'
            )),
            esc_textarea((string) ($s['international_commitment_text'] ?? ''))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Queue change notification', 'sutore-marketplace') . '</th><td>';
        echo '<label><input name="notify_queue_position_change" type="checkbox" value="1" '
            . checked(!empty($s['notify_queue_position_change']), true, false) . ' /> ';
        echo esc_html__('Record event / trigger hook on selector queue change', 'sutore-marketplace') . '</label></td></tr>';
        echo '</tbody></table>';
    }

    private function shippingFields(array $s): void
    {
        $eta = is_array($s['checkout_eta_days'] ?? null) ? $s['checkout_eta_days'] : [];
        $etaDefaults = \SutoreMarketplace\Modules\Shipping\Settings\ShippingSettings::defaultEtaDays();
        $etaLabels = [
            'free' => __('Free shipping (days)', 'sutore-marketplace'),
            'fast' => __('Fast shipping (days)', 'sutore-marketplace'),
            'express' => __('Express shipping (days)', 'sutore-marketplace'),
            'international' => __('International shipping (days)', 'sutore-marketplace'),
            'cyprus' => __('Cyprus shipping (days)', 'sutore-marketplace'),
            'imported_free' => __('Imported product free shipping (days)', 'sutore-marketplace'),
        ];

        echo '<table class="form-table" role="presentation"><tbody>';

        $numberFields = [
            'checkout_fast_shipping_fee' => [__('Fast shipping fee (TL)', 'sutore-marketplace'), '1'],
            'checkout_express_base_fee' => [__('Express base fee (TL)', 'sutore-marketplace'), '1'],
            'checkout_express_per_item_surcharge' => [__('Express per-item surcharge (TL)', 'sutore-marketplace'), '1'],
            'checkout_international_fee' => [__('International shipping fee (TRY)', 'sutore-marketplace'), '1'],
            'checkout_cyprus_fee' => [__('Cyprus shipping fee (TRY)', 'sutore-marketplace'), '1'],
            'checkout_fast_campaign_price' => [__('Fast shipping campaign price (TL)', 'sutore-marketplace'), '1'],
            'checkout_free_fast_cart_threshold' => [__('Free express shipping threshold (item count)', 'sutore-marketplace'), '1'],
        ];

        foreach ($numberFields as $name => [$label, $step]) {
            echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
            printf(
                '<input name="%1$s" type="number" id="%1$s" value="%2$s" class="regular-text" min="0" step="%3$s" />',
                esc_attr($name),
                esc_attr((string) ($s[$name] ?? 0)),
                esc_attr($step)
            );
            echo '</td></tr>';
        }

        echo '<tr><th scope="row">' . esc_html__('Fast shipping campaign', 'sutore-marketplace') . '</th><td>';
        echo '<label><input name="checkout_fast_campaign_active" type="checkbox" value="1" '
            . checked(!empty($s['checkout_fast_campaign_active']), true, false) . ' /> ';
        echo esc_html__('Apply special express shipping price for customers with completed orders', 'sutore-marketplace') . '</label></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Fast shipping everywhere', 'sutore-marketplace') . '</th><td>';
        echo '<label><input name="checkout_express_everywhere_enabled" type="checkbox" value="1" '
            . checked(!empty($s['checkout_express_everywhere_enabled']), true, false) . ' /> ';
        echo esc_html__('Show express shipping options without province restriction', 'sutore-marketplace') . '</label></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Delivery times', 'sutore-marketplace') . '</th><td><fieldset>';
        foreach ($etaLabels as $key => $label) {
            $field = 'checkout_eta_days[' . $key . ']';
            $value = (int) ($eta[$key] ?? $etaDefaults[$key] ?? 0);
            echo '<p><label for="' . esc_attr($field) . '">' . esc_html($label) . '</label><br />';
            printf(
                '<input name="%1$s" type="number" id="%1$s" value="%2$s" class="small-text" min="0" step="1" />',
                esc_attr($field),
                esc_attr((string) $value)
            );
            echo '</p>';
        }
        echo '</fieldset></td></tr>';

        echo '</tbody></table>';
    }

    private function couponBehaviorFields(array $s): void
    {
        AdminAssets::enqueue();
        echo '<p class="description">' . esc_html__(
            'Brand campaign rules and discount amounts are managed from WooCommerce → Coupons. Only general behavior settings are here.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<p><a class="button" href="' . esc_url(admin_url('edit.php?post_type=shop_coupon')) . '">'
            . esc_html__('Manage WooCommerce coupons', 'sutore-marketplace') . '</a></p>';
        echo '<p><button type="button" class="button button-secondary" data-rest-click'
            . ' data-rest-path="admin/coupons/seed-brand" data-rest-method="POST">'
            . esc_html__('Create sample brand coupons (3)', 'sutore-marketplace')
            . '</button></p>';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="coupon_lockout_max_attempts">' . esc_html__('Coupon attempt limit', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="coupon_lockout_max_attempts" type="number" id="coupon_lockout_max_attempts" value="%s" class="small-text" min="1" step="1" />',
            esc_attr((string) ($s['coupon_lockout_max_attempts'] ?? 5))
        );
        echo '<p class="description">' . esc_html__('A temporary block is applied after consecutive failed coupon attempts.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="coupon_lockout_minutes">' . esc_html__('Block duration (minutes)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="coupon_lockout_minutes" type="number" id="coupon_lockout_minutes" value="%s" class="small-text" min="1" step="1" />',
            esc_attr((string) ($s['coupon_lockout_minutes'] ?? 15))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="coupon_cart_notice_limit">' . esc_html__('Cart notice limit', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="coupon_cart_notice_limit" type="number" id="coupon_cart_notice_limit" value="%s" class="small-text" min="1" step="1" />',
            esc_attr((string) ($s['coupon_cart_notice_limit'] ?? 2))
        );
        echo '<p class="description">' . esc_html__('Maximum number of brand campaign progress messages shown in the cart.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
    }

    private function campaignFields(array $s): void
    {
        echo '<table class="form-table" role="presentation"><tbody>';
        $fields = [
            'campaign_discount_min_percent' => [__('Minimum seller discount (%)', 'sutore-marketplace'), '10'],
            'campaign_discount_max_percent' => [__('Maximum seller discount (%)', 'sutore-marketplace'), '40'],
            'campaign_max_days' => [__('Maximum campaign duration (days)', 'sutore-marketplace'), '14'],
            'campaign_cooldown_days' => [__('Cooldown after a campaign (days)', 'sutore-marketplace'), '14'],
            'campaign_aging_day_1' => [__('Aging suggestion day 1', 'sutore-marketplace'), '45'],
            'campaign_aging_day_2' => [__('Aging suggestion day 2 (matched)', 'sutore-marketplace'), '60'],
        ];
        foreach ($fields as $key => $meta) {
            echo '<tr><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($meta[0]) . '</label></th><td>';
            printf(
                '<input name="%1$s" type="number" id="%1$s" value="%2$s" class="small-text" min="0" step="1" />',
                esc_attr($key),
                esc_attr((string) ($s[$key] ?? $meta[1]))
            );
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>' . esc_html__('Customer price offers', 'sutore-marketplace') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'Customers bid against seller price. Accepting issues a personal, time-limited coupon for that product — the public price does not change.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Enable customer offers', 'sutore-marketplace') . '</th><td>';
        echo '<label><input name="customer_offer_enabled" type="checkbox" value="1" '
            . checked(!empty($s['customer_offer_enabled']), true, false) . ' /> ';
        echo esc_html__('Allow logged-in customers to send a price offer to the product currently for sale.', 'sutore-marketplace');
        echo '</label></td></tr>';
        echo '<tr><th scope="row"><label for="customer_offer_auto_decline_hours">' . esc_html__('Auto-decline unanswered offers (hours)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="customer_offer_auto_decline_hours" type="number" id="customer_offer_auto_decline_hours" value="%s" class="small-text" min="1" max="168" step="1" />',
            esc_attr((string) ($s['customer_offer_auto_decline_hours'] ?? 48))
        );
        echo '<p class="description">' . esc_html__(
            'Pending offers with no seller response are declined after this many hours. If another seller is queued for the same size, the bid is forwarded.',
            'sutore-marketplace'
        ) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="customer_offer_ttl_hours">' . esc_html__('Accepted coupon lifetime (hours)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="customer_offer_ttl_hours" type="number" id="customer_offer_ttl_hours" value="%s" class="small-text" min="1" max="168" step="1" />',
            esc_attr((string) ($s['customer_offer_ttl_hours'] ?? 48))
        );
        echo '<p class="description">' . esc_html__(
            'How long the personal coupon stays valid after a seller accepts an offer.',
            'sutore-marketplace'
        ) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="customer_offer_min_percent">' . esc_html__('Minimum bid (% of the price)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="customer_offer_min_percent" type="number" id="customer_offer_min_percent" value="%s" class="small-text" min="1" max="99" step="1" />',
            esc_attr((string) ($s['customer_offer_min_percent'] ?? 70))
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="customer_offer_max_per_day">' . esc_html__('Max offers per customer per day', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="customer_offer_max_per_day" type="number" id="customer_offer_max_per_day" value="%s" class="small-text" min="1" max="50" step="1" />',
            esc_attr((string) ($s['customer_offer_max_per_day'] ?? 10))
        );
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__(
            'Timed strikethrough discounts only. Permanent markdowns are a silent price drop. System suggestions waive fees up to service + guarantee.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=sutore-marketplace-campaigns')) . '">'
            . esc_html__('Manage campaigns', 'sutore-marketplace') . '</a></p>';
    }

    private function contractsFields(array $s): void
    {
        echo '<p class="description">' . esc_html__(
            'Checkout contract texts are read from template files inside the module. When you change the template text, increase the version number; new orders are saved with the updated text.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Checkout contracts', 'sutore-marketplace') . '</th><td>';
        printf(
            '<label><input type="checkbox" name="contracts_enabled" value="1" %s /> %s</label>',
            checked(!empty($s['contracts_enabled']), true, false),
            esc_html__('Show pre-information and distance sales contracts at checkout', 'sutore-marketplace')
        );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="contracts_checkbox_title">' . esc_html__('Checkbox title', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="contracts_checkbox_title" type="text" id="contracts_checkbox_title" value="%s" class="regular-text" />',
            esc_attr((string) ($s['contracts_checkbox_title'] ?? ''))
        );
        echo '<p class="description">' . esc_html__('Leave empty to use the translated default (“Contracts”).', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="contracts_template_version">' . esc_html__('Template version', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="contracts_template_version" type="number" id="contracts_template_version" value="%s" class="small-text" min="1" step="1" />',
            esc_attr((string) ($s['contracts_template_version'] ?? 1))
        );
        echo '</td></tr></tbody></table>';
    }

    private function fulfillmentSettings(): FulfillmentSettingsPage
    {
        return $this->fulfillmentSettings ??= new FulfillmentSettingsPage();
    }
    private function smsSettingsSection(): SmsSettingsSection
    {
        return $this->smsSettings ??= new SmsSettingsSection();
    }
    private function invoiceSettingsSection(): InvoiceSettingsSection
    {
        return $this->invoiceSettings ??= new InvoiceSettingsSection();
    }
    private function behaviorSettingsSection(): BehaviorSettingsSection
    {
        return $this->behaviorSettings ??= new BehaviorSettingsSection();
    }
}
