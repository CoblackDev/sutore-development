<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = [
    'sutore_marketplace_merchant_notifications',
    'sutore_marketplace_merchant_payout_lines',
    'sutore_marketplace_merchant_profiles',
    'sutore_marketplace_campaign_offers',
    'sutore_marketplace_campaigns',
    'sutore_marketplace_outlet_optins',
    'sutore_marketplace_outlet_items',
    'sutore_marketplace_outlet_windows',
    'sutore_marketplace_customer_offers',
    'sutore_marketplace_listings',
    'sutore_marketplace_listing_conditions',
    'sutore_marketplace_sourcing_requests',
    'sutore_marketplace_listing_events',
    'sutore_marketplace_merchant_restrictions',
    'sutore_marketplace_task_definitions',
    'sutore_marketplace_merchant_task_progress',
    'sutore_marketplace_merchant_rewards',
    'sutore_marketplace_invoices',
];

foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
}

delete_option('sutore_marketplace_settings');
delete_option('sutore_marketplace_db_version');
delete_option('sutore_marketplace_parasut_tokens');
