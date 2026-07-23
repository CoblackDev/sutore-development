<?php

/**
 * Full marketplace scenario seeder + purge.
 *
 * Wipes plugin domain data (listings, campaigns, sourcing, payouts, …) and
 * related WC products/orders, then seeds merchants + catalog + every major
 * My Account / staff scenario so you can click through the product.
 *
 * Usage:
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-scenarios.php --force
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-scenarios.php --purge-only
 *
 * Password for all seed users: SutoreDemo123!
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
    fwrite(STDERR, "sutore-marketplace plugin not loaded / not active.\n");
    exit(1);
}

use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\CampaignOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\CampaignStatus;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\CampaignService;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\NotificationRepository;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Services\RestrictionService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Modules\Orders\Services\PaymentHandler;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Modules\Sourcing\Repositories\SourcingRepository;
use SutoreMarketplace\Modules\Tasks\Repositories\TaskProgressRepository;
use SutoreMarketplace\Modules\Tasks\Repositories\TasksRepository;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Settings\Settings;

$argvFlags = array_slice($argv, 1);
$purgeOnly = in_array('--purge-only', $argvFlags, true);
$force = in_array('--force', $argvFlags, true) || $purgeOnly || in_array('--purge', $argvFlags, true);

if (!$force && !$purgeOnly) {
    fwrite(STDERR, "Refusing to run without --force (destructive purge + reseed).\n");
    fwrite(STDERR, "Use: …/seed-scenarios.php --force\n");
    fwrite(STDERR, "Or:  …/seed-scenarios.php --purge-only\n");
    exit(1);
}

const SEED_META = '_sutore_marketplace_scenarios_seed';
const PASSWORD = 'SutoreDemo123!';
const TRACK_SELLER = '111222333444';
const TRACK_SUTORE = '999888777666';

/** @var array<string, mixed> */
$REPORT = [
    'users' => [],
    'products' => [],
    'listings' => [],
    'orders' => [],
    'campaigns' => [],
    'sourcing' => [],
    'notes' => [],
];

function seed_log(string $msg): void
{
    echo $msg . PHP_EOL;
}

function price_step(): int
{
    $step = (int) Settings::listingPriceStep();

    return $step > 0 ? $step : 100;
}

function ask(int $units): int
{
    return max(price_step(), $units * price_step());
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

/**
 * @param array<string, mixed> $profileExtra
 */
function upsert_user(
    string $login,
    string $email,
    string $display,
    string $role,
    string $level = MerchantLevels::VERIFIED,
    array $profileExtra = []
): int {
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

    $phone = '555' . str_pad((string) ($userId % 10000000), 7, '0', STR_PAD_LEFT);
    MerchantMeta::writeProfile($userId, array_merge([
        MerchantMeta::ACCOUNT_PHONE => $phone,
        MerchantMeta::ACCOUNT_NAME => $display,
        MerchantMeta::ACCOUNT_LASTNAME => 'Seed',
        MerchantMeta::ACCOUNT_CITY => 'TR34',
        MerchantMeta::ACCOUNT_STATE => 'Kadikoy',
        MerchantMeta::ACCOUNT_IBAN => 'TR330006100519786457841326',
        MerchantMeta::ACCOUNT_EMAIL => $email,
        MerchantMeta::ACCOUNT_TCKNO => '10000000146',
        MerchantMeta::ACCOUNT_BIRTH_YEAR => '1990',
    ], $profileExtra['account'] ?? []), array_merge([
        'merchant_status' => $level,
        'tckno_verified' => $level === MerchantLevels::NORMAL ? 0 : 1,
        'tckno_verified_at' => $level === MerchantLevels::NORMAL ? 0 : time(),
        'tckno_verify_method' => $level === MerchantLevels::NORMAL ? '' : 'seed',
    ], $profileExtra['meta'] ?? []));

    update_user_meta($userId, SEED_META, '1');

    return $userId;
}

function ensure_size_term(string $slug, string $name): WP_Term
{
    $taxonomy = 'pa_beden-numara';
    if (!taxonomy_exists($taxonomy)) {
        if (function_exists('wc_create_attribute')) {
            wc_create_attribute([
                'name' => 'Beden / Numara',
                'slug' => 'beden-numara',
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);
            delete_transient('wc_attribute_taxonomies');
        }
        register_taxonomy($taxonomy, 'product', [
            'label' => 'Size',
            'hierarchical' => false,
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => false,
        ]);
    }

    $term = term_exists($slug, $taxonomy);
    if (!$term) {
        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        if (is_wp_error($created)) {
            throw new RuntimeException('Size term failed: ' . $created->get_error_message());
        }
        $termId = (int) $created['term_id'];
    } else {
        $termId = (int) (is_array($term) ? $term['term_id'] : $term);
    }

    $obj = get_term($termId, $taxonomy);
    if (!$obj || is_wp_error($obj)) {
        throw new RuntimeException('Size term missing: ' . $slug);
    }

    return $obj;
}

/**
 * @param list<WP_Term> $sizeTerms
 */
function create_parent_product(string $name, string $code, array $sizeTerms): int
{
    $product = new WC_Product_Variable();
    $product->set_name($name);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description('Scenario seed product (' . $code . ').');
    $product->set_sku('SEED-' . $code . '-' . wp_generate_password(4, false));
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');

    $ids = array_map(static fn (WP_Term $t): int => (int) $t->term_id, $sizeTerms);
    $attribute = new WC_Product_Attribute();
    $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_beden-numara'));
    $attribute->set_name('pa_beden-numara');
    $attribute->set_options($ids);
    $attribute->set_visible(true);
    $attribute->set_variation(true);
    $product->set_attributes([$attribute]);
    $parentId = (int) $product->save();
    if ($parentId <= 0) {
        throw new RuntimeException('Parent create failed: ' . $name);
    }

    wp_set_object_terms($parentId, $ids, 'pa_beden-numara');
    update_post_meta($parentId, SEED_META, '1');
    update_post_meta($parentId, 'urun_kodu', $code);
    update_post_meta($parentId, '_sutore_release_price', (string) ask(80));

    return $parentId;
}

function purge_all_marketplace(): void
{
    global $wpdb;

    seed_log('=== PURGE marketplace domain ===');

    $listingsTable = Schema::table('listings');
    $rows = $wpdb->get_results("SELECT id, variation_id, parent_product_id, order_id FROM {$listingsTable}") ?: [];
    $variationIds = [];
    $parentIds = [];
    $orderIds = [];
    foreach ($rows as $row) {
        $variationIds[] = (int) $row->variation_id;
        $parentIds[] = (int) $row->parent_product_id;
        if ((int) $row->order_id > 0) {
            $orderIds[] = (int) $row->order_id;
        }
    }

    $tables = [
        'listing_conditions',
        'listing_events',
        'campaign_offers',
        'campaigns',
        'sourcing_requests',
        'merchant_payout_lines',
        'merchant_notifications',
        'merchant_restrictions',
        'merchant_task_progress',
        'merchant_rewards',
        'merchant_commission_overrides',
        'merchant_events',
        'listings',
    ];
    foreach ($tables as $suffix) {
        $table = Schema::table($suffix);
        $deleted = (int) $wpdb->query("DELETE FROM {$table}");
        seed_log(sprintf('  cleared %s (%d rows)', $suffix, $deleted));
    }

    // Seed-tagged WC products (parents + orphans).
    $metaProducts = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
        SEED_META
    )) ?: [];
    $legacyMetaKeys = [
        '_sutore_marketplace_lifecycle_demo',
        '_sutore_marketplace_sourcing_demo',
        '_sutore_marketplace_campaign_demo',
    ];
    foreach ($legacyMetaKeys as $key) {
        $extra = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $key
        )) ?: [];
        $metaProducts = array_merge($metaProducts, $extra);
    }

    $productIds = array_values(array_unique(array_filter(array_map('intval', array_merge(
        $variationIds,
        $parentIds,
        $metaProducts
    )))));

    foreach ($productIds as $productId) {
        $product = wc_get_product($productId);
        if ($product && $product->is_type('variable')) {
            foreach ($product->get_children() as $childId) {
                wp_delete_post((int) $childId, true);
            }
        }
        wp_delete_post($productId, true);
    }
    seed_log('  deleted WC products/variations: ' . count($productIds));

    // Seed-tagged orders + listing-linked orders.
    $orderMeta = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
        SEED_META
    )) ?: [];
    foreach ($legacyMetaKeys as $key) {
        $orderMeta = array_merge($orderMeta, $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $key
        )) ?: []);
    }
    $orderIds = array_values(array_unique(array_filter(array_map('intval', array_merge($orderIds, $orderMeta)))));
    foreach ($orderIds as $orderId) {
        $order = wc_get_order($orderId);
        if ($order) {
            $order->delete(true);
        }
    }
    seed_log('  deleted WC orders: ' . count($orderIds));

    foreach ([
        'sutore_lifecycle_demo_state',
        'sutore_sourcing_demo_state',
        'sutore_campaign_demo_state',
        'sutore_marketplace_lifecycle_demo_parent_id',
        'sutore_marketplace_scenarios_seed_state',
    ] as $option) {
        delete_option($option);
    }

    seed_log('Purge complete.');
}

/**
 * @param array<string, mixed> $input
 */
function create_listing(int $merchantId, int $parentId, int $sizeTermId, int $asking, array $input = []): Listing
{
    wp_set_current_user($merchantId);
    $listing = (new ListingService())->create(array_merge([
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
    ], $input), $merchantId, ['skip_task_increment' => true]);

    if (is_wp_error($listing)) {
        throw new RuntimeException('Listing create failed: ' . $listing->get_error_message());
    }

    update_post_meta((int) $listing->variationId, SEED_META, '1');

    return $listing;
}

function create_paid_order(int $customerId, int $variationId, string $note): WC_Order
{
    $order = wc_create_order(['customer_id' => $customerId]);
    $order->set_billing_first_name('Demo');
    $order->set_billing_last_name('Customer');
    $order->set_billing_email('demo_customer@example.com');
    $order->set_billing_phone('5551112233');
    $order->set_billing_address_1('Seed Street 1');
    $order->set_billing_city('Istanbul');
    $order->set_billing_country('TR');
    $order->set_payment_method('cod');
    $order->set_payment_method_title('COD (scenario seed)');
    $order->update_meta_data(ShipmentMeta::TYPE, 'standard');
    $order->update_meta_data(SEED_META, '1');
    $product = wc_get_product($variationId);
    if (!$product) {
        throw new RuntimeException('Variation missing #' . $variationId);
    }
    $order->add_product($product, 1);
    $order->calculate_totals();
    $order->save();
    $order->update_status('processing', $note);
    (new PaymentHandler())->onPaymentComplete((int) $order->get_id());

    return $order;
}

/**
 * Advance a sale listing through FulfillmentService up to $targetStatus.
 */
function advance_sale_to(int $listingId, string $targetStatus, int $merchantId, int $adminId): void
{
    $fs = new FulfillmentService();
    $repo = new FulfillmentRepository();
    $path = [
        ListingStatus::SOLD,
        ListingStatus::CONFIRMED,
        ListingStatus::SHIPPED_TO_SUTORE,
        ListingStatus::ARRIVED_TO_SUTORE,
        ListingStatus::VERIFIED,
        ListingStatus::READY_TO_SHIPPING,
        ListingStatus::SHIPPED,
        ListingStatus::DELIVERED_TO_CUSTOMER,
    ];

    $targetIndex = array_search($targetStatus, $path, true);
    if ($targetIndex === false) {
        throw new RuntimeException('Unsupported advance target: ' . $targetStatus);
    }

    for ($i = 1; $i <= $targetIndex; $i++) {
        $step = $path[$i];
        $row = $repo->find($listingId);
        if (!$row) {
            throw new RuntimeException('Sale row missing for listing #' . $listingId);
        }
        if ((string) $row->fulfillment_status === $step) {
            continue;
        }

        if ($step === ListingStatus::CONFIRMED || $step === ListingStatus::SHIPPED_TO_SUTORE) {
            wp_set_current_user($merchantId);
        } else {
            wp_set_current_user($adminId);
        }

        if ($step === ListingStatus::CONFIRMED) {
            $result = $fs->merchantConfirmSale($listingId, $merchantId);
        } elseif ($step === ListingStatus::SHIPPED_TO_SUTORE) {
            $result = $fs->merchantSubmitShipment($listingId, $merchantId, TRACK_SELLER);
        } elseif ($step === ListingStatus::ARRIVED_TO_SUTORE) {
            $result = $fs->markArrivedAtSutore($listingId);
        } elseif ($step === ListingStatus::VERIFIED) {
            $result = $fs->markVerified($listingId);
        } elseif ($step === ListingStatus::READY_TO_SHIPPING) {
            $result = $fs->markReadyToShip($listingId);
        } elseif ($step === ListingStatus::SHIPPED) {
            $result = $fs->markShippedToCustomer($listingId, [
                'sutore_shipment_code' => TRACK_SUTORE,
            ]);
        } elseif ($step === ListingStatus::DELIVERED_TO_CUSTOMER) {
            $result = $fs->markDeliveredToCustomer($listingId);
        } else {
            $result = new WP_Error('seed', 'Unknown step');
        }

        if (is_wp_error($result)) {
            throw new RuntimeException($step . ' failed for #' . $listingId . ': ' . $result->get_error_message());
        }
    }
}

function remember_listing(string $key, Listing $listing, string $note = ''): void
{
    global $REPORT;
    $REPORT['listings'][$key] = [
        'id' => (int) $listing->id,
        'status' => $listing->listingStatus,
        'merchant_id' => (int) $listing->merchantId,
        'variation_id' => (int) $listing->variationId,
        'asking' => (int) $listing->asking,
        'note' => $note,
    ];
}

try {
    ensure_merchant_role();
    purge_all_marketplace();

    if ($purgeOnly) {
        seed_log('Purge-only complete.');
        exit(0);
    }

    seed_log('');
    seed_log('=== SEED scenarios ===');

    OrderSettings::update([
        'require_admin_payment_confirm' => false,
        'sms_enabled' => false,
    ]);

    // --- Users ---
    $adminId = (int) (get_user_by('login', 'admin')->ID ?? 0);
    if ($adminId <= 0) {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $adminId = (int) ($admins[0] ?? 0);
    }
    if ($adminId <= 0) {
        throw new RuntimeException('No administrator user found.');
    }

    $customerId = upsert_user('demo_customer', 'demo_customer@example.com', 'Demo Customer', 'customer', MerchantLevels::NORMAL);
    $sellerNormal = upsert_user('demo_seller_normal', 'demo_seller_normal@example.com', 'Seller Normal', 'merchant', MerchantLevels::NORMAL);
    $sellerVerified = upsert_user('demo_seller_verified', 'demo_seller_verified@example.com', 'Seller Confirmed', 'merchant', MerchantLevels::VERIFIED);
    $sellerPremium = upsert_user('demo_seller_premium', 'demo_seller_premium@example.com', 'Seller Premium', 'merchant', MerchantLevels::PREMIUM);
    $sellerQueued = upsert_user('demo_seller_queued', 'demo_seller_queued@example.com', 'Seller Queue', 'merchant', MerchantLevels::VERIFIED);
    $sellerBanned = upsert_user('demo_seller_banned', 'demo_seller_banned@example.com', 'Seller Banned', 'merchant', MerchantLevels::VERIFIED);
    $sellerSale = upsert_user('demo_seller_sale', 'demo_seller_sale@example.com', 'Seller Sales', 'merchant', MerchantLevels::VERIFIED);

    $REPORT['users'] = [
        'admin' => $adminId,
        'demo_customer' => $customerId,
        'demo_seller_normal' => $sellerNormal,
        'demo_seller_verified' => $sellerVerified,
        'demo_seller_premium' => $sellerPremium,
        'demo_seller_queued' => $sellerQueued,
        'demo_seller_banned' => $sellerBanned,
        'demo_seller_sale' => $sellerSale,
    ];
    seed_log('Users ready (password ' . PASSWORD . ')');

    // Restrictions on banned seller.
    wp_set_current_user($adminId);
    $restriction = (new RestrictionService())->create([
        'merchant_id' => $sellerBanned,
        'restriction_key' => 'listing_create_ban',
        'reason' => 'Scenario seed: create ban for UI testing',
        'expires_at' => '',
    ], $adminId);
    if (is_wp_error($restriction)) {
        seed_log('WARN restriction: ' . $restriction->get_error_message());
    } else {
        $REPORT['notes'][] = 'demo_seller_banned has listing_create_ban';
    }

    // --- Catalog ---
    $size42 = ensure_size_term('seed-42', '42 (Seed)');
    $size43 = ensure_size_term('seed-43', '43 (Seed)');
    $size44 = ensure_size_term('seed-44', '44 (Seed)');

    $parentMarket = create_parent_product('Seed Dunk Low Market', 'SEED-MARKET', [$size42, $size43]);
    $parentQueue = create_parent_product('Seed Jordan 1 Queue', 'SEED-QUEUE', [$size43]);
    $parentSale = create_parent_product('Seed Yeezy Sale Pipeline', 'SEED-SALE', [$size42, $size43, $size44]);
    $parentSource = create_parent_product('Seed Samba Sourcing', 'SEED-SOURCE', [$size42]);
    $parentCampaign = create_parent_product('Seed Campus Campaign', 'SEED-CAMP', [$size43]);

    $REPORT['products'] = [
        'market' => $parentMarket,
        'queue' => $parentQueue,
        'sale' => $parentSale,
        'sourcing' => $parentSource,
        'campaign' => $parentCampaign,
    ];
    seed_log('Catalog parents created');

    // --- Market listings ---
    $pending = create_listing($sellerNormal, $parentMarket, (int) $size42->term_id, ask(25));
    remember_listing('pending_approval', $pending, 'Normal seller → pending winner approval');

    $active = create_listing($sellerVerified, $parentMarket, (int) $size43->term_id, ask(30));
    remember_listing('active_for_sale', $active, 'Confirmed seller → for sale');

    $inactive = create_listing($sellerPremium, $parentMarket, (int) $size42->term_id, ask(28), ['used' => 1]);
    (new ListingRepository())->update((int) $inactive->id, [
        'listing_status' => ListingStatus::NOT_SALE,
        'is_winner' => 0,
    ]);
    $inactive = (new ListingRepository())->find((int) $inactive->id) ?: $inactive;
    remember_listing('not_sale', $inactive, 'Not for sale');

    $expired = create_listing($sellerPremium, $parentCampaign, (int) $size43->term_id, ask(22));
    (new ListingRepository())->update((int) $expired->id, [
        'listing_status' => ListingStatus::EXPIRED,
        'is_winner' => 0,
        'expire_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
    ]);
    $expired = (new ListingRepository())->find((int) $expired->id) ?: $expired;
    remember_listing('expired', $expired, 'Expired listing');

    // Queue: cheaper winner + expensive queued on same parent+size.
    $winner = create_listing($sellerVerified, $parentQueue, (int) $size43->term_id, ask(20));
    $queued = create_listing($sellerQueued, $parentQueue, (int) $size43->term_id, ask(35));
    remember_listing('queue_winner', $winner, 'Queue bucket winner');
    remember_listing('queue_waiting', $queued, 'Queued behind winner');

    seed_log('Market listings seeded');

    // --- Sale pipeline (one listing per status) ---
    $saleSizes = [$size42, $size43, $size44];
    $saleTargets = [
        'payment' => ListingStatus::PAYMENT,
        'sold' => ListingStatus::SOLD,
        'confirmed' => ListingStatus::CONFIRMED,
        'shipped_to_sutore' => ListingStatus::SHIPPED_TO_SUTORE,
        'arrived_to_sutore' => ListingStatus::ARRIVED_TO_SUTORE,
        'verified' => ListingStatus::VERIFIED,
        'ready_to_shipping' => ListingStatus::READY_TO_SHIPPING,
        'shipped' => ListingStatus::SHIPPED,
        'delivered_to_customer' => ListingStatus::DELIVERED_TO_CUSTOMER,
        'chargeback' => ListingStatus::CHARGEBACK,
    ];

    $fs = new FulfillmentService();
    $saleIndex = 0;
    foreach ($saleTargets as $key => $target) {
        $size = $saleSizes[$saleIndex % count($saleSizes)];
        $saleIndex++;

        // Isolated parent per sale status so selector / stock do not collide.
        $parent = create_parent_product(
            'Seed Sale ' . $key,
            'SALE-' . strtoupper(substr($key, 0, 8)),
            [$size]
        );

        if ($target === ListingStatus::PAYMENT) {
            OrderSettings::update(['require_admin_payment_confirm' => true]);
        } else {
            OrderSettings::update(['require_admin_payment_confirm' => false]);
        }

        $listing = create_listing($sellerSale, $parent, (int) $size->term_id, ask(40 + $saleIndex));
        $order = create_paid_order($customerId, (int) $listing->variationId, 'Seed sale → ' . $target);
        $REPORT['orders'][$key] = (int) $order->get_id();

        $fresh = (new ListingRepository())->find((int) $listing->id);
        if (!$fresh) {
            throw new RuntimeException('Listing missing after payment #' . $listing->id);
        }

        if ($target === ListingStatus::PAYMENT) {
            remember_listing('sale_' . $key, $fresh, 'Awaiting staff payment confirm');
            OrderSettings::update(['require_admin_payment_confirm' => false]);
            continue;
        }

        if ($target === ListingStatus::SOLD) {
            remember_listing('sale_' . $key, $fresh, 'Merchant must confirm');
            continue;
        }

        if (in_array($target, [
            ListingStatus::CONFIRMED,
            ListingStatus::SHIPPED_TO_SUTORE,
            ListingStatus::ARRIVED_TO_SUTORE,
            ListingStatus::VERIFIED,
            ListingStatus::READY_TO_SHIPPING,
            ListingStatus::SHIPPED,
            ListingStatus::DELIVERED_TO_CUSTOMER,
        ], true)) {
            advance_sale_to((int) $listing->id, $target, $sellerSale, $adminId);
            $fresh = (new ListingRepository())->find((int) $listing->id) ?: $fresh;
            remember_listing('sale_' . $key, $fresh, 'Pipeline status ' . $target);
            continue;
        }

        // Branch statuses.
        if ($target === ListingStatus::CHARGEBACK) {
            advance_sale_to((int) $listing->id, ListingStatus::ARRIVED_TO_SUTORE, $sellerSale, $adminId);
            wp_set_current_user($adminId);
            $r = $fs->chargebackFulfillment((int) $listing->id, ['staff_note' => 'Scenario seed chargeback']);
            if (is_wp_error($r)) {
                throw new RuntimeException('chargeback: ' . $r->get_error_message());
            }
        } else {
            throw new RuntimeException('Unhandled branch ' . $target);
        }

        $fresh = (new ListingRepository())->find((int) $listing->id) ?: $fresh;
        remember_listing('sale_' . $key, $fresh, 'Terminal/branch ' . $target);
    }

    OrderSettings::update(['require_admin_payment_confirm' => false]);
    seed_log('Sale pipeline listings seeded');

    // --- Payout samples on delivered listing ---
    $deliveredId = (int) ($REPORT['listings']['sale_delivered_to_customer']['id'] ?? 0);
    if ($deliveredId > 0) {
        $payoutRepo = new PayoutLineRepository();
        $existing = $payoutRepo->findByListingId($deliveredId);
        if ($existing) {
            $payoutRepo->update((int) $existing->id, [
                'payout_status' => PayoutStatus::PAID,
                'paid_at' => current_time('mysql'),
                'payment_ref' => 'SEED-PAID-' . $deliveredId,
            ]);
        } else {
            $deliveredListing = (new ListingRepository())->find($deliveredId);
            $payoutRepo->insert([
                'listing_id' => $deliveredId,
                'variation_id' => (int) ($deliveredListing->variationId ?? 0),
                'parent_product_id' => (int) ($deliveredListing->parentProductId ?? 0),
                'order_id' => (int) ($REPORT['orders']['delivered_to_customer'] ?? 0),
                'order_item_id' => (int) ($deliveredListing->orderItemId ?? 0),
                'merchant_id' => $sellerSale,
                'product_title' => 'Seed delivered payout',
                'gross_asking' => (float) ask(45),
                'commission_percent' => 10.0,
                'net_amount' => (float) ask(40),
                'payout_status' => PayoutStatus::PENDING,
            ]);
        }
    }

    // --- Sourcing ---
    $sourcingRepo = new SourcingRepository();
    $openSourceId = $sourcingRepo->create([
        'order_id' => 0,
        'order_item_id' => 0,
        'parent_product_id' => $parentSource,
        'size_term_id' => (int) $size42->term_id,
        'status' => 'open',
        'requested_by' => $adminId,
        'notes' => 'Seed open pre-order on board',
    ]);
    $REPORT['sourcing']['open'] = $openSourceId;

    $preOrderListing = create_listing($sellerVerified, $parentSource, (int) $size42->term_id, ask(50));
    $acceptedSourceId = $sourcingRepo->create([
        'order_id' => 0,
        'order_item_id' => 0,
        'parent_product_id' => $parentSource,
        'size_term_id' => (int) $size42->term_id,
        'status' => 'accepted',
        'requested_by' => $adminId,
        'accepted_merchant_id' => $sellerVerified,
        'notes' => 'Seed accepted pre-order',
    ]);
    (new ListingRepository())->update((int) $preOrderListing->id, [
        'listing_status' => ListingStatus::NOT_SALE,
        'sourcing_request_id' => $acceptedSourceId,
        'is_winner' => 0,
    ]);
    $preOrderListing = (new ListingRepository())->find((int) $preOrderListing->id) ?: $preOrderListing;
    remember_listing('sourcing_held', $preOrderListing, 'Accepted sourcing / pre-order');
    $REPORT['sourcing']['accepted'] = $acceptedSourceId;

    $cancelledSourceId = $sourcingRepo->create([
        'order_id' => 0,
        'order_item_id' => 0,
        'parent_product_id' => $parentSource,
        'size_term_id' => (int) $size42->term_id,
        'status' => 'cancelled',
        'requested_by' => $adminId,
        'notes' => 'Seed cancelled pre-order',
    ]);
    $REPORT['sourcing']['cancelled'] = $cancelledSourceId;
    seed_log('Sourcing seeded');

    // --- Campaigns ---
    $campaignService = new CampaignService();
    $campaignRepo = new CampaignRepository();
    $offerRepo = new CampaignOfferRepository();

    $campListingA = create_listing($sellerVerified, $parentCampaign, (int) $size43->term_id, ask(33));
    $campListingB = create_listing($sellerPremium, $parentCampaign, (int) $size43->term_id, ask(36));

    $draftId = $campaignService->createCampaign([
        'name' => 'Seed Draft Campaign',
        'seller_discount_type' => CampaignDiscountType::FIXED,
        'seller_discount_amount' => ask(2),
        'platform_discount_type' => CampaignDiscountType::FIXED,
        'platform_discount_amount' => ask(1),
        'starts_at' => current_time('mysql'),
        'ends_at' => gmdate('Y-m-d H:i:s', time() + WEEK_IN_SECONDS),
        'notes' => 'Draft — not published',
    ]);
    if (is_wp_error($draftId)) {
        throw new RuntimeException('Draft campaign: ' . $draftId->get_error_message());
    }
    $REPORT['campaigns']['draft'] = (int) $draftId;

    $activeCampId = $campaignService->createCampaign([
        'name' => 'Seed Active Campaign',
        'seller_discount_type' => CampaignDiscountType::PERCENT,
        'seller_discount_amount' => 10,
        'platform_discount_type' => CampaignDiscountType::FIXED,
        'platform_discount_amount' => ask(1),
        'starts_at' => current_time('mysql'),
        'ends_at' => gmdate('Y-m-d H:i:s', time() + WEEK_IN_SECONDS),
        'notes' => 'Published with pending + accepted offers',
    ]);
    if (is_wp_error($activeCampId)) {
        throw new RuntimeException('Active campaign: ' . $activeCampId->get_error_message());
    }
    $published = $campaignService->publish((int) $activeCampId);
    if (is_wp_error($published)) {
        seed_log('WARN campaign publish: ' . $published->get_error_message());
    }
    $REPORT['campaigns']['active'] = (int) $activeCampId;

    // Accept one offer if present.
    $offers = $offerRepo->findPendingByCampaign((int) $activeCampId);
    foreach ($offers as $offer) {
        if ((int) $offer->listing_id === (int) $campListingA->id) {
            $acc = $campaignService->acceptOffer((int) $offer->id, $sellerVerified);
            if (is_wp_error($acc)) {
                seed_log('WARN accept offer: ' . $acc->get_error_message());
            }
            break;
        }
    }

    $endedId = $campaignService->createCampaign([
        'name' => 'Seed Ended Campaign',
        'seller_discount_type' => CampaignDiscountType::FIXED,
        'seller_discount_amount' => ask(3),
        'platform_discount_type' => CampaignDiscountType::FIXED,
        'platform_discount_amount' => 0,
        'starts_at' => gmdate('Y-m-d H:i:s', time() - 2 * WEEK_IN_SECONDS),
        'ends_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
        'notes' => 'Ended campaign',
    ]);
    if (!is_wp_error($endedId)) {
        $campaignRepo->update((int) $endedId, ['status' => CampaignStatus::ENDED]);
        $REPORT['campaigns']['ended'] = (int) $endedId;
    }

    remember_listing('campaign_listing_a', $campListingA, 'Campaign offer listing A');
    remember_listing('campaign_listing_b', $campListingB, 'Campaign offer listing B');
    seed_log('Campaigns seeded');

    // --- Tasks ---
    $tasksRepo = new TasksRepository();
    $defs = $tasksRepo->allDefinitions();
    if ($defs === []) {
        foreach (
            [
                ['listings_created', 'Create listings', 3, 'points', 50],
                ['listing_updates', 'Update listings', 5, 'points', 25],
                ['first_sale', 'Complete first sale', 1, 'commission_percent', 1],
            ] as $def
        ) {
            $tasksRepo->saveDefinition([
                'task_key' => $def[0],
                'title' => $def[1],
                'target_count' => $def[2],
                'reward_type' => $def[3],
                'reward_value' => $def[4],
                'reward_duration_days' => 30,
                'is_active' => 1,
            ]);
        }
        $defs = $tasksRepo->allDefinitions();
    }
    $progressRepo = new TaskProgressRepository();
    foreach ($defs as $def) {
        $taskId = (int) ($def->id ?? 0);
        if ($taskId <= 0) {
            continue;
        }
        if ($progressRepo->find($sellerVerified, $taskId)) {
            continue;
        }
        $progressRepo->create($sellerVerified, $taskId, max(0, (int) ($def->target_count ?? 1) - 1));
    }
    seed_log('Tasks progress seeded for demo_seller_verified');

    // --- Notifications ---
    $notif = new NotificationRepository();
    foreach (
        [
            [NotificationType::LISTING_WINNER_GAINED, 'You are for sale', 'Your listing is now for sale.'],
            [NotificationType::SALE_RECEIVED, 'Confirm your sale', 'A customer bought your item — confirm within the deadline.'],
            [NotificationType::CAMPAIGN_OFFER, 'Campaign offer', 'A new campaign offer is waiting for your response.'],
            [NotificationType::PAYOUT_PAID, 'Payout sent', 'Your payout was marked as paid.'],
        ] as $i => $n
    ) {
        $notif->insert([
            'user_id' => $sellerVerified,
            'type' => $n[0],
            'category' => NotificationType::categoryFor($n[0]),
            'title' => $n[1],
            'body' => $n[2],
            'payload' => wp_json_encode(['seed' => true, 'i' => $i]),
            'entity_type' => 'listing',
            'entity_id' => (int) ($REPORT['listings']['active_for_sale']['id'] ?? 0),
            'listing_id' => (int) ($REPORT['listings']['active_for_sale']['id'] ?? 0),
            'dedupe_key' => 'seed:' . $n[0] . ':' . $i,
        ]);
    }
    seed_log('Notifications seeded');

    update_option('sutore_marketplace_scenarios_seed_state', $REPORT, false);

    seed_log('');
    seed_log('=== DONE ===');
    seed_log('Password for all seed users: ' . PASSWORD);
    seed_log('');
    seed_log('Merchants:');
    foreach ($REPORT['users'] as $login => $id) {
        if ($login === 'admin') {
            continue;
        }
        seed_log(sprintf('  %-24s #%d', $login, $id));
    }
    seed_log('');
    seed_log('Listings (key → id / status):');
    foreach ($REPORT['listings'] as $key => $row) {
        seed_log(sprintf(
            '  %-28s #%d  %-24s  %s',
            $key,
            $row['id'],
            $row['status'],
            $row['note']
        ));
    }
    seed_log('');
    seed_log('Sourcing: open=#' . ($REPORT['sourcing']['open'] ?? 0)
        . ' accepted=#' . ($REPORT['sourcing']['accepted'] ?? 0)
        . ' cancelled=#' . ($REPORT['sourcing']['cancelled'] ?? 0));
    seed_log('Campaigns: draft=#' . ($REPORT['campaigns']['draft'] ?? 0)
        . ' active=#' . ($REPORT['campaigns']['active'] ?? 0)
        . ' ended=#' . ($REPORT['campaigns']['ended'] ?? 0));
    seed_log('');
    seed_log('Try My Account as demo_seller_verified / demo_seller_sale / demo_seller_normal');
    seed_log('Staff Manage Products + Merchants with an administrator.');
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
