<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Services\PayoutLineService;
use SutoreMarketplace\Modules\Orders\Domain\StaffBulkAction;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class PayoutCommands
{
    public function __construct(
        private readonly FulfillmentCommandSupport $support,
        private readonly FulfillmentRepository $repo,
    ) {
    }

    public function markMerchantPayout(int $listingId, string $paymentRef = ''): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row) {
            return new \WP_Error('sutore_marketplace_fulfillment_missing', __('Sale record not found.', 'sutore-marketplace'));
        }

        if (!ListingStatus::allowsPayout((string) $row->fulfillment_status)) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Merchant payout cannot be marked in this status.', 'sutore-marketplace')
            );
        }

        $listing = $this->support->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $payoutService = new PayoutLineService();
        $payoutRepo = new PayoutLineRepository();
        $existing = $payoutRepo->findByVariationId($listingId);
        $alreadyPaid = $existing && (string) $existing->payout_status === PayoutStatus::PAID;
        if (!$existing) {
            $payoutService->createForListing($row, $listing);
        }

        $paid = $payoutService->markPaid($listingId, get_current_user_id(), $paymentRef);
        if ($paid instanceof \WP_Error) {
            return $paid;
        }

        if ($alreadyPaid) {
            return true;
        }

        $order = wc_get_order((int) $row->order_id);
        if ($order) {
            $line = $payoutRepo->findByVariationId($listingId);
            $this->support->dispatchMerchantNotification(
                NotificationType::PAYOUT_PAID,
                $listing,
                $order,
                [
                    'variation_id' => $listingId,
                    'net_amount' => $line ? (float) $line->net_amount : MarketplacePricing::merchantPayout($listing),
                    'price' => $listing->asking,
                ]
            );
        }

        WebhookNotifier::dispatch('payout.paid', [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        $this->support->logListingEvent('fulfillment_payout_paid', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'payment_ref' => $paymentRef,
        ], $row);

        return true;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function adjustPayoutCommission(int $listingId, array $params): true|\WP_Error
    {
        $raw = $params['commission_percent'] ?? null;
        if ($raw === null || $raw === '') {
            return new \WP_Error(
                'sutore_commission_invalid',
                __('Commission percent must be between 0 and 100.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $result = (new PayoutLineService())->adjustCommission(
            $listingId,
            (float) $raw,
            sanitize_textarea_field((string) ($params['staff_note'] ?? $params['note'] ?? ''))
        );

        return $result instanceof \WP_Error ? $result : true;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function setListingCommission(int $listingId, array $params): true|\WP_Error
    {
        $raw = $params['commission_percent'] ?? null;
        $percent = null;
        if ($raw !== null && $raw !== '') {
            $percent = (float) $raw;
        }

        $result = (new ListingService())->setCommissionPercent(
            $listingId,
            $percent,
            sanitize_textarea_field((string) ($params['staff_note'] ?? $params['note'] ?? ''))
        );

        return $result instanceof \WP_Error ? $result : true;
    }
}