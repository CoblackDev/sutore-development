<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

use SutoreMarketplace\Admin\StaffCapabilities;
use SutoreMarketplace\Modules\Merchants\Repositories\RestrictionsRepository;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService;
use SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Settings\Settings;

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

        if (ListingCapabilities::userCanManageOwn($userId)) {
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

        if (StaffCapabilities::canManageOps($userId)) {
            return true;
        }

        return new \WP_Error(
            'sutore_marketplace_not_owner',
            __('This product does not belong to you.', 'sutore-marketplace')
        );
    }

    /**
     * Staff may create listings for another merchant. Non-staff always own as themselves.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     */
    public static function resolveCreateMerchantId(int $actorId, array $input = [], array $options = []): int|\WP_Error
    {
        $requested = (int) ($options['merchant_id'] ?? $input['merchant_id'] ?? 0);
        if ($requested <= 0 || $requested === $actorId) {
            return $actorId;
        }

        if (!StaffCapabilities::canManageOps($actorId)) {
            return new \WP_Error(
                'sutore_marketplace_forbidden',
                __('You do not have permission for this action.', 'sutore-marketplace'),
                ['status' => 403]
            );
        }

        return self::assertValidMerchantTarget($requested);
    }

    public static function assertValidMerchantTarget(int $merchantId): int|\WP_Error
    {
        if ($merchantId <= 0) {
            return new \WP_Error(
                'sutore_marketplace_merchant_required',
                __('Select a seller.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $user = get_userdata($merchantId);
        if (!$user) {
            return new \WP_Error(
                'sutore_marketplace_merchant_missing',
                __('Seller not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $roles = (array) $user->roles;
        if (!in_array('merchant', $roles, true)) {
            return new \WP_Error(
                'sutore_marketplace_invalid_merchant',
                __('Select a valid seller.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        return $merchantId;
    }

    /**
     * Imported products change delivery time, force a national ID at checkout and skip
     * confirm / cargo deadlines, so only staff may set the flag.
     */
    public static function canFlagImported(?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();

        return StaffCapabilities::canManageOps($userId);
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

    public static function maxListingDurationDays(?int $userId = null): int
    {
        $userId = $userId ?: get_current_user_id();
        $status = MerchantLevels::statusForUser($userId);
        $caps = Settings::listingDurationMaxByLevel();
        $max = (int) ($caps[$status] ?? $caps[MerchantLevels::NORMAL] ?? 45);

        return max(1, $max);
    }

    /** @return list<int> */
    public static function allowedListingDurations(?int $userId = null): array
    {
        $max = self::maxListingDurationDays($userId);

        return array_values(array_filter(
            ListingDuration::ALLOWED_DAYS,
            static fn (int $days): bool => $days <= $max
        ));
    }

    public static function assertValidDuration(int $days, ?int $merchantId = null): int|\WP_Error
    {
        if (!ListingDuration::isAllowed($days)) {
            return new \WP_Error(
                'sutore_marketplace_invalid_duration',
                __('Select a valid sale duration.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $allowed = self::allowedListingDurations($merchantId);
        if (!in_array($days, $allowed, true)) {
            return new \WP_Error(
                'sutore_marketplace_duration_not_allowed',
                __('This sale duration is not available for your seller level.', 'sutore-marketplace'),
                ['status' => 403]
            );
        }

        return $days;
    }

    /** Onaylı / Super sellers see competing prices in the size queue. */
    public static function canViewCompetingPrices(?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();
        if (StaffCapabilities::canManageOps($userId)) {
            return true;
        }

        $status = MerchantLevels::statusForUser($userId);

        return in_array($status, [MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true);
    }

    /** Pre-order board: Confirmed/Super sellers with score at or above threshold. */
    public static function canAccessSourcingBoard(?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();
        if (StaffCapabilities::canManageOps($userId)) {
            return true;
        }

        $status = MerchantLevels::statusForUser($userId);
        if (!in_array($status, [MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true)) {
            return false;
        }

        $scores = new BehaviorScoreService();
        if (!$scores->sanctionsActive($userId)) {
            return true;
        }

        return $scores->scoreForMerchant($userId) >= BehaviorSettings::sourcingMinScore();
    }

    public static function assertCanAccessSourcingBoard(?int $userId = null): true|\WP_Error
    {
        $auth = self::assertCanManage($userId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        if (!self::canAccessSourcingBoard($userId)) {
            $status = MerchantLevels::statusForUser($userId ?: get_current_user_id());
            if (!in_array($status, [MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true)) {
                return new \WP_Error(
                    'sutore_marketplace_sourcing_level',
                    __('Pre-order board access requires Confirmed seller level.', 'sutore-marketplace'),
                    ['status' => 403]
                );
            }

            return new \WP_Error(
                'sutore_marketplace_sourcing_score',
                sprintf(
                    /* translators: %s: minimum score */
                    __('Pre-order board access requires a behavior score of at least %s.', 'sutore-marketplace'),
                    (string) BehaviorSettings::sourcingMinScore()
                ),
                ['status' => 403]
            );
        }

        return true;
    }

    /** Super sellers see pre-orders immediately; Confirmed sellers after the early-access window. */
    public static function canViewPreOrderListing(string $listingCreatedAt, ?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();
        if (StaffCapabilities::canManageOps($userId)) {
            return true;
        }

        $status = MerchantLevels::statusForUser($userId);
        if ($status === MerchantLevels::PREMIUM) {
            return true;
        }

        if ($status !== MerchantLevels::VERIFIED) {
            return false;
        }

        $hours = \SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings::sourcingEarlyAccessHours();
        if ($hours <= 0) {
            return true;
        }

        $tz = wp_timezone();
        $created = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $listingCreatedAt, $tz);
        if (!$created) {
            return true;
        }

        $visibleFrom = $created->modify('+' . $hours . ' hours');

        return $visibleFrom <= new \DateTimeImmutable('now', $tz);
    }

    public static function canRequestCatalogProduct(?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();
        if (is_wp_error(self::assertCanManage($userId))) {
            return false;
        }

        $restrictions = new RestrictionsRepository();
        if ($restrictions->hasActive($userId, 'listing_create_ban')
            || $restrictions->hasActive($userId, 'disabled_account')) {
            return false;
        }

        return Settings::merchantCanRequestCatalogProduct($userId);
    }

    public static function assertCanRequestCatalogProduct(?int $userId = null): true|\WP_Error
    {
        $userId = $userId ?: get_current_user_id();
        $auth = self::assertCanManage($userId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $restrictions = new RestrictionsRepository();
        if ($restrictions->hasActive($userId, 'listing_create_ban')
            || $restrictions->hasActive($userId, 'disabled_account')) {
            return new \WP_Error(
                'sutore_marketplace_restricted',
                __('Product creation is restricted.', 'sutore-marketplace'),
                ['status' => 403]
            );
        }

        if (!Settings::merchantCanRequestCatalogProduct($userId)) {
            return new \WP_Error(
                'sutore_catalog_request_level',
                __('Catalog product requests require Confirmed seller level.', 'sutore-marketplace'),
                ['status' => 403]
            );
        }

        return true;
    }
}
