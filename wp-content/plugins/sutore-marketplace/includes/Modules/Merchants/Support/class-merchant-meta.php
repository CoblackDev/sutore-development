<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Support;

use SutoreMarketplace\Modules\Merchants\Repositories\MerchantProfileRepository;

final class MerchantMeta
{
    public const ACCOUNT_NAME = 'account_name';
    public const ACCOUNT_LASTNAME = 'account_lastname';
    public const ACCOUNT_IBAN = 'account_iban';
    public const ACCOUNT_TCKNO = 'account_tckno';
    public const ACCOUNT_BIRTH_YEAR = 'account_birth_year';
    public const ACCOUNT_EMAIL = 'account_email';
    public const ACCOUNT_PHONE = 'account_phone';
    public const ACCOUNT_CITY = 'account_city';
    public const ACCOUNT_STATE = 'account_state';
    public const TCKNO_VERIFIED = 'account_tckno_verified';
    public const TCKNO_VERIFIED_AT = 'account_tckno_verified_at';
    public const TCKNO_VERIFY_METHOD = 'account_tckno_verify_method';
    public const MARKETING_CONSENT = 'marketing_consent';

    /** @return list<string> */
    public static function profileKeys(): array
    {
        return [
            self::ACCOUNT_NAME,
            self::ACCOUNT_LASTNAME,
            self::ACCOUNT_IBAN,
            self::ACCOUNT_TCKNO,
            self::ACCOUNT_BIRTH_YEAR,
            self::ACCOUNT_EMAIL,
            self::ACCOUNT_PHONE,
            self::ACCOUNT_CITY,
            self::ACCOUNT_STATE,
        ];
    }

    /** @return array<string, string> */
    public static function readProfile(int $userId): array
    {
        return (new MerchantProfileRepository())->readProfile($userId);
    }

    /** @param array<string, string> $profile */
    public static function writeProfile(int $userId, array $profile, array $extras = []): void
    {
        (new MerchantProfileRepository())->upsert($userId, $profile, $extras);
    }

    public static function isTcVerified(int $userId): bool
    {
        $row = (new MerchantProfileRepository())->find($userId);

        return $row !== null && !empty($row['tckno_verified']);
    }

    public static function isProfileComplete(int $userId): bool
    {
        foreach (self::readProfile($userId) as $value) {
            if (trim((string) $value) === '') {
                return false;
            }
        }

        return true;
    }

    public static function isMerchant(int $userId): bool
    {
        $user = get_userdata($userId);
        if (!$user) {
            return false;
        }

        return in_array('merchant', (array) $user->roles, true);
    }

    public static function canViewMerchantDashboard(int $userId): bool
    {
        if (self::isMerchant($userId)) {
            return true;
        }

        $user = get_userdata($userId);
        if (!$user) {
            return false;
        }

        if (\SutoreMarketplace\Modules\Listings\Domain\ListingPolicy::assertCanManage($userId) === true) {
            return true;
        }

        return self::isProfileComplete($userId) && self::isTcVerified($userId);
    }

    public static function marketingConsent(int $userId): bool
    {
        $row = (new MerchantProfileRepository())->find($userId);

        return $row !== null && !empty($row['marketing_consent']);
    }

    public static function setMarketingConsent(int $userId, bool $consent): void
    {
        (new MerchantProfileRepository())->setField($userId, 'marketing_consent', $consent ? 1 : 0);
    }

    public static function setPhone(int $userId, string $phone): void
    {
        (new MerchantProfileRepository())->setField($userId, self::ACCOUNT_PHONE, $phone);
    }
}
