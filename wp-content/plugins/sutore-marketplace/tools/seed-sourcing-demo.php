<?php

/**
 * Local demo seed for pre-order (sourcing) flow.
 *
 * Usage (from host):
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-sourcing-demo.php
 *
 * Optional:
 *   --minutes=5   confirm deadline minutes until suspend + open sourcing (default 5)
 *   --force       recreate demo product / listings even if previous seed exists
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

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is required.\n");
    exit(1);
}

if (!class_exists(\SutoreMarketplace\Modules\Listings\Services\ListingService::class)) {
    fwrite(STDERR, "sutore-marketplace plugin classes not loaded. Is the plugin active?\n");
    exit(1);
}

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
const PASSWORD = 'SutoreDemo123!';

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

function upsert_user(string $login, string $email, string $display, string $role): int
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
        'merchant_status' => MerchantLevels::VERIFIED,
        'tckno_verified' => 1,
        'tckno_verified_at' => time(),
        'tckno_verify_method' => 'seed',
    ]);
    update_user_meta($userId, SEED_META, '1');

    return $userId;
}

function ensure_size_term(): WP_Term
{
    $taxonomy = 'pa_beden-numara';
    if (!taxonomy_exists($taxonomy)) {
        register_taxonomy($taxonomy, 'product', [
            'label' => 'Size',
            'hierarchical' => false,
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => false,
        ]);
    }

    if (!function_exists('wc_create_attribute')) {
        throw new RuntimeException('wc_create_attribute missing');
    }

    $attrs = wc_get_attribute_taxonomies();
    $has = false;
    foreach ($attrs as $attr) {
        if ($attr->attribute_name === 'beden-numara') {
            $has = true;
            break;
        }
    }
    if (!$has) {
        wc_create_attribute([
            'name' => 'Beden / Numara',
            'slug' => 'beden-numara',
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ]);
        delete_transient('wc_attribute_taxonomies');
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'product', [
                'label' => 'Size',
                'hierarchical' => false,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ]);
        }
    }

    $term = term_exists('42', $taxonomy);
    if (!$term) {
        $created = wp_insert_term('42', $taxonomy, ['slug' => '42']);
        if (is_wp_error($created)) {
            throw new RuntimeException('Size term failed: ' . $created->get_error_message());
        }
        $termId = (int) $created['term_id'];
    } else {
        $termId = (int) (is_array($term) ? $term['term_id'] : $term);
    }

    $obj = get_term($termId, $taxonomy);
    if (!$obj || is_wp_error($obj)) {
        throw new RuntimeException('Size term missing after create');
    }

    return $obj;
}

function ensure_parent_product(WP_Term $sizeTerm, bool $force): int
{
    $existingId = (int) get_option('sutore_marketplace_sourcing_demo_parent_id', 0);
    if ($existingId && get_post($existingId) && !$force) {
        return $existingId;
    }

    $product = new WC_Product_Variable();
    $product->set_name('Sourcing Demo Nike AF1');
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description('Local demo product for pre-order / sourcing flow tests.');
    $product->set_sku('SOURCING-DEMO-' . wp_generate_password(6, false));
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');

    $attribute = new WC_Product_Attribute();
    $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_beden-numara'));
    $attribute->set_name('pa_beden-numara');
    $attribute->set_options([$sizeTerm->term_id]);
    $attribute->set_visible(true);
    $attribute->set_variation(true);
    $product->set_attributes([$attribute]);
    $product->set_sku('DEMO-AF1-42');

    $parentId = $product->save();
    if (!$parentId) {
        throw new RuntimeException('Parent product create failed');
    }

    wp_set_object_terms($parentId, [$sizeTerm->term_id], 'pa_beden-numara');
    update_post_meta($parentId, SEED_META, '1');
    update_option('sutore_marketplace_sourcing_demo_parent_id', $parentId, false);

    return (int) $parentId;
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
        'used' => 0,
    ], $merchantId);

    if (is_wp_error($result)) {
        throw new RuntimeException('Listing create failed for user ' . $merchantId . ': ' . $result->get_error_message());
    }

    update_post_meta($result->variationId, SEED_META, '1');

    return (int) $result->id;
}

try {
    ensure_merchant_role();

    // Fast path into sold + auto open sourcing on not_sale.
    OrderSettings::update([
        'require_admin_payment_confirm' => false,
        'auto_sourcing_on_suspend' => true,
        'auto_sourcing_on_split' => true,
        'sms_enabled' => false,
        'confirm_deadline_hours' => 24,
        'confirm_grace_hours' => 24,
    ]);
    seed_log('Settings: require_admin_payment_confirm=off, auto_sourcing_on_suspend=on, sms=off');

    $size = ensure_size_term();
    seed_log('Size term: #' . $size->term_id . ' (' . $size->name . ')');

    $sellerId = upsert_user('demo_seller_fail', 'demo_seller_fail@example.com', 'Demo Seller Fail', 'merchant');
    $acceptorId = upsert_user('demo_seller_accept', 'demo_seller_accept@example.com', 'Demo Seller Accept', 'merchant');
    $customerId = upsert_user('demo_customer', 'demo_customer@example.com', 'Demo Customer', 'customer');
    seed_log('Users ready: seller_fail=#' . $sellerId . ' acceptor=#' . $acceptorId . ' customer=#' . $customerId);

    $parentId = ensure_parent_product($size, $force);
    seed_log('Parent product: #' . $parentId);

    // Clean previous open demo fulfillments / sourcing if force.
    if ($force) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}sutore_marketplace_sourcing_requests WHERE notes LIKE 'SOURCING-DEMO%'");
    }

    $repo = new ListingRepository();
    $existingSellerListings = $repo->query([
        'merchant_id' => $sellerId,
        'parent_product_id' => $parentId,
        'size_term_id' => (int) $size->term_id,
        'per_page' => 5,
    ]);
    $existingAcceptorListings = $repo->query([
        'merchant_id' => $acceptorId,
        'parent_product_id' => $parentId,
        'size_term_id' => (int) $size->term_id,
        'per_page' => 5,
    ]);

    if ($force || !$existingSellerListings['items']) {
        $sellerListingId = create_listing_for($sellerId, $parentId, (int) $size->term_id, 2500);
    } else {
        $sellerListingId = (int) $existingSellerListings['items'][0]->id;
    }

    if ($force || !$existingAcceptorListings['items']) {
        $acceptorListingId = create_listing_for($acceptorId, $parentId, (int) $size->term_id, 2600);
    } else {
        $acceptorListingId = (int) $existingAcceptorListings['items'][0]->id;
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

    seed_log('Listings: seller=#' . $sellerListingId . ' (active/winner) acceptor=#' . $acceptorListingId . ' (queued)');

    // Create paid order for winner variation.
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
    // Post-refactor: sale row IS the listing row; findActiveByListingId returns
    // the listing shaped like a fulfillment (id = listing_id).
    $fulfillment = $fulfillmentRepo->findActiveByListingId($sellerListingId)
        ?: $fulfillmentRepo->findByListingId($sellerListingId);
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
        'listing_id' => (int) $fulfillment->id,
        'parent_product_id' => $parentId,
        'size_term_id' => (int) $size->term_id,
        'seller_user_id' => $sellerId,
        'acceptor_user_id' => $acceptorId,
        'customer_user_id' => $customerId,
        'seller_listing_id' => $sellerListingId,
        'acceptor_listing_id' => $acceptorListingId,
        'seller_variation_id' => (int) $sellerListing->variationId,
        'acceptor_variation_id' => (int) $acceptorListing->variationId,
    ], false);

    $base = home_url('/');
    $account = wc_get_page_permalink('myaccount') ?: home_url('/my-account/');

    seed_log('');
    seed_log('=== SOURCING DEMO READY ===');
    seed_log('Site: ' . $base);
    seed_log('Order: #' . $order->get_id());
    seed_log('Listing (sale row): #' . $fulfillment->id . ' (sold)');
    seed_log('Confirm deadline: ' . $deadlineLocal . ' (~' . $minutes . ' min)');
    seed_log('NOTE: reminder phase skipped (confirm_notice_sent=1) so first deadline hit opens pre-order.');
    seed_log('');
    seed_log('Logins (password for all: ' . PASSWORD . ')');
    seed_log('  Failing seller : demo_seller_fail');
    seed_log('  Accepting seller: demo_seller_accept');
    seed_log('  Customer       : demo_customer');
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
    seed_log('After ~' . $minutes . ' minutes:');
    seed_log('  1) WP-Cron will run deadline check (or force it):');
    seed_log('     docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/run-fulfillment-deadlines.php');
    seed_log('  2) Check Admin → Pre-order for an open request');
    seed_log('  3) Login as demo_seller_accept → My Account → Pre-order → Accept');
    seed_log('  4) Admin → Pre-order → Complete (fulfilled)');
    seed_log('  5) Order Flow should show new fulfillment for acceptor listing');
    seed_log('');
    seed_log('Force deadline NOW (do not wait):');
    seed_log('  docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/run-fulfillment-deadlines.php --expire-now');
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
