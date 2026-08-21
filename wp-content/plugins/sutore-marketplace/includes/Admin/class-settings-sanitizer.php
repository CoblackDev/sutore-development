<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

use SutoreMarketplace\Admin\Orders\SettingsPage as FulfillmentSettingsPage;
use SutoreMarketplace\Modules\Invoices\Admin\InvoiceSettingsSection;
use SutoreMarketplace\Modules\Orders\Hooks\CronHooks;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Shared\Settings\Settings;

/**
 * Settings API sanitize callbacks for marketplace options.
 */
final class SettingsSanitizer
{
    /**
     * @param mixed $input Value from options.php for sutore_marketplace_settings.
     * @return array<string, mixed>
     */
    public static function sanitizeGeneral(mixed $input): array
    {
        $existing = Settings::all();

        if (!StaffCapabilities::canManageSettings()) {
            add_settings_error(
                'sutore_marketplace',
                'sutore_marketplace_forbidden',
                __('You are not allowed to manage marketplace settings.', 'sutore-marketplace'),
                'error'
            );

            return $existing;
        }

        $post = self::unslashedPost();
        $tab = sanitize_key((string) ($post['settings_tab'] ?? ''));
        if ($tab === '' && is_array($input)) {
            $tab = sanitize_key((string) ($input['__tab'] ?? 'pricing'));
        }
        if ($tab === '' || $tab === 'orders') {
            $tab = 'pricing';
        }

        $patch = self::buildGeneralPatch($tab, $post);
        $merged = array_replace_recursive($existing, array_intersect_key($patch, Settings::defaults()));
        Settings::forgetMemo();

        return $merged;
    }

    /**
     * @param mixed $input Value from options.php for sutore_marketplace_fulfillment_settings.
     * @return array<string, mixed>
     */
    public static function sanitizeOrders(mixed $input): array
    {
        $existing = OrderSettings::all();

        if (!StaffCapabilities::canManageSettings()) {
            add_settings_error(
                'sutore_marketplace',
                'sutore_marketplace_forbidden',
                __('You are not allowed to manage marketplace settings.', 'sutore-marketplace'),
                'error'
            );

            return $existing;
        }

        $post = self::unslashedPost();
        $subTab = sanitize_key((string) ($post['orders_tab'] ?? 'deadlines'));
        $beforeSchedule = OrderSettings::deadlineCronSchedule();

        $patch = (new FulfillmentSettingsPage())->buildSavePatch($subTab, $post);
        if ($patch === []) {
            return $existing;
        }

        $merged = array_replace_recursive($existing, $patch);
        $merged['settings_version'] = OrderSettings::VERSION;
        if (array_key_exists('payout_weekdays', $patch)) {
            $merged['payout_weekdays'] = $patch['payout_weekdays'];
        }
        if (array_key_exists('merchant_notification_channels', $patch)) {
            $merged['merchant_notification_channels'] = $patch['merchant_notification_channels'];
        }
        if (array_key_exists('sms_events', $patch)) {
            $merged['sms_events'] = $patch['sms_events'];
        }
        unset($merged['merchant_notification_events']);

        OrderSettings::forgetMemo();

        $schedule = sanitize_key((string) ($merged['deadline_cron_schedule'] ?? 'twicedaily'));
        $schedule = in_array($schedule, ['hourly', 'twicedaily', 'daily'], true) ? $schedule : 'twicedaily';
        if ($subTab === 'advanced' && $schedule !== $beforeSchedule) {
            // Persist first so reschedule reads the new value from options after options.php writes.
            add_action('update_option_' . OrderSettings::OPTION, static function () use ($beforeSchedule): void {
                if (OrderSettings::deadlineCronSchedule() !== $beforeSchedule) {
                    CronHooks::reschedule();
                }
            }, 10, 0);
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private static function unslashedPost(): array
    {
        $post = wp_unslash($_POST);
        return is_array($post) ? $post : [];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function buildGeneralPatch(string $tab, array $post): array
    {
        $patch = [];
        $before = Settings::all();

        // Temporarily expose unslashed post to section builders that read $_POST.
        $previous = $_POST;
        $_POST = $post;

        try {
            if ($tab === 'pricing') {
                $patch['listing_price_step'] = max(1, (int) ($post['listing_price_step'] ?? 25));
                $patch['usd_try_exchange_rate'] = max(0, (float) ($post['usd_try_exchange_rate'] ?? 0));
                $patch['service_fee_amount'] = max(0, (float) ($post['service_fee_amount'] ?? 0));
                $patch['assurance_fee_percent'] = max(0, (float) ($post['assurance_fee_percent'] ?? 0));
            } elseif ($tab === 'listing') {
                $defaultDuration = (int) ($post['listing_expire_duration_days'] ?? 45);
                if (!\SutoreMarketplace\Modules\Listings\Domain\ListingDuration::isAllowed($defaultDuration)) {
                    $defaultDuration = 45;
                }
                $patch['listing_expire_duration_days'] = $defaultDuration;
                $maxInput = is_array($post['listing_duration_max_by_level'] ?? null)
                    ? $post['listing_duration_max_by_level']
                    : [];
                $maxPatch = [];
                foreach (Settings::defaults()['listing_duration_max_by_level'] as $level => $fallback) {
                    $value = (int) ($maxInput[$level] ?? $fallback);
                    if (!\SutoreMarketplace\Modules\Listings\Domain\ListingDuration::isAllowed($value)) {
                        $value = (int) $fallback;
                    }
                    $maxPatch[$level] = $value;
                }
                $patch['listing_duration_max_by_level'] = $maxPatch;
            } elseif ($tab === 'operations') {
                $patch['cart_max_quantity'] = max(1, (int) ($post['cart_max_quantity'] ?? 8));
                $patch['checkout_tckno_cart_total_threshold'] = max(0.0, (float) ($post['checkout_tckno_cart_total_threshold'] ?? 80000));
                $patch['auto_active_merchant_statuses'] = sanitize_text_field((string) ($post['auto_active_merchant_statuses'] ?? 'verified,premium'));
                $patch['fast_shipment_city'] = sanitize_text_field((string) ($post['fast_shipment_city'] ?? 'TR34'));
                $patch['fast_shipment_levels'] = sanitize_text_field((string) ($post['fast_shipment_levels'] ?? 'verified,premium'));
                $patch['catalog_product_request_levels'] = sanitize_text_field((string) ($post['catalog_product_request_levels'] ?? 'verified,premium'));
                $patch['international_commitment_text'] = sanitize_textarea_field((string) ($post['international_commitment_text'] ?? ''));
                $patch['notify_queue_position_change'] = !empty($post['notify_queue_position_change']);
                $mode = sanitize_key((string) ($post['tc_verification_mode'] ?? ''));
                $patch['tc_verification_mode'] = in_array($mode, ['', 'nvi', 'algorithm', 'manual'], true) ? $mode : '';
                $patch['tc_verification_nvi_endpoint'] = esc_url_raw((string) ($post['tc_verification_nvi_endpoint'] ?? ''));
                $nviHost = strtolower((string) (wp_parse_url($patch['tc_verification_nvi_endpoint'], PHP_URL_HOST) ?? ''));
                if ($patch['tc_verification_nvi_endpoint'] !== '' && $nviHost !== 'tckimlik.nvi.gov.tr') {
                    $patch['tc_verification_nvi_endpoint'] = 'https://tckimlik.nvi.gov.tr/Service/KPSPublic.asmx';
                }
                $patch['youth_discount_enabled'] = !empty($post['youth_discount_enabled']);
                $patch['youth_discount_max_age'] = max(1, min(120, (int) ($post['youth_discount_max_age'] ?? 26)));
                $patch['youth_discount_percent'] = max(0.0, min(100.0, (float) ($post['youth_discount_percent'] ?? 20)));
                $patch['referral'] = \SutoreMarketplace\Modules\Merchants\Settings\ReferralSettings::sanitizeFromInput($post);
            } elseif ($tab === 'sms') {
                $patch = (new SmsSettingsSection())->buildSavePatch();
            } elseif ($tab === 'invoices') {
                $patch = (new InvoiceSettingsSection())->buildSavePatch();
            } elseif ($tab === 'behavior') {
                $patch = (new BehaviorSettingsSection())->buildSavePatch();
            } elseif ($tab === 'campaigns') {
                $min = max(1, (int) ($post['campaign_discount_min_percent'] ?? 10));
                $max = max($min, (int) ($post['campaign_discount_max_percent'] ?? 40));
                $patch['campaign_discount_min_percent'] = min(90, $min);
                $patch['campaign_discount_max_percent'] = min(90, $max);
                $patch['campaign_max_days'] = max(1, min(90, (int) ($post['campaign_max_days'] ?? 14)));
                $patch['campaign_cooldown_days'] = max(0, min(90, (int) ($post['campaign_cooldown_days'] ?? 14)));
                $day1 = max(1, (int) ($post['campaign_aging_day_1'] ?? 45));
                $day2 = max($day1, (int) ($post['campaign_aging_day_2'] ?? 60));
                $patch['campaign_aging_day_1'] = $day1;
                $patch['campaign_aging_day_2'] = $day2;
                $patch['customer_offer_enabled'] = !empty($post['customer_offer_enabled']);
                $patch['customer_offer_auto_decline_hours'] = max(1, min(168, (int) ($post['customer_offer_auto_decline_hours'] ?? 48)));
                $patch['customer_offer_ttl_hours'] = max(1, min(168, (int) ($post['customer_offer_ttl_hours'] ?? 48)));
                $patch['customer_offer_min_percent'] = max(1, min(99, (int) ($post['customer_offer_min_percent'] ?? 70)));
                $patch['customer_offer_max_per_day'] = max(1, min(50, (int) ($post['customer_offer_max_per_day'] ?? 10)));
            } elseif ($tab === 'shipping') {
                $patch['checkout_fast_shipping_fee'] = max(0.0, (float) ($post['checkout_fast_shipping_fee'] ?? 0));
                $patch['checkout_express_base_fee'] = max(0.0, (float) ($post['checkout_express_base_fee'] ?? 0));
                $patch['checkout_express_per_item_surcharge'] = max(0.0, (float) ($post['checkout_express_per_item_surcharge'] ?? 200));
                $patch['checkout_international_fee'] = max(0.0, (float) ($post['checkout_international_fee'] ?? 1500));
                $patch['checkout_cyprus_fee'] = max(0.0, (float) ($post['checkout_cyprus_fee'] ?? 600));
                $patch['checkout_fast_campaign_price'] = max(0.0, (float) ($post['checkout_fast_campaign_price'] ?? 395));
                $patch['checkout_free_fast_cart_threshold'] = max(1, (int) ($post['checkout_free_fast_cart_threshold'] ?? 4));
                $patch['checkout_fast_campaign_active'] = !empty($post['checkout_fast_campaign_active']);
                $patch['checkout_express_everywhere_enabled'] = !empty($post['checkout_express_everywhere_enabled']);
                $etaInput = is_array($post['checkout_eta_days'] ?? null) ? $post['checkout_eta_days'] : [];
                $etaPatch = [];
                foreach (\SutoreMarketplace\Modules\Shipping\Settings\ShippingSettings::defaultEtaDays() as $key => $default) {
                    $etaPatch[$key] = max(0, (int) ($etaInput[$key] ?? $default));
                }
                $patch['checkout_eta_days'] = $etaPatch;
                $patch['checkout_shipping_revision'] = max(1, (int) Settings::get('checkout_shipping_revision', 1)) + 1;
            } elseif ($tab === 'coupons') {
                $patch['coupon_lockout_max_attempts'] = max(1, (int) ($post['coupon_lockout_max_attempts'] ?? 5));
                $patch['coupon_lockout_minutes'] = max(1, (int) ($post['coupon_lockout_minutes'] ?? 15));
                $patch['coupon_cart_notice_limit'] = max(1, (int) ($post['coupon_cart_notice_limit'] ?? 2));
            } elseif ($tab === 'contracts') {
                $patch['contracts_enabled'] = !empty($post['contracts_enabled']);
                $patch['contracts_checkbox_title'] = sanitize_text_field((string) ($post['contracts_checkbox_title'] ?? ''));
                $patch['contracts_template_version'] = max(1, (int) ($post['contracts_template_version'] ?? 1));
            }
        } finally {
            $_POST = $previous;
        }

        if ($tab === 'pricing') {
            $feesChanged = (float) $patch['service_fee_amount'] !== (float) $before['service_fee_amount']
                || (float) $patch['assurance_fee_percent'] !== (float) $before['assurance_fee_percent'];
            if ($feesChanged) {
                $patch['pricing_revision'] = (int) ($before['pricing_revision'] ?? 1) + 1;
            }
        }

        return $patch;
    }
}
