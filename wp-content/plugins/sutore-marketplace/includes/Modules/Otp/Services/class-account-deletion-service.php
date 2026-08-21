<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Services;

use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;

final class AccountDeletionService
{
    /**
     * Payout lines and listing events keep merchant_id after the WP user is
     * removed so the accounting trail survives account deletion.
     */
    public function assertCanDelete(int $userId): true|\WP_Error
    {
        $blocks = (new FulfillmentRepository())->countAccountDeletionBlocks($userId);

        if ($blocks['in_progress'] > 0) {
            return new \WP_Error(
                'sutore_account_delete_active_sales',
                __('You cannot delete your account while you have active sales or shipments. Please complete them or contact support.', 'sutore-marketplace')
            );
        }

        if ($blocks['pre_order'] > 0) {
            return new \WP_Error(
                'sutore_account_delete_pre_order',
                __('You cannot delete your account while a pre-order is still waiting to be sourced. Please contact support.', 'sutore-marketplace')
            );
        }

        if ($blocks['unpaid_delivered'] > 0) {
            return new \WP_Error(
                'sutore_account_delete_unpaid_payout',
                __('You cannot delete your account until payouts for delivered sales have been paid. Please contact support.', 'sutore-marketplace')
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

        $this->purgeProfilePii($userId);

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

    /**
     * Clear merchant profile PII before WP user delete. Accounting rows keep merchant_id.
     */
    private function purgeProfilePii(int $userId): void
    {
        global $wpdb;
        $table = \SutoreMarketplace\Shared\Database\Schema::table('merchant_profiles');
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update(
            $table,
            [
                'account_name' => '',
                'account_lastname' => '',
                'account_iban' => '',
                'account_tckno' => '',
                'account_birth_year' => '',
                'account_email' => '',
                'account_phone' => '',
                'account_city' => '',
                'account_state' => '',
                'tckno_verified' => 0,
                'tckno_verified_at' => 0,
                'tckno_verify_method' => '',
                'marketing_consent' => 0,
                'updated_at' => current_time('mysql'),
            ],
            ['user_id' => $userId]
        );

        delete_user_meta($userId, \SutoreMarketplace\Shared\Hooks\CheckoutIdentityHooks::USER_META_TCKNO);
        delete_user_meta($userId, \SutoreMarketplace\Shared\Hooks\CheckoutIdentityHooks::USER_META_BIRTH_YEAR);
        delete_user_meta($userId, \SutoreMarketplace\Shared\Services\YouthDiscount::USER_META_FINGERPRINT);
        delete_user_meta($userId, \SutoreMarketplace\Shared\Services\YouthDiscount::USER_META_ELIGIBLE);
        delete_user_meta($userId, \SutoreMarketplace\Shared\Services\YouthDiscount::USER_META_VERIFIED_AT);
    }
}
