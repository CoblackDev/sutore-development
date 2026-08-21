<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Default uninstall keeps marketplace tables and options (financial/audit trail).
 * Define SUTORE_MARKETPLACE_PURGE_ON_UNINSTALL as true in wp-config.php before
 * deleting the plugin to drop custom tables and options.
 */
if (!defined('SUTORE_MARKETPLACE_PURGE_ON_UNINSTALL') || !SUTORE_MARKETPLACE_PURGE_ON_UNINSTALL) {
    return;
}

global $wpdb;

// Keep in sync with Schema::tableSuffixes().
$suffixes = [
    'merchant_profiles',
    'campaigns',
    'campaign_offers',
    'listings',
    'listing_conditions',
    'listing_events',
    'merchant_restrictions',
    'task_definitions',
    'merchant_task_progress',
    'merchant_rewards',
    'merchant_payout_lines',
    'merchant_notifications',
    'merchant_events',
    'merchant_commission_overrides',
    'catalog_product_requests',
    'outlet_windows',
    'outlet_items',
    'outlet_optins',
    'customer_offers',
    'customer_offer_daily_counters',
    'invoices',
    'outbound_effects',
];

foreach ($suffixes as $suffix) {
    $table = $wpdb->prefix . 'sutore_marketplace_' . $suffix;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- controlled suffix list.
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

delete_option('sutore_marketplace_settings');
delete_option('sutore_marketplace_db_version');
delete_option('sutore_marketplace_parasut_tokens');
