<?php

/**
 * Local demo seed for pre-order (sourcing) flow.
 *
 * Usage (from host):
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-sourcing-demo.php
 *
 * Optional:
 *   --minutes=5   confirm deadline minutes until auto-open (confirm_deadline) (default 5)
 *   --force       recreate demo product / listings even if previous seed exists
 *
 * Seeds:
 *   - staff-opened board item (immediate; Confirmed sellers can see it)
 *   - sold listing that auto-opens after --minutes (or --expire-now)
 *   - acceptor listing with a different asking (accept equalizes price)
 *   - demo_seller_normal (no Pre-order menu — Confirmed+ required)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = '/var/www/html';
if (!is_file($root . '/wp-load.php')) {
    $root = dirname(__DIR__, 4);
}
require $root . '/wp-load.php';

if (!defined('SUTORE_MARKETPLACE_SEEDING')) {
    define('SUTORE_MARKETPLACE_SEEDING', true);
}

require __DIR__ . '/seed-catalog-helpers.php';

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is required.\n");
    exit(1);
}

if (!class_exists(\SutoreMarketplace\Modules\Listings\Services\ListingService::class)) {
    fwrite(STDERR, "sutore-marketplace plugin classes not loaded. Is the plugin active?\n");
    exit(1);
}

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Orders\Hooks\CronHooks;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

$minutes = 5;
$force = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--minutes=')) {
        $minutes = max(1, (int) substr($arg, 10));
    }
    if ($arg === '--force') {
        $force = true;
    }
}

const SEED_META = '_sutore_marketplace_sourcing_demo';
const PASSWORD = 'password123';

function seed_log(string $msg): void
{
    echo $msg . PHP_EOL;
}

function ensure_merchant_role(): void
{
    if (!get_role('merchant')) {
        add_role('merchant', 'Merchant', [
            'read' => true,
            'edit_products' => true,
            'upload_files' => true,
        ]);
    }
}

function upsert_user(string $login, string $email, string $display, string $role, string $level = MerchantLevels::VERIFIED): int
{
    $existing = get_user_by('login', $login);
    if ($existing) {
        $userId = (int) $existing->ID;
        wp_set_password(PASSWORD, $userId);
        $u = new WP_User($userId);
        $u->set_role($role);
    } else {
        $userId = (int) wp_insert_user([
            'user_login' => $login,
            'user_pass' => PASSWORD,
            'user_email' => $email,
            'display_name' => $display,
            'role' => $role,
        ]);
        if ($userId <= 0 || is_wp_error($userId)) {
            throw new RuntimeException('Could not create user ' . $login);
        }
    }

    $verified = $level !== MerchantLevels::NORMAL;
    MerchantMeta::writeProfile($userId, [
        MerchantMeta::ACCOUNT_PHONE => '555000' . str_pad((string) ($userId % 10000), 4, '0', STR_PAD_LEFT),
        MerchantMeta::ACCOUNT_NAME => $display,
        MerchantMeta::ACCOUNT_LASTNAME => 'Demo',
        MerchantMeta::ACCOUNT_CITY => 'TR34',
        MerchantMeta::ACCOUNT_STATE => 'Kadikoy',
        MerchantMeta::ACCOUNT_IBAN => 'TR330006100519786457841326',
        MerchantMeta::ACCOUNT_EMAIL => $email,
        MerchantMeta::ACCOUNT_TCKNO => '10000000146',
        MerchantMeta::ACCOUNT_BIRTH_YEAR => '1990',
    ], [
        'merchant_status' => $level,
        'tckno_verified' => $verified ? 1 : 0,
        'tckno_verified_at' => $verified ? time() : 0,
        'tckno_verify_method' => $verified ? 'seed' : '',
    ]);
    update_user_meta($userId, SEED_META, '1');

    return $userId;
}

function ensure_size_term(): WP_Term
{
    return seed_catalog_ensure_size_term('42', '42');
}

/**
 * @param list<WP_Term> $sizeTerms
 */
function ensure_parent_product(array $sizeTerms, bool $force): int
{
    $existingId = (int) get_option('sutore_marketplace_sourcing_demo_parent_id', 0);
    if ($existingId && get_post($existingId) && !$force) {
        $hasAll = true;
        $taxonomy = seed_catalog_primary_taxonomy();
        foreach ($sizeTerms as $term) {
            if (!has_term((int) $term->term_id, $taxonomy, $existingId)) {
                $hasAll = false;
                break;
            }
        }
        if ($hasAll) {
            return $existingId;
        }
    }

    $parentId = seed_catalog_create_variable_parent(
        'Sourcing Demo Nike AF1',
        'DEMO-AF1',
        seed_catalog_primary_taxonomy(),
        $sizeTerms,
        SEED_META
    );
    update_option('sutore_marketplace_sourcing_demo_parent_id', $parentId, false);

    return $parentId;
}

function seed_mysql_offset(int $seconds): string
{
    $base = strtotime(current_time('mysql'));
    $ts = ($base !== false ? $base : time()) + $seconds;

    return wp_date('Y-m-d H:i:s', $ts);
}

function create_listing_for(int $merchantId, int $parentId, int $sizeTermId, int $asking): int
{
    wp_set_current_user($merchantId);
    $service = new ListingService();
    $result = $service->create([
        'parent_product_id' => $parentId,
        'size_term_id' => $sizeTermId,
        'asking' => $asking,
        'fast_shipment' => 0,
        'has_invoice' => 0,
        'no_box' => 0,
        'box_damaged' => 0,
        'missing_accessory' => 0,
        'damaged' => 0,
    ], $merchantId);

    if (is_wp_error($result)) {
        throw new RuntimeException('Listing create failed for user ' . $merchantId . ': ' . $result->get_error_message());
    }

    update_post_meta($result->variationId, SEED_META, '1');

    return (int) $result->variationId;
}

try {
    ensure_merchant_role();

    // Fast path into sold + pre-order on confirm deadline.
    OrderSettings::update([
        'require_admin_payment_confirm' => false,
        'sms_enabled' => false,
        'confirm_deadline_hours' => 24,
        'confirm_grace_hours' => 24,
    ]);
    seed_log('Settings: require_admin_payment_confirm=off, sms=off');

    $size42 = ensure_size_term();
    $size43 = seed_catalog_ensure_size_term('43', '43');
    seed_log('Size terms: #' . $size42->term_id . ' (42), #' . $size43->term_id . ' (43)');

    $sellerId = upsert_user('demo_seller_fail', 'demo_seller_fail@example.com', 'Demo Seller Fail', 'merchant');
    $acceptorId = upsert_user('demo_seller_accept', 'demo_seller_accept@example.com', 'Demo Seller Accept', 'merchant');
    $normalId = upsert_user(
        'demo_seller_sourcing_normal',
        'demo_seller_sourcing_normal@example.com',
        'Demo Seller Sourcing Normal',
        'merchant',
        MerchantLevels::NORMAL
    );
    $customerId = upsert_user('demo_customer', 'demo_customer@example.com', 'Demo Customer', 'customer');
    seed_log('Users ready: seller_fail=#' . $sellerId . ' acceptor=#' . $acceptorId
        . ' normal=#' . $normalId . ' customer=#' . $customerId);

    $parentId = ensure_parent_product([$size42, $size43], $force);
    seed_log('Parent product: #' . $parentId);

    // Clean previous demo pre-order listings if force.
    if ($force) {
        global $wpdb;
        $listingsTable = $wpdb->prefix . 'sutore_marketplace_listings';
        $wpdb->query("DELETE FROM {$listingsTable} WHERE listing_status = 'pre_order'");
    }

    $repo = new ListingRepository();
    $existingSellerListings = $repo->query([
        'merchant_id' => $sellerId,
        'parent_product_id' => $parentId,
        'size_term_id' => (int) $size42->term_id,
        'per_page' => 5,
    ]);
    $existingAcceptorListings = $repo->query([
        'merchant_id' => $acceptorId,
        'parent_product_id' => $parentId,
        'size_term_id' => (int) $size42->term_id,
        'per_page' => 5,
    ]);

    if ($force || !$existingSellerListings['items']) {
        $sellerListingId = create_listing_for($sellerId, $parentId, (int) $size42->term_id, 2500);
    } else {
        $sellerListingId = (int) $existingSellerListings['items'][0]->variationId;
    }

    if ($force || !$existingAcceptorListings['items']) {
        $acceptorListingId = create_listing_for($acceptorId, $parentId, (int) $size42->term_id, 2600);
    } else {
        $acceptorListingId = (int) $existingAcceptorListings['items'][0]->variationId;
    }

    // Make failing seller the cheaper winner / active listing.
    $sellerListing = $repo->find($sellerListingId);
    $acceptorListing = $repo->find($acceptorListingId);
    if (!$sellerListing || !$acceptorListing) {
        throw new RuntimeException('Listings missing after create');
    }

    $repo->update($sellerListingId, [
        'asking' => 2500,
        'listing_status' => 'publish',
        'is_winner' => 1,
    ]);
    $repo->update($acceptorListingId, [
        'asking' => 2600,
        'listing_status' => 'queued',
        'is_winner' => 0,
    ]);

    $sellerProduct = wc_get_product($sellerListing->variationId);
    if ($sellerProduct) {
        $sellerProduct->set_status('publish');
        $sellerProduct->set_stock_status('instock');
        $sellerProduct->set_stock_quantity(1);
        $sellerProduct->set_regular_price('2500');
        $sellerProduct->set_price('2500');
        $sellerProduct->save();
    }
    $acceptorProduct = wc_get_product($acceptorListing->variationId);
    if ($acceptorProduct) {
        $acceptorProduct->set_status('draft');
        $acceptorProduct->set_stock_status('outofstock');
        $acceptorProduct->set_regular_price('2600');
        $acceptorProduct->set_price('2600');
        $acceptorProduct->save();
    }

    seed_log('Listings (auto-open size 42): seller=#' . $sellerListingId . ' (winner 2500) acceptor=#' . $acceptorListingId . ' (queued 2600 — accept equalizes to 2500)');

    // Staff-opened board item (size 43) — visible immediately to Confirmed sellers.
    $staffSellerId = create_listing_for($sellerId, $parentId, (int) $size43->term_id, 2400);
    $staffAcceptorId = create_listing_for($acceptorId, $parentId, (int) $size43->term_id, 2700);
    $repo->update($staffSellerId, [
        'asking' => 2400,
        'listing_status' => 'publish',
        'is_winner' => 1,
    ]);
    $repo->update($staffAcceptorId, [
        'asking' => 2700,
        'listing_status' => 'queued',
        'is_winner' => 0,
    ]);
    $staffSellerProduct = wc_get_product($staffSellerId);
    if ($staffSellerProduct) {
        $staffSellerProduct->set_status('publish');
        $staffSellerProduct->set_stock_status('instock');
        $staffSellerProduct->set_stock_quantity(1);
        $staffSellerProduct->set_regular_price('2400');
        $staffSellerProduct->set_price('2400');
        $staffSellerProduct->save();
    }

    $staffOrder = wc_create_order(['customer_id' => $customerId]);
    $staffOrder->set_billing_first_name('Demo');
    $staffOrder->set_billing_last_name('Customer');
    $staffOrder->set_billing_email('demo_customer@example.com');
    $staffOrder->set_billing_phone('5551112233');
    $staffOrder->set_billing_address_1('Demo Street 1');
    $staffOrder->set_billing_city('Istanbul');
    $staffOrder->set_billing_country('TR');
    $staffOrder->set_payment_method('cod');
    $staffOrder->set_payment_method_title('Cash on delivery (demo)');
    $staffOrder->update_meta_data(ShipmentMeta::TYPE, 'standard');
    $staffOrder->update_meta_data(SEED_META, '1');
    $staffOrder->add_product(wc_get_product($staffSellerId), 1);
    $staffOrder->calculate_totals();
    $staffOrder->save();
    $staffOrder->update_status('processing', 'Sourcing demo staff-open order');
    (new \SutoreMarketplace\Modules\Orders\Services\PaymentHandler())->onPaymentComplete((int) $staffOrder->get_id());

    $staffMarked = (new FulfillmentService())->markAsPreOrder($staffSellerId, 'staff');
    if (is_wp_error($staffMarked)) {
        throw new RuntimeException('Staff markAsPreOrder: ' . $staffMarked->get_error_message());
    }
    $repo->update($staffSellerId, [
        'created_at' => seed_mysql_offset(-26 * HOUR_IN_SECONDS),
    ]);
    $staffListing = $repo->find($staffSellerId);
    if (!$staffListing || $staffListing->listingStatus !== ListingStatus::PRE_ORDER) {
        throw new RuntimeException('Staff-opened listing did not enter pre_order.');
    }
    seed_log('Staff-opened board (size 43): #' . $staffSellerId . ' order=#' . $staffOrder->get_id()
        . ' acceptor=#' . $staffAcceptorId . ' (2700 → 2400 on accept)');

    // Create paid order for winner variation (size 42 — auto-open after deadline).
    $order = wc_create_order(['customer_id' => $customerId]);
    $order->set_billing_first_name('Demo');
    $order->set_billing_last_name('Customer');
    $order->set_billing_email('demo_customer@example.com');
    $order->set_billing_phone('5551112233');
    $order->set_billing_address_1('Demo Street 1');
    $order->set_billing_city('Istanbul');
    $order->set_billing_country('TR');
    $order->set_payment_method('cod');
    $order->set_payment_method_title('Cash on delivery (demo)');
    $order->update_meta_data(ShipmentMeta::TYPE, 'standard');
    $order->update_meta_data(SEED_META, '1');

    $itemId = $order->add_product(wc_get_product($sellerListing->variationId), 1);
    $order->calculate_totals();
    $order->save();

    // Mark processing + fire marketplace sale start.
    $order->update_status('processing', 'Sourcing demo seed order');
    (new \SutoreMarketplace\Modules\Orders\Services\PaymentHandler())->onPaymentComplete((int) $order->get_id());

    $fulfillmentRepo = new FulfillmentRepository();
    // Post-refactor: sale row IS the listing row; findActiveByVariationId returns
    // the listing shaped like a fulfillment (id = variation_id).
    $fulfillment = $fulfillmentRepo->findActiveByVariationId($sellerListingId)
        ?: $fulfillmentRepo->findByVariationId($sellerListingId);
    if (!$fulfillment) {
        throw new RuntimeException('Sale row not found for demo order #' . $order->get_id());
    }

    // Skip reminder phase: when deadline hits, suspend + open sourcing immediately.
    $deadline = gmdate('Y-m-d H:i:s', time() + ($minutes * MINUTE_IN_SECONDS));
    // Store in WP local time format used by current_time('mysql') comparisons.
    $deadlineLocal = date('Y-m-d H:i:s', current_time('timestamp') + ($minutes * MINUTE_IN_SECONDS));
    $fulfillmentRepo->update((int) $fulfillment->id, [
        'fulfillment_status' => 'sold',
        'confirm_deadline_at' => $deadlineLocal,
        'confirm_notice_sent' => 1,
        'confirm_punished' => 0,
    ]);

    // Schedule one-shot deadline runner on the real cron hook (already registered by the plugin).
    $hook = CronHooks::HOOK;
    $runAt = time() + ($minutes * MINUTE_IN_SECONDS);
    wp_schedule_single_event($runAt, $hook);

    update_option('sutore_sourcing_demo_state', [
        'created_at' => current_time('mysql'),
        'minutes' => $minutes,
        'deadline_at' => $deadlineLocal,
        'order_id' => (int) $order->get_id(),
        'variation_id' => (int) $fulfillment->id,
        'parent_product_id' => $parentId,
        'size_term_id' => (int) $size42->term_id,
        'seller_user_id' => $sellerId,
        'acceptor_user_id' => $acceptorId,
        'normal_user_id' => $normalId,
        'customer_user_id' => $customerId,
        'seller_variation_id' => (int) $sellerListing->variationId,
        'acceptor_variation_id' => (int) $acceptorListing->variationId,
        'staff_board_variation_id' => $staffSellerId,
        'staff_order_id' => (int) $staffOrder->get_id(),
        'staff_acceptor_variation_id' => $staffAcceptorId,
        'staff_size_term_id' => (int) $size43->term_id,
    ], false);

    $base = home_url('/');
    $account = wc_get_page_permalink('myaccount') ?: home_url('/my-account/');

    seed_log('');
    seed_log('=== SOURCING DEMO READY ===');
    seed_log('Site: ' . $base);
    seed_log('Staff-opened (now on board): #' . $staffSellerId . ' order=#' . $staffOrder->get_id());
    seed_log('  Accept as demo_seller_accept → price 2700 → 2400, instant swap, sourcing_fulfilled logged');
    seed_log('Auto-open (sold, waiting): #' . $fulfillment->id . ' order=#' . $order->get_id());
    seed_log('  Confirm deadline: ' . $deadlineLocal . ' (~' . $minutes . ' min)');
    seed_log('  NOTE: reminder skipped (confirm_notice_sent=1) so first deadline hit opens pre-order.');
    seed_log('');
    seed_log('Logins (password for all: ' . PASSWORD . ')');
    seed_log('  Failing seller  : demo_seller_fail');
    seed_log('  Accepting seller: demo_seller_accept  (Confirmed — sees staff-opened now)');
    seed_log('  Normal seller   : demo_seller_sourcing_normal  (no Pre-order menu)');
    seed_log('  Customer        : demo_customer');
    seed_log('');
    seed_log('Pages:');
    seed_log('  Staff Manage Products : ' . (function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('manage-products')
        : '(My Account → Manage Products)'));
    seed_log('  Merchant Pre-order    : ' . (function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('sourcing')
        : '(My Account → Pre-order)'));
    seed_log('  Merchant Pre-order: ' . trailingslashit($account) . 'sourcing/');
    seed_log('  Merchant Listings : ' . trailingslashit($account) . 'listings/');
    seed_log('');
    seed_log('Now:');
    seed_log('  1) demo_seller_accept → Pre-order → staff-opened size 43 → Accept (price change confirm)');
    seed_log('  2) demo_seller_sourcing_normal → no Pre-order menu (New seller level)');
    seed_log('');
    seed_log('After ~' . $minutes . ' minutes (auto-open size 42):');
    seed_log('  1) WP-Cron will run deadline check (or force it):');
    seed_log('     docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/run-fulfillment-deadlines.php');
    seed_log('  2) Listing moves to pre_order on the board');
    seed_log('  3) Login as demo_seller_accept → Accept (instant swap; asking 2600 → 2500)');
    seed_log('  4) Order should show acceptor listing in sold pipeline');
    seed_log('');
    seed_log('Force deadline NOW (do not wait):');
    seed_log('  docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/run-fulfillment-deadlines.php --expire-now');
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
