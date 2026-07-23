<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;

/**
 * Merchant profile snapshot frozen at sale time (fulfillment.merchant_snapshot).
 * Do not re-read live user meta for payout display — the seller may update later.
 */
final class MerchantSnapshot
{
    /**
     * @return array{
     *   account_name: string,
     *   account_lastname: string,
     *   name: string,
     *   iban: string,
     *   tc: string,
     *   birth_year: string,
     *   phone: string,
     *   email: string,
     *   city: string,
     *   state: string,
     *   captured_at: string
     * }
     */
    public static function capture(int $merchantId): array
    {
        $profile = MerchantMeta::readProfile($merchantId);
        $name = trim(
            $profile[MerchantMeta::ACCOUNT_NAME] . ' ' . $profile[MerchantMeta::ACCOUNT_LASTNAME]
        );

        return [
            'account_name' => $profile[MerchantMeta::ACCOUNT_NAME],
            'account_lastname' => $profile[MerchantMeta::ACCOUNT_LASTNAME],
            'name' => $name,
            'iban' => $profile[MerchantMeta::ACCOUNT_IBAN],
            'tc' => $profile[MerchantMeta::ACCOUNT_TCKNO],
            'birth_year' => $profile[MerchantMeta::ACCOUNT_BIRTH_YEAR],
            'phone' => $profile[MerchantMeta::ACCOUNT_PHONE],
            'email' => $profile[MerchantMeta::ACCOUNT_EMAIL],
            'city' => $profile[MerchantMeta::ACCOUNT_CITY],
            'state' => $profile[MerchantMeta::ACCOUNT_STATE],
            'captured_at' => current_time('mysql'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            $data = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : [];
        } else {
            $data = [];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = (string) ($value ?? '');
            }
        }

        if (($out['name'] ?? '') === '') {
            $out['name'] = trim(($out['account_name'] ?? '') . ' ' . ($out['account_lastname'] ?? ''));
        }

        return $out;
    }

    public static function hasPaymentFields(array $snapshot): bool
    {
        foreach (['iban', 'name', 'tc', 'phone', 'email'] as $key) {
            if (trim((string) ($snapshot[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
