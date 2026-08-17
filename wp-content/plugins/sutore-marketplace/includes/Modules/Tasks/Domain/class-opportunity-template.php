<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Domain;

final class OpportunityTemplate
{
    public const GROWTH_MONTHLY_SALES = 'growth_monthly_sales';
    public const RECOVERY_TIMELY_CONFIRM = 'recovery_timely_confirm';
    public const ENGAGEMENT_SOURCING = 'engagement_sourcing';
    public const ENGAGEMENT_CAMPAIGN = 'engagement_campaign';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::GROWTH_MONTHLY_SALES => __('Monthly sales tiers', 'sutore-marketplace'),
            self::RECOVERY_TIMELY_CONFIRM => __('On-time confirmations', 'sutore-marketplace'),
            self::ENGAGEMENT_SOURCING => __('Accept a pre-order', 'sutore-marketplace'),
            self::ENGAGEMENT_CAMPAIGN => __('Join a campaign', 'sutore-marketplace'),
        ];
    }

    public static function label(string $key): string
    {
        return self::labels()[$key] ?? $key;
    }

    public static function familyFor(string $templateKey): string
    {
        return match ($templateKey) {
            self::RECOVERY_TIMELY_CONFIRM => OpportunityCardFamily::RECOVERY,
            self::GROWTH_MONTHLY_SALES => OpportunityCardFamily::GROWTH,
            default => OpportunityCardFamily::ENGAGEMENT,
        };
    }

    /** @return list<string> */
    public static function adminSelectable(): array
    {
        return [
            self::GROWTH_MONTHLY_SALES,
            self::RECOVERY_TIMELY_CONFIRM,
            self::ENGAGEMENT_SOURCING,
            self::ENGAGEMENT_CAMPAIGN,
        ];
    }
}
