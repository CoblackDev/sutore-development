<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Domain;

final class CommissionOverrideSource
{
    public const STAFF = 'staff';
    public const TASK = 'task';
    public const CAMPAIGN = 'campaign';
    public const REFERRAL = 'referral';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::STAFF, self::TASK, self::CAMPAIGN, self::REFERRAL];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::all(), true);
    }

    public static function actorRole(string $source): string
    {
        return in_array($source, [self::TASK, self::REFERRAL], true) ? 'system' : 'staff';
    }

    public static function label(string $source): string
    {
        return match ($source) {
            self::STAFF => __('Staff', 'sutore-marketplace'),
            self::TASK => __('Task', 'sutore-marketplace'),
            self::CAMPAIGN => __('Campaign', 'sutore-marketplace'),
            self::REFERRAL => __('Referral', 'sutore-marketplace'),
            default => $source,
        };
    }
}
