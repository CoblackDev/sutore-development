<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class CampaignStatus
{
    public const DRAFT = 'draft';
    public const ACTIVE = 'active';
    public const ENDED = 'ended';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::ACTIVE, self::ENDED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::DRAFT => __('Draft', 'sutore-marketplace'),
            self::ACTIVE => __('Active', 'sutore-marketplace'),
            self::ENDED => __('Ended', 'sutore-marketplace'),
        ];
    }

    public static function label(string $status): string
    {
        $labels = self::labels();

        return $labels[$status] ?? $status;
    }
}
