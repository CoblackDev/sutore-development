<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Privacy;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Hooks\CheckoutIdentityHooks;
use SutoreMarketplace\Shared\Services\YouthDiscount;

/**
 * WordPress privacy exporter / eraser for marketplace PII surfaces.
 */
final class PrivacyHandlers
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerEraser']);
    }

    /** @param array<string, array<string, mixed>> $exporters */
    public static function registerExporter(array $exporters): array
    {
        $exporters['sutore-marketplace'] = [
            'exporter_friendly_name' => __('Sutore Marketplace', 'sutore-marketplace'),
            'callback' => [self::class, 'export'],
        ];

        return $exporters;
    }

    /** @param array<string, array<string, mixed>> $erasers */
    public static function registerEraser(array $erasers): array
    {
        $erasers['sutore-marketplace'] = [
            'eraser_friendly_name' => __('Sutore Marketplace', 'sutore-marketplace'),
            'callback' => [self::class, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @return array{data:list<array<string,mixed>>,done:bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $userId = (int) $user->ID;
        $profile = MerchantMeta::readProfile($userId);
        $group = [
            'group_id' => 'sutore-marketplace-profile',
            'group_label' => __('Marketplace profile', 'sutore-marketplace'),
            'item_id' => 'user-' . $userId,
            'data' => [],
        ];

        foreach ([
            MerchantMeta::ACCOUNT_NAME => __('First name', 'sutore-marketplace'),
            MerchantMeta::ACCOUNT_LASTNAME => __('Last name', 'sutore-marketplace'),
            MerchantMeta::ACCOUNT_EMAIL => __('Account email', 'sutore-marketplace'),
            MerchantMeta::ACCOUNT_PHONE => __('Phone', 'sutore-marketplace'),
            MerchantMeta::ACCOUNT_CITY => __('City', 'sutore-marketplace'),
            MerchantMeta::ACCOUNT_STATE => __('District', 'sutore-marketplace'),
            MerchantMeta::ACCOUNT_IBAN => __('IBAN', 'sutore-marketplace'),
            MerchantMeta::ACCOUNT_TCKNO => __('National ID', 'sutore-marketplace'),
            MerchantMeta::ACCOUNT_BIRTH_YEAR => __('Birth year', 'sutore-marketplace'),
        ] as $key => $label) {
            $value = (string) ($profile[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $group['data'][] = [
                'name' => $label,
                'value' => $value,
            ];
        }

        $tckno = (string) get_user_meta($userId, CheckoutIdentityHooks::USER_META_TCKNO, true);
        if ($tckno !== '') {
            $group['data'][] = [
                'name' => __('Checkout national ID', 'sutore-marketplace'),
                'value' => $tckno,
            ];
        }

        return [
            'data' => $group['data'] === [] ? [] : [$group],
            'done' => true,
        ];
    }

    /**
     * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [],
                'done' => true,
            ];
        }

        $userId = (int) $user->ID;
        global $wpdb;
        $table = Schema::table('merchant_profiles');
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update(
            $table,
            [
                'account_name' => '',
                'account_lastname' => '',
                'account_iban' => '',
                'account_tckno' => '',
                'account_birth_year' => '',
                'account_email' => '',
                'account_phone' => '',
                'account_city' => '',
                'account_state' => '',
                'tckno_verified' => 0,
                'tckno_verified_at' => 0,
                'tckno_verify_method' => '',
                'marketing_consent' => 0,
                'updated_at' => current_time('mysql'),
            ],
            ['user_id' => $userId]
        );

        delete_user_meta($userId, CheckoutIdentityHooks::USER_META_TCKNO);
        delete_user_meta($userId, CheckoutIdentityHooks::USER_META_BIRTH_YEAR);
        delete_user_meta($userId, YouthDiscount::USER_META_FINGERPRINT);
        delete_user_meta($userId, YouthDiscount::USER_META_ELIGIBLE);
        delete_user_meta($userId, YouthDiscount::USER_META_VERIFIED_AT);

        return [
            'items_removed' => true,
            'items_retained' => true,
            'messages' => [
                __('Marketplace profile contact fields were cleared. Order and payout accounting rows are retained.', 'sutore-marketplace'),
            ],
            'done' => true,
        ];
    }
}
