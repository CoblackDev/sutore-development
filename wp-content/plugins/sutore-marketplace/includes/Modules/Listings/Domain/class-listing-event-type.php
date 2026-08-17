<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class ListingEventType
{
    public const SELLER_CANCELLED = 'seller_cancelled';
    public const CONFIRM_DEADLINE_MISSED = 'confirm_deadline_missed';
    public const HUB_REJECTED = 'hub_rejected';
    public const PRE_ORDER_COMMITMENT_BROKEN = 'pre_order_commitment_broken';
    public const PRE_ORDER_ACCEPTED = 'pre_order_accepted';
    public const SOURCING_FULFILLED = 'sourcing_fulfilled';
    public const LISTING_PRE_ORDER = 'listing_pre_order';
    public const EVENT_REVERSAL = 'event_reversal';

    /** Event types that affect the seller behavior score (weights in Settings). */
    public static function scorableTypes(): array
    {
        return [
            self::SELLER_CANCELLED,
            self::CONFIRM_DEADLINE_MISSED,
            'fulfillment_cargo_expired',
            self::HUB_REJECTED,
            self::PRE_ORDER_COMMITMENT_BROKEN,
            'fulfillment_chargeback',
            'fulfillment_seller_confirmed',
            'fulfillment_shipped_to_sutore',
            self::SOURCING_FULFILLED,
        ];
    }

    public static function label(string $eventType): string
    {
        return match ($eventType) {
            'listing_created' => __('Listing created', 'sutore-marketplace'),
            'listing_status_changed' => __('Listing status changed', 'sutore-marketplace'),
            'listing_price_changed' => __('Price changed', 'sutore-marketplace'),
            'listing_condition_changed' => __('Condition changed', 'sutore-marketplace'),
            'listing_shipping_changed' => __('Shipping options changed', 'sutore-marketplace'),
            'listing_duration_changed' => __('Listing duration changed', 'sutore-marketplace'),
            'listing_put_on_sale' => __('Put on sale', 'sutore-marketplace'),
            'listing_removed_from_sale' => __('Removed from sale', 'sutore-marketplace'),
            'listing_deleted' => __('Listing deleted', 'sutore-marketplace'),
            'listing_expired' => __('Listing expired', 'sutore-marketplace'),
            'listing_sold' => __('Sold', 'sutore-marketplace'),
            'listing_payment' => __('Payment pending', 'sutore-marketplace'),
            'order_listing_attached' => __('Added to order', 'sutore-marketplace'),
            'order_listing_detached' => __('Detached from order', 'sutore-marketplace'),
            'order_listing_swapped' => __('Listing swapped on order', 'sutore-marketplace'),
            'price_validation_failed' => __('Price validation failed', 'sutore-marketplace'),
            'campaign_applied' => __('Campaign applied', 'sutore-marketplace'),
            'campaign_cleared' => __('Campaign cleared', 'sutore-marketplace'),
            'campaign_offer_sent' => __('Campaign offer received', 'sutore-marketplace'),
            'campaign_offer_declined' => __('Campaign offer declined', 'sutore-marketplace'),
            'campaign_offer_expired' => __('Campaign offer expired', 'sutore-marketplace'),
            'customer_offer_sent' => __('Customer offer received', 'sutore-marketplace'),
            'customer_offer_accepted' => __('Customer offer accepted', 'sutore-marketplace'),
            'customer_offer_declined' => __('Customer offer declined', 'sutore-marketplace'),
            'customer_offer_expired' => __('Customer offer expired', 'sutore-marketplace'),
            'customer_offer_cancelled' => __('Customer offer cancelled', 'sutore-marketplace'),
            'customer_offer_forwarded' => __('Customer offer forwarded', 'sutore-marketplace'),
            'queue_position_changed' => __('Queue position changed', 'sutore-marketplace'),
            'listing_went_on_sale' => __('Went on sale', 'sutore-marketplace'),
            'listing_left_sale' => __('Left sale', 'sutore-marketplace'),
            'listing_approved' => __('Listing approved', 'sutore-marketplace'),
            'sourcing_pre_order' => __('Pre-order', 'sutore-marketplace'),
            self::SOURCING_FULFILLED => __('Pre-order fulfilled', 'sutore-marketplace'),
            self::PRE_ORDER_ACCEPTED => __('Pre-order accepted', 'sutore-marketplace'),
            self::PRE_ORDER_COMMITMENT_BROKEN => __('Pre-order commitment broken', 'sutore-marketplace'),
            self::SELLER_CANCELLED => __('Seller cancelled sale', 'sutore-marketplace'),
            self::CONFIRM_DEADLINE_MISSED => __('Confirmation deadline missed', 'sutore-marketplace'),
            self::HUB_REJECTED => __('Rejected at hub', 'sutore-marketplace'),
            self::LISTING_PRE_ORDER => __('Moved to pre-order board', 'sutore-marketplace'),
            'sourcing_listing_auto_created' => __('Listing auto-created for pre-order', 'sutore-marketplace'),
            'outlet_optin_accepted' => __('Outlet opt-in accepted', 'sutore-marketplace'),
            'outlet_optin_cancelled' => __('Outlet opt-in cancelled', 'sutore-marketplace'),
            'outlet_listing_created' => __('Outlet listing created', 'sutore-marketplace'),
            'listing_bulk_import' => __('Bulk import completed', 'sutore-marketplace'),
            'listing_lifecycle_completed' => __('Listing lifecycle completed', 'sutore-marketplace'),
            'fulfillment_payment' => __('Fulfillment: payment pending', 'sutore-marketplace'),
            'fulfillment_payment_confirmed' => __('Fulfillment: payment confirmed', 'sutore-marketplace'),
            'fulfillment_sold' => __('Sale received', 'sutore-marketplace'),
            'fulfillment_seller_confirmed' => __('Seller confirmed on time', 'sutore-marketplace'),
            'fulfillment_shipped_to_sutore' => __('Shipped to Sutore on time', 'sutore-marketplace'),
            'fulfillment_arrived_at_sutore' => __('Arrived at Sutore', 'sutore-marketplace'),
            'fulfillment_verified' => __('Verified at Sutore', 'sutore-marketplace'),
            'fulfillment_ready_to_ship' => __('Ready to ship', 'sutore-marketplace'),
            'fulfillment_shipped' => __('Shipped to customer', 'sutore-marketplace'),
            'fulfillment_delivered_to_customer' => __('Delivered to customer', 'sutore-marketplace'),
            'fulfillment_chargeback' => __('Customer refund', 'sutore-marketplace'),
            'fulfillment_payout_paid' => __('Merchant payout paid', 'sutore-marketplace'),
            'sale_commission_locked' => __('Commission locked at sale', 'sutore-marketplace'),
            'listing_commission_set' => __('Listing commission set', 'sutore-marketplace'),
            'payout_commission_adjusted' => __('Payout commission adjusted', 'sutore-marketplace'),
            'fulfillment_cargo_expired' => __('Shipping deadline expired', 'sutore-marketplace'),
            'fulfillment_confirm_reminder' => __('Confirmation reminder', 'sutore-marketplace'),
            'fulfillment_cargo_reminder' => __('Shipping reminder', 'sutore-marketplace'),
            self::EVENT_REVERSAL => __('Score record reversed', 'sutore-marketplace'),
            default => $eventType,
        };
    }

    public static function behaviorDigestLabel(string $eventType): string
    {
        return match ($eventType) {
            self::SELLER_CANCELLED => __('You cancelled a sale before the deadline.', 'sutore-marketplace'),
            self::CONFIRM_DEADLINE_MISSED => __('A confirmation deadline was missed.', 'sutore-marketplace'),
            'fulfillment_cargo_expired' => __('A shipping deadline was missed.', 'sutore-marketplace'),
            self::HUB_REJECTED => __('A product was rejected at the hub.', 'sutore-marketplace'),
            self::PRE_ORDER_COMMITMENT_BROKEN => __('A pre-order commitment was broken.', 'sutore-marketplace'),
            'fulfillment_chargeback' => __('A customer refund was processed.', 'sutore-marketplace'),
            'fulfillment_seller_confirmed' => __('You confirmed a sale on time.', 'sutore-marketplace'),
            'fulfillment_shipped_to_sutore' => __('You shipped to Sutore on time.', 'sutore-marketplace'),
            self::SOURCING_FULFILLED => __('You fulfilled a pre-order request.', 'sutore-marketplace'),
            self::EVENT_REVERSAL => __('A previous score record was reversed.', 'sutore-marketplace'),
            default => self::label($eventType),
        };
    }
}
