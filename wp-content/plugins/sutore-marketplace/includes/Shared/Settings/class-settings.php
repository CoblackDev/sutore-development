<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Settings;

use SutoreMarketplace\Shared\Security\OutboundUrl;

final class Settings
{
    public const OPTION_KEY = 'sutore_marketplace_settings';

    public static function defaults(): array
    {
        return [
            'listing_price_step' => 25,
            'usd_try_exchange_rate' => 0.0,
            'listing_expire_duration_days' => 45,
            'listing_duration_max_by_level' => [
                'normal' => 30,
                'verified' => 45,
                'premium' => 60,
            ],
            'service_fee_amount' => 0.0,
            'assurance_fee_percent' => 0.0,
            'merchant_levels' => [
                'normal' => ['commission_percent' => 15],
                'verified' => ['commission_percent' => 12],
                'premium' => ['commission_percent' => 8],
            ],
            'campaign_discount_min_percent' => 10,
            'campaign_discount_max_percent' => 40,
            'campaign_max_days' => 14,
            'campaign_cooldown_days' => 14,
            'campaign_aging_day_1' => 45,
            'campaign_aging_day_2' => 60,
            'pricing_revision' => 2,
            'cart_max_quantity' => 8,
            'checkout_tckno_cart_total_threshold' => 80000.0,
            'youth_discount_enabled' => false,
            'youth_discount_max_age' => 26,
            'youth_discount_percent' => 20.0,
            'customer_offer_enabled' => true,
            'customer_offer_ttl_hours' => 48,
            'customer_offer_auto_decline_hours' => 48,
            'customer_offer_min_percent' => 70,
            'customer_offer_max_per_day' => 10,
            'auto_active_merchant_statuses' => 'verified,premium',
            'fast_shipment_city' => 'TR34',
            'fast_shipment_levels' => 'verified,premium',
            'catalog_product_request_levels' => 'verified,premium',
            'international_commitment_text' => '',
            'notify_queue_position_change' => false,
            'checkout_fast_shipping_fee' => 0.0,
            'checkout_express_base_fee' => 0.0,
            'checkout_express_per_item_surcharge' => 200.0,
            'checkout_international_fee' => 1500.0,
            'checkout_cyprus_fee' => 600.0,
            'checkout_fast_campaign_price' => 395.0,
            'checkout_fast_campaign_active' => false,
            'checkout_express_everywhere_enabled' => false,
            'checkout_free_fast_cart_threshold' => 4,
            'checkout_shipping_revision' => 1,
            'checkout_eta_days' => [
                'free' => 8,
                'fast' => 6,
                'express' => 1,
                'international' => 12,
                'cyprus' => 10,
                'imported_free' => 17,
            ],
            'coupon_lockout_max_attempts' => 5,
            'coupon_lockout_minutes' => 15,
            'coupon_cart_notice_limit' => 2,
            'contracts_enabled' => true,
            'contracts_checkbox_title' => '',
            'contracts_template_version' => 1,
            'tc_verification_mode' => '',
            'tc_verification_nvi_endpoint' => 'https://tckimlik.nvi.gov.tr/Service/KPSPublic.asmx',
            'otp_enabled' => true,
            'otp_ttl_seconds' => 300,
            'otp_ui_timer_seconds' => 120,
            'otp_max_attempts' => 3,
            'otp_rate_limit_window_seconds' => 900,
            'otp_code_length' => 6,
            'otp_sms_template' => '',
            'netgsm_usercode' => '',
            'netgsm_password' => '',
            'netgsm_header' => 'SUTORE',
            'netgsm_encoding' => 'TR',
            'netgsm_brand_code' => '',
            'sms_simulation_mode' => false,
            'behavior' => \SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings::defaults(),
            'referral' => \SutoreMarketplace\Modules\Merchants\Settings\ReferralSettings::defaults(),
            'invoices' => \SutoreMarketplace\Modules\Invoices\Settings\InvoiceSettings::defaults(),
        ];
    }

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    public static function ensureDefaults(): void
    {
        $current = get_option(self::OPTION_KEY, null);
        if (!is_array($current)) {
            update_option(self::OPTION_KEY, self::defaults());
            self::$memo = null;
            return;
        }

        // Drop removed keys; fill missing defaults. Write only when storage would change.
        $merged = array_replace_recursive(self::defaults(), array_intersect_key($current, self::defaults()));
        if ($merged != $current) {
            update_option(self::OPTION_KEY, $merged);
            self::$memo = null;
        }
    }

    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        self::$memo = array_replace_recursive(self::defaults(), array_intersect_key($settings, self::defaults()));

        return self::$memo;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function update(array $patch): array
    {
        $merged = array_replace_recursive(self::all(), array_intersect_key($patch, self::defaults()));
        update_option(self::OPTION_KEY, $merged);
        self::$memo = $merged;
        return $merged;
    }

    public static function listingPriceStep(): int
    {
        return max(1, (int) self::get('listing_price_step', 25));
    }

    public static function usdTryRate(): float
    {
        return max(0.0, (float) self::get('usd_try_exchange_rate', 0));
    }

    public static function defaultListingDurationDays(): int
    {
        $days = (int) self::get('listing_expire_duration_days', 45);
        if (!\SutoreMarketplace\Modules\Listings\Domain\ListingDuration::isAllowed($days)) {
            return 45;
        }

        return $days;
    }

    /** @return array<string, int> */
    public static function listingDurationMaxByLevel(): array
    {
        $defaults = self::defaults()['listing_duration_max_by_level'];
        $stored = self::get('listing_duration_max_by_level', $defaults);
        if (!is_array($stored)) {
            return $defaults;
        }

        $merged = [];
        foreach ($defaults as $level => $defaultMax) {
            $merged[$level] = max(1, (int) ($stored[$level] ?? $defaultMax));
        }

        return $merged;
    }

    public static function hizmetBedeli(): float
    {
        return max(0.0, (float) self::get('service_fee_amount', 0));
    }

    public static function guvenceBedeli(): float
    {
        return max(0.0, (float) self::get('assurance_fee_percent', 0));
    }

    /** Bumped when global fee components change — used to bust WC price caches. */
    public static function pricingRevision(): int
    {
        return max(1, (int) self::get('pricing_revision', 1));
    }

    public static function bumpPricingRevision(): int
    {
        $next = self::pricingRevision() + 1;
        self::update(['pricing_revision' => $next]);

        return $next;
    }

    public static function cartMaxQuantity(): int
    {
        return max(1, (int) self::get('cart_max_quantity', 8));
    }

    public static function checkoutTcknoCartTotalThreshold(): float
    {
        return max(0.0, (float) self::get('checkout_tckno_cart_total_threshold', 80000));
    }

    public static function youthDiscountEnabled(): bool
    {
        return (bool) self::get('youth_discount_enabled', false);
    }

    public static function youthDiscountMaxAge(): int
    {
        return max(1, min(120, (int) self::get('youth_discount_max_age', 26)));
    }

    public static function youthDiscountPercent(): float
    {
        return max(0.0, min(100.0, (float) self::get('youth_discount_percent', 20)));
    }

    /** @return list<string> */
    public static function autoActiveMerchantStatuses(): array
    {
        $raw = (string) self::get('auto_active_merchant_statuses', 'verified,premium');
        $parts = array_map('sanitize_key', array_filter(array_map('trim', explode(',', $raw))));

        return $parts ?: ['verified', 'premium'];
    }

    public static function merchantAutoActivates(int $merchantId): bool
    {
        $status = \SutoreMarketplace\Shared\Domain\MerchantLevels::statusForUser($merchantId);

        return in_array($status, self::autoActiveMerchantStatuses(), true);
    }

    public static function fastShipmentCity(): string
    {
        $city = trim((string) self::get('fast_shipment_city', 'TR34'));

        return $city !== '' ? $city : 'TR34';
    }

    /** @return list<string> */
    public static function fastShipmentLevels(): array
    {
        $raw = (string) self::get('fast_shipment_levels', 'verified,premium');
        $parts = array_map('sanitize_key', array_filter(array_map('trim', explode(',', $raw))));

        return $parts ?: ['verified', 'premium'];
    }

    /** @return list<string> */
    public static function catalogProductRequestLevels(): array
    {
        $raw = (string) self::get('catalog_product_request_levels', 'verified,premium');
        $parts = array_map('sanitize_key', array_filter(array_map('trim', explode(',', $raw))));

        return $parts ?: ['verified', 'premium'];
    }

    public static function merchantCanRequestCatalogProduct(int $merchantId): bool
    {
        $status = \SutoreMarketplace\Shared\Domain\MerchantLevels::statusForUser($merchantId);

        return in_array($status, self::catalogProductRequestLevels(), true);
    }

    public static function internationalCommitmentText(): string
    {
        $text = trim((string) self::get('international_commitment_text', ''));

        return $text !== '' ? $text : __(
            'I agree to provide invoice and customs documents for international shipping.',
            'sutore-marketplace'
        );
    }

    public static function notifyQueuePositionChange(): bool
    {
        return (bool) self::get('notify_queue_position_change', false);
    }

    public static function tcVerificationMode(): string
    {
        $mode = sanitize_key((string) self::get('tc_verification_mode', ''));
        if ($mode === '') {
            if (self::isLocalDevelopment()) {
                return 'algorithm';
            }

            return 'nvi';
        }

        return in_array($mode, ['nvi', 'algorithm', 'manual'], true) ? $mode : 'nvi';
    }

    public static function tcVerificationNviEndpoint(): string
    {
        $fallback = 'https://tckimlik.nvi.gov.tr/Service/KPSPublic.asmx';
        $url = esc_url_raw((string) self::get('tc_verification_nvi_endpoint', ''));
        if ($url === '') {
            return $fallback;
        }

        $host = strtolower((string) (wp_parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host !== 'tckimlik.nvi.gov.tr' || !OutboundUrl::isSafe($url)) {
            return $fallback;
        }

        return $url;
    }

    private static function isLocalDevelopment(): bool
    {
        if (function_exists('wp_get_environment_type')) {
            $env = wp_get_environment_type();
            if (in_array($env, ['local', 'development'], true)) {
                return true;
            }
        }

        $host = '';
        if (function_exists('home_url')) {
            $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            $host = (string) $_SERVER['HTTP_HOST'];
        }

        $host = strtolower($host);

        return $host === 'localhost'
            || str_starts_with($host, 'localhost:')
            || $host === '127.0.0.1'
            || str_starts_with($host, '127.0.0.1:');
    }
}
