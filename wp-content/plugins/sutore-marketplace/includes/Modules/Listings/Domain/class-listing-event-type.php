<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class ListingEventType
{
    public static function label(string $eventType): string
    {
        return match ($eventType) {
            'listing_created' => __('Listing created', 'sutore-marketplace'),
            'listing_status_changed' => __('Listing status changed', 'sutore-marketplace'),
            'listing_price_changed' => __('Price changed', 'sutore-marketplace'),
            'listing_condition_changed' => __('Condition changed', 'sutore-marketplace'),
            'listing_shipping_changed' => __('Shipping options changed', 'sutore-marketplace'),
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
            'queue_position_changed' => __('Queue position changed', 'sutore-marketplace'),
            'listing_went_on_sale' => __('Went on sale', 'sutore-marketplace'),
            'listing_left_sale' => __('Left sale', 'sutore-marketplace'),
            'listing_approved' => __('Listing approved', 'sutore-marketplace'),
            'sourcing_pre_order' => __('Pre-order', 'sutore-marketplace'),
            'sourcing_fulfilled' => __('Pre-order fulfilled', 'sutore-marketplace'),
            'sourcing_listing_auto_created' => __('Listing auto-created for pre-order', 'sutore-marketplace'),
            'listing_bulk_import' => __('Bulk import completed', 'sutore-marketplace'),
            'listing_lifecycle_completed' => __('Listing lifecycle completed', 'sutore-marketplace'),
            'fulfillment_payment' => __('Fulfillment: payment pending', 'sutore-marketplace'),
            'fulfillment_payment_confirmed' => __('Fulfillment: payment confirmed', 'sutore-marketplace'),
            'fulfillment_sold' => __('Sale received', 'sutore-marketplace'),
            'fulfillment_seller_confirmed' => __('Seller confirmed', 'sutore-marketplace'),
            'fulfillment_shipped_to_sutore' => __('Shipped to Sutore', 'sutore-marketplace'),
            'fulfillment_arrived_at_sutore' => __('Arrived at Sutore', 'sutore-marketplace'),
            'fulfillment_verified' => __('Verified at Sutore', 'sutore-marketplace'),
            'fulfillment_ready_to_ship' => __('Ready to ship', 'sutore-marketplace'),
            'fulfillment_shipped' => __('Shipped to customer', 'sutore-marketplace'),
            'fulfillment_delivered_to_customer' => __('Delivered to customer', 'sutore-marketplace'),
            'fulfillment_chargeback' => __('Refunded', 'sutore-marketplace'),
            'fulfillment_payout_paid' => __('Merchant payout paid', 'sutore-marketplace'),
            'fulfillment_cargo_expired' => __('Shipping deadline expired', 'sutore-marketplace'),
            'fulfillment_confirm_reminder' => __('Confirmation reminder', 'sutore-marketplace'),
            'fulfillment_cargo_reminder' => __('Shipping reminder', 'sutore-marketplace'),
            default => $eventType,
        };
    }
}
