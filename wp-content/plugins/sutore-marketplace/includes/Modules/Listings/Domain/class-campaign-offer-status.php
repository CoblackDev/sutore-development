<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class CampaignOfferStatus
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const EXPIRED = 'expired';
    public const WITHDRAWN = 'withdrawn';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::ACCEPTED,
            self::DECLINED,
            self::EXPIRED,
            self::WITHDRAWN,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::PENDING => __('Pending', 'sutore-marketplace'),
            self::ACCEPTED => __('Accepted', 'sutore-marketplace'),
            self::DECLINED => __('Declined', 'sutore-marketplace'),
            self::EXPIRED => __('Expired', 'sutore-marketplace'),
            self::WITHDRAWN => __('Withdrawn', 'sutore-marketplace'),
        ];
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }
}
