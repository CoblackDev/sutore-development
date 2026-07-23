<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Single linear product (listing) status.
 * Keys align with the previous Sutore membership plugin (underscore form of hyphen statuses).
 * Pre-sale market states and post-sale fulfillment pipeline share one enum.
 * Pre-order is NOT a status — it is sourcing_request_id / is_sourcing.
 * Campaign is NOT a status — it is campaign_status.
 * Payout status stays separate on merchant_payout_lines.
 */
final class ListingStatus
{
    // Pre-sale / market (old: pending, publish, expired, not-sale)
    public const PENDING = 'pending';
    public const PUBLISH = 'publish';
    public const QUEUED = 'queued';
    public const EXPIRED = 'expired';
    public const NOT_SALE = 'not_sale';

    // Sale / fulfillment pipeline (old: payment, sold, confirmed, shipped-to-sutore, …)
    public const PAYMENT = 'payment';
    public const SOLD = 'sold';
    public const CONFIRMED = 'confirmed';
    public const SHIPPED_TO_SUTORE = 'shipped_to_sutore';
    public const ARRIVED_TO_SUTORE = 'arrived_to_sutore';
    public const VERIFIED = 'verified';
    public const READY_TO_SHIPPING = 'ready_to_shipping';
    public const SHIPPED = 'shipped';
    public const DELIVERED_TO_CUSTOMER = 'delivered_to_customer';
    public const CHARGEBACK = 'chargeback';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::PUBLISH => __('For sale', 'sutore-marketplace'),
            self::QUEUED => __('In queue', 'sutore-marketplace'),
            self::PENDING => __('Pending approval', 'sutore-marketplace'),
            self::EXPIRED => __('Expired', 'sutore-marketplace'),
            self::NOT_SALE => __('Not for sale', 'sutore-marketplace'),
            self::PAYMENT => __('Awaiting payment confirmation', 'sutore-marketplace'),
            self::SOLD => __('Awaiting merchant confirmation', 'sutore-marketplace'),
            self::CONFIRMED => __('Merchant confirmed', 'sutore-marketplace'),
            self::SHIPPED_TO_SUTORE => __('Shipped to Sutore', 'sutore-marketplace'),
            self::ARRIVED_TO_SUTORE => __('Arrived at Sutore', 'sutore-marketplace'),
            self::VERIFIED => __('Verified', 'sutore-marketplace'),
            self::READY_TO_SHIPPING => __('Ready to ship', 'sutore-marketplace'),
            self::SHIPPED => __('Shipped to customer', 'sutore-marketplace'),
            self::DELIVERED_TO_CUSTOMER => __('Delivered to customer', 'sutore-marketplace'),
            self::CHARGEBACK => __('Refunded', 'sutore-marketplace'),
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(string $status): bool
    {
        return isset(self::labels()[$status]);
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }

    /** Pre-sale market statuses (competition / expire apply). */
    /** @return list<string> */
    public static function market(): array
    {
        return [self::PENDING, self::PUBLISH, self::QUEUED, self::EXPIRED, self::NOT_SALE];
    }

    /** In-progress sale pipeline (blocks edit / delete / relist). */
    /** @return list<string> */
    public static function saleActive(): array
    {
        return [
            self::PAYMENT,
            self::SOLD,
            self::CONFIRMED,
            self::SHIPPED_TO_SUTORE,
            self::ARRIVED_TO_SUTORE,
            self::VERIFIED,
            self::READY_TO_SHIPPING,
            self::SHIPPED,
            self::DELIVERED_TO_CUSTOMER,
        ];
    }

    /** @return list<string> */
    public static function saleTerminal(): array
    {
        return [
            self::CHARGEBACK,
        ];
    }

    public static function isSaleActive(string $status): bool
    {
        return in_array($status, self::saleActive(), true);
    }

    public static function isSaleTerminal(string $status): bool
    {
        return in_array($status, self::saleTerminal(), true);
    }

    public static function isInSaleLifecycle(string $status): bool
    {
        return self::isSaleActive($status) || self::isSaleTerminal($status);
    }

    /** @return list<string> */
    public static function removableFromSale(): array
    {
        return [self::PUBLISH, self::QUEUED, self::PENDING];
    }

    /** @return list<string> */
    public static function relistable(): array
    {
        return [
            self::NOT_SALE,
            self::EXPIRED,
            self::CHARGEBACK,
        ];
    }

    /** Listing cannot be edited / removed while in these sale statuses. */
    /** @return list<string> */
    public static function orderLocked(): array
    {
        return self::saleActive();
    }

    /** Held for an accepted sourcing request (flag, not a listing status). */
    public static function isSourcingHeld(Listing $listing): bool
    {
        return $listing->sourcingRequestId !== null;
    }

    /** Sale pipeline or sourcing hold blocks merchant edit / delete / remove-from-sale. */
    public static function isProcessLocked(Listing $listing): bool
    {
        return self::isInSaleLifecycle($listing->listingStatus)
            || self::isSourcingHeld($listing)
            || $listing->orderId !== null;
    }

    /** Detach (remove WC order item) only before carrier handoff. */
    public static function allowsDetach(string $status): bool
    {
        return in_array($status, [
            self::PAYMENT,
            self::SOLD,
            self::CONFIRMED,
        ], true);
    }

    public static function allowsPayout(string $status): bool
    {
        return in_array($status, [
            self::VERIFIED,
            self::READY_TO_SHIPPING,
            self::SHIPPED,
            self::DELIVERED_TO_CUSTOMER,
        ], true);
    }

    /**
     * Explicit staff action flags for the current sale status.
     *
     * @return array<string, bool>
     */
    public static function staffCapabilities(string $status): array
    {
        $none = [
            'confirm_payment' => false,
            'swap' => false,
            'detach' => false,
            'mark_arrived' => false,
            'mark_verified' => false,
            'mark_ready_to_ship' => false,
            'mark_shipped_to_customer' => false,
            'mark_delivered' => false,
            'mark_not_for_sale' => false,
            'attach_to_order' => false,
            'chargeback' => false,
            'mark_payout' => false,
            'put_on_sale' => false,
            'delete' => false,
        ];

        return match ($status) {
            self::PUBLISH, self::QUEUED, self::PENDING, self::EXPIRED => array_merge($none, [
                'attach_to_order' => true,
            ]),
            self::PAYMENT => array_merge($none, [
                'confirm_payment' => true,
                'swap' => true,
                'detach' => true,
                'mark_not_for_sale' => true,
            ]),
            self::SOLD => array_merge($none, [
                'swap' => true,
                'detach' => true,
                'mark_not_for_sale' => true,
            ]),
            self::CONFIRMED => array_merge($none, [
                'detach' => true,
                'mark_not_for_sale' => true,
            ]),
            self::SHIPPED_TO_SUTORE => array_merge($none, [
                'mark_arrived' => true,
                'mark_not_for_sale' => true,
            ]),
            self::ARRIVED_TO_SUTORE => array_merge($none, [
                'mark_verified' => true,
                'chargeback' => true,
                'mark_not_for_sale' => true,
            ]),
            self::VERIFIED => array_merge($none, [
                'mark_ready_to_ship' => true,
                'mark_payout' => true,
                'chargeback' => true,
            ]),
            self::READY_TO_SHIPPING => array_merge($none, [
                'mark_shipped_to_customer' => true,
                'mark_payout' => true,
                'chargeback' => true,
            ]),
            self::SHIPPED => array_merge($none, [
                'mark_delivered' => true,
                'mark_payout' => true,
                'chargeback' => true,
            ]),
            self::DELIVERED_TO_CUSTOMER => array_merge($none, [
                'mark_payout' => true,
                'chargeback' => true,
            ]),
            self::CHARGEBACK => array_merge($none, [
                'put_on_sale' => true,
                'delete' => true,
            ]),
            self::NOT_SALE => array_merge($none, [
                'attach_to_order' => true,
                'put_on_sale' => true,
                'delete' => true,
            ]),
            default => $none,
        };
    }

    /** Market listing may be manually linked to a processing WC order. */
    public static function allowsManualOrderAttach(string $status): bool
    {
        return in_array($status, [
            self::PUBLISH,
            self::QUEUED,
            self::PENDING,
            self::EXPIRED,
            self::NOT_SALE,
        ], true);
    }

    /** @return list<string> */
    public static function actionsRequiringStaffNote(): array
    {
        return [
            'detach',
            'mark_not_for_sale',
            'chargeback',
        ];
    }

    /** @return array<string, string> */
    public static function campaignLabels(): array
    {
        return [
            'none' => __('None', 'sutore-marketplace'),
            'offer' => __('Campaign offer', 'sutore-marketplace'),
            'active' => __('On campaign', 'sutore-marketplace'),
        ];
    }

    public static function campaignLabel(string $status): string
    {
        $labels = self::campaignLabels();

        return $labels[$status] ?? $status;
    }
}
