<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Repositories;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Security\SecretBox;

final class MerchantProfileRepository
{
    public function table(): string
    {
        return Schema::table('merchant_profiles');
    }

    /** @return array<string, string>|null */
    public function find(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE user_id = %d',
            $userId
        ), ARRAY_A);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /** @return array<string, string> */
    public function readProfile(int $userId): array
    {
        $row = $this->find($userId);
        $profile = $this->emptyProfile();
        if ($row === null) {
            return $profile;
        }

        foreach (MerchantMeta::profileKeys() as $key) {
            $profile[$key] = (string) ($row[$key] ?? '');
        }

        return $profile;
    }

    /**
     * @param array<string, string> $profile
     * @param array<string, mixed> $extras tckno_verified, merchant_status, etc.
     */
    public function upsert(int $userId, array $profile, array $extras = []): void
    {
        if ($userId <= 0) {
            return;
        }

        global $wpdb;
        $now = current_time('mysql');
        $existing = $this->find($userId);

        $data = [
            'user_id' => $userId,
            'account_name' => (string) ($profile[MerchantMeta::ACCOUNT_NAME] ?? $existing['account_name'] ?? ''),
            'account_lastname' => (string) ($profile[MerchantMeta::ACCOUNT_LASTNAME] ?? $existing['account_lastname'] ?? ''),
            'account_iban' => $this->sealSecretField(
                (string) ($profile[MerchantMeta::ACCOUNT_IBAN] ?? $existing['account_iban'] ?? '')
            ),
            'account_tckno' => $this->sealSecretField(
                (string) ($profile[MerchantMeta::ACCOUNT_TCKNO] ?? $existing['account_tckno'] ?? '')
            ),
            'account_birth_year' => (string) ($profile[MerchantMeta::ACCOUNT_BIRTH_YEAR] ?? $existing['account_birth_year'] ?? ''),
            'account_email' => (string) ($profile[MerchantMeta::ACCOUNT_EMAIL] ?? $existing['account_email'] ?? ''),
            'account_phone' => (string) ($profile[MerchantMeta::ACCOUNT_PHONE] ?? $existing['account_phone'] ?? ''),
            'account_city' => (string) ($profile[MerchantMeta::ACCOUNT_CITY] ?? $existing['account_city'] ?? ''),
            'account_state' => (string) ($profile[MerchantMeta::ACCOUNT_STATE] ?? $existing['account_state'] ?? ''),
            'updated_at' => $now,
        ];

        if (array_key_exists('tckno_verified', $extras)) {
            $data['tckno_verified'] = !empty($extras['tckno_verified']) ? 1 : 0;
        } elseif ($existing !== null) {
            $data['tckno_verified'] = (int) ($existing['tckno_verified'] ?? 0);
        } else {
            $data['tckno_verified'] = 0;
        }

        if (array_key_exists('tckno_verified_at', $extras)) {
            $data['tckno_verified_at'] = (int) $extras['tckno_verified_at'];
        } elseif ($existing !== null) {
            $data['tckno_verified_at'] = (int) ($existing['tckno_verified_at'] ?? 0);
        } else {
            $data['tckno_verified_at'] = 0;
        }

        if (array_key_exists('tckno_verify_method', $extras)) {
            $data['tckno_verify_method'] = sanitize_key((string) $extras['tckno_verify_method']);
        } elseif ($existing !== null) {
            $data['tckno_verify_method'] = (string) ($existing['tckno_verify_method'] ?? '');
        } else {
            $data['tckno_verify_method'] = '';
        }

        if (array_key_exists('marketing_consent', $extras)) {
            $data['marketing_consent'] = !empty($extras['marketing_consent']) ? 1 : 0;
        } elseif ($existing !== null) {
            $data['marketing_consent'] = (int) ($existing['marketing_consent'] ?? 0);
        } else {
            $data['marketing_consent'] = 0;
        }

        if (array_key_exists('merchant_status', $extras)) {
            $status = sanitize_key((string) $extras['merchant_status']);
            $data['merchant_status'] = in_array($status, [MerchantLevels::NORMAL, MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true)
                ? $status
                : MerchantLevels::NORMAL;
        } elseif ($existing !== null) {
            $data['merchant_status'] = (string) ($existing['merchant_status'] ?? MerchantLevels::NORMAL);
        } else {
            $data['merchant_status'] = MerchantLevels::NORMAL;
        }

        if ($existing === null) {
            $data['created_at'] = $now;
            $wpdb->insert($this->table(), $data);
        } else {
            $wpdb->update($this->table(), $data, ['user_id' => $userId]);
        }
    }

    public function setField(int $userId, string $column, string|int $value): void
    {
        $profile = $this->readProfile($userId);
        $extras = [];
        if (in_array($column, ['tckno_verified', 'tckno_verified_at', 'tckno_verify_method', 'marketing_consent', 'merchant_status'], true)) {
            $extras[$column] = $value;
            $this->upsert($userId, $profile, $extras);

            return;
        }

        if (in_array($column, MerchantMeta::profileKeys(), true)) {
            $profile[$column] = (string) $value;
            $this->upsert($userId, $profile);
        }
    }

    public function findUserIdByReferralCode(string $code): int
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . $this->table() . ' WHERE referral_code = %s LIMIT 1',
            $code
        ));
    }

    public function setReferralCode(int $userId, string $code): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $code = strtoupper(trim($code));
        if ($code === '') {
            return false;
        }

        global $wpdb;

        $result = $wpdb->update(
            $this->table(),
            [
                'referral_code' => $code,
                'updated_at' => current_time('mysql'),
            ],
            ['user_id' => $userId],
            ['%s', '%s'],
            ['%d']
        );

        return is_int($result) && $result > 0;
    }

    public function setReferredBy(int $inviteeId, int $inviterId): bool
    {
        if ($inviteeId <= 0 || $inviterId <= 0 || $inviteeId === $inviterId) {
            return false;
        }

        global $wpdb;
        $now = current_time('mysql');
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . $this->table() . '
                SET referred_by_user_id = %d, updated_at = %s
              WHERE user_id = %d
                AND (referred_by_user_id IS NULL OR referred_by_user_id = 0 OR referred_by_user_id = %d)',
            $inviterId,
            $now,
            $inviteeId,
            $inviterId
        ));

        return $updated === 1 || $this->isReferredBy($inviteeId, $inviterId);
    }

    public function claimReferralReward(int $inviteeId): bool
    {
        if ($inviteeId <= 0) {
            return false;
        }

        global $wpdb;
        $now = current_time('mysql');
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . $this->table() . '
                SET referral_rewarded_at = %s, updated_at = %s
              WHERE user_id = %d
                AND referred_by_user_id > 0
                AND referral_rewarded_at IS NULL',
            $now,
            $now,
            $inviteeId
        ));

        return $updated === 1;
    }

    public function isReferredBy(int $inviteeId, int $inviterId): bool
    {
        $row = $this->find($inviteeId);

        return $row !== null && (int) ($row['referred_by_user_id'] ?? 0) === $inviterId;
    }

    public function findUserIdByPhone(string $phone, int $exceptUserId = 0): int
    {
        if ($phone === '') {
            return 0;
        }

        global $wpdb;
        if ($exceptUserId > 0) {
            $userId = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT user_id FROM ' . $this->table() . ' WHERE account_phone = %s AND user_id <> %d LIMIT 1',
                $phone,
                $exceptUserId
            ));
        } else {
            $userId = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT user_id FROM ' . $this->table() . ' WHERE account_phone = %s LIMIT 1',
                $phone
            ));
        }

        return $userId;
    }

    /** @return array<string, string> */
    private function emptyProfile(): array
    {
        $profile = [];
        foreach (MerchantMeta::profileKeys() as $key) {
            $profile[$key] = '';
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = is_scalar($value) || $value === null ? (string) $value : '';
        }

        foreach ([MerchantMeta::ACCOUNT_IBAN, MerchantMeta::ACCOUNT_TCKNO] as $secretKey) {
            $raw = (string) ($out[$secretKey] ?? '');
            if ($raw === '') {
                continue;
            }
            if (SecretBox::isSealed($raw)) {
                $out[$secretKey] = SecretBox::open($raw);
                continue;
            }
            // One-shot seal of legacy plaintext on next write path; open as-is for reads.
            $out[$secretKey] = $raw;
        }

        return $out;
    }

    private function sealSecretField(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }
        if (SecretBox::isSealed($plain)) {
            return $plain;
        }

        return SecretBox::seal($plain);
    }
}
