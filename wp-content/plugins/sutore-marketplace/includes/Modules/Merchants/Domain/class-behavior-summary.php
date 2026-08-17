<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Domain;

use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;

final class BehaviorSummary
{
    public const NO_SALES = 'no_sales_yet';
    public const ALL_ON_TIME = 'all_on_time';
    public const STRONG = 'strong';
    public const PROTECTED = 'protected';
    public const SHADOW = 'shadow';
    public const SELLER_CANCELLED = 'seller_cancelled';
    public const CONFIRM_DELAYS = 'confirm_delays';
    public const CARGO_DELAYS = 'cargo_delays';
    public const HUB_REJECTED = 'hub_rejected';
    public const PRE_ORDER_BROKEN = 'pre_order_broken';
    public const MIXED = 'mixed';

    public static function fromEventType(string $eventType): string
    {
        return match ($eventType) {
            ListingEventType::SELLER_CANCELLED => self::SELLER_CANCELLED,
            ListingEventType::CONFIRM_DEADLINE_MISSED, 'fulfillment_confirm_reminder' => self::CONFIRM_DELAYS,
            'fulfillment_cargo_expired' => self::CARGO_DELAYS,
            ListingEventType::HUB_REJECTED => self::HUB_REJECTED,
            ListingEventType::PRE_ORDER_COMMITMENT_BROKEN => self::PRE_ORDER_BROKEN,
            default => self::MIXED,
        };
    }

    public static function sentence(string $key, int $negativeEvents = 0): string
    {
        return match ($key) {
            self::NO_SALES => __('Complete your first sales to establish your behavior score.', 'sutore-marketplace'),
            self::ALL_ON_TIME => __('You handled recent sales reliably in the last 90 days.', 'sutore-marketplace'),
            self::STRONG => __('Your recent performance is strong.', 'sutore-marketplace'),
            self::PROTECTED => __('Your score will appear after your first deliveries.', 'sutore-marketplace'),
            self::SHADOW => __('Your score is being calibrated and is not shown yet.', 'sutore-marketplace'),
            self::SELLER_CANCELLED => __('A sale cancellation lowered your score.', 'sutore-marketplace'),
            self::CONFIRM_DELAYS => sprintf(
                /* translators: %d: number of late confirmations */
                _n(
                    '%d late confirmation lowered your score.',
                    '%d late confirmations lowered your score.',
                    max(1, $negativeEvents),
                    'sutore-marketplace'
                ),
                max(1, $negativeEvents)
            ),
            self::CARGO_DELAYS => sprintf(
                /* translators: %d: number of late shipments */
                _n(
                    '%d late shipment lowered your score.',
                    '%d late shipments lowered your score.',
                    max(1, $negativeEvents),
                    'sutore-marketplace'
                ),
                max(1, $negativeEvents)
            ),
            self::HUB_REJECTED => __('A hub rejection lowered your score.', 'sutore-marketplace'),
            self::PRE_ORDER_BROKEN => __('A broken pre-order commitment lowered your score.', 'sutore-marketplace'),
            default => __('Your score reflects recent sale events in the last 90 days.', 'sutore-marketplace'),
        };
    }
}
