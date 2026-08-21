<?php

declare(strict_types=1);

/**
 * Load WordPress inside Docker so marketplace tests hit the real plugin.
 *
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tests/run.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = '/var/www/html';
if (!is_file($root . '/wp-load.php')) {
    $root = dirname(__DIR__, 4);
}
if (!is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "wp-load.php not found.\n");
    exit(1);
}

require $root . '/wp-load.php';

if (!defined('SUTORE_MARKETPLACE_PATH')) {
    fwrite(STDERR, "sutore-marketplace is not loaded.\n");
    exit(1);
}

require_once __DIR__ . '/Support/class-failed.php';
require_once __DIR__ . '/Support/class-skipped.php';
require_once __DIR__ . '/Support/class-harness.php';
require_once __DIR__ . '/Support/class-fixtures.php';

// Repeated suite runs share fixture users; clear OTP rate counters and offer daily caps.
(static function (): void {
    global $wpdb;
    $logins = [
        'st_seller_verified',
        'st_seller_normal',
        'st_seller_premium',
        'st_seller_queued',
        'st_customer',
    ];
    foreach ($logins as $login) {
        $user = get_user_by('login', $login);
        if (!$user) {
            continue;
        }
        $id = (int) $user->ID;
        delete_transient('sutore_otp_request_' . $id);
        delete_transient('sutore_otp_attempts_' . $id);
        delete_transient('sutore_otp_session_' . $id);
        $phone = \SutoreMarketplace\Modules\Otp\Services\OtpPhoneResolver::forUser($id);
        if ($phone !== '') {
            delete_transient('sutore_otp_phone_' . hash('sha256', $phone));
        }
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sutore_otp_%' OR option_name LIKE '_transient_timeout_sutore_otp_%'"
    );
    wp_cache_delete('alloptions', 'options');
    $offers = $wpdb->prefix . 'sutore_marketplace_customer_offers';
    $offerCaps = $wpdb->prefix . 'sutore_marketplace_customer_offer_daily_counters';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix only.
    $wpdb->query("DELETE FROM `{$offers}` WHERE customer_id IN (SELECT ID FROM {$wpdb->users} WHERE user_login = 'st_customer')");
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix only.
    $wpdb->query("DELETE FROM `{$offerCaps}` WHERE customer_id IN (SELECT ID FROM {$wpdb->users} WHERE user_login = 'st_customer')");
})();
