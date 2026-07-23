<?php

/**
 * Campaign demo: product + pending offer (not accepted).
 * Uses an existing pa_beden-numara term from the database.
 *
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-campaign-demo.php --force
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-campaign-demo.php --force --pending-only
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

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignRepository;
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
$pendingOnly = in_array('--pending-only', $cliArgs, true) || !in_array('--accept', $cliArgs, true);

const SEED_META = '_sutore_marketplace_campaign_demo';
const PASSWORD = 'SutoreDemo123!';
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

function upsert_merchant(): int
{
    $login = 'demo_campaign_seller';
    $email = 'demo_campaign_seller@example.com';
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
            'display_name' => 'Demo Campaign Seller',
            'role' => 'merchant',
        ]);
        if ($userId <= 0 || is_wp_error($userId)) {
            throw new RuntimeException('Could not create merchant');
        }
    }

    MerchantMeta::writeProfile($userId, [
        MerchantMeta::ACCOUNT_PHONE => '5552001001',
        MerchantMeta::ACCOUNT_NAME => 'Demo Campaign',
        MerchantMeta::ACCOUNT_LASTNAME => 'Seller',
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

/** Pick a real size term already in the DB (not a seed-only slug). */
function pick_existing_size_term(): WP_Term
{
    $taxonomy = 'pa_beden-numara';
    if (!taxonomy_exists($taxonomy)) {
        throw new RuntimeException('Taxonomy pa_beden-numara does not exist.');
    }

    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
        'number' => 50,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (is_wp_error($terms) || $terms === []) {
        throw new RuntimeException('No pa_beden-numara terms found in the database.');
    }

    // Prefer numeric-looking sizes; skip previous demo-only slugs if others exist.
    $preferred = null;
    foreach ($terms as $term) {
        if (!($term instanceof WP_Term)) {
            continue;
        }
        if (str_contains((string) $term->slug, 'campaign-demo') || str_contains((string) $term->slug, 'lifecycle')) {
            continue;
        }
        $preferred = $term;
        if (preg_match('/^\d/', (string) $term->name) || preg_match('/^\d/', (string) $term->slug)) {
            return $term;
        }
    }

    $fallback = $preferred ?: $terms[0];
    if (!$fallback instanceof WP_Term) {
        throw new RuntimeException('Could not resolve a size term.');
    }

    return $fallback;
}

function purge_previous(): void
{
    global $wpdb;
    $state = get_option(STATE_OPTION);
    if (!is_array($state)) {
        // Also wipe any leftover seed-marked campaign products.
        $ids = get_posts([
            'post_type' => ['product', 'product_variation'],
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => SEED_META,
            'meta_value' => '1',
        ]);
        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }
        return;
    }

    $listingId = (int) ($state['listing_id'] ?? 0);
    $variationId = (int) ($state['variation_id'] ?? 0);
    $parentId = (int) ($state['parent_product_id'] ?? 0);
    $campaignId = (int) ($state['campaign_id'] ?? 0);
    $offerId = (int) ($state['offer_id'] ?? 0);

    if ($offerId > 0) {
        $wpdb->delete(Schema::table('campaign_offers'), ['id' => $offerId]);
    }
    if ($campaignId > 0) {
        $wpdb->delete(Schema::table('campaign_offers'), ['campaign_id' => $campaignId]);
        $wpdb->delete(Schema::table('campaigns'), ['id' => $campaignId]);
    }
    if ($listingId > 0) {
        (new ListingEventsRepository())->deleteForListing($listingId, $variationId);
        $wpdb->delete(Schema::table('listing_conditions'), ['listing_id' => $listingId]);
        $wpdb->delete(Schema::table('listings'), ['id' => $listingId]);
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
    }

    // Clean seed-marked leftovers.
    $ids = get_posts([
        'post_type' => ['product', 'product_variation'],
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids',
        'meta_key' => SEED_META,
        'meta_value' => '1',
    ]);
    foreach ($ids as $id) {
        wp_delete_post((int) $id, true);
    }

    delete_option(STATE_OPTION);
    seed_log('Purged previous campaign demo state.');
}

function ensure_parent_product(WP_Term $sizeTerm): int
{
    $product = new WC_Product_Variable();
    $product->set_name('Campaign Demo Nike Dunk Low');
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description('Demo product for platform campaign offer (pending accept).');
    $product->set_sku('CAMPAIGN-DEMO-' . gmdate('YmdHis'));
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');

    $attribute = new WC_Product_Attribute();
    $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_beden-numara'));
    $attribute->set_name('pa_beden-numara');
    $attribute->set_options([$sizeTerm->term_id]);
    $attribute->set_visible(true);
    $attribute->set_variation(true);
    $product->set_attributes([$attribute]);
    $parentId = $product->save();
    if (!$parentId) {
        throw new RuntimeException('Parent product create failed');
    }

    wp_set_object_terms($parentId, [$sizeTerm->term_id], 'pa_beden-numara');
    update_post_meta($parentId, SEED_META, '1');
    update_post_meta($parentId, 'urun_kodu', 'CAMP-DEMO-001');

    return (int) $parentId;
}

function print_prices(string $label, int $listingId, int $variationId): void
{
    $listing = (new ListingRepository())->find($listingId);
    if (!$listing) {
        seed_log($label . ': listing missing');
        return;
    }
    $product = wc_get_product($variationId);
    $customer = MarketplacePricing::customerPrice($listing);
    $compare = MarketplacePricing::compareAtPrice($listing);
    seed_log(sprintf(
        '%s | listing.asking=%.2f campaign=%s | pricing customer=%.2f compare=%.2f | WC regular=%s sale=%s price=%s on_sale=%s',
        $label,
        $listing->asking,
        $listing->campaignStatus,
        $customer,
        $compare,
        $product ? (string) $product->get_regular_price('edit') : '-',
        $product ? (string) $product->get_sale_price('edit') : '-',
        $product ? (string) $product->get_price('edit') : '-',
        $product && $product->is_on_sale('edit') ? 'yes' : 'no'
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
        $campaignId = (int) ($state['campaign_id'] ?? 0);
        $listingId = (int) ($state['listing_id'] ?? 0);
        $variationId = (int) ($state['variation_id'] ?? 0);
        print_prices('BEFORE expire', $listingId, $variationId);
        (new CampaignRepository())->update($campaignId, [
            'ends_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);
        // ends_at in campaign table is WP-local; set past local time
        (new CampaignRepository())->update($campaignId, [
            'ends_at' => wp_date('Y-m-d H:i:s', time() - 60),
        ]);
        $n = (new CampaignService())->runExpiryPass(50);
        seed_log('Expiry pass reverted offers: ' . $n);
        print_prices('AFTER expire', $listingId, $variationId);
        exit(0);
    }

    if ($force || get_option(STATE_OPTION)) {
        purge_previous();
    }

    $size = pick_existing_size_term();
    seed_log(sprintf('Using DB size term #%d (%s / %s)', $size->term_id, $size->name, $size->slug));

    $merchantId = upsert_merchant();
    $parentId = ensure_parent_product($size);
    seed_log('Merchant #' . $merchantId . ' (demo_campaign_seller / ' . PASSWORD . ')');
    seed_log('Parent product #' . $parentId);

    $step = max(1, Settings::listingPriceStep());
    $asking = max($step * 20, 2000);
    $asking = (int) (floor($asking / $step) * $step);

    wp_set_current_user($merchantId);
    $listingService = new ListingService();
    $listing = $listingService->create([
        'parent_product_id' => $parentId,
        'size_term_id' => (int) $size->term_id,
        'asking' => $asking,
        'fast_shipment' => 0,
        'has_invoice' => 0,
        'no_box' => 0,
        'box_damaged' => 0,
        'missing_accessory' => 0,
        'damaged' => 0,
        'used' => 0,
    ], $merchantId);
    if (is_wp_error($listing)) {
        throw new RuntimeException('Listing create failed: ' . $listing->get_error_message());
    }

    $listingId = (int) $listing->id;
    $variationId = (int) $listing->variationId;
    update_post_meta($variationId, SEED_META, '1');

    if ($listing->listingStatus === ListingStatus::PENDING) {
        $approved = (new ListingSelector())->approvePendingWinner($listingId);
        if (is_wp_error($approved)) {
            throw new RuntimeException('Approve failed: ' . $approved->get_error_message());
        }
        $listing = $approved;
        seed_log('Listing approved → ' . $listing->listingStatus);
    }

    $listing = (new ListingRepository())->find($listingId);
    if (!$listing || !in_array($listing->listingStatus, [ListingStatus::PUBLISH, ListingStatus::QUEUED], true)) {
        throw new RuntimeException('Listing not offerable (status=' . ($listing->listingStatus ?? 'missing') . ')');
    }

    seed_log(sprintf(
        'Listing #%d variation=#%d asking=%.0f status=%s winner=%s size=%s',
        $listingId,
        $variationId,
        $listing->asking,
        $listing->listingStatus,
        $listing->isWinner ? 'yes' : 'no',
        $size->name
    ));
    print_prices('BEFORE campaign', $listingId, $variationId);

    $startsAt = current_time('mysql');
    $endsAt = wp_date('Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS);
    $sellerDiscount = 5.0;
    $platformDiscount = 5.0;
    $sellerType = 'percent';
    $platformType = 'percent';

    $campaignService = new CampaignService();
    $campaignId = $campaignService->createCampaign([
        'name' => 'Demo Pending Campaign 5% / 5%',
        'seller_discount_type' => $sellerType,
        'seller_discount_amount' => $sellerDiscount,
        'platform_discount_type' => $platformType,
        'platform_discount_amount' => $platformDiscount,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'notes' => 'Seeded pending campaign — seller −5% asking, platform −5% of fees',
        'targeting' => [
            'merchant_levels' => [MerchantLevels::VERIFIED, MerchantLevels::PREMIUM, MerchantLevels::NORMAL],
            'product_ids' => [$parentId],
        ],
    ]);
    if (is_wp_error($campaignId)) {
        throw new RuntimeException('Campaign create failed: ' . $campaignId->get_error_message());
    }

    $publish = $campaignService->publish((int) $campaignId);
    if (is_wp_error($publish)) {
        throw new RuntimeException('Publish failed: ' . $publish->get_error_message());
    }
    seed_log(sprintf(
        'Campaign #%d published: offers_created=%d skipped=%d ends_at=%s',
        (int) $campaignId,
        (int) $publish['offers_created'],
        (int) $publish['offers_skipped'],
        $endsAt
    ));

    $offer = (new CampaignOfferRepository())->findPendingForListingCampaign($listingId, (int) $campaignId);
    if (!$offer) {
        $offers = (new CampaignOfferRepository())->findForMerchant($merchantId, 'pending', 5, 0);
        $offer = $offers[0] ?? null;
    }
    if (!$offer) {
        throw new RuntimeException('No pending offer created for listing #' . $listingId);
    }

    if (!$pendingOnly) {
        $accept = $campaignService->acceptOffer((int) $offer->id, $merchantId);
        if (is_wp_error($accept)) {
            throw new RuntimeException('Accept failed: ' . $accept->get_error_message());
        }
        seed_log('Offer #' . (int) $offer->id . ' accepted');
        print_prices('AFTER accept (sale active)', $listingId, $variationId);
    } else {
        seed_log('Offer #' . (int) $offer->id . ' left PENDING — accept it yourself in My Account');
        print_prices('AFTER offer (pending)', $listingId, $variationId);
    }

    $permalink = get_permalink($parentId) ?: '';
    $accountOffers = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('campaign-offers')
        : home_url('/my-account/campaign-offers/');
    $listingsUrl = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('listings')
        : home_url('/my-account/listings/');

    update_option(STATE_OPTION, [
        'campaign_id' => (int) $campaignId,
        'offer_id' => (int) $offer->id,
        'listing_id' => $listingId,
        'variation_id' => $variationId,
        'parent_product_id' => $parentId,
        'merchant_id' => $merchantId,
        'size_term_id' => (int) $size->term_id,
        'size_name' => (string) $size->name,
        'asking_before' => (float) $offer->asking_before,
        'seller_discount_type' => $sellerType,
        'seller_discount' => (float) $offer->seller_discount,
        'platform_discount_type' => $platformType,
        'platform_discount' => (float) $offer->platform_discount,
        'seller_discount_value' => $sellerDiscount,
        'platform_discount_value' => $platformDiscount,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'permalink' => $permalink,
        'pending_only' => $pendingOnly,
    ], false);

    seed_log('');
    seed_log('=== HOW TO CHECK ===');
    seed_log('Product: ' . $permalink);
    seed_log('Size used: #' . $size->term_id . ' ' . $size->name);
    seed_log('Merchant login: demo_campaign_seller / ' . PASSWORD);
    seed_log('Listings: ' . $listingsUrl);
    seed_log('Campaign offers (accept here): ' . $accountOffers);
    seed_log('Campaign ends at: ' . $endsAt);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
