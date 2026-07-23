<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class ListingPolicy
{
    public const ALLOWED_ROLES = ['merchant', 'shop_manager', 'administrator'];

    public static function assertCanManage(?int $userId = null): true|\WP_Error
    {
        $userId = $userId ?: get_current_user_id();
        $user = get_userdata($userId);

        if (!$user) {
            return new \WP_Error('sutore_marketplace_auth', __('You must log in.', 'sutore-marketplace'));
        }

        $roles = (array) $user->roles;
        foreach (self::ALLOWED_ROLES as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }

        if (user_can($userId, 'manage_woocommerce')) {
            return true;
        }

        return new \WP_Error(
            'sutore_marketplace_forbidden',
            __('You do not have permission for this action.', 'sutore-marketplace')
        );
    }

    public static function assertOwnsListing(Listing $listing, ?int $userId = null): true|\WP_Error
    {
        $userId = $userId ?: get_current_user_id();
        if ((int) $listing->merchantId === (int) $userId) {
            return true;
        }

        if (user_can($userId, 'manage_woocommerce') || user_can($userId, 'edit_others_products')) {
            return true;
        }

        return new \WP_Error(
            'sutore_marketplace_not_owner',
            __('This Listing does not belong to you.', 'sutore-marketplace')
        );
    }

    public static function canUseFastShipment(?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();
        $city = (string) (MerchantMeta::readProfile($userId)[MerchantMeta::ACCOUNT_CITY] ?? '');
        $status = MerchantLevels::statusForUser($userId);
        $requiredCity = \SutoreMarketplace\Shared\Settings\Settings::fastShipmentCity();
        $allowedLevels = \SutoreMarketplace\Shared\Settings\Settings::fastShipmentLevels();

        return $city === $requiredCity && in_array($status, $allowedLevels, true);
    }

    /** Onaylı / Premium satıcılar bedendeki tüm satış fiyatlarını görebilir. */
    public static function canViewCompetingPrices(?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();
        if (user_can($userId, 'manage_woocommerce')) {
            return true;
        }

        $status = MerchantLevels::statusForUser($userId);

        return in_array($status, [MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true);
    }
}
