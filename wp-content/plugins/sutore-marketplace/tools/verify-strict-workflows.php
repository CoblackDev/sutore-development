<?php

/**
 * Smoke checks for strict fulfillment/listing workflow guards.
 *
 * Usage:
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/verify-strict-workflows.php
 *
 * Expects seed-scenarios.php (sutore_marketplace_scenarios_seed_state) or,
 * as fallback, seed-lifecycle-demo.php (sutore_lifecycle_demo_state).
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

/** @return array<string, mixed> */
function load_seed_state(): array
{
    $scenarios = get_option('sutore_marketplace_scenarios_seed_state', []);
    if (is_array($scenarios) && !empty($scenarios['verify']['delivered_listing_id'])) {
        return $scenarios;
    }

    $lifecycle = get_option('sutore_lifecycle_demo_state', []);
    if (is_array($lifecycle) && !empty($lifecycle['variation_id'])) {
        return [
            'verify' => [
                'delivered_listing_id' => (int) $lifecycle['variation_id'],
                'sold_listing_id' => (int) $lifecycle['variation_id'],
                'chargeback_listing_id' => (int) $lifecycle['variation_id'],
            ],
            '_source' => 'lifecycle_demo',
        ];
    }

    return [];
}

$state = load_seed_state();
$deliveredId = (int) ($state['verify']['delivered_listing_id'] ?? 0);
$soldId = (int) ($state['verify']['sold_listing_id'] ?? 0);
$chargebackId = (int) ($state['verify']['chargeback_listing_id'] ?? 0);

if ($deliveredId <= 0) {
    fwrite(STDERR, "Run seed-scenarios.php --force first (or seed-lifecycle-demo.php).\n");
    exit(1);
}

if ($soldId <= 0) {
    $soldId = $deliveredId;
}
if ($chargebackId <= 0) {
    $chargebackId = $deliveredId;
}

$fs = new FulfillmentService();
$ls = new ListingService();
$repo = new FulfillmentRepository();

$adminId = (int) (get_user_by('login', 'admin')->ID ?? 0);
if ($adminId <= 0) {
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    $adminId = (int) ($admins[0] ?? 0);
}
if ($adminId > 0) {
    wp_set_current_user($adminId);
}

foreach (
    [
        'delivered' => $deliveredId,
        'sold' => $soldId,
        'chargeback' => $chargebackId,
    ] as $label => $listingId
) {
    $listing = (new ListingRepository())->find($listingId);
    $row = $repo->find($listingId);
    echo sprintf(
        'Listing #%d (%s) listing_status=%s fulfillment_status=%s' . PHP_EOL,
        $listingId,
        $label,
        $listing?->listingStatus ?? '—',
        $row->fulfillment_status ?? '—'
    );
}

echo PHP_EOL;

assert_error($fs->markArrivedAtSutore($soldId), 'sold → arrived skip');
assert_error(
    $fs->markShippedToCustomer($deliveredId, ['sutore_shipment_code' => 'SKIP']),
    'delivered → shipped skip'
);
assert_error($fs->markMerchantPayout($soldId, 'EARLY'), 'early payout on sold');
assert_error($fs->splitFromOrder($chargebackId, true, 'split', 'Staff test note'), 'terminal detach');
assert_error(
    $ls->removeFromSale($deliveredId, null, ['staff_note' => 'Should fail — order linked']),
    'order-linked remove-from-sale'
);

echo 'All strict workflow guard checks passed.' . PHP_EOL;
