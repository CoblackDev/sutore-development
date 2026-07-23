<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Repositories\MerchantEventsRepository;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class MerchantProfileChangeLogger
{
    public function __construct(
        private readonly MerchantEventsRepository $events = new MerchantEventsRepository(),
    ) {
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @param array<string, mixed> $extrasApplied
     * @param array<string, mixed> $context actor_role, staff_changed_fields, etc.
     */
    public function logProfileWrite(
        int $merchantId,
        array $before,
        array $after,
        array $extrasApplied = [],
        array $context = [],
        bool $wasNew = false
    ): void {
        if ($merchantId <= 0) {
            return;
        }

        $base = $context;
        if ($wasNew) {
            $this->events->log($merchantId, 'merchant_profile_created', $base);

            return;
        }

        $changed = [];

        if ($this->changed($before, $after, MerchantMeta::ACCOUNT_IBAN)) {
            $this->events->log($merchantId, 'merchant_iban_changed', array_merge($base, [
                'old_iban_last4' => $this->ibanLast4($before[MerchantMeta::ACCOUNT_IBAN] ?? ''),
                'new_iban_last4' => $this->ibanLast4($after[MerchantMeta::ACCOUNT_IBAN] ?? ''),
            ]));
            $changed[] = 'iban';
        }

        if ($this->changed($before, $after, MerchantMeta::ACCOUNT_PHONE)) {
            $this->events->log($merchantId, 'merchant_phone_changed', array_merge($base, [
                'old_phone' => (string) ($before[MerchantMeta::ACCOUNT_PHONE] ?? ''),
                'new_phone' => (string) ($after[MerchantMeta::ACCOUNT_PHONE] ?? ''),
            ]));
            $changed[] = 'phone';
        }

        if ($this->changed($before, $after, MerchantMeta::ACCOUNT_EMAIL)) {
            $this->events->log($merchantId, 'merchant_email_changed', array_merge($base, [
                'old_email' => (string) ($before[MerchantMeta::ACCOUNT_EMAIL] ?? ''),
                'new_email' => (string) ($after[MerchantMeta::ACCOUNT_EMAIL] ?? ''),
            ]));
            $changed[] = 'email';
        }

        if ($this->changed($before, $after, MerchantMeta::ACCOUNT_NAME)
            || $this->changed($before, $after, MerchantMeta::ACCOUNT_LASTNAME)) {
            $this->events->log($merchantId, 'merchant_name_changed', array_merge($base, [
                'old_name' => trim(($before[MerchantMeta::ACCOUNT_NAME] ?? '') . ' ' . ($before[MerchantMeta::ACCOUNT_LASTNAME] ?? '')),
                'new_name' => trim(($after[MerchantMeta::ACCOUNT_NAME] ?? '') . ' ' . ($after[MerchantMeta::ACCOUNT_LASTNAME] ?? '')),
            ]));
            $changed[] = 'name';
        }

        if ($this->changed($before, $after, MerchantMeta::ACCOUNT_CITY)
            || $this->changed($before, $after, MerchantMeta::ACCOUNT_STATE)) {
            $this->events->log($merchantId, 'merchant_address_changed', array_merge($base, [
                'old_city' => (string) ($before[MerchantMeta::ACCOUNT_CITY] ?? ''),
                'old_state' => (string) ($before[MerchantMeta::ACCOUNT_STATE] ?? ''),
                'new_city' => (string) ($after[MerchantMeta::ACCOUNT_CITY] ?? ''),
                'new_state' => (string) ($after[MerchantMeta::ACCOUNT_STATE] ?? ''),
            ]));
            $changed[] = 'address';
        }

        if ($this->changed($before, $after, MerchantMeta::ACCOUNT_TCKNO)) {
            $this->events->log($merchantId, 'merchant_tckno_changed', array_merge($base, [
                'old_tckno_last4' => $this->last4((string) ($before[MerchantMeta::ACCOUNT_TCKNO] ?? '')),
                'new_tckno_last4' => $this->last4((string) ($after[MerchantMeta::ACCOUNT_TCKNO] ?? '')),
            ]));
            $changed[] = 'tckno';
        }

        if ($this->changed($before, $after, MerchantMeta::ACCOUNT_BIRTH_YEAR)) {
            $this->events->log($merchantId, 'merchant_birth_year_changed', array_merge($base, [
                'old_birth_year' => (string) ($before[MerchantMeta::ACCOUNT_BIRTH_YEAR] ?? ''),
                'new_birth_year' => (string) ($after[MerchantMeta::ACCOUNT_BIRTH_YEAR] ?? ''),
            ]));
            $changed[] = 'birth_year';
        }

        if (array_key_exists('tckno_verified', $extrasApplied)) {
            $verified = !empty($extrasApplied['tckno_verified']);
            $this->events->log(
                $merchantId,
                $verified ? 'merchant_tc_verified' : 'merchant_tc_verification_cleared',
                array_merge($base, [
                    'method' => (string) ($extrasApplied['tckno_verify_method'] ?? ''),
                ])
            );
            $changed[] = 'tc_verified';
        }

        if (array_key_exists('merchant_status', $extrasApplied)) {
            $previous = (string) ($context['old_merchant_status'] ?? '');
            $newStatus = sanitize_key((string) $extrasApplied['merchant_status']);
            if ($previous !== $newStatus) {
                $this->events->log($merchantId, 'merchant_level_changed', array_merge($base, [
                    'old_status' => $previous !== '' ? $previous : MerchantLevels::NORMAL,
                    'new_status' => $newStatus,
                ]));
                $changed[] = 'level';
            }
        }

        if (($context['actor_role'] ?? '') === 'staff' && $changed !== []) {
            $this->events->log($merchantId, 'merchant_profile_updated_by_staff', array_merge($base, [
                'changed_fields' => $changed,
            ]));
        }
    }

    public function logLevelChange(int $merchantId, string $oldStatus, string $newStatus, array $context = []): void
    {
        if ($merchantId <= 0 || $oldStatus === $newStatus) {
            return;
        }

        $this->events->log($merchantId, 'merchant_level_changed', array_merge($context, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]));
    }

    public function logRestrictionCreated(int $merchantId, int $restrictionId, string $key, string $reason = '', array $context = []): void
    {
        $this->events->log($merchantId, 'merchant_restriction_created', array_merge($context, [
            'restriction_id' => $restrictionId,
            'restriction_key' => $key,
            'reason' => $reason,
        ]));
    }

    public function logRestrictionDeactivated(int $merchantId, int $restrictionId, string $key = '', array $context = []): void
    {
        $this->events->log($merchantId, 'merchant_restriction_deactivated', array_merge($context, [
            'restriction_id' => $restrictionId,
            'restriction_key' => $key,
        ]));
    }

    public function logRoleGranted(int $merchantId, array $context = []): void
    {
        $this->events->log($merchantId, 'merchant_role_granted', $context);
    }

    /** @param array<string, string> $before @param array<string, string> $after */
    private function changed(array $before, array $after, string $key): bool
    {
        return (string) ($before[$key] ?? '') !== (string) ($after[$key] ?? '');
    }

    private function ibanLast4(string $iban): string
    {
        $clean = preg_replace('/\s+/', '', $iban) ?? '';

        return $clean !== '' ? substr($clean, -4) : '';
    }

    private function last4(string $value): string
    {
        return $value !== '' ? substr($value, -4) : '';
    }
}
