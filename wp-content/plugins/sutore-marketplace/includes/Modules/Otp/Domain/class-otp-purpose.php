<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Domain;

final class OtpPurpose
{
    public const MERCHANT_PROFILE = 'merchant_profile';
    public const ACCOUNT_DETAILS = 'account_details';
    public const PASSWORD_CHANGE = 'password_change';
    public const ACCOUNT_DELETE = 'account_delete';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MERCHANT_PROFILE,
            self::ACCOUNT_DETAILS,
            self::PASSWORD_CHANGE,
            self::ACCOUNT_DELETE,
        ];
    }

    public static function isValid(string $purpose): bool
    {
        return in_array($purpose, self::all(), true);
    }

    public static function label(string $purpose): string
    {
        return match ($purpose) {
            self::MERCHANT_PROFILE => __('Merchant profile', 'sutore-marketplace'),
            self::ACCOUNT_DETAILS => __('Account details', 'sutore-marketplace'),
            self::PASSWORD_CHANGE => __('Password change', 'sutore-marketplace'),
            self::ACCOUNT_DELETE => __('Account deletion', 'sutore-marketplace'),
            default => $purpose,
        };
    }
}
