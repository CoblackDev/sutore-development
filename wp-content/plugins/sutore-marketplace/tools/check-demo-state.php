<?php

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

$state = get_option('sutore_lifecycle_demo_state');
echo json_encode($state, JSON_PRETTY_PRINT) . PHP_EOL;

if (!is_array($state)) {
    exit(0);
}

$lid = (int) ($state['listing_id'] ?? 0);
$oid = (int) ($state['order_id'] ?? 0);

// Since the fulfillments table has been eliminated, sale data lives on the
// listing row and every "fulfillment id" is the listing id.
$repo = new FulfillmentRepository();
$f = $repo->find($lid);
$active = $repo->findActiveByListingId($lid);
$latest = $repo->findByListingId($lid);
$listing = (new ListingRepository())->find($lid);
$order = wc_get_order($oid);

echo '--- sale row (listing #' . $lid . ') ---' . PHP_EOL;
echo 'status: ' . ($f->fulfillment_status ?? 'missing') . PHP_EOL;
echo 'label: ' . ListingStatus::label((string) ($f->fulfillment_status ?? '')) . PHP_EOL;
echo 'is terminal: ' . (ListingStatus::isSaleTerminal((string) ($f->fulfillment_status ?? '')) ? 'yes' : 'no') . PHP_EOL;
echo 'in saleActive(): ' . (in_array((string) ($f->fulfillment_status ?? ''), ListingStatus::saleActive(), true) ? 'yes' : 'no') . PHP_EOL;
echo '--- listing #' . $lid . ' ---' . PHP_EOL;
echo 'listing_status: ' . ($listing->listingStatus ?? 'missing') . PHP_EOL;
echo 'listing_status_label: ' . ListingStatus::label((string) ($listing->listingStatus ?? '')) . PHP_EOL;
echo '--- integration lookup ---' . PHP_EOL;
echo 'findActiveByListingId: ' . ($active->fulfillment_status ?? 'null') . PHP_EOL;
echo 'findByListingId (latest): ' . ($latest->fulfillment_status ?? 'null') . PHP_EOL;
echo '--- wc order #' . $oid . ' ---' . PHP_EOL;
echo 'order_status: ' . ($order ? $order->get_status() : 'missing') . PHP_EOL;

wp_set_current_user((int) get_user_by('login', 'demo_seller_accept')?->ID);
$listing = (new ListingRepository())->find($lid);
if ($listing) {
    $item = (new SutoreMarketplace\Modules\Listings\Services\ListingQueryPresenter())->enrich($listing);
    echo '--- REST enrich (merchant) ---' . PHP_EOL;
    echo 'listing_status: ' . ($item['listing_status'] ?? '') . PHP_EOL;
    echo 'fulfillment.status: ' . ($item['fulfillment']['status'] ?? 'null') . PHP_EOL;
    echo 'fulfillment.status_label: ' . ($item['fulfillment']['status_label'] ?? 'null') . PHP_EOL;
}
