<?php

/**
 * Smoke checks for strict fulfillment/listing workflow guards.
 *
 * Usage:
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/verify-strict-workflows.php
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

use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;

function assert_error($result, string $label): void
{
    if (is_wp_error($result)) {
        echo '[OK] ' . $label . ' → ' . $result->get_error_code() . PHP_EOL;
        return;
    }
    echo '[FAIL] ' . $label . ' was allowed' . PHP_EOL;
    exit(1);
}

$state = get_option('sutore_lifecycle_demo_state', []);
$listingId = (int) ($state['listing_id'] ?? 0);

if ($listingId <= 0) {
    fwrite(STDERR, "Run seed-lifecycle-demo.php first.\n");
    exit(1);
}

// Post-refactor: the fulfillments table no longer exists; sale rows live on
// the listings table and every "fulfillment id" is the listing id.
$fs = new FulfillmentService();
$ls = new ListingService();
$listing = (new ListingRepository())->find($listingId);
$row = (new FulfillmentRepository())->find($listingId);

echo 'Sale row (listing #' . $listingId . ') status=' . ($row->fulfillment_status ?? '—') . PHP_EOL;
echo 'Listing #' . $listingId . ' status=' . ($listing?->listingStatus ?? '—') . PHP_EOL;

assert_error($fs->markArrivedAtSutore($listingId), 'sold → arrived skip');
assert_error(
    $fs->markShippedToCustomer($listingId, ['sutore_shipment_code' => 'SKIP']),
    'verified → shipped skip'
);
assert_error($fs->markMerchantPayout($listingId, 'EARLY'), 'early/closed payout');
assert_error($fs->splitFromOrder($listingId, true, 'split', 'Staff test note'), 'terminal detach');
assert_error(
    $ls->removeFromSale($listingId, null, ['staff_note' => 'Should fail — order linked']),
    'order-linked remove-from-sale'
);

echo 'All strict workflow guard checks passed.' . PHP_EOL;
