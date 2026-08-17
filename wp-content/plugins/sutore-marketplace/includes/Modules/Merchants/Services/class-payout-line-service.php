<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Orders\Services\Notifications;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Services\CustomerOfferService;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

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
     *                        (id / variation_id = listing id, plus order_id / order_item_id).
     */
    public function createForListing(object $saleRow, Listing $listing): int
    {
        $variationId = (int) ($saleRow->variation_id ?? $listing->variationId);
        $existing = $this->repo->findByVariationId($variationId);
        if ($existing) {
            return (int) $existing->id;
        }

        $resolver = new CommissionResolver();
        $commission = $resolver->percentForPayout($listing);
        $offerBid = (new CustomerOfferService())
            ->bidGrossForPayout((int) $listing->variationId, (int) $saleRow->order_id);
        $gross = $offerBid !== null ? $offerBid : MarketplacePricing::activeAsking($listing);
        $fees = MarketplacePricing::feeBreakdownForListing($listing);
        $commissionAmount = round($gross * max(0.0, $commission) / 100, 2);
        $extra = 0.0;
        $net = MarketplacePricing::netFromAsking($gross, $commission);
        $title = Notifications::productTitle((int) $listing->variationId, $listing->variationId, $listing->parentProductId);
        $now = current_time('mysql');
        $scheduled = PayoutSchedule::scheduledDateFrom($now);

        $lineId = $this->repo->insert([
            'variation_id' => $variationId,
            'parent_product_id' => (int) $listing->parentProductId,
            'order_id' => (int) $saleRow->order_id,
            'order_item_id' => (int) ($saleRow->order_item_id ?? 0),
            'merchant_id' => (int) $listing->merchantId,
            'product_title' => $title,
            'gross_asking' => $gross,
            'commission_percent' => $commission,
            'commission_amount' => $commissionAmount,
            'hizmet_fee' => (float) $fees['hizmet'],
            'guvence_fee' => (float) $fees['guvence'],
            'extra_deduction' => $extra,
            'net_amount' => $net,
            'payout_status' => PayoutStatus::PENDING,
            'scheduled_payout_date' => $scheduled,
        ]);

        (new NotificationService())->dispatch((int) $listing->merchantId, NotificationType::PAYOUT_PENDING, [
            'product' => $title,
            'net_amount' => $net,
            'variation_id' => $variationId,
            'order_id' => (int) $saleRow->order_id,
            'scheduled_payout_date' => $scheduled,
        ]);

        return $lineId;
    }

    /**
     * Staff gesture: rewrite the pending payout rate and net. Does not change other sales.
     *
     * @return array{commission_percent:float,net_amount:float}| \WP_Error
     */
    public function adjustCommission(int $listingId, float $percent, string $note = ''): array|\WP_Error
    {
        $line = $this->repo->findByVariationId($listingId);
        if (!$line) {
            return new \WP_Error(
                'sutore_payout_missing',
                __('No payment record found for this sale.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }
        if ((string) $line->payout_status !== PayoutStatus::PENDING) {
            return new \WP_Error(
                'sutore_payout_not_pending',
                __('Commission can only be adjusted on a pending payout.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $percent = round($percent, 2);
        if ($percent < 0 || $percent > 100) {
            return new \WP_Error(
                'sutore_commission_invalid',
                __('Commission percent must be between 0 and 100.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $gross = (float) $line->gross_asking;
        $previous = round((float) $line->commission_percent, 2);
        $commissionAmount = round($gross * $percent / 100, 2);
        $extra = round((float) ($line->extra_deduction ?? 0), 2);
        $net = round(max(0.0, MarketplacePricing::netFromAsking($gross, $percent) - $extra), 2);

        $this->repo->update((int) $line->id, [
            'commission_percent' => $percent,
            'commission_amount' => $commissionAmount,
            'net_amount' => $net,
        ]);

        $note = sanitize_textarea_field($note);
        $payload = [
            'previous_percent' => $previous,
            'commission_percent' => $percent,
            'net_amount' => $net,
            'gross_asking' => $gross,
            'staff_note' => $note,
        ];

        (new \SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository())->log(
            'payout_commission_adjusted',
            $payload,
            $listingId,
            (int) $line->merchant_id,
            'admin_only'
        );
        (new \SutoreMarketplace\Modules\Merchants\Repositories\MerchantEventsRepository())->log(
            (int) $line->merchant_id,
            'merchant_payout_commission_adjusted',
            array_merge($payload, [
                'actor_role' => 'staff',
                'variation_id' => $listingId,
            ])
        );

        return [
            'commission_percent' => $percent,
            'net_amount' => $net,
        ];
    }

    public function markPaid(int $listingId, ?int $adminUserId = null, string $paymentRef = ''): true|\WP_Error
    {
        $line = $this->repo->findByVariationId($listingId);
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

        $listing = (new \SutoreMarketplace\Modules\Listings\Repositories\ListingRepository())->find($listingId);
        if ($listing) {
            (new \SutoreMarketplace\Modules\Invoices\Services\InvoiceIssuer())->queueSellerCommission(
                $listing,
                (int) ($listing->orderId ?? $line->order_id ?? 0)
            );
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentLine(object $line): array
    {
        $status = (string) ($line->payout_status ?? '');
        $scheduled = PayoutSchedule::normalizeDate($line->scheduled_payout_date ?? '');
        $paidAt = trim((string) ($line->paid_at ?? ''));
        $paidTs = $paidAt !== '' ? strtotime($paidAt) : false;

        return [
            'id' => (int) ($line->id ?? 0),
            'product_title' => (string) ($line->product_title ?? ''),
            'variation_id' => (int) ($line->variation_id ?? 0),
            'net_amount' => (float) ($line->net_amount ?? 0),
            'formatted_net' => MarketplacePricing::formatTl((float) ($line->net_amount ?? 0)),
            'payout_status' => $status,
            'payout_status_label' => PayoutStatus::label($status),
            'scheduled_payout_date' => $scheduled,
            'scheduled_payout_date_display' => PayoutSchedule::formatDateWithWeekday($scheduled),
            'scheduled_message' => $status === PayoutStatus::PENDING
                ? PayoutSchedule::merchantPendingMessage($scheduled)
                : '',
            'payout_due' => $status === PayoutStatus::PENDING && PayoutSchedule::isDue($scheduled),
            'paid_at' => $paidAt,
            'paid_at_display' => $paidTs
                ? (string) wp_date(get_option('date_format'), $paidTs)
                : '',
            'payment_ref' => (string) ($line->payment_ref ?? ''),
            'created_at' => (string) ($line->created_at ?? ''),
        ];
    }

    public function reverseForListing(int $listingId): void
    {
        $line = $this->repo->findByVariationId($listingId);
        if (!$line || (string) $line->payout_status === PayoutStatus::REVERSED) {
            return;
        }

        $this->repo->update((int) $line->id, [
            'payout_status' => PayoutStatus::REVERSED,
        ]);

        (new NotificationService())->dispatch((int) $line->merchant_id, NotificationType::PAYOUT_REVERSED, [
            'product' => (string) $line->product_title,
            'net_amount' => (float) $line->net_amount,
            'variation_id' => (int) $line->variation_id,
            'order_id' => (int) $line->order_id,
        ]);
    }
}
