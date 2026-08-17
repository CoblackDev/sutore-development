<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Services;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Otp\Domain\OtpPurpose;
use SutoreMarketplace\Modules\Otp\Settings\OtpSettings;

final class AccountSecurityService
{
    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,reload?:bool}|\WP_Error
     */
    public function updateDetails(int $userId, array $input): array|\WP_Error
    {
        $preflight = (new OtpPreflightValidator())->validate($userId, OtpPurpose::ACCOUNT_DETAILS, $input);
        if ($preflight instanceof \WP_Error) {
            return $preflight;
        }

        $otp = (new OtpService())->verifyAndConsume(
            $userId,
            OtpPurpose::ACCOUNT_DETAILS,
            (string) ($input['otp_code'] ?? '')
        );
        if ($otp instanceof \WP_Error) {
            return $otp;
        }

        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_Error('sutore_user_missing', __('User not found.', 'sutore-marketplace'));
        }

        $firstName = sanitize_text_field((string) ($input['user_name'] ?? $input['first_name'] ?? ''));
        $lastName = sanitize_text_field((string) ($input['user_lastname'] ?? $input['last_name'] ?? ''));
        $email = sanitize_email((string) ($input['user_email'] ?? $input['email'] ?? ''));
        $phone = OtpPhoneResolver::normalize((string) ($input['user_phone'] ?? $input['phone'] ?? ''));

        wp_update_user([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'user_email' => $email,
        ]);

        MerchantMeta::setPhone($userId, $phone);
        MerchantMeta::setMarketingConsent($userId, !empty($input['marketing_consent'] ?? false));

        return [
            'success' => true,
            'message' => __('Your account details have been updated.', 'sutore-marketplace'),
            'reload' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,reload?:bool}|\WP_Error
     */
    public function changePassword(int $userId, array $input): array|\WP_Error
    {
        $preflight = (new OtpPreflightValidator())->validate($userId, OtpPurpose::PASSWORD_CHANGE, $input);
        if ($preflight instanceof \WP_Error) {
            return $preflight;
        }

        $otp = (new OtpService())->verifyAndConsume(
            $userId,
            OtpPurpose::PASSWORD_CHANGE,
            (string) ($input['otp_code'] ?? '')
        );
        if ($otp instanceof \WP_Error) {
            return $otp;
        }

        wp_set_password((string) ($input['new_password'] ?? ''), $userId);

        return [
            'success' => true,
            'message' => __('Your password has been updated.', 'sutore-marketplace'),
            'reload' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,reload?:bool}|\WP_Error
     */
    public function deleteAccount(int $userId, array $input): array|\WP_Error
    {
        $preflight = (new OtpPreflightValidator())->validate($userId, OtpPurpose::ACCOUNT_DELETE, $input);
        if ($preflight instanceof \WP_Error) {
            return $preflight;
        }

        $otp = (new OtpService())->verifyAndConsume(
            $userId,
            OtpPurpose::ACCOUNT_DELETE,
            (string) ($input['otp_code'] ?? '')
        );
        if ($otp instanceof \WP_Error) {
            return $otp;
        }

        if (user_can($userId, 'administrator')) {
            return new \WP_Error('sutore_account_delete_forbidden', __('Administrator accounts cannot be deleted from this screen.', 'sutore-marketplace'));
        }

        $deletion = new AccountDeletionService();

        $canDelete = $deletion->assertCanDelete($userId);
        if ($canDelete instanceof \WP_Error) {
            return $canDelete;
        }

        $listingsRemoved = $deletion->removeMerchantListings($userId);
        if ($listingsRemoved instanceof \WP_Error) {
            return $listingsRemoved;
        }

        $deletion->revokeMarketing($userId);

        $deleted = $deletion->deleteUser($userId);
        if ($deleted instanceof \WP_Error) {
            return $deleted;
        }

        return [
            'success' => true,
            'message' => __('Your Sutore account has been deleted.', 'sutore-marketplace'),
            'reload' => true,
        ];
    }

    /** @return array{enabled:bool,ttl_seconds:int,ui_timer_seconds:int} */
    public static function publicConfig(): array
    {
        return [
            'enabled' => OtpSettings::isEnabled(),
            'ttl_seconds' => OtpSettings::ttlSeconds(),
            'ui_timer_seconds' => OtpSettings::uiTimerSeconds(),
        ];
    }
}
