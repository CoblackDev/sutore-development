<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Default uninstall keeps marketplace tables and options (financial/audit trail).
 * Define SUTORE_MARKETPLACE_PURGE_ON_UNINSTALL as true in wp-config.php before
 * deleting the plugin to drop custom tables and options.
 *
 * Even with purge enabled, the invoices table is retained (legal e-Arşiv retention).
 */
if (!defined('SUTORE_MARKETPLACE_PURGE_ON_UNINSTALL') || !SUTORE_MARKETPLACE_PURGE_ON_UNINSTALL) {
    return;
}

if (!defined('SUTORE_MARKETPLACE_PATH')) {
    define('SUTORE_MARKETPLACE_PATH', plugin_dir_path(__FILE__));
}

require_once SUTORE_MARKETPLACE_PATH . 'includes/Bootstrap/class-autoloader.php';
SutoreMarketplace\Bootstrap\Autoloader::register();

global $wpdb;

SutoreMarketplace\Shared\Hooks\CronRegistry::unscheduleAll();

/** @var list<string> $retainSuffixes Tables kept on purge for legal / audit retention. */
$retainSuffixes = ['invoices'];

foreach (SutoreMarketplace\Shared\Database\Schema::tableSuffixes() as $suffix) {
    if (in_array($suffix, $retainSuffixes, true)) {
        continue;
    }
    $table = SutoreMarketplace\Shared\Database\Schema::table($suffix);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- controlled Schema suffix list.
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

$options = [
    SutoreMarketplace\Shared\Settings\Settings::OPTION_KEY,
    SutoreMarketplace\Modules\Orders\Settings\Settings::OPTION,
    'sutore_marketplace_db_version',
    'sutore_marketplace_parasut_tokens',
    'sutore_marketplace_pre_order_digest_sent_ids',
    'sutore_marketplace_sourcing_digest_sent_ids',
    'sutore_marketplace_behavior_monthly_run',
];

foreach ($options as $option) {
    delete_option($option);
}

// Endpoint flush flags and any other sutore_marketplace_* options / transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('sutore_marketplace_') . '%',
        $wpdb->esc_like('_transient_sutore_marketplace_') . '%'
    )
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_timeout_sutore_marketplace_') . '%'
    )
);

remove_role('merchant');

foreach (['administrator', 'shop_manager'] as $roleName) {
    $role = get_role($roleName);
    if (!$role) {
        continue;
    }
    $role->remove_cap(SutoreMarketplace\Admin\StaffCapabilities::MANAGE_OPS);
    $role->remove_cap(SutoreMarketplace\Admin\StaffCapabilities::MANAGE_SETTINGS);
    $role->remove_cap(SutoreMarketplace\Admin\StaffCapabilities::MANAGE_PAYOUTS);
    $role->remove_cap(SutoreMarketplace\Modules\Listings\Domain\ListingCapabilities::MANAGE_OWN);
}

$userMetaKeys = [
    SutoreMarketplace\Shared\Hooks\CheckoutIdentityHooks::USER_META_TCKNO,
    SutoreMarketplace\Shared\Hooks\CheckoutIdentityHooks::USER_META_BIRTH_YEAR,
    SutoreMarketplace\Shared\Services\YouthDiscount::USER_META_FINGERPRINT,
    SutoreMarketplace\Shared\Services\YouthDiscount::USER_META_ELIGIBLE,
    SutoreMarketplace\Shared\Services\YouthDiscount::USER_META_VERIFIED_AT,
];

foreach ($userMetaKeys as $metaKey) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete($wpdb->usermeta, ['meta_key' => $metaKey]);
}

flush_rewrite_rules(false);
