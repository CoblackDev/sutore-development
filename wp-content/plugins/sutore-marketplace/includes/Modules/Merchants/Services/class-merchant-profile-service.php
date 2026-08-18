<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Repositories\MerchantProfileRepository;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Otp\Domain\OtpPurpose;
use SutoreMarketplace\Modules\Otp\Services\OtpService;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Settings\Settings;
use SutoreMarketplace\Shared\Sms\IysConsentService;

final class MerchantProfileService
{
    public function __construct(
        private readonly MerchantProfileChangeLogger $changeLogger = new MerchantProfileChangeLogger(),
        private readonly MerchantProfileRepository $profiles = new MerchantProfileRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{profile: array<string, string>}|\WP_Error
     */
    public function validateInput(int $userId, array $input, bool $requirePassword = true): array|\WP_Error
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_Error('sutore_user_missing', __('User not found.', 'sutore-marketplace'));
        }

        if ($requirePassword) {
            $password = (string) ($input['current_password'] ?? '');
            if ($password === '' || !wp_check_password($password, $user->user_pass, $userId)) {
                return new \WP_Error(
                    'sutore_password_invalid',
                    __('Your current password is incorrect.', 'sutore-marketplace')
                );
            }
        }

        $profile = $this->sanitizeInput($input);
        $validationError = $this->validateProfile($profile);
        if ($validationError instanceof \WP_Error) {
            return $validationError;
        }

        return ['profile' => $profile];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,reload?:bool,verified?:bool}|\WP_Error
     */
    public function save(int $userId, array $input): array|\WP_Error
    {
        $validated = $this->validateInput($userId, $input);
        if ($validated instanceof \WP_Error) {
            return $validated;
        }

        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_Error('sutore_user_missing', __('User not found.', 'sutore-marketplace'));
        }

        $wasMerchant = in_array('merchant', (array) $user->roles, true);
        $inviteCode = strtoupper(trim((string) ($input['invite_code'] ?? '')));
        $referral = new ReferralService();
        if (!$wasMerchant && $inviteCode !== '') {
            $inviteCheck = $referral->validateInvite($userId, $inviteCode);
            if ($inviteCheck instanceof \WP_Error) {
                return $inviteCheck;
            }
        }

        $otp = (new OtpService())->verifyAndConsume(
            $userId,
            OtpPurpose::MERCHANT_PROFILE,
            (string) ($input['otp_code'] ?? '')
        );
        if ($otp instanceof \WP_Error) {
            return $otp;
        }

        $profile = $validated['profile'];
        $before = MerchantMeta::readProfile($userId);
        $wasNew = $this->profiles->find($userId) === null;
        $oldStatus = MerchantLevels::statusForUser($userId);

        $needsNviCheck = $this->needsNviVerification($userId, $profile);
        $tcMode = Settings::tcVerificationMode();
        $markVerified = false;

        if ($needsNviCheck) {
            if ($tcMode === 'manual') {
                $markVerified = false;
            } else {
                $verified = TcIdentityVerifier::verify(
                    $profile[MerchantMeta::ACCOUNT_TCKNO],
                    $profile[MerchantMeta::ACCOUNT_NAME],
                    $profile[MerchantMeta::ACCOUNT_LASTNAME],
                    (int) $profile[MerchantMeta::ACCOUNT_BIRTH_YEAR]
                );
                if ($verified instanceof \WP_Error) {
                    return $verified;
                }
                $markVerified = true;
                $verifyMethod = $tcMode;
            }
        } elseif (!MerchantMeta::isTcVerified($userId)) {
            return new \WP_Error(
                'sutore_tc_not_verified',
                __('Registration cannot be completed before TC identity verification.', 'sutore-marketplace')
            );
        }

        $extras = [];
            if ($markVerified) {
                $extras['tckno_verified'] = 1;
                $extras['tckno_verified_at'] = time();
                if (isset($verifyMethod)) {
                    $extras['tckno_verify_method'] = $verifyMethod;
                }
            }

        MerchantMeta::writeProfile($userId, $profile, $extras);
        $this->changeLogger->logProfileWrite(
            $userId,
            $before,
            $profile,
            $extras,
            [
                'actor_role' => 'merchant',
                'old_merchant_status' => $oldStatus,
            ],
            $wasNew
        );
        $this->syncIysPhone($userId, $user, $before, $profile);

        if ($markVerified) {
            (new BehaviorLevelService())->evaluateConfirmed($userId);
        }

        $reload = false;
        if (!$wasMerchant) {
            $granted = $this->grantMerchantRole($user);
            if ($granted instanceof \WP_Error) {
                return $granted;
            }
            if ($granted) {
                $this->changeLogger->logRoleGranted($userId, ['actor_role' => 'merchant']);
                if ($inviteCode !== '') {
                    $accepted = $referral->acceptInvite($userId, $inviteCode);
                    if ($accepted instanceof \WP_Error) {
                        return $accepted;
                    }
                }
                if (!$markVerified) {
                    MerchantLevels::setStatus($userId, MerchantLevels::NORMAL);
                }
                $reload = true;
                $message = $markVerified
                    ? __('Your merchant information has been saved. Your TC identity was verified.', 'sutore-marketplace')
                    : __('Your merchant information has been saved. TC verification is pending admin approval.', 'sutore-marketplace');
            } elseif ($markVerified) {
                $message = __('Your merchant information has been updated. Your TC identity was verified.', 'sutore-marketplace');
            } elseif ($needsNviCheck && $tcMode === 'manual') {
                $message = __('Your merchant information has been updated. TC verification is pending admin approval.', 'sutore-marketplace');
            } else {
                $message = __('Your merchant information has been updated.', 'sutore-marketplace');
            }
        } elseif ($markVerified) {
            $message = __('Your merchant information has been updated. Your TC identity was verified.', 'sutore-marketplace');
        } elseif ($needsNviCheck && $tcMode === 'manual') {
            $message = __('Your merchant information has been updated. TC verification is pending admin approval.', 'sutore-marketplace');
        } else {
            $message = __('Your merchant information has been updated.', 'sutore-marketplace');
        }

        return [
            'success' => true,
            'message' => $message,
            'reload' => $reload,
            'verified' => MerchantMeta::isTcVerified($userId),
        ];
    }

    /**
     * Staff update of a merchant profile (no password / OTP).
     *
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string}|\WP_Error
     */
    public function saveByStaff(int $merchantId, array $input): array|\WP_Error
    {
        $user = get_userdata($merchantId);
        if (!$user || !in_array('merchant', (array) $user->roles, true)) {
            return new \WP_Error('sutore_merchant_not_found', __('Seller not found.', 'sutore-marketplace'), ['status' => 404]);
        }

        $validated = $this->validateInput($merchantId, $input, false);
        if ($validated instanceof \WP_Error) {
            return $validated;
        }

        $profile = $validated['profile'];
        $before = MerchantMeta::readProfile($merchantId);
        $wasNew = $this->profiles->find($merchantId) === null;
        $oldStatus = MerchantLevels::statusForUser($merchantId);
        $wasVerified = MerchantMeta::isTcVerified($merchantId);

        $identityChanged = $this->identityFieldsChanged($before, $profile);
        $extras = [];
        if ($identityChanged && $wasVerified) {
            $extras['tckno_verified'] = 0;
            $extras['tckno_verified_at'] = 0;
            $extras['tckno_verify_method'] = '';
        }

        if (!empty($input['mark_tc_verified'])) {
            $extras['tckno_verified'] = 1;
            $extras['tckno_verified_at'] = time();
            $extras['tckno_verify_method'] = 'manual';
        }

        MerchantMeta::writeProfile($merchantId, $profile, $extras);
        $this->changeLogger->logProfileWrite(
            $merchantId,
            $before,
            $profile,
            $extras,
            [
                'actor_role' => 'staff',
                'old_merchant_status' => $oldStatus,
            ],
            $wasNew
        );
        $this->syncIysPhone($merchantId, $user, $before, $profile);

        return [
            'success' => true,
            'message' => __('Seller profile updated.', 'sutore-marketplace'),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function sanitizeInput(array $input): array
    {
        return [
            MerchantMeta::ACCOUNT_NAME => sanitize_text_field((string) ($input['account_name'] ?? '')),
            MerchantMeta::ACCOUNT_LASTNAME => sanitize_text_field((string) ($input['account_lastname'] ?? '')),
            MerchantMeta::ACCOUNT_IBAN => strtoupper(preg_replace('/\s+/', '', sanitize_text_field((string) ($input['account_iban'] ?? ''))) ?? ''),
            MerchantMeta::ACCOUNT_TCKNO => preg_replace('/\D/', '', sanitize_text_field((string) ($input['account_tckno'] ?? ''))) ?? '',
            MerchantMeta::ACCOUNT_BIRTH_YEAR => (string) max(0, (int) ($input['account_birth_year'] ?? 0)),
            MerchantMeta::ACCOUNT_EMAIL => sanitize_email((string) ($input['account_email'] ?? '')),
            MerchantMeta::ACCOUNT_PHONE => preg_replace('/\D/', '', sanitize_text_field((string) ($input['account_phone'] ?? ''))) ?? '',
            MerchantMeta::ACCOUNT_CITY => sanitize_text_field((string) ($input['account_city'] ?? '')),
            MerchantMeta::ACCOUNT_STATE => sanitize_text_field((string) ($input['account_state'] ?? '')),
        ];
    }

    /** @param array<string, string> $profile */
    private function validateProfile(array $profile): ?\WP_Error
    {
        if ($profile[MerchantMeta::ACCOUNT_NAME] === '' || $profile[MerchantMeta::ACCOUNT_LASTNAME] === '') {
            return new \WP_Error('sutore_name_required', __('First and last name are required.', 'sutore-marketplace'));
        }

        if (!preg_match('/^[a-zA-ZığüşöçİĞÜŞÖÇ ]+$/u', $profile[MerchantMeta::ACCOUNT_NAME])
            || !preg_match('/^[a-zA-ZığüşöçİĞÜŞÖÇ ]+$/u', $profile[MerchantMeta::ACCOUNT_LASTNAME])) {
            return new \WP_Error('sutore_name_format', __('First and last name must contain only letters.', 'sutore-marketplace'));
        }

        if ($profile[MerchantMeta::ACCOUNT_IBAN] === '' || !IbanValidator::isValid($profile[MerchantMeta::ACCOUNT_IBAN])) {
            return new \WP_Error('sutore_iban_invalid', __('Invalid IBAN.', 'sutore-marketplace'));
        }

        if (!TcValidator::isValid($profile[MerchantMeta::ACCOUNT_TCKNO])) {
            return new \WP_Error('sutore_tc_invalid', __('Invalid TC identity number.', 'sutore-marketplace'));
        }

        $birthYear = (int) $profile[MerchantMeta::ACCOUNT_BIRTH_YEAR];
        if ($birthYear < 1900 || $birthYear > (int) gmdate('Y')) {
            return new \WP_Error('sutore_birth_year_invalid', __('Enter a valid year of birth.', 'sutore-marketplace'));
        }

        if ($profile[MerchantMeta::ACCOUNT_EMAIL] === '' || !is_email($profile[MerchantMeta::ACCOUNT_EMAIL])) {
            return new \WP_Error('sutore_email_invalid', __('Enter a valid email address.', 'sutore-marketplace'));
        }

        $phone = $profile[MerchantMeta::ACCOUNT_PHONE];
        if (strlen($phone) < 10 || strlen($phone) > 11) {
            return new \WP_Error('sutore_phone_invalid', __('Enter a valid phone number.', 'sutore-marketplace'));
        }

        if ($profile[MerchantMeta::ACCOUNT_CITY] === '' || $profile[MerchantMeta::ACCOUNT_STATE] === '') {
            return new \WP_Error('sutore_address_required', __('City and district are required.', 'sutore-marketplace'));
        }

        return null;
    }

    /**
     * Adds merchant role without replacing existing roles.
     * Privileged staff accounts cannot self-convert via this endpoint.
     *
     * @return bool|\WP_Error true when role was added, false when skipped (admin), error when forbidden
     */
    private function grantMerchantRole(\WP_User $user): bool|\WP_Error
    {
        $roles = (array) $user->roles;
        if (in_array('administrator', $roles, true)) {
            return false;
        }

        $blocked = array_intersect($roles, ['shop_manager', 'editor', 'author']);
        if ($blocked !== []) {
            return new \WP_Error(
                'sutore_merchant_role_forbidden',
                __('This account cannot be converted to a merchant via self-service registration.', 'sutore-marketplace'),
                ['status' => 403]
            );
        }

        $user->add_role('merchant');

        return true;
    }

    /** @param array<string, string> $profile */
    private function needsNviVerification(int $userId, array $profile): bool
    {
        if (!MerchantMeta::isTcVerified($userId)) {
            return true;
        }

        $stored = MerchantMeta::readProfile($userId);

        return $this->identityFieldsChanged($stored, $profile);
    }

    /** @param array<string, string> $before @param array<string, string> $after */
    private function identityFieldsChanged(array $before, array $after): bool
    {
        return ($before[MerchantMeta::ACCOUNT_TCKNO] ?? '') !== ($after[MerchantMeta::ACCOUNT_TCKNO] ?? '')
            || ($before[MerchantMeta::ACCOUNT_NAME] ?? '') !== ($after[MerchantMeta::ACCOUNT_NAME] ?? '')
            || ($before[MerchantMeta::ACCOUNT_LASTNAME] ?? '') !== ($after[MerchantMeta::ACCOUNT_LASTNAME] ?? '')
            || ($before[MerchantMeta::ACCOUNT_BIRTH_YEAR] ?? '') !== ($after[MerchantMeta::ACCOUNT_BIRTH_YEAR] ?? '');
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $profile
     */
    private function syncIysPhone(int $userId, \WP_User $user, array $before, array $profile): void
    {
        $consent = MerchantMeta::marketingConsent($userId);
        $oldEmail = (string) ($before[MerchantMeta::ACCOUNT_EMAIL] ?? '');
        $newEmail = (string) ($profile[MerchantMeta::ACCOUNT_EMAIL] ?? '');
        if ($oldEmail === '') {
            $oldEmail = (string) $user->user_email;
        }
        if ($newEmail === '') {
            $newEmail = (string) $user->user_email;
        }
        (new IysConsentService())->sync(
            $oldEmail,
            (string) ($before[MerchantMeta::ACCOUNT_PHONE] ?? ''),
            $newEmail,
            (string) ($profile[MerchantMeta::ACCOUNT_PHONE] ?? ''),
            $consent,
            $consent
        );
    }
}
