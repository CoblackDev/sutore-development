<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Domain;

final class OpportunityCardFamily
{
    public const RECOVERY = 'recovery';
    public const GROWTH = 'growth';
    public const ENGAGEMENT = 'engagement';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::RECOVERY, self::GROWTH, self::ENGAGEMENT];
    }

    public static function label(string $family): string
    {
        return match ($family) {
            self::RECOVERY => __('Recovery', 'sutore-marketplace'),
            self::GROWTH => __('Growth', 'sutore-marketplace'),
            self::ENGAGEMENT => __('Engagement', 'sutore-marketplace'),
            default => $family,
        };
    }
}
