<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Domain;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;

/**
 * Staff manage-products work-queue filters (deadline urgency / awaiting merchant).
 * Shipment type is a separate filter (order_shipment_type), not a queue.
 */
final class StaffQueueFilter
{
    public const YELLOW_ZONE = 'yellow_zone';
    public const RED_ZONE = 'red_zone';
    public const AWAITING_MERCHANT = 'awaiting_merchant';

    /** Yellow: customer ETA within 4 days. */
    public const YELLOW_WITHIN_SECONDS = 345600;

    /** Red: customer ETA within 1 day. */
    public const RED_WITHIN_SECONDS = 86400;

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::YELLOW_ZONE,
            self::RED_ZONE,
            self::AWAITING_MERCHANT,
        ];
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::YELLOW_ZONE => __('Yellow zone', 'sutore-marketplace'),
            self::RED_ZONE => __('Red zone', 'sutore-marketplace'),
            self::AWAITING_MERCHANT => __('Awaiting merchant', 'sutore-marketplace'),
        ];
    }

    public static function label(string $key): string
    {
        return self::labels()[$key] ?? $key;
    }

    /**
     * Listing statuses included when filtering by shipment deadline.
     *
     * @return list<string>
     */
    public static function inPipelineStatuses(): array
    {
        return array_merge(ListingStatus::saleActive(), [
            ListingStatus::NOT_SALE,
        ]);
    }

    /**
     * Payment + sold (awaiting merchant confirmation).
     *
     * @return list<string>
     */
    public static function awaitingMerchantStatuses(): array
    {
        return [
            ListingStatus::PAYMENT,
            ListingStatus::SOLD,
        ];
    }
}
