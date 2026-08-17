<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Single linear product (listing) status.
 * Keys align with the previous Sutore membership plugin (underscore form of hyphen statuses).
 * Pre-sale market states and post-sale fulfillment pipeline share one enum.
 * Open pre-order board entries use market status `pre_order` (order-linked listing).
 * Campaign is NOT a status — it is campaign_status.
 * Payout status stays separate on merchant_payout_lines.
 */
final class ListingStatus
{
    // Pre-sale / market (old: pending, publish, expired, not-sale, pre-order)
    public const PENDING = 'pending';
    public const PUBLISH = 'publish';
    public const QUEUED = 'queued';
    public const EXPIRED = 'expired';
    public const NOT_SALE = 'not_sale';
    /** Staff detached from order — terminal; merchant must create a new listing to sell again. */
    public const ORDER_DETACHED = 'order_detached';
    /** Open pre-order — linked to a customer order, visible on the merchant board. */
    public const PRE_ORDER = 'pre_order';

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
            self::ORDER_DETACHED => __('Detached from order / Could not be sourced', 'sutore-marketplace'),
            self::PRE_ORDER => __('Pre-order', 'sutore-marketplace'),
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

    /**
     * Customer-facing order-item label (My Account / thank-you).
     * Remaps internal pipeline statuses the way the previous membership plugin did —
     * e.g. payment / sold / pre_order all read as “Pending Seller Confirmation”.
     *
     * @param string $shipmentType Order shipment type (`international` vs domestic).
     */
    public static function customerLabel(string $status, string $shipmentType = 'standard'): string
    {
        switch ($status) {
            case self::PENDING:
                return __('Pending Confirmation', 'sutore-marketplace');
            case self::PUBLISH:
                return __('On Sale', 'sutore-marketplace');
            case self::PAYMENT:
            case self::SOLD:
            case self::PRE_ORDER:
            case self::NOT_SALE:
            case self::ORDER_DETACHED:
                return __('Pending Seller Confirmation', 'sutore-marketplace');
            case self::CONFIRMED:
                return __('Seller Confirmed', 'sutore-marketplace');
            case self::SHIPPED_TO_SUTORE:
                return __('Shipped to Sutore', 'sutore-marketplace');
            case self::ARRIVED_TO_SUTORE:
                return __('Arrived at Sutore', 'sutore-marketplace');
            case self::VERIFIED:
                return __('Verified', 'sutore-marketplace');
            case self::READY_TO_SHIPPING:
                return __('Ready to Shipping', 'sutore-marketplace');
            case self::SHIPPED:
                return $shipmentType === 'international'
                    ? __('Shipped to You', 'sutore-marketplace')
                    : __('Shipped', 'sutore-marketplace');
            case self::DELIVERED_TO_CUSTOMER:
                return __('Delivered', 'sutore-marketplace');
            case self::CHARGEBACK:
                return __('Returned', 'sutore-marketplace');
            default:
                return self::label($status);
        }
    }

    /** Pre-sale market statuses (competition / expire apply). */
    /** @return list<string> */
    public static function market(): array
    {
        return [self::PENDING, self::PUBLISH, self::QUEUED, self::EXPIRED, self::NOT_SALE, self::PRE_ORDER];
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

    /**
     * Merchant still owes work (confirm / ship). Used by account deletion.
     * Delivered sales are not included — those wait on payout separately.
     *
     * @return list<string>
     */
    public static function saleInProgress(): array
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
        ];
    }

    public static function isSaleInProgress(string $status): bool
    {
        return in_array($status, self::saleInProgress(), true);
    }

    /** WC order cancel auto-releases these; later pipeline needs staff. */
    public static function allowsEarlyOrderCancelRelease(string $status): bool
    {
        return in_array($status, [
            self::PAYMENT,
            self::SOLD,
            self::CONFIRMED,
            self::PRE_ORDER,
        ], true);
    }

    public static function isLateFulfillment(string $status): bool
    {
        return in_array($status, [
            self::SHIPPED_TO_SUTORE,
            self::ARRIVED_TO_SUTORE,
            self::VERIFIED,
            self::READY_TO_SHIPPING,
            self::SHIPPED,
            self::DELIVERED_TO_CUSTOMER,
        ], true);
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

    public static function isPreOrder(Listing $listing): bool
    {
        return $listing->listingStatus === self::PRE_ORDER;
    }

    /** Sale pipeline or open pre-order blocks merchant edit / delete / remove-from-sale. */
    public static function isProcessLocked(Listing $listing): bool
    {
        return self::isInSaleLifecycle($listing->listingStatus)
            || self::isPreOrder($listing)
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

    /** Still in flux — customer e-Archive must wait. */
    public static function invoiceOpen(string $status): bool
    {
        return in_array($status, [
            self::PAYMENT,
            self::SOLD,
            self::CONFIRMED,
            self::SHIPPED_TO_SUTORE,
            self::ARRIVED_TO_SUTORE,
            self::PRE_ORDER,
        ], true);
    }

    /** Platform fees are earned — line may appear on the customer invoice. */
    public static function invoiceBillable(string $status): bool
    {
        return self::allowsPayout($status);
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
            'remove_from_sale' => false,
            'attach_to_order' => false,
            'chargeback' => false,
            'hub_reject' => false,
            'mark_payout' => false,
            'put_on_sale' => false,
            'approve' => false,
            'send_campaign_offer' => false,
            'delete' => false,
            'close_pre_order' => false,
        ];

        return match ($status) {
            self::PUBLISH, self::QUEUED => array_merge($none, [
                'attach_to_order' => true,
                'remove_from_sale' => true,
                'delete' => true,
                'send_campaign_offer' => true,
            ]),
            self::PENDING => array_merge($none, [
                'attach_to_order' => true,
                'remove_from_sale' => true,
                'delete' => true,
                'approve' => true,
            ]),
            self::EXPIRED => array_merge($none, [
                'attach_to_order' => true,
                'put_on_sale' => true,
                'delete' => true,
            ]),
            self::PAYMENT => array_merge($none, [
                'confirm_payment' => true,
                'swap' => true,
                'detach' => true,
                'mark_pre_order' => true,
                'mark_not_for_sale' => true,
            ]),
            self::SOLD => array_merge($none, [
                'swap' => true,
                'detach' => true,
                'mark_pre_order' => true,
                'mark_not_for_sale' => true,
            ]),
            self::CONFIRMED => array_merge($none, [
                'detach' => true,
                'mark_not_for_sale' => true,
            ]),
            self::SHIPPED_TO_SUTORE => array_merge($none, [
                'mark_arrived' => true,
                'hub_reject' => true,
                'mark_not_for_sale' => true,
            ]),
            self::ARRIVED_TO_SUTORE => array_merge($none, [
                'mark_verified' => true,
                'hub_reject' => true,
                'chargeback' => true,
                'mark_not_for_sale' => true,
            ]),
            self::VERIFIED => array_merge($none, [
                'mark_ready_to_ship' => true,
                'hub_reject' => true,
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
            self::ORDER_DETACHED => array_merge($none, [
                'delete' => true,
            ]),
            self::PRE_ORDER => array_merge($none, [
                'close_pre_order' => true,
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
            'close_pre_order',
            'mark_not_for_sale',
            'remove_from_sale',
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
