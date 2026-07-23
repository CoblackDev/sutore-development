<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Services;

use SutoreMarketplace\Modules\Merchants\Services\MerchantProfileService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Otp\Domain\OtpPurpose;

final class OtpPreflightValidator
{
    /**
     * @param array<string, mixed> $payload
     */
    public function validate(int $userId, string $purpose, array $payload): true|\WP_Error
    {
        if (!OtpPurpose::isValid($purpose)) {
            return new \WP_Error('sutore_otp_invalid_purpose', __('Invalid verification purpose.', 'sutore-marketplace'));
        }

        return match ($purpose) {
            OtpPurpose::MERCHANT_PROFILE => $this->merchantProfile($userId, $payload),
            OtpPurpose::ACCOUNT_DETAILS => $this->accountDetails($userId, $payload),
            OtpPurpose::PASSWORD_CHANGE => $this->passwordChange($userId, $payload),
            OtpPurpose::ACCOUNT_DELETE => $this->accountDelete($userId, $payload),
            default => new \WP_Error('sutore_otp_invalid_purpose', __('Invalid verification purpose.', 'sutore-marketplace')),
        };
    }

    /** @param array<string, mixed> $payload */
    private function merchantProfile(int $userId, array $payload): true|\WP_Error
    {
        $result = (new MerchantProfileService())->validateInput($userId, $payload);
        if ($result instanceof \WP_Error) {
            return $result;
        }

        $phone = OtpPhoneResolver::forUser($userId, (string) ($result['profile'][MerchantMeta::ACCOUNT_PHONE] ?? ''));
        if ($phone === '' || strlen($phone) < 10) {
            return new \WP_Error('sutore_otp_phone_missing', __('A valid phone number is required for SMS verification.', 'sutore-marketplace'));
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function accountDetails(int $userId, array $payload): true|\WP_Error
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_Error('sutore_user_missing', __('User not found.', 'sutore-marketplace'));
        }

        $password = (string) ($payload['current_password'] ?? '');
        if ($password === '' || !wp_check_password($password, $user->user_pass, $userId)) {
            return new \WP_Error(
                'sutore_password_invalid',
                __('Your current password is incorrect.', 'sutore-marketplace')
            );
        }

        $firstName = sanitize_text_field((string) ($payload['user_name'] ?? $payload['first_name'] ?? ''));
        $lastName = sanitize_text_field((string) ($payload['user_lastname'] ?? $payload['last_name'] ?? ''));
        $email = sanitize_email((string) ($payload['user_email'] ?? $payload['email'] ?? ''));
        $phone = OtpPhoneResolver::normalize((string) ($payload['user_phone'] ?? $payload['phone'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            return new \WP_Error('sutore_name_required', __('First and last name are required.', 'sutore-marketplace'));
        }

        if ($email === '' || !is_email($email)) {
            return new \WP_Error('sutore_email_invalid', __('Enter a valid email address.', 'sutore-marketplace'));
        }

        if ($email !== $user->user_email && email_exists($email)) {
            return new \WP_Error('sutore_email_in_use', __('This email address is already registered.', 'sutore-marketplace'));
        }

        if (strlen($phone) < 10 || strlen($phone) > 11) {
            return new \WP_Error('sutore_phone_invalid', __('Enter a valid phone number.', 'sutore-marketplace'));
        }

        $currentPhone = OtpPhoneResolver::forUser($userId);
        if ($phone !== $currentPhone && !OtpPhoneResolver::isAvailable($phone, $userId)) {
            return new \WP_Error('sutore_phone_in_use', __('This phone number is already registered.', 'sutore-marketplace'));
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function passwordChange(int $userId, array $payload): true|\WP_Error
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_Error('sutore_user_missing', __('User not found.', 'sutore-marketplace'));
        }

        $current = (string) ($payload['current_password'] ?? '');
        $next = (string) ($payload['new_password'] ?? '');
        $repeat = (string) ($payload['new_password_repeat'] ?? '');

        if ($current === '' || !wp_check_password($current, $user->user_pass, $userId)) {
            return new \WP_Error(
                'sutore_password_invalid',
                __('Your current password is incorrect.', 'sutore-marketplace')
            );
        }

        if ($next === '' || $repeat === '') {
            return new \WP_Error('sutore_password_required', __('Enter and confirm your new password.', 'sutore-marketplace'));
        }

        if ($next !== $repeat) {
            return new \WP_Error('sutore_password_mismatch', __('The passwords you entered do not match.', 'sutore-marketplace'));
        }

        if (!self::isStrongPassword($next)) {
            return new \WP_Error(
                'sutore_password_weak',
                __('Your password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.', 'sutore-marketplace')
            );
        }

        $phone = OtpPhoneResolver::forUser($userId);
        if ($phone === '') {
            return new \WP_Error('sutore_otp_phone_missing', __('A valid phone number is required for SMS verification.', 'sutore-marketplace'));
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function accountDelete(int $userId, array $payload): true|\WP_Error
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_Error('sutore_user_missing', __('User not found.', 'sutore-marketplace'));
        }

        $current = (string) ($payload['current_password'] ?? '');
        if ($current === '' || !wp_check_password($current, $user->user_pass, $userId)) {
            return new \WP_Error(
                'sutore_password_invalid',
                __('Your current password is incorrect.', 'sutore-marketplace')
            );
        }

        $phone = OtpPhoneResolver::forUser($userId);
        if ($phone === '') {
            return new \WP_Error('sutore_otp_phone_missing', __('A valid phone number is required for SMS verification.', 'sutore-marketplace'));
        }

        return true;
    }

    public static function isStrongPassword(string $password): bool
    {
        if (strlen($password) < 8) {
            return false;
        }

        return (bool) preg_match('/[A-Z]/', $password)
            && (bool) preg_match('/[a-z]/', $password)
            && (bool) preg_match('/[0-9]/', $password)
            && (bool) preg_match('/[^\w]/', $password);
    }
}
