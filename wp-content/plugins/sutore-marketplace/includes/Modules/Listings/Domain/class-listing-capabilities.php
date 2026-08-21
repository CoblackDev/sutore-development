<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

use SutoreMarketplace\Admin\StaffCapabilities;

/**
 * Marketplace-specific capabilities. Merchants must not hold native WooCommerce
 * product write caps; listing mutations go through plugin services only.
 */
final class ListingCapabilities
{
    public const MANAGE_OWN = 'sutore_manage_own_listings';

    /** @return array<string, bool> */
    public static function merchantCaps(): array
    {
        return [
            'read' => true,
            self::MANAGE_OWN => true,
        ];
    }

    public static function reconcileMerchantRole(): void
    {
        $role = get_role('merchant');
        if (!$role) {
            add_role('merchant', 'Merchant', self::merchantCaps());

            return;
        }

        $role->add_cap('read');
        $role->add_cap(self::MANAGE_OWN);
        $role->remove_cap('edit_products');
        $role->remove_cap('edit_published_products');
        $role->remove_cap('publish_products');
        $role->remove_cap('delete_products');
        $role->remove_cap('upload_files');
    }

    public static function userCanManageOwn(?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();
        if ($userId <= 0) {
            return false;
        }

        return user_can($userId, self::MANAGE_OWN) || StaffCapabilities::canManageOps($userId);
    }
}
