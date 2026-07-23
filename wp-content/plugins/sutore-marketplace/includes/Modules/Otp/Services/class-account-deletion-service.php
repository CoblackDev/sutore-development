<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Services;

use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;

final class AccountDeletionService
{
    public function assertCanDelete(int $userId): true|\WP_Error
    {
        $activeSales = (new FulfillmentRepository())->countActiveForMerchant($userId);
        if ($activeSales > 0) {
            return new \WP_Error(
                'sutore_account_delete_active_sales',
                __('You cannot delete your account while you have active sales or shipments. Please complete them or contact support.', 'sutore-marketplace')
            );
        }

        return true;
    }

    public function revokeMarketing(int $userId): void
    {
        $user = get_userdata($userId);
        if (!$user) {
            return;
        }

        MerchantMeta::setMarketingConsent($userId, false);

        /**
         * Fires when a user opts out of marketing (e.g. account deletion).
         *
         * @param int    $userId User ID.
         * @param string $email  Account email.
         * @param string $phone  Domestic phone digits.
         */
        do_action(
            'sutore_marketplace_marketing_opt_out',
            $userId,
            (string) $user->user_email,
            OtpPhoneResolver::forUser($userId)
        );
    }

    public function removeMerchantListings(int $userId): true|\WP_Error
    {
        return (new ListingService())->purgeAllForMerchant($userId);
    }

    public function deleteUser(int $userId): true|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        if (get_current_user_id() === $userId) {
            wp_logout();
        }

        if (!wp_delete_user($userId)) {
            return new \WP_Error(
                'sutore_account_delete_failed',
                __('Your account could not be deleted. Please contact support.', 'sutore-marketplace')
            );
        }

        return true;
    }
}
