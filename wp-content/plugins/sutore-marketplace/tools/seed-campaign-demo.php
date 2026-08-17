<?php

/**
 * Campaign demo: one language, three doors — admin, system suggestion, seller start.
 *
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-campaign-demo.php --force
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-campaign-demo.php --force --expire-now
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

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\CampaignService;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Settings\Settings;

$cliArgs = array_slice($argv, 1);
$force = in_array('--force', $cliArgs, true);
$expireNow = in_array('--expire-now', $cliArgs, true);

const SEED_META = '_sutore_marketplace_campaign_demo';
const PASSWORD = 'password123';
const STATE_OPTION = 'sutore_campaign_demo_state';

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

function upsert_merchant(string $login, string $email, string $first, string $last, string $phone): int
{
    $existing = get_user_by('login', $login);
    if ($existing) {
        $userId = (int) $existing->ID;
        wp_set_password(PASSWORD, $userId);
        (new WP_User($userId))->set_role('merchant');
    } else {
        $userId = (int) wp_insert_user([
            'user_login' => $login,
            'user_pass' => PASSWORD,
            'user_email' => $email,
            'display_name' => $first . ' ' . $last,
            'role' => 'merchant',
        ]);
        if ($userId <= 0 || is_wp_error($userId)) {
            throw new RuntimeException('Could not create merchant ' . $login);
        }
    }

    MerchantMeta::writeProfile($userId, [
        MerchantMeta::ACCOUNT_PHONE => $phone,
        MerchantMeta::ACCOUNT_NAME => $first,
        MerchantMeta::ACCOUNT_LASTNAME => $last,
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

/** @return list<WP_Term> */
function pick_size_terms(int $need): array
{
    $taxonomy = seed_catalog_primary_taxonomy();
    if (!taxonomy_exists($taxonomy)) {
        throw new RuntimeException('Taxonomy ' . $taxonomy . ' does not exist.');
    }
    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
        'number' => 80,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (is_wp_error($terms) || $terms === []) {
        throw new RuntimeException('No ' . $taxonomy . ' terms found.');
    }
    $picked = [];
    foreach ($terms as $term) {
        if (!($term instanceof WP_Term)) {
            continue;
        }
        if (str_contains((string) $term->slug, 'campaign-demo') || str_contains((string) $term->slug, 'lifecycle')) {
            continue;
        }
        $picked[] = $term;
        if (count($picked) >= $need) {
            break;
        }
    }
    $i = 0;
    while (count($picked) < $need) {
        $picked[] = seed_catalog_ensure_size_term(
            'campaign-demo-' . $i,
            'Demo ' . (40 + $i)
        );
        $i++;
    }

    return array_slice($picked, 0, $need);
}

function purge_previous(): void
{
    global $wpdb;
    $state = get_option(STATE_OPTION);
    $variationIds = [];
    $parentIds = [];
    $campaignIds = [];
    if (is_array($state)) {
        $variationIds = array_map('intval', (array) ($state['variation_ids'] ?? []));
        if (!empty($state['variation_id'])) {
            $variationIds[] = (int) $state['variation_id'];
        }
        $parentIds = array_map('intval', (array) ($state['parent_ids'] ?? []));
        if (!empty($state['parent_product_id'])) {
            $parentIds[] = (int) $state['parent_product_id'];
        }
        $campaignIds = array_map('intval', (array) ($state['campaign_ids'] ?? []));
        if (!empty($state['campaign_id'])) {
            $campaignIds[] = (int) $state['campaign_id'];
        }
    }

    foreach (array_unique($campaignIds) as $campaignId) {
        if ($campaignId <= 0) {
            continue;
        }
        $wpdb->delete(Schema::table('campaign_offers'), ['campaign_id' => $campaignId]);
        $wpdb->delete(Schema::table('campaigns'), ['id' => $campaignId]);
    }

    $ids = get_posts([
        'post_type' => ['product', 'product_variation'],
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids',
        'meta_key' => SEED_META,
        'meta_value' => '1',
    ]);
    foreach ($ids as $id) {
        $variationIds[] = (int) $id;
        $parent = (int) wp_get_post_parent_id((int) $id);
        if ($parent > 0) {
            $parentIds[] = $parent;
        }
        $parentIds[] = (int) $id;
    }

    $events = new ListingEventsRepository();
    foreach (array_unique($variationIds) as $variationId) {
        if ($variationId <= 0) {
            continue;
        }
        $events->deleteForListing($variationId);
        $wpdb->delete(Schema::table('listing_conditions'), ['variation_id' => $variationId]);
        $wpdb->delete(Schema::table('listings'), ['variation_id' => $variationId]);
        $wpdb->delete(Schema::table('campaign_offers'), ['variation_id' => $variationId]);
        wp_delete_post($variationId, true);
    }
    foreach (array_unique($parentIds) as $parentId) {
        if ($parentId <= 0) {
            continue;
        }
        $product = wc_get_product($parentId);
        if ($product && $product->is_type('variable')) {
            foreach ($product->get_children() as $childId) {
                wp_delete_post((int) $childId, true);
            }
        }
        wp_delete_post($parentId, true);
    }

    delete_option(STATE_OPTION);
    seed_log('Purged previous campaign demo state.');
}

function create_listing(ListingService $service, int $merchantId, int $parentId, int $sizeTermId, float $asking): \SutoreMarketplace\Modules\Listings\Domain\Listing
{
    wp_set_current_user($merchantId);
    $listing = $service->create([
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
    if (is_wp_error($listing)) {
        throw new RuntimeException('Listing create failed: ' . $listing->get_error_message());
    }
    update_post_meta((int) $listing->variationId, SEED_META, '1');
    if ($listing->listingStatus === ListingStatus::PENDING) {
        $approved = (new ListingSelector())->approvePendingWinner((int) $listing->variationId);
        if (is_wp_error($approved)) {
            throw new RuntimeException('Approve failed: ' . $approved->get_error_message());
        }
        $listing = $approved;
    }
    $fresh = (new ListingRepository())->find((int) $listing->variationId);
    if (!$fresh) {
        throw new RuntimeException('Listing missing after create');
    }

    return $fresh;
}

function backdate_on_sale(int $variationId, int $merchantId, int $daysAgo): void
{
    global $wpdb;
    $at = wp_date('Y-m-d H:i:s', (int) current_time('timestamp') - ($daysAgo * DAY_IN_SECONDS));
    $wpdb->update(
        Schema::table('listings'),
        ['created_at' => $at],
        ['variation_id' => $variationId]
    );
    $startTypes = [
        'listing_created',
        'listing_put_on_sale',
        'listing_went_on_sale',
        'listing_approved',
    ];
    $placeholders = implode(',', array_fill(0, count($startTypes), '%s'));
    $wpdb->query($wpdb->prepare(
        'UPDATE ' . Schema::table('listing_events') . "
         SET created_at = %s
         WHERE variation_id = %d
           AND event_type IN ({$placeholders})",
        $at,
        $variationId,
        ...$startTypes
    ));
    unset($merchantId);
}

function print_prices(string $label, int $variationId): void
{
    $listing = (new ListingRepository())->find($variationId);
    if (!$listing) {
        seed_log($label . ': listing missing');
        return;
    }
    $product = wc_get_product($variationId);
    $customer = MarketplacePricing::customerPrice($listing);
    $compare = MarketplacePricing::compareAtPrice($listing);
    seed_log(sprintf(
        '%s | #%d asking=%.0f campaign=%s aging=%d | customer=%.0f compare=%.0f strikethrough=%s',
        $label,
        $variationId,
        $listing->asking,
        $listing->campaignStatus,
        $listing->campaignAgingStep,
        $customer,
        $compare,
        $compare > $customer ? 'yes' : 'no'
    ));
}

try {
    Schema::install();
    ensure_merchant_role();

    if ($expireNow) {
        $state = get_option(STATE_OPTION);
        if (!is_array($state)) {
            throw new RuntimeException('No campaign demo state. Run seed without --expire-now first.');
        }
        $n = (new CampaignService())->runExpiryPass(200);
        seed_log('Expiry pass reverted offers: ' . $n);
        foreach ((array) ($state['variation_ids'] ?? []) as $variationId) {
            print_prices('AFTER expire', (int) $variationId);
        }
        exit(0);
    }

    if ($force || get_option(STATE_OPTION)) {
        purge_previous();
    }

    $sizes = pick_size_terms(8);
    $sellerId = upsert_merchant('demo_campaign_seller', 'demo_campaign_seller@example.com', 'Demo Campaign', 'Seller', '5552001001');
    $winnerId = upsert_merchant('demo_campaign_winner', 'demo_campaign_winner@example.com', 'Demo Campaign', 'Winner', '5552001002');
    $parentId = seed_catalog_create_variable_parent(
        'Campaign Demo Nike Dunk Low',
        'CAMP-DEMO-001',
        seed_catalog_primary_taxonomy(),
        $sizes,
        SEED_META
    );

    $step = max(1, Settings::listingPriceStep());
    $high = (int) (floor(max($step * 40, 2000) / $step) * $step);
    $low = (int) (floor(max($step * 28, 1400) / $step) * $step);
    $service = new ListingService();
    $campaigns = new CampaignService();
    $listings = new ListingRepository();
    $offers = new CampaignOfferRepository();
    $variationIds = [];
    $campaignIds = [];
    $scenarios = [];

    seed_log('Merchant seller #' . $sellerId . ' (demo_campaign_seller / ' . PASSWORD . ')');
    seed_log('Merchant winner #' . $winnerId . ' (demo_campaign_winner / ' . PASSWORD . ')');
    seed_log('Parent product #' . $parentId);

    // Size 0: competitor winner (low) + aging seller (high) → system suggestion card.
    $winnerListing = create_listing($service, $winnerId, $parentId, (int) $sizes[0]->term_id, (float) $low);
    $agingListing = create_listing($service, $sellerId, $parentId, (int) $sizes[0]->term_id, (float) $high);
    backdate_on_sale((int) $agingListing->variationId, $sellerId, 46);
    $variationIds[] = (int) $winnerListing->variationId;
    $variationIds[] = (int) $agingListing->variationId;
    $agingCreated = $campaigns->runAgingPass(20);
    seed_log('Aging pass created ' . $agingCreated . ' suggestion offer(s) for 45-day listing.');
    $agingListing = $listings->find((int) $agingListing->variationId) ?: $agingListing;
    if ($agingListing->campaignId) {
        $campaignIds[] = (int) $agingListing->campaignId;
    }
    $scenarios['system_suggestion_pending'] = (int) $agingListing->variationId;
    print_prices('SYSTEM 45d suggestion', (int) $agingListing->variationId);

    // Size 1: 60-day stronger suggestion (step 1 already consumed) + cheaper winner.
    $winner2 = create_listing($service, $winnerId, $parentId, (int) $sizes[1]->term_id, (float) $low);
    $aging2 = create_listing($service, $sellerId, $parentId, (int) $sizes[1]->term_id, (float) $high);
    $listings->update((int) $aging2->variationId, ['campaign_aging_step' => 1]);
    backdate_on_sale((int) $aging2->variationId, $sellerId, 61);
    $variationIds[] = (int) $winner2->variationId;
    $variationIds[] = (int) $aging2->variationId;
    $campaigns->runAgingPass(20);
    $aging2 = $listings->find((int) $aging2->variationId) ?: $aging2;
    if ($aging2->campaignId) {
        $campaignIds[] = (int) $aging2->campaignId;
    }
    $scenarios['system_suggestion_step2'] = (int) $aging2->variationId;
    print_prices('SYSTEM 60d matched suggestion', (int) $aging2->variationId);

    // Size 7: declined suggestion so step 1 does not re-fire.
    $declined = create_listing($service, $sellerId, $parentId, (int) $sizes[7]->term_id, (float) $high);
    backdate_on_sale((int) $declined->variationId, $sellerId, 46);
    $variationIds[] = (int) $declined->variationId;
    $campaigns->runAgingPass(20);
    $declined = $listings->find((int) $declined->variationId) ?: $declined;
    if ($declined->campaignId) {
        $pendingDecline = $offers->findPendingForVariationCampaign(
            (int) $declined->variationId,
            (int) $declined->campaignId
        );
        if ($pendingDecline) {
            $declinedResult = $campaigns->declineOffer((int) $pendingDecline->id, $sellerId);
            if (is_wp_error($declinedResult)) {
                throw new RuntimeException('Decline failed: ' . $declinedResult->get_error_message());
            }
        }
        $campaignIds[] = (int) $declined->campaignId;
    }
    $declined = $listings->find((int) $declined->variationId) ?: $declined;
    $scenarios['system_suggestion_declined'] = (int) $declined->variationId;
    print_prices('SYSTEM declined (Not now)', (int) $declined->variationId);

    // Size 2: admin pending offer (gate 1).
    $adminPending = create_listing($service, $sellerId, $parentId, (int) $sizes[2]->term_id, (float) $high);
    $variationIds[] = (int) $adminPending->variationId;
    $adminCampaignId = $campaigns->createCampaign([
        'name' => 'Demo admin campaign 15% / fee waiver',
        'seller_discount_type' => 'percent',
        'seller_discount_amount' => 15,
        'platform_discount_type' => 'percent',
        'platform_discount_amount' => 100,
        'starts_at' => CampaignDatetime::nowMysql(),
        'ends_at' => CampaignDatetime::plusDays(7),
        'targeting' => ['product_ids' => [$parentId]],
        'notes' => 'Admin-opened campaign (gate 1).',
    ]);
    if (is_wp_error($adminCampaignId)) {
        throw new RuntimeException('Admin campaign failed: ' . $adminCampaignId->get_error_message());
    }
    $campaignIds[] = (int) $adminCampaignId;
    $offer = $campaigns->createOfferForListing((int) $adminCampaignId, (int) $adminPending->variationId, [
        'skip_targeting' => true,
        'activate_campaign' => true,
        'staff_manual' => true,
    ]);
    if (is_wp_error($offer)) {
        throw new RuntimeException('Admin offer failed: ' . $offer->get_error_message());
    }
    $scenarios['admin_pending'] = (int) $adminPending->variationId;
    print_prices('ADMIN pending offer', (int) $adminPending->variationId);

    // Size 3: admin accepted → customer strikethrough.
    $adminActive = create_listing($service, $sellerId, $parentId, (int) $sizes[3]->term_id, (float) $high);
    $variationIds[] = (int) $adminActive->variationId;
    $acceptedOffer = $campaigns->createOfferForListing((int) $adminCampaignId, (int) $adminActive->variationId, [
        'skip_targeting' => true,
        'activate_campaign' => true,
        'staff_manual' => true,
    ]);
    if (is_wp_error($acceptedOffer)) {
        throw new RuntimeException('Admin accept-offer failed: ' . $acceptedOffer->get_error_message());
    }
    $accepted = $campaigns->acceptOffer((int) $acceptedOffer['offer_id'], $sellerId);
    if (is_wp_error($accepted)) {
        throw new RuntimeException('Admin accept failed: ' . $accepted->get_error_message());
    }
    $scenarios['admin_active'] = (int) $adminActive->variationId;
    print_prices('ADMIN accepted strikethrough', (int) $adminActive->variationId);

    // Size 4: seller-started campaign (gate 3).
    $merchantStart = create_listing($service, $sellerId, $parentId, (int) $sizes[4]->term_id, (float) $high);
    $variationIds[] = (int) $merchantStart->variationId;
    $started = $campaigns->startMerchantCampaign((int) $merchantStart->variationId, $sellerId, 20.0, 7);
    if (is_wp_error($started)) {
        throw new RuntimeException('Merchant start failed: ' . $started->get_error_message());
    }
    $campaignIds[] = (int) $started['campaign_id'];
    $scenarios['merchant_started'] = (int) $merchantStart->variationId;
    print_prices('SELLER started campaign', (int) $merchantStart->variationId);

    // Size 5: silent asking drop (no campaign) + cooldown listing via ended campaign.
    $silent = create_listing($service, $sellerId, $parentId, (int) $sizes[5]->term_id, (float) $high);
    $variationIds[] = (int) $silent->variationId;
    $lower = (int) (floor(($high * 0.8) / $step) * $step);
    $updated = $service->update((int) $silent->variationId, ['asking' => $lower], $sellerId);
    if (is_wp_error($updated)) {
        throw new RuntimeException('Silent drop failed: ' . $updated->get_error_message());
    }
    $scenarios['silent_asking_drop'] = (int) $silent->variationId;
    print_prices('SILENT asking drop (no strikethrough)', (int) $silent->variationId);

    $cooldown = create_listing($service, $winnerId, $parentId, (int) $sizes[6]->term_id, (float) $low);
    $variationIds[] = (int) $cooldown->variationId;
    $coolStarted = $campaigns->startMerchantCampaign((int) $cooldown->variationId, $winnerId, 10.0, 3);
    if (is_wp_error($coolStarted)) {
        throw new RuntimeException('Cooldown start failed: ' . $coolStarted->get_error_message());
    }
    $ended = $campaigns->endCampaign((int) $coolStarted['campaign_id']);
    if (is_wp_error($ended)) {
        throw new RuntimeException('Cooldown end failed: ' . $ended->get_error_message());
    }
    $campaignIds[] = (int) $coolStarted['campaign_id'];
    $scenarios['cooldown'] = (int) $cooldown->variationId;
    print_prices('COOLDOWN after ended campaign', (int) $cooldown->variationId);

    $eligible = $listings->find((int) $winnerListing->variationId);
    $scenarios['eligible_seller_start'] = $eligible ? (int) $eligible->variationId : (int) $winnerListing->variationId;
    print_prices('ELIGIBLE put-on-campaign (winner listing)', $scenarios['eligible_seller_start']);

    $offersUrl = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('campaign-offers')
        : home_url('/my-account/campaign-offers/');
    $listingsUrl = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('listings')
        : home_url('/my-account/listings/');
    $permalink = get_permalink($parentId) ?: '';

    update_option(STATE_OPTION, [
        'parent_ids' => [$parentId],
        'parent_product_id' => $parentId,
        'variation_ids' => array_values(array_unique($variationIds)),
        'campaign_ids' => array_values(array_unique($campaignIds)),
        'merchant_id' => $sellerId,
        'winner_merchant_id' => $winnerId,
        'scenarios' => $scenarios,
        'permalink' => $permalink,
    ], false);

    seed_log('');
    seed_log('=== HOW TO CHECK ===');
    seed_log('Product: ' . $permalink);
    seed_log('Seller login: demo_campaign_seller / ' . PASSWORD);
    seed_log('Winner login: demo_campaign_winner / ' . PASSWORD);
    seed_log('Listings: ' . $listingsUrl);
    seed_log('Campaign offers: ' . $offersUrl);
    seed_log('Scenarios:');
    seed_log('  system_suggestion_pending  listing #' . $scenarios['system_suggestion_pending'] . '  → Campaign offers (45d card)');
    seed_log('  system_suggestion_step2     listing #' . $scenarios['system_suggestion_step2'] . '  → Campaign offers (60d matched)');
    seed_log('  admin_pending               listing #' . $scenarios['admin_pending'] . '  → Campaign offers (gate 1)');
    seed_log('  admin_active                listing #' . $scenarios['admin_active'] . '  → PDP strikethrough');
    seed_log('  merchant_started            listing #' . $scenarios['merchant_started'] . '  → Listings On campaign');
    seed_log('  silent_asking_drop          listing #' . $scenarios['silent_asking_drop'] . '  → PDP no strikethrough');
    seed_log('  system_suggestion_declined  listing #' . $scenarios['system_suggestion_declined'] . '  → Campaign offers (Not now, no re-nag)');
    seed_log('  cooldown                    listing #' . $scenarios['cooldown'] . '  → Put on campaign disabled');
    seed_log('  eligible_seller_start       listing #' . $scenarios['eligible_seller_start'] . '  → Put on campaign visible');
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    exit(1);
}
