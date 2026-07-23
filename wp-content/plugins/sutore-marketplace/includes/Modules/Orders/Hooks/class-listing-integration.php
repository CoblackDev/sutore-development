<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Hooks;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingExpireDisplay;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Orders\Services\SourcingBridge;
use SutoreMarketplace\Modules\Orders\Support\ShipmentTracking;

/**
 * Enriches merchant listing REST items with sale/logistics chrome from listing columns.
 */
final class ListingIntegration
{
    /** @var array<int, object>|null */
    private static ?array $payoutCache = null;

    public function register(): void
    {
        add_filter('sutore_marketplace_listing_query_item', [$this, 'enrichListingItem'], 10, 2);
        add_action('sutore_marketplace_sourcing_fulfilled', [$this, 'onSourcingFulfilled'], 10, 2);
    }

    /** @param list<int> $listingIds */
    public static function primeFulfillmentCache(array $listingIds): void
    {
        self::$payoutCache = (new PayoutLineRepository())->findByListingIds($listingIds);
    }

    /** @param array<string, mixed> $item */
    public function enrichListingItem(array $item, Listing $listing): array
    {
        if (!$listing->id || !ListingStatus::isInSaleLifecycle($listing->listingStatus)) {
            return $item;
        }

        $listingId = (int) $listing->id;
        $status = $listing->listingStatus;
        $confirmDeadline = (string) ($listing->confirmDeadlineAt ?? '');
        $cargoDeadline = (string) ($listing->cargoDeadlineAt ?? '');
        $sellerConfirmedAt = (string) ($listing->sellerConfirmedAt ?? '');
        $merchantShippedAt = (string) ($listing->merchantShippedAt ?? '');
        $confirmRemaining = null;
        $cargoRemaining = null;

        if ($merchantShippedAt === '' && !in_array($status, [
            ListingStatus::PAYMENT,
            ListingStatus::SOLD,
            ListingStatus::CONFIRMED,
        ], true)) {
            $merchantShippedAt = (string) ($listing->updatedAt ?? '');
        }

        if ($status === ListingStatus::SOLD) {
            $confirmRemaining = ListingExpireDisplay::remainingFromDatetime($confirmDeadline !== '' ? $confirmDeadline : null);
            if ($confirmRemaining !== null) {
                $item['remaining_label'] = $confirmRemaining;
            }
        } elseif ($status === ListingStatus::CONFIRMED) {
            $cargoRemaining = ListingExpireDisplay::remainingFromDatetime($cargoDeadline !== '' ? $cargoDeadline : null);
            if ($cargoRemaining !== null) {
                $item['remaining_label'] = $cargoRemaining;
            }
        }

        $merchantCode = trim((string) ($listing->merchantShipmentCode ?? ''));
        $sutoreCode = trim((string) ($listing->sutoreShipmentCode ?? ''));
        $showMerchantTrack = $merchantCode !== '' && !in_array($status, [
            ListingStatus::PAYMENT,
            ListingStatus::SOLD,
            ListingStatus::CONFIRMED,
        ], true);
        $showSutoreTrack = $sutoreCode !== '' && in_array($status, [
            ListingStatus::SHIPPED,
            ListingStatus::DELIVERED_TO_CUSTOMER,
        ], true);
        $showDelivered = $status === ListingStatus::DELIVERED_TO_CUSTOMER;
        $showReturnWindow = $status === ListingStatus::DELIVERED_TO_CUSTOMER;
        $cargoExpired = $status === ListingStatus::CONFIRMED && $listing->cargoExpiredFlag;

        $payoutPayload = null;
        if (self::$payoutCache !== null) {
            $payout = self::$payoutCache[$listingId] ?? null;
        } else {
            $payout = (new PayoutLineRepository())->findByListingId($listingId);
        }
        if ($payout) {
            $paidAt = (string) ($payout->paid_at ?? '');
            $payoutPayload = [
                'payout_status' => (string) $payout->payout_status,
                'payout_status_label' => PayoutStatus::label((string) $payout->payout_status),
                'commission_percent' => (float) $payout->commission_percent,
                'net_amount' => (float) $payout->net_amount,
                'net_amount_display' => number_format((float) $payout->net_amount, 0, ',', '.') . ' TL',
                'paid_at' => $paidAt !== '' ? self::formatMerchantDatetime($paidAt) : '',
            ];
        }

        $item['fulfillment'] = [
            'id' => $listingId,
            'status' => $status,
            'status_label' => ListingStatus::label($status),
            'can_confirm' => $status === ListingStatus::SOLD,
            'can_ship' => $status === ListingStatus::CONFIRMED,
            'can_details' => true,
            'confirm_deadline_at' => $status === ListingStatus::SOLD
                ? self::formatMerchantDatetime($confirmDeadline)
                : '',
            'cargo_deadline_at' => $status === ListingStatus::CONFIRMED
                ? self::formatMerchantDatetime($cargoDeadline)
                : '',
            'seller_confirmed_at' => !in_array($status, [
                ListingStatus::PAYMENT,
                ListingStatus::SOLD,
            ], true)
                ? self::formatMerchantDatetime($sellerConfirmedAt)
                : '',
            'merchant_shipped_at' => !in_array($status, [
                ListingStatus::PAYMENT,
                ListingStatus::SOLD,
                ListingStatus::CONFIRMED,
            ], true)
                ? self::formatMerchantDatetime($merchantShippedAt)
                : '',
            'merchant_shipment_code' => $showMerchantTrack ? $merchantCode : '',
            'merchant_track_url' => $showMerchantTrack
                ? ShipmentTracking::customerTrackUrl('standard', $merchantCode)
                : '',
            'sutore_shipment_code' => $showSutoreTrack ? $sutoreCode : '',
            'sutore_track_url' => $showSutoreTrack
                ? ShipmentTracking::customerTrackUrl('standard', $sutoreCode)
                : '',
            'delivered_at' => $showDelivered
                ? self::formatMerchantDatetime((string) ($listing->deliveredAt ?? ''))
                : '',
            'return_window_ends_at' => $showReturnWindow
                ? self::formatMerchantDatetime((string) ($listing->returnWindowEndsAt ?? ''))
                : '',
            'cargo_expired' => $cargoExpired,
            'confirm_remaining_label' => $confirmRemaining,
            'cargo_remaining_label' => $cargoRemaining,
            'payout' => $payoutPayload,
        ];

        return $item;
    }

    private static function formatMerchantDatetime(string $mysqlDatetime): string
    {
        $mysqlDatetime = trim($mysqlDatetime);
        if ($mysqlDatetime === '') {
            return '';
        }
        $ts = strtotime($mysqlDatetime);
        if (!$ts) {
            return $mysqlDatetime;
        }

        return (string) wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            $ts
        );
    }

    public function onSourcingFulfilled(int $requestId, object $row): void
    {
        (new SourcingBridge())->onFulfilled($requestId, $row);
    }
}
