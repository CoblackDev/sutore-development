<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Domain;

final class PayoutStatus
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const REVERSED = 'reversed';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::PAID, self::REVERSED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::PENDING => __('Awaiting payment', 'sutore-marketplace'),
            self::PAID => __('Paid to seller', 'sutore-marketplace'),
            self::REVERSED => __('Cancel / refund', 'sutore-marketplace'),
        ];
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }
}
