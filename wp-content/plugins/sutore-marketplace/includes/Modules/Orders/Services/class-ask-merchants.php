<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings;

final class AskMerchants
{
    public function notifyForSize(int $parentId, int $sizeTermId, float $asking, string $productTitle): void
    {
        if (!Settings::smsEventEnabled('alternative_sourcing')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sutore_marketplace_listings';
        $tolerance = 1 + (Settings::sourcingPriceTolerancePercent() / 100);
        $maxAsking = $asking * $tolerance;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT merchant_id FROM {$table}
             WHERE parent_product_id = %d AND size_term_id = %d
               AND listing_status IN ('publish','queued','pending')
               AND asking <= %f
             LIMIT 50",
            $parentId,
            $sizeTermId,
            $maxAsking
        ));

        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $phone = Notifications::merchantPhone((int) $row->merchant_id);
            if ($phone !== '') {
                Notifications::sendEvent('alternative_sourcing', $phone, ['product' => $productTitle], true);
            }
        }
    }
}
