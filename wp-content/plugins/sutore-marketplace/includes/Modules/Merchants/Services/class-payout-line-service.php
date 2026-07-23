<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Orders\Services\Notifications;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class PayoutLineService
{
    public function __construct(
        private readonly PayoutLineRepository $repo = new PayoutLineRepository(),
    ) {
    }

    /**
     * Create (or return the existing) payout line for a listing sale.
     *
     * @param object $saleRow Fulfillment-shaped row returned by FulfillmentRepository
     *                        (id / listing_id = listing id, plus order_id / order_item_id).
     */
    public function createForListing(object $saleRow, Listing $listing): int
    {
        $listingId = (int) ($saleRow->listing_id ?? $listing->id);
        $existing = $this->repo->findByListingId($listingId);
        if ($existing) {
            return (int) $existing->id;
        }

        $commission = MerchantLevels::commissionPercentForUser((int) $listing->merchantId);
        $gross = MarketplacePricing::activeAsking($listing);
        $net = MarketplacePricing::merchantPayout($listing, $commission);
        $title = Notifications::productTitle((int) $listing->id, $listing->variationId, $listing->parentProductId);

        $lineId = $this->repo->insert([
            'listing_id' => $listingId,
            'variation_id' => (int) $listing->variationId,
            'parent_product_id' => (int) $listing->parentProductId,
            'order_id' => (int) $saleRow->order_id,
            'order_item_id' => (int) ($saleRow->order_item_id ?? 0),
            'merchant_id' => (int) $listing->merchantId,
            'product_title' => $title,
            'gross_asking' => $gross,
            'commission_percent' => $commission,
            'net_amount' => $net,
            'payout_status' => PayoutStatus::PENDING,
        ]);

        (new NotificationService())->dispatch((int) $listing->merchantId, NotificationType::PAYOUT_PENDING, [
            'product' => $title,
            'net_amount' => $net,
            'listing_id' => $listingId,
            'order_id' => (int) $saleRow->order_id,
        ]);

        return $lineId;
    }

    public function markPaid(int $listingId, ?int $adminUserId = null, string $paymentRef = ''): true|\WP_Error
    {
        $line = $this->repo->findByListingId($listingId);
        if (!$line) {
            return new \WP_Error('sutore_payout_missing', __('No payment record found for this sale.', 'sutore-marketplace'));
        }
        if ((string) $line->payout_status === PayoutStatus::PAID) {
            return true;
        }
        if ((string) $line->payout_status === PayoutStatus::REVERSED) {
            return new \WP_Error('sutore_payout_reversed', __('Payment cannot be marked for a cancelled sale.', 'sutore-marketplace'));
        }

        $this->repo->update((int) $line->id, [
            'payout_status' => PayoutStatus::PAID,
            'paid_at' => current_time('mysql'),
            'paid_by' => $adminUserId ?: get_current_user_id(),
            'payment_ref' => sanitize_text_field($paymentRef),
        ]);

        return true;
    }

    public function reverseForListing(int $listingId): void
    {
        $line = $this->repo->findByListingId($listingId);
        if (!$line || (string) $line->payout_status === PayoutStatus::REVERSED) {
            return;
        }

        $this->repo->update((int) $line->id, [
            'payout_status' => PayoutStatus::REVERSED,
        ]);

        (new NotificationService())->dispatch((int) $line->merchant_id, NotificationType::PAYOUT_REVERSED, [
            'product' => (string) $line->product_title,
            'net_amount' => (float) $line->net_amount,
            'listing_id' => (int) $line->listing_id,
            'order_id' => (int) $line->order_id,
        ]);
    }
}
