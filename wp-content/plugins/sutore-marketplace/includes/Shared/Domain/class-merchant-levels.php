<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Domain;

use SutoreMarketplace\Modules\Merchants\Repositories\MerchantProfileRepository;
use SutoreMarketplace\Modules\Merchants\Services\CommissionResolver;
use SutoreMarketplace\Modules\Merchants\Services\MerchantProfileChangeLogger;
use SutoreMarketplace\Shared\Settings\Settings;

/**
 * Seller level (marketplace tier) — not TC identity status.
 *
 * Storage keys: normal | verified | premium
 * UI labels:    Normal | Confirmed | Premium
 *
 * TC identity uses separate flags (tckno_verified). Never label TC status with
 * bare "Verified" next to a seller level; use "TC verified" / "TC not verified".
 */
final class MerchantLevels
{
    public const NORMAL = 'normal';
    /** Storage key remains "verified"; public label is "Confirmed". */
    public const VERIFIED = 'verified';
    public const PREMIUM = 'premium';

    public static function statusForUser(int $userId): string
    {
        $row = (new MerchantProfileRepository())->find($userId);
        $status = is_array($row) ? (string) ($row['merchant_status'] ?? '') : '';
        if (in_array($status, [self::NORMAL, self::VERIFIED, self::PREMIUM], true)) {
            return $status;
        }

        return self::NORMAL;
    }

    public static function levelCommissionPercentForUser(int $userId): float
    {
        $status = self::statusForUser($userId);
        $levels = (array) Settings::get('merchant_levels', []);
        $config = $levels[$status] ?? [];

        return (float) ($config['commission_percent'] ?? 12);
    }

    public static function commissionPercentForUser(int $userId): float
    {
        return (new CommissionResolver())->forUser($userId)['percent'];
    }

    public static function labelForStatus(string $status): string
    {
        return match ($status) {
            self::PREMIUM => __('Premium', 'sutore-marketplace'),
            self::VERIFIED => __('Confirmed', 'sutore-marketplace'),
            default => __('Normal', 'sutore-marketplace'),
        };
    }

    /** TC identity flag labels (distinct from seller level). */
    public static function tcStatusLabel(bool $verified): string
    {
        return $verified
            ? __('TC verified', 'sutore-marketplace')
            : __('TC not verified', 'sutore-marketplace');
    }

    public static function setStatus(int $userId, string $status): void
    {
        if (!in_array($status, [self::NORMAL, self::VERIFIED, self::PREMIUM], true)) {
            return;
        }

        $oldStatus = self::statusForUser($userId);
        if ($oldStatus === $status) {
            return;
        }

        $repo = new MerchantProfileRepository();
        $profile = $repo->readProfile($userId);
        $repo->upsert($userId, $profile, ['merchant_status' => $status]);

        (new MerchantProfileChangeLogger())->logLevelChange($userId, $oldStatus, $status, [
            'actor_role' => current_user_can('manage_woocommerce') ? 'staff' : 'system',
        ]);
    }
}
