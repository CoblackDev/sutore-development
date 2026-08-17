<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Services;

use SutoreMarketplace\Modules\Merchants\Repositories\MerchantProfileRepository;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Sms\PhoneNormalizer;

final class OtpPhoneResolver
{
    public static function normalize(string $phone): string
    {
        return PhoneNormalizer::toDomestic($phone);
    }

    public static function forUser(int $userId, ?string $candidate = null): string
    {
        if ($candidate !== null && $candidate !== '') {
            return self::normalize($candidate);
        }

        $profile = MerchantMeta::readProfile($userId);

        return self::normalize((string) ($profile[MerchantMeta::ACCOUNT_PHONE] ?? ''));
    }

    public static function mask(string $phone): string
    {
        $digits = self::normalize($phone);
        if (strlen($digits) < 4) {
            return '****';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    public static function isAvailable(string $phone, int $exceptUserId = 0): bool
    {
        $normalized = self::normalize($phone);
        if ($normalized === '') {
            return false;
        }

        $found = (new MerchantProfileRepository())->findUserIdByPhone($normalized, $exceptUserId);

        return $found === 0;
    }
}
