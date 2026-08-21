<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Shared\Settings\Settings;

/**
 * Registers marketplace options with the WordPress Settings API.
 */
final class SettingsApi
{
    public const GROUP_GENERAL = 'sutore_marketplace';

    public const GROUP_ORDERS = 'sutore_marketplace_orders';

    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerSettings(): void
    {
        register_setting(self::GROUP_GENERAL, Settings::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [SettingsSanitizer::class, 'sanitizeGeneral'],
            'default' => Settings::defaults(),
            'show_in_rest' => false,
            'capability' => StaffCapabilities::MANAGE_SETTINGS,
        ]);

        register_setting(self::GROUP_ORDERS, OrderSettings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [SettingsSanitizer::class, 'sanitizeOrders'],
            'default' => [],
            'show_in_rest' => false,
            'capability' => StaffCapabilities::MANAGE_SETTINGS,
        ]);
    }
}
