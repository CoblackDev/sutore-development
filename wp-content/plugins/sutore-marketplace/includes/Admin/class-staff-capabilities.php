<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

/**
 * Staff capability set. WordPress roles receive these at activation/boot;
 * application code checks capabilities, not role names.
 */
final class StaffCapabilities
{
    /** General marketplace operations (fulfillments, campaigns UI, staff My Account). */
    public const MANAGE_OPS = 'sutore_manage_ops';

    /** Provider secrets, NVI endpoint, SMS / invoice credentials. */
    public const MANAGE_SETTINGS = 'sutore_manage_settings';

    /** Mark payout paid, adjust commission, CSV export. */
    public const MANAGE_PAYOUTS = 'sutore_manage_payouts';

    public static function reconcile(): void
    {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::MANAGE_OPS);
            $admin->add_cap(self::MANAGE_SETTINGS);
            $admin->add_cap(self::MANAGE_PAYOUTS);
        }

        $shop = get_role('shop_manager');
        if ($shop) {
            $shop->add_cap(self::MANAGE_OPS);
            $shop->add_cap(self::MANAGE_PAYOUTS);
            // Settings (secrets) stay administrator-only by default.
            $shop->remove_cap(self::MANAGE_SETTINGS);
        }
    }

    public static function canManageOps(?int $userId = null): bool
    {
        return self::userCan(self::MANAGE_OPS, $userId);
    }

    public static function canManageSettings(?int $userId = null): bool
    {
        return self::userCan(self::MANAGE_SETTINGS, $userId)
            || self::userCan('manage_options', $userId);
    }

    public static function canManagePayouts(?int $userId = null): bool
    {
        return self::userCan(self::MANAGE_PAYOUTS, $userId)
            || self::userCan(self::MANAGE_SETTINGS, $userId)
            || self::userCan('manage_options', $userId);
    }

    private static function userCan(string $cap, ?int $userId): bool
    {
        $userId = $userId ?: get_current_user_id();
        if ($userId <= 0) {
            return false;
        }

        return user_can($userId, $cap);
    }
}
