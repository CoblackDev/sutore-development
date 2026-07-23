<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Domain;

final class NotificationType
{
    public const SALE_RECEIVED = 'sale.received';
    public const SALE_CONFIRM_REMINDER = 'sale.confirm_reminder';
    public const SALE_CONFIRMED = 'sale.confirmed';
    public const SALE_SUSPENDED = 'sale.suspended';
    public const SALE_CARGO_REMINDER = 'sale.cargo_reminder';
    public const SALE_CARGO_EXPIRED = 'sale.cargo_expired';

    public const FULFILLMENT_SHIPPED_TO_SUTORE = 'fulfillment.shipped_to_sutore';
    public const FULFILLMENT_ARRIVED_AT_SUTORE = 'fulfillment.arrived_at_sutore';
    public const FULFILLMENT_VERIFIED = 'fulfillment.verified';
    public const FULFILLMENT_SHIPPED = 'fulfillment.shipped';

    public const PAYOUT_PENDING = 'payout.pending';
    public const PAYOUT_PAID = 'payout.paid';
    public const PAYOUT_REVERSED = 'payout.reversed';

    public const LISTING_WINNER_GAINED = 'listing.winner_gained';
    public const LISTING_WINNER_LOST = 'listing.winner_lost';
    public const LISTING_EXPIRED = 'listing.expired';

    public const LISTING_BULK_IMPORT_COMPLETED = 'listing.bulk_import_completed';

    public const TASK_COMPLETED = 'task.completed';

    public const CAMPAIGN_OFFER = 'campaign.offer';

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            self::SALE_RECEIVED => 'sales',
            self::SALE_CONFIRM_REMINDER => 'sales',
            self::SALE_CONFIRMED => 'sales',
            self::SALE_SUSPENDED => 'sales',
            self::SALE_CARGO_REMINDER => 'sales',
            self::SALE_CARGO_EXPIRED => 'sales',
            self::FULFILLMENT_SHIPPED_TO_SUTORE => 'fulfillment',
            self::FULFILLMENT_ARRIVED_AT_SUTORE => 'fulfillment',
            self::FULFILLMENT_VERIFIED => 'fulfillment',
            self::FULFILLMENT_SHIPPED => 'fulfillment',
            self::PAYOUT_PENDING => 'payout',
            self::PAYOUT_PAID => 'payout',
            self::PAYOUT_REVERSED => 'payout',
            self::LISTING_WINNER_GAINED => 'listing',
            self::LISTING_WINNER_LOST => 'listing',
            self::LISTING_EXPIRED => 'listing',
            self::LISTING_BULK_IMPORT_COMPLETED => 'listing',
            self::TASK_COMPLETED => 'system',
            self::CAMPAIGN_OFFER => 'listing',
        ];
    }

    public static function categoryFor(string $type): string
    {
        return self::categories()[$type] ?? 'system';
    }

    /** @return array<string, bool> */
    public static function defaultEventFlags(): array
    {
        $flags = [];
        foreach (array_keys(self::categories()) as $type) {
            $flags[$type] = true;
        }

        return $flags;
    }

    /** @return array<string, string> */
    public static function eventLabels(): array
    {
        return [
            self::SALE_RECEIVED => __('Sale received', 'sutore-marketplace'),
            self::SALE_CONFIRM_REMINDER => __('Sale confirmation reminder', 'sutore-marketplace'),
            self::SALE_CONFIRMED => __('Sale confirmed', 'sutore-marketplace'),
            self::SALE_SUSPENDED => __('Sale not for sale', 'sutore-marketplace'),
            self::SALE_CARGO_REMINDER => __('Shipping reminder', 'sutore-marketplace'),
            self::SALE_CARGO_EXPIRED => __('Shipping deadline expired', 'sutore-marketplace'),
            self::FULFILLMENT_SHIPPED_TO_SUTORE => __('Shipped to Sutore', 'sutore-marketplace'),
            self::FULFILLMENT_ARRIVED_AT_SUTORE => __('Arrived at hub', 'sutore-marketplace'),
            self::FULFILLMENT_VERIFIED => __('Verified', 'sutore-marketplace'),
            self::FULFILLMENT_SHIPPED => __('Shipped to customer', 'sutore-marketplace'),
            self::PAYOUT_PENDING => __('Pending payout', 'sutore-marketplace'),
            self::PAYOUT_PAID => __('Payout paid', 'sutore-marketplace'),
            self::PAYOUT_REVERSED => __('Payout cancelled', 'sutore-marketplace'),
            self::LISTING_WINNER_GAINED => __('Moved up to #1', 'sutore-marketplace'),
            self::LISTING_WINNER_LOST => __('Queue loss', 'sutore-marketplace'),
            self::LISTING_EXPIRED => __('Listing expired', 'sutore-marketplace'),
            self::LISTING_BULK_IMPORT_COMPLETED => __('Bulk import completed', 'sutore-marketplace'),
            self::TASK_COMPLETED => __('Task completed', 'sutore-marketplace'),
            self::CAMPAIGN_OFFER => __('Campaign offer', 'sutore-marketplace'),
        ];
    }

    public static function eventLabel(string $type): string
    {
        return self::eventLabels()[$type] ?? $type;
    }

    /** @return array<string, string> */
    public static function categoryLabels(): array
    {
        return [
            'sales' => __('Sale', 'sutore-marketplace'),
            'fulfillment' => __('Shipping & verification', 'sutore-marketplace'),
            'payout' => __('Payout', 'sutore-marketplace'),
            'listing' => __('Listing & queue', 'sutore-marketplace'),
            'system' => __('System', 'sutore-marketplace'),
        ];
    }

    /** @return array<string, list<string>> */
    public static function typesByCategory(): array
    {
        $grouped = [];
        foreach (self::categories() as $type => $category) {
            $grouped[$category][] = $type;
        }

        return $grouped;
    }
}
