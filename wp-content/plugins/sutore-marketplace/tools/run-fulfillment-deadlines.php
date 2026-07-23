<?php

/**
 * Run fulfillment deadline processor (suspend + auto open sourcing).
 *
 * Usage:
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/run-fulfillment-deadlines.php
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/run-fulfillment-deadlines.php --expire-now
 *
 * Since the fulfillments table has been eliminated, every "fulfillment id" in
 * this seed is really the listing id; sale fields live directly on the
 * listings row.
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

use SutoreMarketplace\Modules\Orders\Hooks\CronHooks;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Sourcing\Repositories\SourcingRepository;
use SutoreMarketplace\Shared\Database\Schema;

$expireNow = in_array('--expire-now', $argv, true);

if ($expireNow) {
    $state = get_option('sutore_sourcing_demo_state', []);
    $listingId = (int) ($state['listing_id'] ?? $state['seller_listing_id'] ?? 0);
    if ($listingId <= 0) {
        fwrite(STDERR, "No demo listing in sutore_sourcing_demo_state. Run seed-sourcing-demo.php first.\n");
        exit(1);
    }

    $past = date('Y-m-d H:i:s', current_time('timestamp') - MINUTE_IN_SECONDS);
    (new FulfillmentRepository())->update($listingId, [
        'confirm_deadline_at' => $past,
        'confirm_notice_sent' => 1,
        'confirm_punished' => 0,
    ]);
    echo "Forced listing #{$listingId} confirm_deadline_at={$past} (notice already sent)\n";
}

echo 'Running deadline batch at ' . current_time('mysql') . PHP_EOL;
(new CronHooks())->run();

$state = get_option('sutore_sourcing_demo_state', []);
$orderId = (int) ($state['order_id'] ?? 0);
$parentId = (int) ($state['parent_product_id'] ?? 0);
$sizeTermId = (int) ($state['size_term_id'] ?? 0);

if ($orderId > 0) {
    global $wpdb;
    $listings = Schema::table('listings');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, listing_status, confirm_deadline_at, confirm_punished
         FROM {$listings}
         WHERE order_id = %d
         ORDER BY id DESC
         LIMIT 5",
        $orderId
    ));
    echo PHP_EOL . "Sale rows (listings) for order #{$orderId}:" . PHP_EOL;
    foreach ($rows ?: [] as $row) {
        echo sprintf(
            "  listing #%d status=%s deadline=%s punished=%s\n",
            (int) $row->id,
            (string) $row->listing_status,
            (string) ($row->confirm_deadline_at ?? '-'),
            (string) $row->confirm_punished
        );
    }

    $sourcing = (new SourcingRepository())->query([
        'page' => 1,
        'per_page' => 10,
    ]);
    echo PHP_EOL . 'Recent sourcing requests:' . PHP_EOL;
    foreach ($sourcing['items'] as $item) {
        if ($orderId && (int) $item->order_id !== $orderId && $parentId && (int) $item->parent_product_id !== $parentId) {
            continue;
        }
        echo sprintf(
            "  #%d order=%d parent=%d size=%d status=%s accepted_merchant=%s notes=%s\n",
            (int) $item->id,
            (int) $item->order_id,
            (int) $item->parent_product_id,
            (int) $item->size_term_id,
            (string) $item->status,
            (string) ($item->accepted_merchant_id ?? '-'),
            (string) ($item->notes ?? '')
        );
    }

    if ($parentId && $sizeTermId) {
        $open = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . (new SourcingRepository())->table() . " WHERE order_id = %d AND parent_product_id = %d AND size_term_id = %d AND status IN ('open','accepted') ORDER BY id DESC LIMIT 1",
            $orderId,
            $parentId,
            $sizeTermId
        ));
        if ($open) {
            echo PHP_EOL . 'OK: sourcing request #' . (int) $open->id . ' is ' . $open->status . PHP_EOL;
            echo 'Next: login as demo_seller_accept → My Account → Pre-order → Accept' . PHP_EOL;
            echo 'Then: Admin → Pre-order → Complete' . PHP_EOL;
        } else {
            echo PHP_EOL . 'No open/accepted sourcing request yet for this demo order.' . PHP_EOL;
            echo 'If still sold, wait for deadline or re-run with --expire-now.' . PHP_EOL;
        }
    }
}

echo PHP_EOL . 'Done.' . PHP_EOL;
