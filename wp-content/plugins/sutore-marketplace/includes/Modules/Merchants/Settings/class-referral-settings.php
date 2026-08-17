<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Settings;

use SutoreMarketplace\Shared\Settings\Settings;

final class ReferralSettings
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'invitee_points_off' => 2.0,
            'invitee_duration_days' => 30,
            'inviter_points_off' => 2.0,
            'inviter_duration_days' => 30,
            'inviter_max_rewards_per_period' => 5,
            'period_days' => 30,
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $referral = (array) Settings::get('referral', self::defaults());
        $defaults = self::defaults();

        return $referral[$key] ?? $defaults[$key] ?? $default;
    }

    public static function inviteePointsOff(): float
    {
        return max(0.0, min(100.0, (float) self::get('invitee_points_off', 2.0)));
    }

    public static function inviteeDurationDays(): int
    {
        return max(1, (int) self::get('invitee_duration_days', 30));
    }

    public static function inviterPointsOff(): float
    {
        return max(0.0, min(100.0, (float) self::get('inviter_points_off', 2.0)));
    }

    public static function inviterDurationDays(): int
    {
        return max(1, (int) self::get('inviter_duration_days', 30));
    }

    public static function inviterMaxRewardsPerPeriod(): int
    {
        return max(0, (int) self::get('inviter_max_rewards_per_period', 5));
    }

    public static function periodDays(): int
    {
        return max(1, (int) self::get('period_days', 30));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitizeFromInput(array $input): array
    {
        $defaults = self::defaults();

        return [
            'invitee_points_off' => max(0.0, min(100.0, (float) ($input['referral_invitee_points_off'] ?? $defaults['invitee_points_off']))),
            'invitee_duration_days' => max(1, (int) ($input['referral_invitee_duration_days'] ?? $defaults['invitee_duration_days'])),
            'inviter_points_off' => max(0.0, min(100.0, (float) ($input['referral_inviter_points_off'] ?? $defaults['inviter_points_off']))),
            'inviter_duration_days' => max(1, (int) ($input['referral_inviter_duration_days'] ?? $defaults['inviter_duration_days'])),
            'inviter_max_rewards_per_period' => max(0, (int) ($input['referral_inviter_max_rewards_per_period'] ?? $defaults['inviter_max_rewards_per_period'])),
            'period_days' => max(1, (int) ($input['referral_period_days'] ?? $defaults['period_days'])),
        ];
    }
}
