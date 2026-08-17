<?php

/**
 * Full listing → sale → fulfillment lifecycle demo (activity log verification).
 *
 * Usage:
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-lifecycle-demo.php
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-lifecycle-demo.php --force
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

use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingActivityPresenter;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Modules\Orders\Services\PaymentHandler;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

// Since the fulfillments table has been eliminated, every "fulfillment id" in
// this seed is really the listing variation id: the FulfillmentRepository is a
// facade over the listings table and hydrates rows with id = variation_id.

$force = in_array('--force', array_slice($argv, 1), true);

const SEED_META = '_sutore_marketplace_lifecycle_demo';
const PASSWORD = 'password123';
const SIZE_SLUG = 'lifecycle-43';
const TRACKING_SELLER = '123456789012';
const TRACKING_SUTORE = '987654321098';

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
    return seed_catalog_ensure_size_term(SIZE_SLUG, '43 (Lifecycle)');
}

function purge_previous_lifecycle_demo(): void
{
    global $wpdb;

    $state = get_option('sutore_lifecycle_demo_state');
    if (is_array($state)) {
        $variationId = (int) ($state['variation_id'] ?? 0);
        $orderId = (int) ($state['order_id'] ?? 0);
        $parentId = (int) ($state['parent_product_id'] ?? 0);

        if ($variationId > 0) {
            (new ListingEventsRepository())->deleteForListing($variationId);
            $wpdb->delete(Schema::table('listing_conditions'), ['variation_id' => $variationId]);
            $wpdb->delete(Schema::table('listings'), ['variation_id' => $variationId]);
            seed_log('Purged listing #' . $variationId . ' (sale data lived on the same row)');
        }

        if ($orderId > 0) {
            $order = wc_get_order($orderId);
            if ($order) {
                $order->delete(true);
                seed_log('Purged order #' . $orderId);
            }
        }

        if ($variationId > 0) {
            wp_delete_post($variationId, true);
        }

        if ($parentId > 0) {
            $product = wc_get_product($parentId);
            if ($product && $product->is_type('variable')) {
                foreach ($product->get_children() as $childId) {
                    wp_delete_post((int) $childId, true);
                }
            }
            wp_delete_post($parentId, true);
            seed_log('Purged parent product #' . $parentId);
        }
    }

    delete_option('sutore_marketplace_lifecycle_demo_parent_id');
    delete_option('sutore_lifecycle_demo_state');
}

function ensure_parent_product(WP_Term $sizeTerm): int
{
    $parentId = seed_catalog_create_variable_parent(
        'Lifecycle Demo Air Jordan 1',
        'LIFE-DEMO-001',
        seed_catalog_primary_taxonomy(),
        [$sizeTerm],
        SEED_META
    );
    update_option('sutore_marketplace_lifecycle_demo_parent_id', $parentId, false);

    return $parentId;
}

function print_activity(int $variationId): void
{
    $presenter = new ListingActivityPresenter();
    $merchantRows = $presenter->present($variationId, 'merchant_visible');
    $allRows = $presenter->present($variationId, null);

    seed_log('');
    seed_log('=== ACTIVITY LOG (merchant_visible) ===');
    foreach ($merchantRows as $row) {
        seed_log(sprintf(
            '  [%s] %s (%s) | %s | actor=%s',
            $row['date'],
            $row['event_label'],
            $row['event_type'],
            $row['summary'],
            $row['actor']
        ));
    }

    seed_log('');
    seed_log('=== ACTIVITY LOG (all, incl. admin_only / system_internal) ===');
    foreach ($allRows as $row) {
        seed_log(sprintf(
            '  [%s] %s (%s) | %s',
            $row['date'],
            $row['event_label'],
            $row['event_type'],
            $row['summary']
        ));
    }

    $merchantTypes = array_column($merchantRows, 'event_type');
    $expected = [
        'listing_created',
        'listing_went_on_sale',
        'listing_approved',
        'listing_price_changed',
        'listing_condition_changed',
        'listing_shipping_changed',
        'listing_sold',
        'order_listing_attached',
        'fulfillment_sold',
        'fulfillment_seller_confirmed',
        'fulfillment_shipped_to_sutore',
        'fulfillment_arrived_at_sutore',
        'fulfillment_verified',
        'fulfillment_ready_to_ship',
        'fulfillment_shipped',
        'fulfillment_payout_paid',
        'fulfillment_delivered_to_customer',
        'listing_lifecycle_completed',
    ];
    $missing = array_values(array_diff($expected, $merchantTypes));
    $forbidden = array_values(array_intersect($merchantTypes, [
        'listing_updated',
        'fulfillment_status_changed',
        'fulfillment_completed',
        'listing_released_from_order',
        'fulfillment_split',
        'fulfillment_swap',
        'selector_rerun',
    ]));

    seed_log('');
    if ($forbidden !== []) {
        seed_log('Lifecycle check: removed event types found (should be zero): ' . implode(', ', $forbidden));
    }
    if ($missing === []) {
        seed_log('Lifecycle check: all expected merchant-visible steps present.');
    } else {
        seed_log('Lifecycle check: missing merchant-visible steps: ' . implode(', ', $missing));
    }
}

try {
    ensure_merchant_role();
    purge_previous_lifecycle_demo();

    OrderSettings::update([
        'require_admin_payment_confirm' => false,
        'sms_enabled' => false,
    ]);
    seed_log('Settings: require_admin_payment_confirm=off, sms=off');

    $size = ensure_size_term();
    seed_log('Size term: #' . $size->term_id . ' (' . $size->name . ')');

    $merchantId = upsert_user('demo_seller_accept', 'demo_seller_accept@example.com', 'Demo Seller Accept', 'merchant');
    $customerId = upsert_user('demo_customer', 'demo_customer@example.com', 'Demo Customer', 'customer');
    $adminId = (int) get_user_by('login', 'admin')?->ID;
    if ($adminId <= 0) {
        throw new RuntimeException('admin user missing');
    }
    seed_log('Users: merchant=#' . $merchantId . ' customer=#' . $customerId . ' admin=#' . $adminId);

    $parentId = ensure_parent_product($size);
    seed_log('Parent product: #' . $parentId);

    wp_set_current_user($merchantId);
    $listingService = new ListingService();
    $listing = $listingService->create([
        'parent_product_id' => $parentId,
        'size_term_id' => (int) $size->term_id,
        'asking' => 3200,
        'fast_shipment' => 0,
        'has_invoice' => 0,
        'no_box' => 0,
        'box_damaged' => 0,
        'missing_accessory' => 0,
        'damaged' => 0,
    ], $merchantId);

    if (is_wp_error($listing)) {
        throw new RuntimeException('Listing create failed: ' . $listing->get_error_message());
    }

    $variationId = (int) $listing->variationId;
    update_post_meta($variationId, SEED_META, '1');
    seed_log('Step 1: listing created #' . $variationId . ' status=' . $listing->listingStatus . ' winner=' . ($listing->isWinner ? 'yes' : 'no'));

    $priceUpdate = $listingService->update($variationId, ['asking' => 3100], $merchantId);
    if (is_wp_error($priceUpdate)) {
        throw new RuntimeException('Price update failed: ' . $priceUpdate->get_error_message());
    }
    seed_log('Step 3: price updated 3200 → 3100');

    $conditionUpdate = $listingService->update($variationId, [
        'conditions' => ['damaged' => 1],
    ], $merchantId);
    if (is_wp_error($conditionUpdate)) {
        throw new RuntimeException('Condition update failed: ' . $conditionUpdate->get_error_message());
    }
    seed_log('Step 4: condition updated (damaged)');

    $shippingUpdate = $listingService->update($variationId, [
        'has_invoice' => 1,
    ], $merchantId);
    if (is_wp_error($shippingUpdate)) {
        throw new RuntimeException('Shipping update failed: ' . $shippingUpdate->get_error_message());
    }
    seed_log('Step 5: shipping updated (international invoice on)');

    $order = wc_create_order(['customer_id' => $customerId]);
    $order->set_billing_first_name('Demo');
    $order->set_billing_last_name('Customer');
    $order->set_billing_email('demo_customer@example.com');
    $order->set_billing_phone('5551112233');
    $order->set_billing_address_1('Demo Street 1');
    $order->set_billing_city('Istanbul');
    $order->set_billing_country('TR');
    $order->set_payment_method('cod');
    $order->set_payment_method_title('Cash on delivery (demo lifecycle)');
    $order->update_meta_data(ShipmentMeta::TYPE, 'standard');
    $order->update_meta_data(SEED_META, '1');
    $order->add_product(wc_get_product($variationId), 1);
    $order->calculate_totals();
    $order->save();
    $order->update_status('processing', 'Lifecycle demo seed order');
    (new PaymentHandler())->onPaymentComplete((int) $order->get_id());
    seed_log('Step 6: order #' . $order->get_id() . ' paid → sale started');

    $fulfillmentRepo = new FulfillmentRepository();
    $fulfillment = $fulfillmentRepo->findActiveByVariationId($variationId)
        ?: $fulfillmentRepo->findByVariationId($variationId);
    if (!$fulfillment) {
        throw new RuntimeException('Sale row not found for listing #' . $variationId);
    }
    // Post-refactor: id = variation_id (fulfillments table gone).
    $fulfillmentId = (int) $fulfillment->id;
    seed_log('Step 7: sale row (listing #' . $fulfillmentId . ') status=' . $fulfillment->fulfillment_status);

    wp_set_current_user($merchantId);
    $fs = new FulfillmentService();
    $confirm = $fs->merchantConfirmSale($fulfillmentId, $merchantId);
    if (is_wp_error($confirm)) {
        throw new RuntimeException('merchantConfirmSale: ' . $confirm->get_error_message());
    }
    seed_log('Step 8: merchant confirmed sale');

    $ship = $fs->merchantSubmitShipment($fulfillmentId, $merchantId, TRACKING_SELLER);
    if (is_wp_error($ship)) {
        throw new RuntimeException('merchantSubmitShipment: ' . $ship->get_error_message());
    }
    seed_log('Step 9: merchant shipped to Sutore (tracking ' . TRACKING_SELLER . ')');

    wp_set_current_user($adminId);
    $arrived = $fs->markArrivedAtSutore($fulfillmentId);
    if (is_wp_error($arrived)) {
        throw new RuntimeException('markArrivedAtSutore: ' . $arrived->get_error_message());
    }
    seed_log('Step admin: arrived at Sutore');

    $verified = $fs->markVerified($fulfillmentId);
    if (is_wp_error($verified)) {
        throw new RuntimeException('markVerified: ' . $verified->get_error_message());
    }
    seed_log('Step admin: verified at Sutore');

    $ready = $fs->markReadyToShip($fulfillmentId);
    if (is_wp_error($ready)) {
        throw new RuntimeException('markReadyToShip: ' . $ready->get_error_message());
    }
    seed_log('Step admin: ready to ship to customer');

    $shipCustomer = $fs->markShippedToCustomer($fulfillmentId, [
        'sutore_shipment_code' => TRACKING_SUTORE,
    ]);
    if (is_wp_error($shipCustomer)) {
        throw new RuntimeException('markShippedToCustomer: ' . $shipCustomer->get_error_message());
    }
    seed_log('Step 10: shipped to customer (Sutore tracking ' . TRACKING_SUTORE . ')');

    $payout = $fs->markMerchantPayout($fulfillmentId, 'LIFE-DEMO-EFT-001');
    if (is_wp_error($payout)) {
        throw new RuntimeException('markMerchantPayout: ' . $payout->get_error_message());
    }
    seed_log('Step 11: merchant payout marked paid');

    $delivered = $fs->markDeliveredToCustomer($fulfillmentId);
    if (is_wp_error($delivered)) {
        throw new RuntimeException('markDeliveredToCustomer: ' . $delivered->get_error_message());
    }
    seed_log('Step 12: delivered to customer (sale complete; return window informational)');

    $finalFulfillment = $fulfillmentRepo->find($fulfillmentId);
    $finalListing = (new ListingRepository())->find($variationId);

    update_option('sutore_lifecycle_demo_state', [
        'created_at' => current_time('mysql'),
        'merchant_user' => 'demo_seller_accept',
        'customer_user' => 'demo_customer',
        'admin_user' => 'admin',
        'password' => PASSWORD,
        'parent_product_id' => $parentId,
        'variation_id' => $variationId,
        'order_id' => (int) $order->get_id(),
        'listing_status' => $finalListing?->listingStatus,
        'fulfillment_status' => $finalFulfillment?->fulfillment_status,
    ], false);

    $account = wc_get_page_permalink('myaccount') ?: home_url('/my-account/');
    $listingUrl = trailingslashit($account) . 'listings/' . $variationId . '/';

    seed_log('');
    seed_log('=== LIFECYCLE DEMO COMPLETE ===');
    seed_log('Listing #' . $variationId . ' (also acts as fulfillment id) | Order #' . $order->get_id());
    seed_log('Final listing status: ' . ($finalListing?->listingStatus ?? '—'));
    seed_log('Final fulfillment status (mirror of listing_status): ' . ($finalFulfillment?->fulfillment_status ?? '—'));
    seed_log('');
    seed_log('Logins (password: ' . PASSWORD . ')');
    seed_log('  Merchant : demo_seller_accept');
    seed_log('  Customer : demo_customer');
    seed_log('  Admin    : admin');
    seed_log('');
    seed_log('Pages:');
    seed_log('  Listing detail (activity log): ' . $listingUrl);
    seed_log('  Merchant listings list       : ' . trailingslashit($account) . 'listings/');
    seed_log('  Staff Manage Products : ' . (function_exists('wc_get_account_endpoint_url')
        ? add_query_arg('variation_id', $variationId, wc_get_account_endpoint_url('manage-products'))
        : '(My Account → Manage Products)'));
    seed_log('  WC order                     : ' . admin_url('post.php?post=' . $order->get_id() . '&action=edit'));

    print_activity($variationId);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
