<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Settings;

use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;
use SutoreMarketplace\Shared\Settings\Settings;

final class BehaviorSettings
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'score_window_days' => 90,
            'asking_reference' => 10000.0,
            'confirmed_min_score' => 3.5,
            'confirmed_min_sales' => 1,
            'sourcing_min_score' => 4.0,
            'premium_min_score' => 4.5,
            'premium_monthly_min_sales' => 5,
            'premium_monthly_min_revenue' => 50000.0,
            'sourcing_early_access_hours' => 24,
            'new_seller_protection_deliveries' => 3,
            'new_seller_protection_days' => 30,
            'shadow_mode_weeks' => 4,
            'shadow_mode_enabled' => false,
            'growth_sales_tiers' => [3, 5, 8],
            'growth_commission_rewards' => [11.0, 10.0, 9.0],
            'recovery_confirm_target' => 3,
            'event_weights' => self::defaultEventWeights(),
        ];
    }

    /** @return array<string, float> */
    public static function defaultEventWeights(): array
    {
        return [
            ListingEventType::SELLER_CANCELLED => -0.6,
            ListingEventType::CONFIRM_DEADLINE_MISSED => -0.5,
            'fulfillment_cargo_expired' => -0.5,
            ListingEventType::HUB_REJECTED => -0.8,
            ListingEventType::PRE_ORDER_COMMITMENT_BROKEN => -1.2,
            'fulfillment_chargeback' => -0.3,
            'fulfillment_seller_confirmed' => 0.15,
            'fulfillment_shipped_to_sutore' => 0.15,
            ListingEventType::SOURCING_FULFILLED => 0.2,
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $behavior = (array) Settings::get('behavior', self::defaults());
        $defaults = self::defaults();

        return $behavior[$key] ?? $defaults[$key] ?? $default;
    }

    public static function scoreWindowDays(): int
    {
        return max(7, (int) self::get('score_window_days', 90));
    }

    public static function askingReference(): float
    {
        return max(1.0, (float) self::get('asking_reference', 10000.0));
    }

    public static function confirmedMinScore(): float
    {
        return (float) self::get('confirmed_min_score', 3.5);
    }

    public static function confirmedMinSales(): int
    {
        return max(0, (int) self::get('confirmed_min_sales', 1));
    }

    public static function sourcingMinScore(): float
    {
        return (float) self::get('sourcing_min_score', 4.0);
    }

    public static function premiumMinScore(): float
    {
        return (float) self::get('premium_min_score', 4.5);
    }

    public static function premiumMonthlyMinSales(): int
    {
        return max(1, (int) self::get('premium_monthly_min_sales', 5));
    }

    public static function premiumMonthlyMinRevenue(): float
    {
        return max(0.0, (float) self::get('premium_monthly_min_revenue', 50000.0));
    }

    public static function sourcingEarlyAccessHours(): int
    {
        return max(0, (int) self::get('sourcing_early_access_hours', 24));
    }

    public static function newSellerProtectionDeliveries(): int
    {
        return max(0, (int) self::get('new_seller_protection_deliveries', 3));
    }

    public static function newSellerProtectionDays(): int
    {
        return max(0, (int) self::get('new_seller_protection_days', 30));
    }

    public static function shadowModeWeeks(): int
    {
        return max(0, (int) self::get('shadow_mode_weeks', 4));
    }

    public static function shadowModeEnabled(): bool
    {
        return (bool) self::get('shadow_mode_enabled', false);
    }

    /** @return array<string, float> */
    public static function eventWeights(): array
    {
        $stored = self::get('event_weights', self::defaultEventWeights());
        if (!is_array($stored)) {
            return self::defaultEventWeights();
        }

        $merged = self::defaultEventWeights();
        foreach ($stored as $key => $value) {
            $merged[(string) $key] = (float) $value;
        }

        return $merged;
    }

    public static function eventWeight(string $eventType): float
    {
        $weights = self::eventWeights();

        return (float) ($weights[$eventType] ?? 0.0);
    }

    /** @return list<int> */
    public static function growthSalesTiers(): array
    {
        $raw = self::get('growth_sales_tiers', [3, 5, 8]);
        if (!is_array($raw)) {
            return [3, 5, 8];
        }

        $tiers = array_values(array_filter(array_map(static fn ($v) => max(1, (int) $v), $raw)));
        sort($tiers);

        return $tiers !== [] ? $tiers : [3, 5, 8];
    }

    /** @return list<float> */
    public static function growthCommissionRewards(): array
    {
        $raw = self::get('growth_commission_rewards', [11.0, 10.0, 9.0]);
        if (!is_array($raw)) {
            return [11.0, 10.0, 9.0];
        }

        return array_map(static fn ($v) => (float) $v, $raw);
    }

    public static function recoveryConfirmTarget(): int
    {
        return max(1, (int) self::get('recovery_confirm_target', 3));
    }

    public static function currentPeriodKey(): string
    {
        return wp_date('Y-m');
    }
}
