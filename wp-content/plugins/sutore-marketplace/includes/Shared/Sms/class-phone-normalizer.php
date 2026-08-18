<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

final class PhoneNormalizer
{
    public static function toDomestic(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (str_starts_with($digits, '90') && strlen($digits) > 10) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    public static function isValidDomestic(string $phone): bool
    {
        $digits = self::toDomestic($phone);

        return strlen($digits) === 10 && str_starts_with($digits, '5');
    }

    /** E.164 for IYS (Netgsm expects +90…). */
    public static function toIysRecipient(string $phone): string
    {
        $digits = self::toDomestic($phone);
        if (!self::isValidDomestic($digits)) {
            return '';
        }

        return '+90' . $digits;
    }
}
