<?php
/**
 * Run fulfillment deadline processing and print pre-order board state.
 *
 * Usage (inside Docker):
 *   php wp-content/plugins/sutore-marketplace/tools/run-fulfillment-deadlines.php
 *   php wp-content/plugins/sutore-marketplace/tools/run-fulfillment-deadlines.php --expire-now
 *   php wp-content/plugins/sutore-marketplace/tools/run-fulfillment-deadlines.php --order-id=123
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__, 4) . '/wp-load.php';

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Shared\Database\Schema;

$expireNow = in_array('--expire-now', $argv ?? [], true);
$orderId = 0;
$parentId = 0;
$sizeTermId = 0;

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--order-id=(\d+)$/', $arg, $m)) {
        $orderId = (int) $m[1];
    }
    if (preg_match('/^--parent-id=(\d+)$/', $arg, $m)) {
        $parentId = (int) $m[1];
    }
    if (preg_match('/^--size-term-id=(\d+)$/', $arg, $m)) {
        $sizeTermId = (int) $m[1];
    }
}

$repo = new FulfillmentRepository();
$service = new FulfillmentService();

if ($expireNow) {
    global $wpdb;
    $listings = Schema::table('listings');
    $wpdb->query(
        "UPDATE {$listings}
         SET confirm_deadline_at = DATE_SUB(NOW(), INTERVAL 1 HOUR),
             confirm_notice_sent = 1
         WHERE listing_status = 'sold'"
    );
    echo "Forced confirm deadlines into the past for sold listings.\n";
}

$batch = $repo->deadlineBatch(50);
echo 'Processing ' . count($batch) . " deadline row(s)...\n";
foreach ($batch as $row) {
    $service->processDeadline($row);
}

if ($orderId > 0) {
    global $wpdb;
    $listings = Schema::table('listings');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT variation_id, listing_status, confirm_deadline_at, confirm_punished, order_id
         FROM {$listings}
         WHERE order_id = %d
         ORDER BY variation_id DESC
         LIMIT 5",
        $orderId
    ));
    echo PHP_EOL . "Sale rows (listings) for order #{$orderId}:" . PHP_EOL;
    foreach ($rows ?: [] as $row) {
        echo sprintf(
            "  listing #%d status=%s deadline=%s punished=%s\n",
            (int) $row->variation_id,
            (string) $row->listing_status,
            (string) ($row->confirm_deadline_at ?? '-'),
            (string) $row->confirm_punished
        );
    }
}

$board = (new ListingRepository())->query([
    'status' => ListingStatus::PRE_ORDER,
    'page' => 1,
    'per_page' => 20,
    'orderby' => 'created_at',
    'order' => 'DESC',
]);
echo PHP_EOL . 'Pre-order board listings:' . PHP_EOL;
foreach ($board['items'] as $listing) {
    if ($orderId > 0 && (int) ($listing->orderId ?? 0) !== $orderId) {
        continue;
    }
    if ($parentId > 0 && (int) $listing->parentProductId !== $parentId) {
        continue;
    }
    if ($sizeTermId > 0 && (int) $listing->sizeTermId !== $sizeTermId) {
        continue;
    }
    echo sprintf(
        "  #%d order=%d parent=%d size=%d merchant=%d\n",
        (int) $listing->variationId,
        (int) ($listing->orderId ?? 0),
        (int) $listing->parentProductId,
        (int) $listing->sizeTermId,
        (int) $listing->merchantId
    );
}

if ($orderId > 0 && $parentId > 0 && $sizeTermId > 0) {
    $match = null;
    foreach ($board['items'] as $listing) {
        if ((int) ($listing->orderId ?? 0) === $orderId
            && (int) $listing->parentProductId === $parentId
            && (int) $listing->sizeTermId === $sizeTermId) {
            $match = $listing;
            break;
        }
    }
    if ($match) {
        echo PHP_EOL . 'OK: pre-order listing #' . (int) $match->variationId . ' is on the board.' . PHP_EOL;
        echo 'Next: login as another merchant → My Account → Pre-order → Accept (instant swap).' . PHP_EOL;
    } else {
        echo PHP_EOL . 'No pre-order listing on the board for this demo order yet.' . PHP_EOL;
        echo 'If still sold, wait for deadline or re-run with --expire-now.' . PHP_EOL;
    }
}

echo PHP_EOL . 'Done.' . PHP_EOL;
