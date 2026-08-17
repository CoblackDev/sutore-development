<?php

/**
 * Full marketplace scenario seeder + purge.
 *
 * Wipes plugin domain data (listings, campaigns, pre-orders, payouts, …) and
 * related WC products/orders, then seeds merchants + catalog + every major
 * My Account / staff scenario so you can click through the product.
 *
 * Usage:
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-scenarios.php --force
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-scenarios.php --purge-only
 *
 * Password for all seed users: password
 */

declare(strict_types=1);

use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\CampaignOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\CampaignStatus;
use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignRepository;
use SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\CatalogProductRequestRepository;
use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\CampaignService;
use SutoreMarketplace\Modules\Listings\Services\CustomerOfferService;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Listings\Services\OutletService;
use SutoreMarketplace\Modules\Coupons\Support\CouponMeta;
use SutoreMarketplace\Modules\Merchants\Domain\CommissionAdjustment;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Repositories\NotificationRepository;
use SutoreMarketplace\Modules\Merchants\Repositories\MerchantEventsRepository;
use SutoreMarketplace\Modules\Merchants\Services\CommissionOverrideService;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Services\ReferralService;
use SutoreMarketplace\Modules\Merchants\Settings\ReferralSettings;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule;
use SutoreMarketplace\Modules\Merchants\Services\PayoutLineService;
use SutoreMarketplace\Modules\Merchants\Services\RestrictionService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Modules\Orders\Services\PaymentHandler;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Modules\Sourcing\Services\SourcingService;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService;
use SutoreMarketplace\Modules\Tasks\Services\OpportunityCardService;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Services\YouthDiscount;
use SutoreMarketplace\Shared\Settings\Settings;

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

if (!class_exists(ListingService::class)) {
    fwrite(STDERR, "sutore-marketplace plugin not loaded / not active.\n");
    exit(1);
}

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
const PASSWORD = 'password';
const TRACK_SELLER = '111222333444';
const TRACK_SUTORE = '999888777666';

/** @var array<string, mixed> */
$REPORT = [
    'users' => [],
    'products' => [],
    'listings' => [],
    'orders' => [],
    'campaigns' => [],
    'outlet' => [],
    'customer_offers' => [],
    'pre_order' => [],
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

function seed_mysql_offset(int $seconds): string
{
    $base = strtotime(current_time('mysql'));
    $ts = ($base !== false ? $base : time()) + $seconds;

    return wp_date('Y-m-d H:i:s', $ts);
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
    return seed_catalog_ensure_size_term($slug, $name);
}

function ensure_color_term(string $slug, string $name): WP_Term
{
    return seed_catalog_ensure_color_term($slug, $name);
}

/**
 * @param list<WP_Term> $terms
 */
function create_parent_product(string $name, string $code, array $terms, ?string $taxonomy = null): int
{
    $taxonomy = $taxonomy ?? seed_catalog_primary_taxonomy();

    return seed_catalog_create_variable_parent($name, $code, $taxonomy, $terms, SEED_META);
}

function purge_all_marketplace(): void
{
    global $wpdb;

    seed_log('=== PURGE marketplace domain ===');

    $listingsTable = Schema::table('listings');
    $rows = [];
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $listingsTable))) {
        $rows = $wpdb->get_results("SELECT variation_id, parent_product_id, order_id FROM {$listingsTable}") ?: [];
    }
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
        'outlet_optins',
        'outlet_items',
        'outlet_windows',
        'customer_offers',
        'invoices',
        'catalog_product_requests',
        'merchant_payout_lines',
        'merchant_notifications',
        'merchant_restrictions',
        'merchant_task_progress',
        'merchant_rewards',
        'merchant_commission_overrides',
        'merchant_events',
        'listings',
    ];
    // Legacy table may still exist on older DBs — clear if present, then drop.
    $legacySourcing = Schema::table('sourcing_requests');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacySourcing))) {
        $tables[] = 'sourcing_requests';
    }

    foreach ($tables as $suffix) {
        $table = Schema::table($suffix);
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            seed_log(sprintf('  skip missing %s', $suffix));
            continue;
        }
        $deleted = (int) $wpdb->query("DELETE FROM {$table}");
        seed_log(sprintf('  cleared %s (%d rows)', $suffix, $deleted));
    }

    $offerCouponIds = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
        CouponMeta::CUSTOMER_OFFER_ID
    )) ?: [];
    foreach ($offerCouponIds as $couponPostId) {
        wp_delete_post((int) $couponPostId, true);
    }
    if ($offerCouponIds !== []) {
        seed_log(sprintf('  deleted %d customer-offer coupons', count($offerCouponIds)));
    }

    $profilesTable = Schema::table('merchant_profiles');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $profilesTable))) {
        $cols = $wpdb->get_col("DESCRIBE {$profilesTable}", 0) ?: [];
        if (in_array('referral_code', $cols, true)) {
            $wpdb->query(
                "UPDATE {$profilesTable}
                    SET referral_code = NULL,
                        referred_by_user_id = NULL,
                        referral_rewarded_at = NULL"
            );
            seed_log('  reset merchant_profiles referral columns');
        }
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacySourcing))) {
        $wpdb->query("DROP TABLE {$legacySourcing}");
        seed_log('  dropped legacy sourcing_requests');
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

    // Seed-tagged orders (posts meta + HPOS orders meta) + listing-linked orders.
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

    $hposMeta = $wpdb->prefix . 'wc_orders_meta';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hposMeta))) {
        $orderMeta = array_merge($orderMeta, $wpdb->get_col($wpdb->prepare(
            "SELECT order_id FROM {$hposMeta} WHERE meta_key = %s",
            SEED_META
        )) ?: []);
        foreach ($legacyMetaKeys as $key) {
            $orderMeta = array_merge($orderMeta, $wpdb->get_col($wpdb->prepare(
                "SELECT order_id FROM {$hposMeta} WHERE meta_key = %s",
                $key
            )) ?: []);
        }
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
        'sutore_marketplace_pre_order_digest_sent_ids',
        'sutore_marketplace_sourcing_digest_sent_ids',
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
    $fs = new \SutoreMarketplace\Modules\Orders\Services\FulfillmentService();
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
        'id' => (int) $listing->variationId,
        'status' => $listing->listingStatus,
        'merchant_id' => (int) $listing->merchantId,
        'variation_id' => (int) $listing->variationId,
        'note' => $note,
    ];
}

/**
 * Open a pre-order board row: paid sale → markAsPreOrder, plus a matching acceptor listing.
 *
 * @return array{origin: Listing, acceptor: Listing, order_id: int}
 */
function seed_open_pre_order(
    int $originMerchantId,
    int $acceptorMerchantId,
    int $customerId,
    int $parentId,
    int $sizeTermId,
    int $originAsk,
    int $acceptorAsk,
    string $reason,
    string $originKey,
    string $acceptorKey,
    string $originNote,
    string $acceptorNote,
    bool $backdateForConfirmed = false
): array {
    $origin = create_listing($originMerchantId, $parentId, $sizeTermId, $originAsk);
    $order = create_paid_order($customerId, (int) $origin->variationId, 'Seed pre-order ' . $reason);
    $repo = new ListingRepository();
    $fresh = $repo->find((int) $origin->variationId);
    if (!$fresh || $fresh->listingStatus !== ListingStatus::SOLD) {
        throw new RuntimeException('Pre-order seed listing did not enter sold status (' . $originKey . ').');
    }

    $marked = (new FulfillmentService())->markAsPreOrder((int) $fresh->variationId, $reason);
    if (is_wp_error($marked)) {
        throw new RuntimeException('markAsPreOrder (' . $originKey . '): ' . $marked->get_error_message());
    }

    if ($backdateForConfirmed) {
        $repo->update((int) $fresh->variationId, [
            'created_at' => seed_mysql_offset(-26 * HOUR_IN_SECONDS),
        ]);
    }

    $fresh = $repo->find((int) $fresh->variationId) ?: $fresh;
    remember_listing($originKey, $fresh, $originNote);

    $acceptor = create_listing($acceptorMerchantId, $parentId, $sizeTermId, $acceptorAsk);
    remember_listing($acceptorKey, $acceptor, $acceptorNote);

    return [
        'origin' => $fresh,
        'acceptor' => $acceptor,
        'order_id' => (int) $order->get_id(),
    ];
}

function seed_payout_listing(
    int $merchantId,
    int $customerId,
    int $adminId,
    WP_Term $size,
    string $key,
    string $title,
    string $sku,
    int $askUnits,
    string $targetStatus,
    string $note
): Listing {
    $parent = create_parent_product($title, $sku, [$size]);
    $listing = create_listing($merchantId, $parent, (int) $size->term_id, ask($askUnits));
    $order = create_paid_order($customerId, (int) $listing->variationId, 'Seed payout → ' . $key);
    global $REPORT;
    $REPORT['orders'][$key] = (int) $order->get_id();
    $REPORT['products'][$key] = $parent;
    advance_sale_to((int) $listing->variationId, $targetStatus, $merchantId, $adminId);
    $fresh = (new ListingRepository())->find((int) $listing->variationId);
    if (!$fresh) {
        throw new RuntimeException('Payout listing missing after pipeline: ' . $key);
    }
    remember_listing($key, $fresh, $note);

    return $fresh;
}

function set_payout_schedule(int $listingId, string $ymd, string $notes = ''): void
{
    $line = (new PayoutLineRepository())->findByVariationId($listingId);
    if (!$line) {
        throw new RuntimeException('Payout line missing for listing #' . $listingId);
    }
    (new PayoutLineRepository())->update((int) $line->id, [
        'scheduled_payout_date' => $ymd,
    ]);
    if ($notes !== '') {
        (new ListingRepository())->update($listingId, ['notes' => $notes]);
    }
}

function seed_date_offset(int $days): string
{
    $tz = wp_timezone();
    $base = date_create_immutable(PayoutSchedule::today() . ' 12:00:00', $tz);
    if (!$base) {
        $base = new DateTimeImmutable('now', $tz);
    }
    if ($days >= 0) {
        return $base->add(new DateInterval('P' . $days . 'D'))->format('Y-m-d');
    }

    return $base->sub(new DateInterval('P' . abs($days) . 'D'))->format('Y-m-d');
}

try {
    Schema::install();
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
        'payout_min_hold_days' => 7,
        'payout_weekdays' => [3],
    ]);
    $REPORT['notes'][] = 'Order Flow → Notifications: merchant events use one dispatcher (Panel / SMS). Seed leaves SMS globally off. Campaign offer is panel-only until SMS is enabled on that row.';

    Settings::update([
        'youth_discount_enabled' => true,
        'youth_discount_max_age' => 26,
        'youth_discount_percent' => 20.0,
        'referral' => ReferralSettings::defaults(),
        'catalog_product_request_levels' => 'verified,premium',
        'customer_offer_enabled' => true,
        'customer_offer_ttl_hours' => 48,
        'customer_offer_min_percent' => 70,
        'customer_offer_max_per_day' => 10,
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
    wp_set_password(PASSWORD, $adminId);

    $customerId = upsert_user('demo_customer', 'demo_customer@example.com', 'Demo Customer', 'customer', MerchantLevels::NORMAL);
    update_user_meta($customerId, 'billing_email', 'demo_customer@example.com');
    $youthBirthYear = YouthDiscount::currentYear() - 20;
    $customerYouthId = upsert_user(
        'demo_customer_youth',
        'demo_customer_youth@example.com',
        'Youth Customer',
        'customer',
        MerchantLevels::NORMAL,
        [
            'account' => [
                MerchantMeta::ACCOUNT_NAME => 'Youth',
                MerchantMeta::ACCOUNT_LASTNAME => 'Customer',
                MerchantMeta::ACCOUNT_BIRTH_YEAR => (string) $youthBirthYear,
            ],
        ]
    );
    update_user_meta($customerYouthId, 'billing_first_name', 'Youth');
    update_user_meta($customerYouthId, 'billing_last_name', 'Customer');
    update_user_meta($customerYouthId, 'billing_email', 'demo_customer_youth@example.com');
    YouthDiscount::rememberVerified(
        $customerYouthId,
        '10000000146',
        $youthBirthYear,
        'Youth',
        'Customer'
    );
    $sellerNormal = upsert_user('demo_seller_normal', 'demo_seller_normal@example.com', 'Seller Normal', 'merchant', MerchantLevels::NORMAL);
    $sellerVerified = upsert_user('demo_seller_verified', 'demo_seller_verified@example.com', 'Seller Confirmed', 'merchant', MerchantLevels::VERIFIED);
    $sellerPremium = upsert_user('demo_seller_premium', 'demo_seller_premium@example.com', 'Seller Premium', 'merchant', MerchantLevels::PREMIUM);
    $sellerQueued = upsert_user('demo_seller_queued', 'demo_seller_queued@example.com', 'Seller Queue', 'merchant', MerchantLevels::VERIFIED);
    $sellerBanned = upsert_user('demo_seller_banned', 'demo_seller_banned@example.com', 'Seller Banned', 'merchant', MerchantLevels::VERIFIED);
    $sellerSale = upsert_user('demo_seller_sale', 'demo_seller_sale@example.com', 'Seller Sales', 'merchant', MerchantLevels::VERIFIED);
    $sellerReferred = upsert_user('demo_seller_referred', 'demo_seller_referred@example.com', 'Seller Referred', 'merchant', MerchantLevels::NORMAL);

    $REPORT['users'] = [
        'admin' => $adminId,
        'demo_customer' => $customerId,
        'demo_customer_youth' => $customerYouthId,
        'demo_seller_normal' => $sellerNormal,
        'demo_seller_verified' => $sellerVerified,
        'demo_seller_premium' => $sellerPremium,
        'demo_seller_queued' => $sellerQueued,
        'demo_seller_banned' => $sellerBanned,
        'demo_seller_sale' => $sellerSale,
        'demo_seller_referred' => $sellerReferred,
    ];
    seed_log('Users ready (password ' . PASSWORD . ')');
    $REPORT['notes'][] = 'Youth discount enabled (Settings → Operations): max age 26, 20%, capped by hizmet + güvence + commission. Asking and seller net unchanged.';
    $REPORT['notes'][] = 'demo_customer_youth (age ~20, TC verified): add Seed Dunk Low Market size 43 to cart — automatic Youth discount fee. demo_customer (adult) same product, no fee.';
    $REPORT['notes'][] = 'Invoices (Settings → Invoices) stay off during seed — no Paraşüt API calls. Enable after saving credentials; one customer e-Archive per order when remaining items are verified or dropped (Hizmet Bedeli + Güvence Bedeli lines per remaining product; youth allocated hizmet→güvence→commission). Sold/payment does not invoice. Credit/return invoices are not issued. Seller e-Archive (Komisyon Bedeli) when payout is marked paid. PDFs are private; staff/merchant open them from the listing row. Failures retry in queue and do not block sale/payout.';

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

    $catalogRequests = new CatalogProductRequestRepository();
    $catalogRequests->create([
        'merchant_id' => $sellerVerified,
        'sku_or_link' => 'NOT-IN-CATALOG-001',
        'size_note' => '42',
        'note' => 'Seed: missing Dunk colorway — please add to catalog',
        'status' => CatalogProductRequestStatus::PENDING,
    ]);
    $catalogRequests->create([
        'merchant_id' => $sellerPremium,
        'sku_or_link' => 'https://example.com/seed-missing-product',
        'size_note' => '43',
        'note' => 'Seed: product page link for catalog intake',
        'status' => CatalogProductRequestStatus::PENDING,
    ]);
    $fulfilledRequestId = $catalogRequests->create([
        'merchant_id' => $sellerVerified,
        'sku_or_link' => 'SEED-MARKET',
        'size_note' => '42',
        'note' => 'Seed: already added — seller can open a listing',
        'status' => CatalogProductRequestStatus::FULFILLED,
        'resolved_parent_product_id' => null,
        'resolved_by' => $adminId,
        'resolved_at' => current_time('mysql'),
    ]);
    if ($fulfilledRequestId > 0) {
        (new NotificationService())->dispatch($sellerVerified, NotificationType::CATALOG_REQUEST_FULFILLED, [
            'request_id' => $fulfilledRequestId,
            'product' => 'Seed Dunk Low Market',
            'product_code' => 'SEED-MARKET',
            'size_note' => '42',
        ], 0);
    }
    $REPORT['notes'][] = 'Catalog requests: demo_seller_verified + demo_seller_premium have pending rows; verified also has a fulfilled notification (SEED-MARKET). demo_seller_normal cannot submit (level gate).';

    $platformCampaign = (new CommissionOverrideService())->create(0, [
        'commission_percent' => 50,
        'adjustment' => CommissionAdjustment::PERCENT_OFF,
        'starts_at' => seed_mysql_offset(-DAY_IN_SECONDS),
        'expires_at' => seed_mysql_offset(7 * DAY_IN_SECONDS),
        'note' => 'Scenario seed: all-seller 50% off (active during sale pipeline)',
        'source' => 'campaign',
        'created_by' => $adminId,
    ]);
    $platformCampaignId = 0;
    if (is_wp_error($platformCampaign)) {
        seed_log('WARN platform commission campaign: ' . $platformCampaign->get_error_message());
    } else {
        $platformCampaignId = (int) $platformCampaign['id'];
        $REPORT['notes'][] = 'Platform campaign 50% off is active during sale pipeline (rate locked at sale)';
    }

    // --- Catalog ---
    $size42 = ensure_size_term('seed-42', '42 (Seed)');
    $size43 = ensure_size_term('seed-43', '43 (Seed)');
    $size44 = ensure_size_term('seed-44', '44 (Seed)');

    $parentMarket = create_parent_product('Seed Dunk Low Market', 'SEED-MARKET', [$size42, $size43]);
    $parentQueue = create_parent_product('Seed Jordan 1 Queue', 'SEED-QUEUE', [$size43]);
    $parentSale = create_parent_product('Seed Yeezy Sale Pipeline', 'SEED-SALE', [$size42, $size43, $size44]);
    $parentPreOrder = create_parent_product('Seed Samba Pre-order', 'SEED-PREORDER', [$size42, $size43]);
    $parentPreOrderDone = create_parent_product('Seed Samba Pre-order Fulfilled', 'SEED-PRE-DONE', [$size42]);
    $parentCampaign = create_parent_product('Seed Campus Campaign', 'SEED-CAMP', [$size43]);
    $parentOutlet = create_parent_product('Seed Dunk Outlet', 'SEED-OUTLET', [$size42, $size43]);

    $colorRed = ensure_color_term('seed-red', 'Red (Seed)');
    $colorBlue = ensure_color_term('seed-blue', 'Blue (Seed)');
    $parentColor = create_parent_product('Seed Tee Color Axis', 'SEED-COLOR', [$colorRed, $colorBlue], 'pa_color');

    $oneSizeTerm = ensure_size_term('seed-one-size', 'One Size');
    $parentOneSize = create_parent_product('Seed Cap One Size', 'SEED-ONESIZE', [$oneSizeTerm]);

    $REPORT['products'] = [
        'market' => $parentMarket,
        'queue' => $parentQueue,
        'sale' => $parentSale,
        'pre_order' => $parentPreOrder,
        'pre_order_fulfilled' => $parentPreOrderDone,
        'campaign' => $parentCampaign,
        'outlet' => $parentOutlet,
        'color_axis' => $parentColor,
        'one_size' => $parentOneSize,
    ];
    seed_log('Catalog parents created');

    // --- Market listings ---
    $pending = create_listing($sellerNormal, $parentMarket, (int) $size42->term_id, ask(25), ['duration_days' => 7]);
    remember_listing('pending_approval', $pending, 'Normal seller → pending, 7-day duration');

    $active = create_listing($sellerVerified, $parentMarket, (int) $size43->term_id, ask(30), ['duration_days' => 45]);
    remember_listing('active_for_sale', $active, 'Confirmed seller → for sale, 45-day duration');

    $colorListing = create_listing($sellerPremium, $parentColor, (int) $colorRed->term_id, ask(24), ['duration_days' => 60]);
    remember_listing('color_axis', $colorListing, 'Premium seller color-axis (pa_color), 60-day duration');

    $oneSizeListing = create_listing($sellerVerified, $parentOneSize, (int) $oneSizeTerm->term_id, ask(18), ['duration_days' => 30]);
    remember_listing('one_size_axis', $oneSizeListing, 'Single size term (One Size), 30-day duration');

    $inactive = create_listing($sellerPremium, $parentMarket, (int) $size42->term_id, ask(28), ['damaged' => 1]);
    wp_set_current_user($sellerPremium);
    $removed = (new ListingService())->removeFromSale((int) $inactive->variationId, $sellerPremium);
    if (is_wp_error($removed)) {
        throw new RuntimeException('not_sale removeFromSale: ' . $removed->get_error_message());
    }
    $inactive = (new ListingRepository())->find((int) $inactive->variationId) ?: $removed;
    remember_listing('not_sale', $inactive, 'Seller removed from sale (relistable)');

    $expired = create_listing($sellerPremium, $parentCampaign, (int) $size43->term_id, ask(22));
    (new ListingRepository())->update((int) $expired->variationId, [
        'listing_status' => ListingStatus::EXPIRED,
        'is_winner' => 0,
        'expire_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
    ]);
    $expired = (new ListingRepository())->find((int) $expired->variationId) ?: $expired;
    remember_listing('expired', $expired, 'Expired listing');

    // Queue: cheaper winner + expensive queued on same parent+size.
    $winner = create_listing($sellerVerified, $parentQueue, (int) $size43->term_id, ask(20));
    $queued = create_listing($sellerQueued, $parentQueue, (int) $size43->term_id, ask(35));
    remember_listing('queue_winner', $winner, 'Queue bucket winner');
    remember_listing('queue_waiting', $queued, 'Queued behind winner');

    // Price race ignores condition: cheapest damaged listing wins; flawless expensive waits.
    $parentCondition = create_parent_product('Seed Condition Price Race', 'SEED-COND', [$size42]);
    $REPORT['products']['condition_race'] = $parentCondition;
    $condFlawless = create_listing($sellerVerified, $parentCondition, (int) $size42->term_id, ask(40));
    $condNoBox = create_listing($sellerQueued, $parentCondition, (int) $size42->term_id, ask(22), ['no_box' => 1]);
    $condDamaged = create_listing($sellerPremium, $parentCondition, (int) $size42->term_id, ask(18), ['damaged' => 1]);
    $condFlawless = (new ListingRepository())->find((int) $condFlawless->variationId) ?: $condFlawless;
    $condNoBox = (new ListingRepository())->find((int) $condNoBox->variationId) ?: $condNoBox;
    $condDamaged = (new ListingRepository())->find((int) $condDamaged->variationId) ?: $condDamaged;
    remember_listing(
        'condition_queued_flawless',
        $condFlawless,
        'Flawless expensive listing queued behind cheaper defective winners'
    );
    remember_listing(
        'condition_queued_no_box',
        $condNoBox,
        'No-box mid price — queued; cheaper than flawless, more expensive than damaged'
    );
    remember_listing(
        'condition_winner_damaged',
        $condDamaged,
        'Damaged cheapest listing is on sale — PDP shows Damaged badge'
    );
    $REPORT['notes'][] = 'SEED-COND size 42: damaged asking wins over flawless. Open the product page and pick 42 (Seed).';

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

        $fresh = (new ListingRepository())->find((int) $listing->variationId);
        if (!$fresh) {
            throw new RuntimeException('Listing missing after payment #' . $listing->variationId);
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
            advance_sale_to((int) $listing->variationId, $target, $sellerSale, $adminId);
            $fresh = (new ListingRepository())->find((int) $listing->variationId) ?: $fresh;
            remember_listing('sale_' . $key, $fresh, 'Pipeline status ' . $target);
            continue;
        }

        // Branch statuses.
        if ($target === ListingStatus::CHARGEBACK) {
            advance_sale_to((int) $listing->variationId, ListingStatus::ARRIVED_TO_SUTORE, $sellerSale, $adminId);
            wp_set_current_user($adminId);
            $r = $fs->chargebackFulfillment((int) $listing->variationId, ['staff_note' => 'Scenario seed chargeback']);
            if (is_wp_error($r)) {
                throw new RuntimeException('chargeback: ' . $r->get_error_message());
            }
        } else {
            throw new RuntimeException('Unhandled branch ' . $target);
        }

        $fresh = (new ListingRepository())->find((int) $listing->variationId) ?: $fresh;
        remember_listing('sale_' . $key, $fresh, 'Terminal/branch ' . $target);
    }

    OrderSettings::update(['require_admin_payment_confirm' => false]);
    seed_log('Sale pipeline listings seeded');

    // --- order_detached (staff detach — not relistable) ---
    $parentDetached = create_parent_product('Seed Detached From Order', 'SEED-DETACH', [$size44]);
    $detachListing = create_listing($sellerPremium, $parentDetached, (int) $size44->term_id, ask(27));
    $detachOrder = create_paid_order($customerId, (int) $detachListing->variationId, 'Seed → order_detached');
    wp_set_current_user($adminId);
    $detachResult = $fs->markNotForSale((int) $detachListing->variationId, [
        'staff_note' => 'Scenario seed: could not source for order',
    ]);
    if (is_wp_error($detachResult)) {
        throw new RuntimeException('order_detached: ' . $detachResult->get_error_message());
    }
    $detachFresh = (new ListingRepository())->find((int) $detachListing->variationId);
    if (!$detachFresh) {
        throw new RuntimeException('order_detached listing missing after markNotForSale');
    }
    remember_listing('order_detached', $detachFresh, 'Staff detached — no put on sale');
    $REPORT['orders']['order_detached'] = (int) $detachOrder->get_id();
    $REPORT['products']['detached'] = $parentDetached;
    $REPORT['notes'][] = 'Listing durations: normal max 30d (pending=7d); verified/premium up to 60d';
    $REPORT['notes'][] = 'not_sale = seller removeFromSale (relistable); order_detached = staff markNotForSale (no relist)';
    seed_log('order_detached listing seeded');

    if ($platformCampaignId > 0) {
        global $wpdb;
        $wpdb->update(
            Schema::table('merchant_commission_overrides'),
            [
                'expires_at' => seed_mysql_offset(-HOUR_IN_SECONDS),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $platformCampaignId]
        );
        $REPORT['notes'][] = 'Platform 50% off expired after pipeline — sale_verified keeps ~6% locked at sale';
    }

    $referral = new ReferralService();
    $inviteCode = $referral->ensureCode($sellerVerified);
    $accepted = $referral->acceptInvite($sellerReferred, $inviteCode);
    if (is_wp_error($accepted)) {
        seed_log('WARN referral accept: ' . $accepted->get_error_message());
    } else {
        $REPORT['notes'][] = 'demo_seller_referred used demo_seller_verified invite code ' . $inviteCode . ' — welcome commission points-off is active';
    }

    $parentReferral = create_parent_product('Seed Referral First Sale', 'SEED-REF', [$size42]);
    $REPORT['products']['referral'] = $parentReferral;
    OrderSettings::update(['require_admin_payment_confirm' => false]);
    $referralListing = create_listing($sellerReferred, $parentReferral, (int) $size42->term_id, ask(28), ['duration_days' => 30]);
    $referralOrder = create_paid_order($customerId, (int) $referralListing->variationId, 'Seed referral first sale');
    $REPORT['orders']['referral_first_sale'] = (int) $referralOrder->get_id();
    $referralFresh = (new ListingRepository())->find((int) $referralListing->variationId);
    if ($referralFresh) {
        remember_listing('referral_first_sale', $referralFresh, 'Invited seller first sold — inviter reward + notification');
    }
    $REPORT['notes'][] = 'demo_seller_verified: Notifications → referral reward; Merchant exclusive → invite code; commission override source=referral';
    $REPORT['notes'][] = 'demo_seller_referred: first sale sold; welcome referral override. Staff Sellers shows both.';
    seed_log('Referral scenario seeded (code ' . $inviteCode . ')');

    seed_log('Commission plane listings…');
    wp_set_current_user($adminId);
    $listingService = new ListingService();

    $parentAfter = create_parent_product('Seed Commission After Campaign', 'SEED-CM-AFTER', [$size42]);
    $REPORT['products']['commission_after'] = $parentAfter;
    $afterCampaign = create_listing($sellerSale, $parentAfter, (int) $size42->term_id, ask(29));
    $afterOrder = create_paid_order($customerId, (int) $afterCampaign->variationId, 'Seed sold after commission campaign');
    $REPORT['orders']['commission_after_campaign'] = (int) $afterOrder->get_id();
    advance_sale_to((int) $afterCampaign->variationId, ListingStatus::VERIFIED, $sellerSale, $adminId);
    $afterFresh = (new ListingRepository())->find((int) $afterCampaign->variationId) ?: $afterCampaign;
    remember_listing(
        'commission_after_campaign',
        $afterFresh,
        'Sold after 50% campaign ended — locked at Confirmed 12%'
    );

    $parentGesture = create_parent_product('Seed Commission Gesture', 'SEED-CM-GEST', [$size42]);
    $REPORT['products']['commission_gesture'] = $parentGesture;
    $gestureListing = create_listing($sellerSale, $parentGesture, (int) $size42->term_id, ask(41));
    $gestureOrder = create_paid_order($customerId, (int) $gestureListing->variationId, 'Seed commission gesture');
    $REPORT['orders']['commission_gesture'] = (int) $gestureOrder->get_id();
    advance_sale_to((int) $gestureListing->variationId, ListingStatus::VERIFIED, $sellerSale, $adminId);
    wp_set_current_user($adminId);
    $gestured = (new PayoutLineService())->adjustCommission(
        (int) $gestureListing->variationId,
        0.0,
        'Scenario seed: staff gesture on this payout only'
    );
    if (is_wp_error($gestured)) {
        seed_log('WARN payout gesture: ' . $gestured->get_error_message());
    }
    $gestureFresh = (new ListingRepository())->find((int) $gestureListing->variationId);
    if ($gestureFresh) {
        remember_listing('commission_gesture', $gestureFresh, 'Locked 12% at sale; pending payout gestured to 0%');
    }

    $parentZero = create_parent_product('Seed Listing Commission Zero', 'SEED-CM-ZERO', [$size43]);
    $REPORT['products']['commission_zero'] = $parentZero;
    $zeroListing = create_listing($sellerPremium, $parentZero, (int) $size43->term_id, ask(26));
    wp_set_current_user($adminId);
    $zeroSet = $listingService->setCommissionPercent(
        (int) $zeroListing->variationId,
        0.0,
        'Scenario seed: return-customer relist at 0%'
    );
    if (is_wp_error($zeroSet)) {
        seed_log('WARN listing 0% commission: ' . $zeroSet->get_error_message());
    }
    $zeroFresh = (new ListingRepository())->find((int) $zeroListing->variationId) ?: $zeroListing;
    remember_listing('commission_listing_zero', $zeroFresh, 'On sale — listing commission field 0%');

    $parentFive = create_parent_product('Seed Listing Commission Five', 'SEED-CM-FIVE', [$size42]);
    $REPORT['products']['commission_five'] = $parentFive;
    $fiveListing = create_listing($sellerVerified, $parentFive, (int) $size42->term_id, ask(31));
    wp_set_current_user($adminId);
    $fiveSet = $listingService->setCommissionPercent(
        (int) $fiveListing->variationId,
        5.0,
        'Scenario seed: listing-specific 5%'
    );
    if (is_wp_error($fiveSet)) {
        seed_log('WARN listing 5% commission: ' . $fiveSet->get_error_message());
    }
    $fiveFresh = (new ListingRepository())->find((int) $fiveListing->variationId) ?: $fiveListing;
    remember_listing('commission_listing_five', $fiveFresh, 'On sale — listing commission field 5%');

    $parentZeroSold = create_parent_product('Seed Listing Commission Zero Sold', 'SEED-CM-ZSLD', [$size42]);
    $REPORT['products']['commission_zero_sold'] = $parentZeroSold;
    $zeroSoldListing = create_listing($sellerPremium, $parentZeroSold, (int) $size42->term_id, ask(32));
    wp_set_current_user($adminId);
    $zeroSoldSet = $listingService->setCommissionPercent(
        (int) $zeroSoldListing->variationId,
        0.0,
        'Scenario seed: 0% listing rate locked into payout'
    );
    if (is_wp_error($zeroSoldSet)) {
        seed_log('WARN listing 0% sold: ' . $zeroSoldSet->get_error_message());
    }
    $zeroSoldOrder = create_paid_order($customerId, (int) $zeroSoldListing->variationId, 'Seed listing 0% sold');
    $REPORT['orders']['commission_listing_zero_sold'] = (int) $zeroSoldOrder->get_id();
    advance_sale_to((int) $zeroSoldListing->variationId, ListingStatus::VERIFIED, $sellerPremium, $adminId);
    $zeroSoldFresh = (new ListingRepository())->find((int) $zeroSoldListing->variationId);
    if ($zeroSoldFresh) {
        remember_listing(
            'commission_listing_zero_sold',
            $zeroSoldFresh,
            'Listing 0% set before sale — payout 0% (not a gesture)'
        );
    }

    wp_set_current_user($adminId);
    $activePlatform = (new CommissionOverrideService())->create(0, [
        'commission_percent' => 5,
        'adjustment' => CommissionAdjustment::PERCENT_OFF,
        'starts_at' => seed_mysql_offset(-HOUR_IN_SECONDS),
        'expires_at' => seed_mysql_offset(14 * DAY_IN_SECONDS),
        'note' => 'Scenario seed: live 5% off (Sellers list — does not change locked sales)',
        'source' => 'campaign',
        'created_by' => $adminId,
    ]);
    if (is_wp_error($activePlatform)) {
        seed_log('WARN live platform campaign: ' . $activePlatform->get_error_message());
    } else {
        $REPORT['notes'][] = 'Sellers list: live platform campaign 5% off (relative to each level)';
    }

    $scheduledPlatform = (new CommissionOverrideService())->create(0, [
        'commission_percent' => 25,
        'adjustment' => CommissionAdjustment::PERCENT_OFF,
        'starts_at' => seed_mysql_offset(3 * DAY_IN_SECONDS),
        'expires_at' => seed_mysql_offset(17 * DAY_IN_SECONDS),
        'note' => 'Scenario seed: scheduled 25% off — visible, not yet applying',
        'source' => 'campaign',
        'created_by' => $adminId,
    ]);
    if (is_wp_error($scheduledPlatform)) {
        seed_log('WARN scheduled platform campaign: ' . $scheduledPlatform->get_error_message());
    } else {
        $REPORT['notes'][] = 'Sellers list: scheduled platform campaign 25% off (starts in 3 days)';
    }
    seed_log('Commission plane listings seeded');

    // --- Deadline edge cases (staff Manage Products filters / badges) ---
    $listingRepo = new ListingRepository();
    $soldId = (int) ($REPORT['listings']['sale_sold']['id'] ?? 0);
    if ($soldId > 0) {
        $listingRepo->update($soldId, [
            'confirm_deadline_at' => gmdate('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS),
            'confirm_notice_sent' => 1,
        ]);
        $REPORT['notes'][] = 'sale_sold has expired confirm deadline';
    }
    $confirmedId = (int) ($REPORT['listings']['sale_confirmed']['id'] ?? 0);
    if ($confirmedId > 0) {
        $listingRepo->update($confirmedId, [
            'cargo_deadline_at' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
            'cargo_notice_sent' => 1,
            'cargo_expired_flag' => 1,
        ]);
        $REPORT['notes'][] = 'sale_confirmed has expired cargo deadline';
    }

    // --- Payout A8 scenarios (due list / future date / paid + ref / Alacaklarım) ---
    seed_log('Payout schedule scenarios…');
    wp_set_current_user($adminId);
    $today = PayoutSchedule::today();
    $dueToday = seed_payout_listing(
        $sellerSale,
        $customerId,
        $adminId,
        $size42,
        'payout_due_today',
        'Seed Payout Due Today',
        'SEED-PAY-DUE',
        33,
        ListingStatus::VERIFIED,
        'Pending payout scheduled today — Due for payout filter + CSV'
    );
    set_payout_schedule(
        (int) $dueToday->variationId,
        $today,
        'Seed: due today. Filter Due for payout → Export CSV → bulk Mark as Paid.'
    );

    $dueOverdue = seed_payout_listing(
        $sellerSale,
        $customerId,
        $adminId,
        $size43,
        'payout_due_overdue',
        'Seed Payout Overdue',
        'SEED-PAY-OVD',
        31,
        ListingStatus::READY_TO_SHIPPING,
        'Pending payout scheduled in the past — still in due list'
    );
    set_payout_schedule(
        (int) $dueOverdue->variationId,
        seed_date_offset(-7),
        'Seed: overdue Wednesday batch row.'
    );

    $dueSecond = seed_payout_listing(
        $sellerSale,
        $customerId,
        $adminId,
        $size44,
        'payout_due_second',
        'Seed Payout Due Batch 2',
        'SEED-PAY-DU2',
        28,
        ListingStatus::VERIFIED,
        'Second due row for bulk payment_ref'
    );
    set_payout_schedule(
        (int) $dueSecond->variationId,
        $today,
        'Seed: second due row for bulk Mark as Paid + shared EFT ref.'
    );

    $future = seed_payout_listing(
        $sellerSale,
        $customerId,
        $adminId,
        $size42,
        'payout_scheduled_future',
        'Seed Payout Future Wednesday',
        'SEED-PAY-FUT',
        36,
        ListingStatus::VERIFIED,
        'Pending payout on a future Wednesday — Alacaklarım date, not in due list'
    );
    set_payout_schedule(
        (int) $future->variationId,
        seed_date_offset(14),
        'Seed: future payout. Should NOT appear in Due for payout.'
    );

    $paidRef = seed_payout_listing(
        $sellerSale,
        $customerId,
        $adminId,
        $size43,
        'payout_paid_ref',
        'Seed Payout Paid With Ref',
        'SEED-PAY-PAID',
        30,
        ListingStatus::DELIVERED_TO_CUSTOMER,
        'Paid payout with shared EFT reference'
    );
    $paid = (new PayoutLineService())->markPaid((int) $paidRef->variationId, $adminId, 'SEED-EFT-WED-001');
    if (is_wp_error($paid)) {
        seed_log('WARN markPaid payout_paid_ref: ' . $paid->get_error_message());
    }

    $deliveredId = (int) ($REPORT['listings']['sale_delivered_to_customer']['id'] ?? 0);
    if ($deliveredId > 0) {
        wp_set_current_user($adminId);
        $paid = (new PayoutLineService())->markPaid($deliveredId, $adminId, 'SEED-PAID-' . $deliveredId);
        if (is_wp_error($paid)) {
            seed_log('WARN markPaid delivered: ' . $paid->get_error_message());
        }
    }

    $REPORT['notes'][] = 'Payout settings: 7-day hold, Wednesday only (Order Flow → Payout)';
    $REPORT['notes'][] = 'Due list: payout_due_today + payout_due_overdue + payout_due_second — Manage Products → Due for payout → Export CSV → bulk Mark as Paid + payment_ref';
    $REPORT['notes'][] = 'Alacaklarım (demo_seller_sale): payout_scheduled_future shows a future Wednesday; payout_paid_ref is paid with SEED-EFT-WED-001';
    $REPORT['notes'][] = 'sale_verified pending (auto schedule); sale_delivered_to_customer paid; sale_chargeback reversed';
    $REPORT['notes'][] = 'Account deletion: blocks payment→shipped, open pre_order, delivered without paid payout; delivered+paid and chargeback do not block (rows kept). Staff close_pre_order marks unsourced. WC cancelled auto-detaches payment/sold/confirmed/pre_order; later pipeline notifies admins.';
    $REPORT['notes'][] = 'Payout: sale_verified ~6% locked; commission_after_campaign 12% locked; commission_gesture payout 0%; listing-zero-sold payout 0%';

    // --- Staff merchant controls: commission override + activity events ---
    wp_set_current_user($adminId);
    $commission = (new CommissionOverrideService())->create($sellerPremium, [
        'commission_percent' => 7.5,
        'adjustment' => CommissionAdjustment::ABSOLUTE,
        'expires_at' => seed_mysql_offset(30 * DAY_IN_SECONDS),
        'note' => 'Scenario seed: reduced commission for premium seller UI',
        'created_by' => $adminId,
    ]);
    if (is_wp_error($commission)) {
        seed_log('WARN commission override: ' . $commission->get_error_message());
    } else {
        $REPORT['notes'][] = 'Seller Premium: 7.5% absolute (beats live 5% off of Super 8%)';
    }

    $raise = (new CommissionOverrideService())->create($sellerNormal, [
        'commission_percent' => 20,
        'adjustment' => CommissionAdjustment::ABSOLUTE,
        'note' => 'Scenario seed: absolute rate above New level (raise warning)',
        'created_by' => $adminId,
    ]);
    if (is_wp_error($raise)) {
        seed_log('WARN raise override: ' . $raise->get_error_message());
    } else {
        $REPORT['notes'][] = 'Seller Normal: 20% override (raise warning in Sellers → Commission; live rate is still the cheaper 5% off)';
    }

    $pointsOff = (new CommissionOverrideService())->create($sellerVerified, [
        'commission_percent' => 2,
        'adjustment' => CommissionAdjustment::POINTS_OFF,
        'expires_at' => seed_mysql_offset(21 * DAY_IN_SECONDS),
        'note' => 'Scenario seed: 2 points off Confirmed 12% → 10%',
        'created_by' => $adminId,
    ]);
    if (is_wp_error($pointsOff)) {
        seed_log('WARN points-off override: ' . $pointsOff->get_error_message());
    } else {
        $REPORT['notes'][] = 'Seller Confirmed: 2 points off (12% → 10%; cheaper than live 5% off)';
    }

    $REPORT['notes'][] = 'Manage Products: sale_verified locked ~6%; commission_after_campaign locked 12%; commission_gesture Adjust commission; listing 0%/5% on the listing form';

    $events = new MerchantEventsRepository();
    $events->log($sellerVerified, 'merchant_level_changed', [
        'from_level' => MerchantLevels::NORMAL,
        'to_level' => MerchantLevels::VERIFIED,
        'actor_role' => 'staff',
    ], 'merchant_visible');
    $events->log($sellerBanned, 'merchant_restriction_set', [
        'restriction_key' => 'listing_create_ban',
        'reason' => 'Scenario seed: create ban for UI testing',
        'actor_role' => 'staff',
    ], 'admin_only');
    $REPORT['notes'][] = 'Merchant activity events seeded for verified + banned sellers';

    // --- Extra WC orders (staff Manage Orders status filters) ---
    $completedOrder = wc_create_order(['customer_id' => $customerId]);
    $completedOrder->set_billing_first_name('Demo');
    $completedOrder->set_billing_last_name('Customer');
    $completedOrder->set_billing_email('demo_customer@example.com');
    $completedOrder->set_billing_country('TR');
    $completedOrder->set_payment_method('cod');
    $completedOrder->update_meta_data(SEED_META, '1');
    $completedOrder->calculate_totals();
    $completedOrder->save();
    $completedOrder->update_status('completed', 'Scenario seed: completed order without marketplace line');
    $REPORT['orders']['staff_completed'] = (int) $completedOrder->get_id();

    $cancelledOrder = wc_create_order(['customer_id' => $customerId]);
    $cancelledOrder->set_billing_first_name('Demo');
    $cancelledOrder->set_billing_last_name('Customer');
    $cancelledOrder->set_billing_email('demo_customer@example.com');
    $cancelledOrder->set_billing_country('TR');
    $cancelledOrder->set_payment_method('cod');
    $cancelledOrder->update_meta_data(SEED_META, '1');
    $cancelledOrder->calculate_totals();
    $cancelledOrder->save();
    $cancelledOrder->update_status('cancelled', 'Scenario seed: cancelled order');
    $REPORT['orders']['staff_cancelled'] = (int) $cancelledOrder->get_id();
    seed_log('Staff order status samples seeded');

    // Verification helpers (tools/verify-strict-workflows.php).
    $REPORT['verify'] = [
        'delivered_listing_id' => (int) ($REPORT['listings']['sale_delivered_to_customer']['id'] ?? 0),
        'sold_listing_id' => (int) ($REPORT['listings']['sale_sold']['id'] ?? 0),
        'chargeback_listing_id' => (int) ($REPORT['listings']['sale_chargeback']['id'] ?? 0),
        'delivered_order_id' => (int) ($REPORT['orders']['delivered_to_customer'] ?? 0),
    ];

    // --- Pre-order board (listing_status=pre_order, order-linked; accept = instant swap) ---
    // Keep sale_sold intact for verify-strict-workflows — dedicated samples only.
    OrderSettings::update(['require_admin_payment_confirm' => false]);

    $staffBoard = seed_open_pre_order(
        $sellerSale,
        $sellerVerified,
        $customerId,
        $parentPreOrder,
        (int) $size42->term_id,
        ask(48),
        ask(50),
        'staff',
        'pre_order_board',
        'pre_order_acceptor',
        'Staff-opened board (backdated so Confirmed sellers see it now)',
        'Matching listing — asking differs; accept equalizes price',
        true
    );
    $REPORT['orders']['pre_order'] = $staffBoard['order_id'];
    $REPORT['pre_order']['board_variation_id'] = (int) $staffBoard['origin']->variationId;
    $REPORT['pre_order']['order_id'] = $staffBoard['order_id'];
    $REPORT['pre_order']['acceptor_variation_id'] = (int) $staffBoard['acceptor']->variationId;
    $REPORT['pre_order']['staff_origin_asking'] = ask(48);
    $REPORT['pre_order']['staff_acceptor_asking'] = ask(50);

    $autoBoard = seed_open_pre_order(
        $sellerSale,
        $sellerPremium,
        $customerId,
        $parentPreOrder,
        (int) $size43->term_id,
        ask(46),
        ask(52),
        'confirm_deadline',
        'pre_order_auto',
        'pre_order_auto_acceptor',
        'Auto-opened after confirm deadline (fresh — Super sees immediately)',
        'Premium matching listing — asking differs; accept equalizes price',
        false
    );
    $REPORT['orders']['pre_order_auto'] = $autoBoard['order_id'];
    $REPORT['pre_order']['auto_board_variation_id'] = (int) $autoBoard['origin']->variationId;
    $REPORT['pre_order']['auto_order_id'] = $autoBoard['order_id'];
    $REPORT['pre_order']['auto_acceptor_variation_id'] = (int) $autoBoard['acceptor']->variationId;

    $fulfilledBoard = seed_open_pre_order(
        $sellerSale,
        $sellerPremium,
        $customerId,
        $parentPreOrderDone,
        (int) $size42->term_id,
        ask(40),
        ask(55),
        'staff',
        'pre_order_fulfilled_origin',
        'pre_order_fulfilled_acceptor',
        'Origin listing before accept (will leave board)',
        'Acceptor listing — SourcingService::accept logs sourcing_fulfilled',
        true
    );
    wp_set_current_user($sellerPremium);
    $accepted = (new SourcingService())->accept(
        (int) $fulfilledBoard['origin']->variationId,
        $sellerPremium,
        (int) $fulfilledBoard['acceptor']->variationId,
        false
    );
    if (is_wp_error($accepted)) {
        throw new RuntimeException('Pre-order accept seed: ' . $accepted->get_error_message());
    }
    $fulfilledAcceptor = (new ListingRepository())->find((int) $fulfilledBoard['acceptor']->variationId);
    if ($fulfilledAcceptor) {
        remember_listing(
            'pre_order_fulfilled_acceptor',
            $fulfilledAcceptor,
            'Accepted swap — asking equalized to offer; sourcing_fulfilled logged'
        );
    }
    $fulfilledOrigin = (new ListingRepository())->find((int) $fulfilledBoard['origin']->variationId);
    if ($fulfilledOrigin) {
        remember_listing(
            'pre_order_fulfilled_origin',
            $fulfilledOrigin,
            'Detached after swap (staff-opened then accepted)'
        );
    }
    $REPORT['orders']['pre_order_fulfilled'] = $fulfilledBoard['order_id'];
    $REPORT['pre_order']['fulfilled_origin_variation_id'] = (int) $fulfilledBoard['origin']->variationId;
    $REPORT['pre_order']['fulfilled_acceptor_variation_id'] = (int) $fulfilledBoard['acceptor']->variationId;
    $REPORT['pre_order']['fulfilled_order_id'] = $fulfilledBoard['order_id'];
    $REPORT['pre_order']['fulfilled_asking'] = (int) ($accepted['asking'] ?? ask(40));

    update_option('sutore_marketplace_pre_order_digest_sent_ids', [
        (int) $autoBoard['origin']->variationId => time(),
    ], false);
    $REPORT['notes'][] = 'Pre-order digest already sent for auto-opened #' . (int) $autoBoard['origin']->variationId
        . ' (staff-opened #' . (int) $staffBoard['origin']->variationId . ' is still new for digest)';
    $REPORT['notes'][] = 'demo_seller_verified Pre-order: staff-opened size 42 — accept shows price '
        . ask(50) . ' → ' . ask(48);
    $REPORT['notes'][] = 'demo_seller_premium Pre-order: also sees fresh auto-opened size 43 (Confirmed waits 24h)';
    $REPORT['notes'][] = 'demo_seller_normal: no Pre-order menu (Confirmed+ required)';
    $REPORT['notes'][] = 'Events: sourcing_fulfilled on accepted listing #'
        . (int) $fulfilledBoard['acceptor']->variationId;
    seed_log('Pre-order board seeded (staff + auto + fulfilled)');

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
        // Narrow targeting so market/queue listings stay free for new-campaign preview.
        'targeting' => [
            'product_ids' => [$parentCampaign],
        ],
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
        if ((int) $offer->variation_id === (int) $campListingA->variationId) {
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

    // --- Outlet ---
    $outlet = new OutletService();
    $draftOutletId = $outlet->createWindow([
        'name' => 'Seed Outlet Draft',
        'starts_at' => seed_mysql_offset(3 * DAY_IN_SECONDS),
        'ends_at' => seed_mysql_offset(10 * DAY_IN_SECONDS),
        'notes' => 'Draft — publish from wp-admin Outlet',
    ]);
    if (is_wp_error($draftOutletId)) {
        throw new RuntimeException('Draft outlet: ' . $draftOutletId->get_error_message());
    }
    $draftItem = $outlet->addItem((int) $draftOutletId, [
        'parent_product_id' => $parentOutlet,
        'size_term_id' => (int) $size43->term_id,
        'customer_sale' => ask(120),
        'seller_net' => ask(100),
    ]);
    if (is_wp_error($draftItem)) {
        throw new RuntimeException('Draft outlet item: ' . $draftItem->get_error_message());
    }
    $REPORT['outlet']['draft'] = (int) $draftOutletId;

    $scheduledOutletId = $outlet->createWindow([
        'name' => 'Seed Outlet Upcoming',
        'starts_at' => seed_mysql_offset(2 * DAY_IN_SECONDS),
        'ends_at' => seed_mysql_offset(9 * DAY_IN_SECONDS),
        'notes' => 'Scheduled — verified seller has a waiting opt-in',
    ]);
    if (is_wp_error($scheduledOutletId)) {
        throw new RuntimeException('Scheduled outlet: ' . $scheduledOutletId->get_error_message());
    }
    $scheduledItem = $outlet->addItem((int) $scheduledOutletId, [
        'parent_product_id' => $parentOutlet,
        'size_term_id' => (int) $size42->term_id,
        'customer_sale' => ask(160),
        'seller_net' => ask(144),
    ]);
    if (is_wp_error($scheduledItem)) {
        throw new RuntimeException('Scheduled outlet item: ' . $scheduledItem->get_error_message());
    }
    $publishedUpcoming = $outlet->publish((int) $scheduledOutletId);
    if (is_wp_error($publishedUpcoming)) {
        throw new RuntimeException('Scheduled outlet publish: ' . $publishedUpcoming->get_error_message());
    }
    $pendingOptin = $outlet->optIn((int) $scheduledItem, $sellerVerified);
    if (is_wp_error($pendingOptin)) {
        throw new RuntimeException('Scheduled outlet opt-in: ' . $pendingOptin->get_error_message());
    }
    $REPORT['outlet']['scheduled'] = (int) $scheduledOutletId;
    $REPORT['outlet']['scheduled_item'] = (int) $scheduledItem;

    $liveOutletId = $outlet->createWindow([
        'name' => 'Seed Outlet Live',
        'starts_at' => seed_mysql_offset(-HOUR_IN_SECONDS),
        'ends_at' => seed_mysql_offset(7 * DAY_IN_SECONDS),
        'notes' => 'Open window — premium seller listing is live at committed asking',
    ]);
    if (is_wp_error($liveOutletId)) {
        throw new RuntimeException('Live outlet: ' . $liveOutletId->get_error_message());
    }
    $liveItem = $outlet->addItem((int) $liveOutletId, [
        'parent_product_id' => $parentOutlet,
        'size_term_id' => (int) $size42->term_id,
        'customer_sale' => ask(160),
        'seller_net' => ask(144),
    ]);
    if (is_wp_error($liveItem)) {
        throw new RuntimeException('Live outlet item: ' . $liveItem->get_error_message());
    }
    $publishedLive = $outlet->publish((int) $liveOutletId);
    if (is_wp_error($publishedLive)) {
        throw new RuntimeException('Live outlet publish: ' . $publishedLive->get_error_message());
    }
    $liveOptin = $outlet->optIn((int) $liveItem, $sellerPremium);
    if (is_wp_error($liveOptin)) {
        throw new RuntimeException('Live outlet opt-in: ' . $liveOptin->get_error_message());
    }
    $REPORT['outlet']['active'] = (int) $liveOutletId;
    $REPORT['outlet']['active_item'] = (int) $liveItem;
    $REPORT['outlet']['live_variation_id'] = (int) ($liveOptin['variation_id'] ?? 0);
    if (!empty($liveOptin['variation_id'])) {
        $liveListing = (new ListingRepository())->find((int) $liveOptin['variation_id']);
        if ($liveListing) {
            remember_listing('outlet_live', $liveListing, 'Outlet live listing (premium, asking ' . ask(144) . ')');
        }
    }
    $REPORT['notes'][] = 'demo_seller_verified Outlet: waiting opt-in on Seed Outlet Upcoming (Dunk 42, asking '
        . ask(144) . ' / customer ' . ask(160) . ') — listing is created when the window opens';
    $REPORT['notes'][] = 'demo_seller_premium Outlet: live listing on Seed Outlet Live at asking ' . ask(144)
        . '; customer price ' . ask(160) . ' (SEED-OUTLET size 42)';
    $REPORT['notes'][] = 'demo_seller_queued Outlet: can still join both open items; wp-admin Outlet has a draft window to publish';
    seed_log('Outlet: draft=#' . (int) $draftOutletId
        . ' scheduled=#' . (int) $scheduledOutletId
        . ' active=#' . (int) $liveOutletId
        . ' live listing=#' . (int) ($liveOptin['variation_id'] ?? 0));

    // --- Customer price offers ---
    $offerService = new CustomerOfferService();
    $marketLiveId = (int) ($REPORT['listings']['active_for_sale']['id'] ?? 0);
    $queueWinnerId = (int) ($REPORT['listings']['queue_winner']['id'] ?? 0);
    if ($marketLiveId <= 0 || $queueWinnerId <= 0) {
        throw new RuntimeException('Customer offers: missing market/queue listings');
    }

    $accepted = $offerService->create($customerId, $marketLiveId, (float) ask(22));
    if (is_wp_error($accepted)) {
        throw new RuntimeException('Customer offer accepted seed: ' . $accepted->get_error_message());
    }
    $acceptedApply = $offerService->accept((int) $accepted['offer_id'], $sellerVerified);
    if (is_wp_error($acceptedApply)) {
        throw new RuntimeException('Customer offer accept: ' . $acceptedApply->get_error_message());
    }
    $REPORT['customer_offers']['accepted'] = (int) $accepted['offer_id'];
    $REPORT['customer_offers']['coupon'] = (string) ($acceptedApply['coupon_code'] ?? '');

    $pending = $offerService->create($customerId, $queueWinnerId, (float) ask(16));
    if (is_wp_error($pending)) {
        throw new RuntimeException('Customer offer pending seed: ' . $pending->get_error_message());
    }
    $REPORT['customer_offers']['pending'] = (int) $pending['offer_id'];

    $forwarded = $offerService->create($customerYouthId, $queueWinnerId, (float) ask(15));
    if (is_wp_error($forwarded)) {
        throw new RuntimeException('Customer offer waterfall seed: ' . $forwarded->get_error_message());
    }
    $declined = $offerService->decline((int) $forwarded['offer_id'], $sellerVerified);
    if (is_wp_error($declined)) {
        throw new RuntimeException('Customer offer decline/forward: ' . $declined->get_error_message());
    }
    $REPORT['customer_offers']['forwarded_from'] = (int) $forwarded['offer_id'];
    $queueWaitingId = (int) ($REPORT['listings']['queue_waiting']['id'] ?? 0);
    $queueParentId = (int) ($REPORT['products']['queue'] ?? 0);
    $forwardedRow = (new CustomerOfferRepository())->findPendingForCustomerProductSize(
        $customerYouthId,
        $queueParentId,
        (int) $size43->term_id
    );
    if (
        !$forwardedRow
        || (int) $forwardedRow->listing_id !== $queueWaitingId
        || (int) $forwardedRow->merchant_id !== $sellerQueued
    ) {
        throw new RuntimeException('Customer offer waterfall did not reach the queued seller');
    }
    $REPORT['customer_offers']['forwarded_to'] = (int) $forwardedRow->id;
    $REPORT['notes'][] = 'demo_customer My offers: accepted coupon on Seed Dunk Low Market size 43 (asking '
        . ask(30) . ', bid ' . ask(22) . ', code ' . (string) ($acceptedApply['coupon_code'] ?? '')
        . '); pending offer on Seed Jordan 1 Queue size 43 (asking ' . ask(20) . ', bid ' . ask(16) . ').';
    $REPORT['notes'][] = 'demo_seller_verified Customer offers: pending bid ' . ask(16)
        . ' on Seed Jordan 1 Queue (asking ' . ask(20) . '). Accept issues a coupon; decline forwards to the queued seller.';
    $REPORT['notes'][] = 'demo_seller_queued Customer offers: forwarded bid ' . ask(15)
        . ' from demo_customer_youth after verified declined (queued asking ' . ask(35) . ').';
    $REPORT['notes'][] = 'PDP: logged-in demo_customer on SEED-MARKET size 43 sees the accepted-offer state; asking on the page is unchanged.';
    seed_log('Customer offers: accepted=#' . (int) $accepted['offer_id']
        . ' coupon=' . (string) ($acceptedApply['coupon_code'] ?? '')
        . ' pending=#' . (int) $pending['offer_id']
        . ' forwarded-from=#' . (int) $forwarded['offer_id']);

    // --- Opportunity templates ---
    (new OpportunityCardService())->ensureSystemTemplates();
    seed_log('Opportunity templates ensured');

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
            'variation_id' => (int) ($REPORT['listings']['active_for_sale']['id'] ?? 0),
            'dedupe_key' => 'seed:' . $n[0] . ':' . $i,
        ]);
    }
    seed_log('Notifications seeded');

    seed_log('Behavior listing events…');
    $eventsRepo = new ListingEventsRepository();

    $deliveredVariation = (int) ($REPORT['listings']['sale_delivered_to_customer']['id'] ?? 0);
    $confirmedVariation = (int) ($REPORT['listings']['sale_confirmed']['id'] ?? 0);
    $activeVariation = (int) ($REPORT['listings']['active_for_sale']['id'] ?? 0);

    if ($deliveredVariation > 0) {
        $eventsRepo->log(
            'fulfillment_seller_confirmed',
            ['asking' => ask(55), 'seed' => true],
            $deliveredVariation,
            $sellerPremium,
            'merchant_visible'
        );
        $eventsRepo->log(
            'fulfillment_shipped_to_sutore',
            ['asking' => ask(55), 'seed' => true],
            $deliveredVariation,
            $sellerPremium,
            'merchant_visible'
        );
    }

    if ($confirmedVariation > 0) {
        $eventsRepo->log(
            'fulfillment_seller_confirmed',
            ['asking' => ask(48), 'seed' => true],
            $confirmedVariation,
            $sellerVerified,
            'merchant_visible'
        );
        $eventsRepo->log(
            ListingEventType::CONFIRM_DEADLINE_MISSED,
            ['asking' => ask(42), 'seed' => true],
            $confirmedVariation,
            $sellerVerified,
            'merchant_visible'
        );
    }

    if ($activeVariation > 0) {
        $eventsRepo->log(
            ListingEventType::SELLER_CANCELLED,
            ['asking' => ask(40), 'seed' => true],
            $activeVariation,
            $sellerNormal,
            'merchant_visible'
        );
    }

    $REPORT['notes'][] = 'Behavior events seeded on premium/verified/normal demo sellers (see listing_events)';

    seed_log('Behavior scores + opportunity cards…');
    (new OpportunityCardService())->ensureSystemTemplates();
    $scoreService = new BehaviorScoreService();
    $cardService = new OpportunityCardService();
    foreach ($REPORT['users'] as $login => $uid) {
        if (in_array($login, ['admin', 'demo_customer', 'demo_customer_youth'], true)) {
            continue;
        }
        $merchantId = (int) $uid;
        $scoreService->refreshMerchant($merchantId);
        $cardService->generateForMerchant($merchantId);
    }
    $REPORT['notes'][] = 'Behavior scores computed; monthly opportunity cards generated';
    $REPORT['notes'][] = 'demo_seller_normal (New): no pre-order board — Confirmed+ required';
    $REPORT['notes'][] = 'demo_seller_premium (Super): strong score from seeded listing events';
    $REPORT['notes'][] = 'demo_seller_verified: score affected by seeded confirm_deadline_missed event';
    $REPORT['notes'][] = 'Behavior weights/thresholds: WooCommerce → Marketplace → Settings → Behavior tab';

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
    seed_log('Pre-order:');
    seed_log('  staff-open  #' . ($REPORT['pre_order']['board_variation_id'] ?? 0)
        . ' order=#' . ($REPORT['pre_order']['order_id'] ?? 0)
        . ' acceptor=#' . ($REPORT['pre_order']['acceptor_variation_id'] ?? 0)
        . ' (price ' . ($REPORT['pre_order']['staff_acceptor_asking'] ?? '') . ' → ' . ($REPORT['pre_order']['staff_origin_asking'] ?? '') . ')');
    seed_log('  auto-open   #' . ($REPORT['pre_order']['auto_board_variation_id'] ?? 0)
        . ' order=#' . ($REPORT['pre_order']['auto_order_id'] ?? 0)
        . ' acceptor=#' . ($REPORT['pre_order']['auto_acceptor_variation_id'] ?? 0)
        . ' (Super-only for 24h)');
    seed_log('  fulfilled   origin=#' . ($REPORT['pre_order']['fulfilled_origin_variation_id'] ?? 0)
        . ' acceptor=#' . ($REPORT['pre_order']['fulfilled_acceptor_variation_id'] ?? 0)
        . ' order=#' . ($REPORT['pre_order']['fulfilled_order_id'] ?? 0)
        . ' (sourcing_fulfilled logged)');
    seed_log('Campaigns: draft=#' . ($REPORT['campaigns']['draft'] ?? 0)
        . ' active=#' . ($REPORT['campaigns']['active'] ?? 0)
        . ' ended=#' . ($REPORT['campaigns']['ended'] ?? 0));
    seed_log('Outlet: draft=#' . ($REPORT['outlet']['draft'] ?? 0)
        . ' scheduled=#' . ($REPORT['outlet']['scheduled'] ?? 0)
        . ' active=#' . ($REPORT['outlet']['active'] ?? 0)
        . ' live=#' . ($REPORT['outlet']['live_variation_id'] ?? 0));
    seed_log('Customer offers: accepted=#' . ($REPORT['customer_offers']['accepted'] ?? 0)
        . ' coupon=' . ($REPORT['customer_offers']['coupon'] ?? '')
        . ' pending=#' . ($REPORT['customer_offers']['pending'] ?? 0)
        . ' forwarded-to=#' . ($REPORT['customer_offers']['forwarded_to'] ?? 0));
    if (($REPORT['notes'] ?? []) !== []) {
        seed_log('');
        seed_log('Notes:');
        foreach ($REPORT['notes'] as $note) {
            seed_log('  - ' . $note);
        }
    }
    seed_log('');
    seed_log('Verify: docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/verify-strict-workflows.php');
    seed_log('Try My Account as demo_seller_verified / demo_seller_sale / demo_seller_referred');
    seed_log('Pre-order board: demo_seller_verified or demo_seller_premium (not demo_seller_normal)');
    seed_log('Opportunities: any merchant → My Account → Opportunities');
    seed_log('Staff Manage Products + Merchants with an administrator.');
    seed_log('Payout click-through:');
    seed_log('  WP Admin → Sutore Marketplace Settings → Order Flow → Payout (7 days, Wednesday)');
    seed_log('  Staff Manage Products → Filter → Due for payout → Export CSV → bulk Mark as Paid + payment_ref');
    seed_log('  Login demo_seller_sale → merchant profile Alacaklarım (future date + paid line)');
    seed_log('Payout click-through:');
    seed_log('  WP Admin → Sutore Marketplace Settings → Order Flow → Payout (7 days, Wednesday)');
    seed_log('  Staff Manage Products → Filter → Due for payout → Export CSV → bulk Mark as Paid + payment_ref');
    seed_log('  Login demo_seller_sale → merchant profile Alacaklarım (future date + paid line)');
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
