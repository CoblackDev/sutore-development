<?php

/**
 * Print scenario / demo seed state for quick inspection.
 *
 * Usage:
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/check-demo-state.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = '/var/www/html';
if (!is_file($root . '/wp-load.php')) {
    $root = dirname(__DIR__, 4);
}
require $root . '/wp-load.php';

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;

$scenarios = get_option('sutore_marketplace_scenarios_seed_state');
$lifecycle = get_option('sutore_lifecycle_demo_state');

if (is_array($scenarios) && $scenarios !== []) {
    echo "=== sutore_marketplace_scenarios_seed_state ===" . PHP_EOL;
    echo json_encode($scenarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    $verifyId = (int) ($scenarios['verify']['delivered_listing_id'] ?? 0);
    if ($verifyId > 0) {
        print_listing_snapshot($verifyId, 'verify.delivered_listing_id');
    }

    $orderId = (int) ($scenarios['verify']['delivered_order_id'] ?? 0);
    if ($orderId > 0) {
        $order = wc_get_order($orderId);
        echo '--- wc order #' . $orderId . ' (verify sample) ---' . PHP_EOL;
        echo 'order_status: ' . ($order ? $order->get_status() : 'missing') . PHP_EOL;
    }

    exit(0);
}

if (is_array($lifecycle) && $lifecycle !== []) {
    echo "=== sutore_lifecycle_demo_state (legacy) ===" . PHP_EOL;
    echo json_encode($lifecycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    $lid = (int) ($lifecycle['variation_id'] ?? 0);
    if ($lid > 0) {
        print_listing_snapshot($lid, 'lifecycle variation');
    }

    $oid = (int) ($lifecycle['order_id'] ?? 0);
    $order = wc_get_order($oid);
    echo '--- wc order #' . $oid . ' ---' . PHP_EOL;
    echo 'order_status: ' . ($order ? $order->get_status() : 'missing') . PHP_EOL;
    exit(0);
}

fwrite(STDERR, "No seed state found. Run seed-scenarios.php --force first.\n");
exit(1);

function print_listing_snapshot(int $listingId, string $label): void
{
    $repo = new FulfillmentRepository();
    $f = $repo->find($listingId);
    $listing = (new ListingRepository())->find($listingId);

    echo '--- ' . $label . ' (listing #' . $listingId . ') ---' . PHP_EOL;
    echo 'listing_status: ' . ($listing->listingStatus ?? 'missing') . PHP_EOL;
    echo 'listing_status_label: ' . ListingStatus::label((string) ($listing->listingStatus ?? '')) . PHP_EOL;
    echo 'fulfillment_status: ' . ($f->fulfillment_status ?? 'missing') . PHP_EOL;
    echo 'is terminal: ' . (ListingStatus::isSaleTerminal((string) ($f->fulfillment_status ?? '')) ? 'yes' : 'no') . PHP_EOL;
}
