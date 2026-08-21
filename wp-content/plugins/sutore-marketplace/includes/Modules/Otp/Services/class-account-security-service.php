<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Services;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Otp\Domain\OtpPurpose;
use SutoreMarketplace\Modules\Otp\Settings\OtpSettings;
use SutoreMarketplace\Shared\Sms\IysConsentService;

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

        if (OtpSettings::isEnabled()) {
            $otpService = new OtpService();
            $otp = $otpService->verifyAndConsume(
                $userId,
                OtpPurpose::ACCOUNT_DETAILS,
                (string) ($input['otp_code'] ?? ''),
                $input
            );
            if ($otp instanceof \WP_Error) {
                return $otp;
            }

            $user = get_userdata($userId);
            if (!$user) {
                return new \WP_Error('sutore_user_missing', __('User not found.', 'sutore-marketplace'));
            }

            $phone = OtpPhoneResolver::normalize((string) ($input['user_phone'] ?? $input['phone'] ?? ''));
            $oldPhone = OtpPhoneResolver::forUser($userId);
            if ($phone !== $oldPhone) {
                $newPhoneOtp = $otpService->verifyAndConsume(
                    $userId,
                    OtpPurpose::ACCOUNT_DETAILS_NEW_PHONE,
                    (string) ($input['otp_code_new_phone'] ?? ''),
                    $input
                );
                if ($newPhoneOtp instanceof \WP_Error) {
                    return $newPhoneOtp;
                }
            }
        }

        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_Error('sutore_user_missing', __('User not found.', 'sutore-marketplace'));
        }

        $firstName = sanitize_text_field((string) ($input['user_name'] ?? $input['first_name'] ?? ''));
        $lastName = sanitize_text_field((string) ($input['user_lastname'] ?? $input['last_name'] ?? ''));
        $email = sanitize_email((string) ($input['user_email'] ?? $input['email'] ?? ''));
        $phone = OtpPhoneResolver::normalize((string) ($input['user_phone'] ?? $input['phone'] ?? ''));
        $oldEmail = (string) $user->user_email;
        $oldPhone = OtpPhoneResolver::forUser($userId);
        $oldConsent = MerchantMeta::marketingConsent($userId);
        $newConsent = !empty($input['marketing_consent'] ?? false);

        wp_update_user([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'user_email' => $email,
        ]);

        MerchantMeta::setPhone($userId, $phone);
        MerchantMeta::setMarketingConsent($userId, $newConsent);
        (new IysConsentService())->sync($oldEmail, $oldPhone, $email, $phone, $oldConsent, $newConsent);

        if ($email !== $oldEmail && is_email($oldEmail)) {
            $this->notifyEmailChange($oldEmail, $email, $firstName);
        }

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

        if (OtpSettings::isEnabled()) {
            $otp = (new OtpService())->verifyAndConsume(
                $userId,
                OtpPurpose::PASSWORD_CHANGE,
                (string) ($input['otp_code'] ?? ''),
                $input
            );
            if ($otp instanceof \WP_Error) {
                return $otp;
            }
        }

        wp_set_password((string) ($input['new_password'] ?? ''), $userId);
        if (get_current_user_id() === $userId && function_exists('wp_destroy_other_sessions')) {
            wp_destroy_other_sessions();
        } else {
            \WP_Session_Tokens::get_instance($userId)->destroy_all();
        }

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

        if (OtpSettings::isEnabled()) {
            $otp = (new OtpService())->verifyAndConsume(
                $userId,
                OtpPurpose::ACCOUNT_DELETE,
                (string) ($input['otp_code'] ?? ''),
                $input
            );
            if ($otp instanceof \WP_Error) {
                return $otp;
            }
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

    private function notifyEmailChange(string $oldEmail, string $newEmail, string $firstName): void
    {
        $subject = __('Your Sutore email address was changed', 'sutore-marketplace');
        $greeting = $firstName !== ''
            ? sprintf(
                /* translators: %s: first name */
                __('Hi %s,', 'sutore-marketplace'),
                $firstName
            )
            : __('Hi,', 'sutore-marketplace');
        $body = $greeting . "\n\n"
            . sprintf(
                /* translators: %s: new email address */
                __('Your account email was changed to %s. If you did not request this change, contact Sutore support immediately.', 'sutore-marketplace'),
                $newEmail
            );

        wp_mail($oldEmail, $subject, $body);
    }
}
