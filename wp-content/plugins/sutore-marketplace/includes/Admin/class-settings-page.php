<?php



declare(strict_types=1);



namespace SutoreMarketplace\Admin;



use SutoreMarketplace\Admin\Orders\SettingsPage as FulfillmentSettingsPage;

use SutoreMarketplace\Shared\Settings\Settings;



final class SettingsPage

{

    private ?FulfillmentSettingsPage $fulfillmentSettings = null;

    private ?SmsSettingsSection $smsSettings = null;



    public function render(): void

    {

        if (!current_user_can(AdminMenu::CAP)) {

            return;

        }



        if (

            isset($_POST['sutore_marketplace_settings_nonce'])

            && wp_verify_nonce(sanitize_text_field((string) $_POST['sutore_marketplace_settings_nonce']), 'sutore_marketplace_settings')

        ) {

            $this->save();

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'sutore-marketplace') . '</p></div>';

        }



        $s = Settings::all();

        $tab = sanitize_key((string) ($_GET['tab'] ?? 'pricing'));

        $tabs = [

            'pricing' => __('Pricing', 'sutore-marketplace'),

            'listing' => __('Listing', 'sutore-marketplace'),

            'operations' => __('Operations', 'sutore-marketplace'),

            'sms' => __('SMS', 'sutore-marketplace'),

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



        echo '<form method="post" action="">';

        wp_nonce_field('sutore_marketplace_settings', 'sutore_marketplace_settings_nonce');

        echo '<input type="hidden" name="settings_tab" value="' . esc_attr($tab) . '" />';



        if ($tab === 'listing') {

            $this->listingFields($s);

        } elseif ($tab === 'operations') {

            $this->operationsFields($s);

        } elseif ($tab === 'sms') {

            $this->smsSettingsSection()->render($s);

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

        echo '<tr><th scope="row"><label for="listing_expire_duration_days">' . esc_html__('Listing duration (days)', 'sutore-marketplace') . '</label></th><td>';

        printf(

            '<input name="listing_expire_duration_days" type="number" id="listing_expire_duration_days" value="%s" class="small-text" min="1" step="1" />',

            esc_attr((string) $s['listing_expire_duration_days'])

        );

        echo '<p class="description">' . esc_html__('Default expire duration for draft/queued listings.', 'sutore-marketplace') . '</p>';

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

        echo '<tr><th scope="row">' . esc_html__('Freeze expiry while for sale', 'sutore-marketplace') . '</th><td>';

        echo '<label><input name="freeze_expire_on_sale" type="checkbox" value="1" '

            . checked(!empty($s['freeze_expire_on_sale']), true, false) . ' /> ';

        echo esc_html__('Clear expire duration during payment/sale process', 'sutore-marketplace') . '</label></td></tr>';

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

        echo '<tr><th scope="row"><label for="default_platform_discount">' . esc_html__('Default platform discount', 'sutore-marketplace') . '</label></th><td>';

        printf(

            '<input name="default_platform_discount" type="number" id="default_platform_discount" value="%s" class="regular-text" min="0" step="1" />',

            esc_attr((string) ($s['default_platform_discount'] ?? 0))

        );

        echo '</td></tr></tbody></table>';



        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=sutore-marketplace-campaigns')) . '">'

            . esc_html__('Manage campaigns', 'sutore-marketplace') . '</a></p>';

    }



    private function save(): void

    {

        $tab = sanitize_key((string) ($_POST['settings_tab'] ?? 'pricing'));



        if ($tab === 'orders') {

            $ordersTab = sanitize_key((string) ($_POST['orders_tab'] ?? 'deadlines'));

            $this->fulfillmentSettings()->save($ordersTab);

            return;

        }



        $patch = [];

        $before = Settings::all();



        if ($tab === 'pricing') {

            $patch['listing_price_step'] = max(1, (int) ($_POST['listing_price_step'] ?? 25));

            $patch['usd_try_exchange_rate'] = max(0, (float) ($_POST['usd_try_exchange_rate'] ?? 0));

            $patch['service_fee_amount'] = max(0, (float) ($_POST['service_fee_amount'] ?? 0));

            $patch['assurance_fee_percent'] = max(0, (float) ($_POST['assurance_fee_percent'] ?? 0));

        } elseif ($tab === 'listing') {

            $patch['listing_expire_duration_days'] = max(1, (int) ($_POST['listing_expire_duration_days'] ?? 45));

        } elseif ($tab === 'operations') {

            $patch['cart_max_quantity'] = max(1, (int) ($_POST['cart_max_quantity'] ?? 8));

            $patch['checkout_tckno_cart_total_threshold'] = max(0.0, (float) ($_POST['checkout_tckno_cart_total_threshold'] ?? 80000));

            $patch['auto_active_merchant_statuses'] = sanitize_text_field((string) ($_POST['auto_active_merchant_statuses'] ?? 'verified,premium'));

            $patch['fast_shipment_city'] = sanitize_text_field((string) ($_POST['fast_shipment_city'] ?? 'TR34'));

            $patch['fast_shipment_levels'] = sanitize_text_field((string) ($_POST['fast_shipment_levels'] ?? 'verified,premium'));

            $patch['international_commitment_text'] = sanitize_textarea_field((string) ($_POST['international_commitment_text'] ?? ''));

            $patch['freeze_expire_on_sale'] = !empty($_POST['freeze_expire_on_sale']);

            $patch['notify_queue_position_change'] = !empty($_POST['notify_queue_position_change']);

            $mode = sanitize_key((string) ($_POST['tc_verification_mode'] ?? ''));
            $patch['tc_verification_mode'] = in_array($mode, ['', 'nvi', 'algorithm', 'manual'], true) ? $mode : '';
            $patch['tc_verification_nvi_endpoint'] = esc_url_raw((string) ($_POST['tc_verification_nvi_endpoint'] ?? ''));

        } elseif ($tab === 'sms') {

            $patch = $this->smsSettingsSection()->buildSavePatch();

        } elseif ($tab === 'campaigns') {

            $patch['default_platform_discount'] = max(0, (float) ($_POST['default_platform_discount'] ?? 0));

        } elseif ($tab === 'shipping') {

            $patch['checkout_fast_shipping_fee'] = max(0.0, (float) ($_POST['checkout_fast_shipping_fee'] ?? 0));

            $patch['checkout_express_base_fee'] = max(0.0, (float) ($_POST['checkout_express_base_fee'] ?? 0));

            $patch['checkout_express_per_item_surcharge'] = max(0.0, (float) ($_POST['checkout_express_per_item_surcharge'] ?? 200));

            $patch['checkout_international_fee'] = max(0.0, (float) ($_POST['checkout_international_fee'] ?? 1500));

            $patch['checkout_cyprus_fee'] = max(0.0, (float) ($_POST['checkout_cyprus_fee'] ?? 600));

            $patch['checkout_fast_campaign_price'] = max(0.0, (float) ($_POST['checkout_fast_campaign_price'] ?? 395));

            $patch['checkout_free_fast_cart_threshold'] = max(1, (int) ($_POST['checkout_free_fast_cart_threshold'] ?? 4));

            $patch['checkout_fast_campaign_active'] = !empty($_POST['checkout_fast_campaign_active']);

            $patch['checkout_express_everywhere_enabled'] = !empty($_POST['checkout_express_everywhere_enabled']);

            $etaInput = is_array($_POST['checkout_eta_days'] ?? null) ? $_POST['checkout_eta_days'] : [];

            $etaPatch = [];

            foreach (\SutoreMarketplace\Modules\Shipping\Settings\ShippingSettings::defaultEtaDays() as $key => $default) {

                $etaPatch[$key] = max(0, (int) ($etaInput[$key] ?? $default));

            }

            $patch['checkout_eta_days'] = $etaPatch;

            $patch['checkout_shipping_revision'] = max(1, (int) Settings::get('checkout_shipping_revision', 1)) + 1;

        } elseif ($tab === 'coupons') {

            $patch['coupon_lockout_max_attempts'] = max(1, (int) ($_POST['coupon_lockout_max_attempts'] ?? 5));

            $patch['coupon_lockout_minutes'] = max(1, (int) ($_POST['coupon_lockout_minutes'] ?? 15));

            $patch['coupon_cart_notice_limit'] = max(1, (int) ($_POST['coupon_cart_notice_limit'] ?? 2));

        } elseif ($tab === 'contracts') {

            $patch['contracts_enabled'] = !empty($_POST['contracts_enabled']);

            $patch['contracts_checkbox_title'] = sanitize_text_field((string) ($_POST['contracts_checkbox_title'] ?? ''));

            $patch['contracts_template_version'] = max(1, (int) ($_POST['contracts_template_version'] ?? 1));

        }



        $feesChanged = false;

        if ($tab === 'pricing') {

            $feesChanged = (float) $patch['service_fee_amount'] !== (float) $before['service_fee_amount']

                || (float) $patch['assurance_fee_percent'] !== (float) $before['assurance_fee_percent'];

        } elseif ($tab === 'campaigns' && array_key_exists('default_platform_discount', $patch)) {

            $feesChanged = (float) $patch['default_platform_discount'] !== (float) ($before['default_platform_discount'] ?? 0);

        }



        if ($feesChanged) {

            $patch['pricing_revision'] = (int) ($before['pricing_revision'] ?? 1) + 1;

        }



        Settings::update($patch);

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

}

