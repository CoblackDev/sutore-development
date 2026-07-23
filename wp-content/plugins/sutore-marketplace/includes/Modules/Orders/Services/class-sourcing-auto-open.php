<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Sourcing\Repositories\SourcingRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings;

final class SourcingAutoOpen
{
    public function forFailedSeller(Listing $listing, int $orderId, int $orderItemId): ?int
    {
        if (!Settings::autoSourcingOnSuspend()) {
            return null;
        }

        global $wpdb;
        $table = (new SourcingRepository())->table();

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_id = %d AND parent_product_id = %d AND size_term_id = %d AND status IN ('open','accepted') LIMIT 1",
            $orderId,
            $listing->parentProductId,
            $listing->sizeTermId
        ));

        if ($existing) {
            return (int) $existing;
        }

        return (new SourcingRepository())->create([
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'parent_product_id' => $listing->parentProductId,
            'size_term_id' => $listing->sizeTermId,
            'status' => 'open',
            'requested_by' => 0,
            'notes' => __('Merchant did not confirm — automatic pre-order request', 'sutore-marketplace'),
        ]);
    }

    public function forSplit(Listing $listing, int $orderId, int $orderItemId): ?int
    {
        if (!Settings::autoSourcingOnSplit()) {
            return null;
        }

        return $this->forFailedSeller($listing, $orderId, $orderItemId);
    }
}
