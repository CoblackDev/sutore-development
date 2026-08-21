<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Services;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Otp\Domain\OtpPurpose;
use SutoreMarketplace\Modules\Otp\Settings\OtpSettings;
use SutoreMarketplace\Shared\Sms\Settings\SmsSimulationSettings;

final class OtpService
{
    private const TRANSIENT_SESSION = 'sutore_otp_session_';
    private const TRANSIENT_REQUEST = 'sutore_otp_request_';
    private const TRANSIENT_ATTEMPTS = 'sutore_otp_attempts_';
    private const TRANSIENT_PHONE = 'sutore_otp_phone_';

    /**
     * @param array<string, mixed> $payload
     * @return array{expires_at:int,ttl_seconds:int,masked_phone:string,purpose:string}|\WP_Error
     */
    public function request(int $userId, string $purpose, array $payload = []): array|\WP_Error
    {
        if (!OtpSettings::isEnabled()) {
            return new \WP_Error('sutore_otp_disabled', __('SMS verification is disabled.', 'sutore-marketplace'));
        }

        if (!OtpPurpose::isValid($purpose)) {
            return new \WP_Error('sutore_otp_invalid_purpose', __('Invalid verification purpose.', 'sutore-marketplace'));
        }

        $requestLimit = $this->assertRequestNotRateLimited($userId);
        if ($requestLimit instanceof \WP_Error) {
            return $requestLimit;
        }

        $preflight = (new OtpPreflightValidator())->validate($userId, $purpose, $payload);
        if ($preflight instanceof \WP_Error) {
            return $preflight;
        }

        $phone = $this->resolvePhoneForPurpose($userId, $purpose, $payload);
        if ($phone === '') {
            return new \WP_Error('sutore_otp_phone_missing', __('A valid phone number is required for SMS verification.', 'sutore-marketplace'));
        }

        $phoneLimit = $this->assertPhoneNotRateLimited($phone);
        if ($phoneLimit instanceof \WP_Error) {
            return $phoneLimit;
        }

        $code = $this->generateCode();
        $ttl = OtpSettings::ttlSeconds();
        $expiresAt = time() + $ttl;

        $this->storeSession($userId, [
            'code_hash' => $this->hashCode($code),
            'expires_at' => $expiresAt,
            'purpose' => $purpose,
            'phone' => $phone,
            'payload_fp' => self::payloadFingerprint($purpose, $payload),
        ], $ttl);
        $this->clearAttempts($userId);
        $this->bumpRequestCount($userId);
        $this->bumpPhoneCount($phone);

        $sent = OtpSmsGateway::send($phone, OtpSettings::smsMessage($code));
        if (!$sent) {
            $this->clearSession($userId, $purpose);

            return new \WP_Error(
                'sutore_otp_sms_failed',
                __('Verification code could not be sent. Please try again.', 'sutore-marketplace'),
                ['status' => 502]
            );
        }

        $response = [
            'purpose' => $purpose,
            'expires_at' => $expiresAt,
            'ttl_seconds' => $ttl,
            'masked_phone' => OtpPhoneResolver::mask($phone),
        ];

        if (SmsSimulationSettings::isEnabled()) {
            $response['simulation'] = true;
            if (
                defined('WP_DEBUG')
                && WP_DEBUG
                && wp_get_environment_type() !== 'production'
            ) {
                $response['debug_code'] = $code;
            }
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload Must match the payload used when the code was requested.
     */
    public function verifyAndConsume(int $userId, string $purpose, string $code, array $payload = []): true|\WP_Error
    {
        if (!OtpSettings::isEnabled()) {
            return new \WP_Error(
                'sutore_otp_disabled',
                __('SMS verification is required for this action.', 'sutore-marketplace')
            );
        }

        $code = trim($code);
        if ($code === '') {
            return new \WP_Error('sutore_otp_required', __('Enter the verification code sent to your phone.', 'sutore-marketplace'));
        }

        $rateLimit = $this->assertNotRateLimited($userId);
        if ($rateLimit instanceof \WP_Error) {
            return $rateLimit;
        }

        $session = $this->getSession($userId, $purpose);
        if ($session === null) {
            return $this->failAttempt($userId, new \WP_Error(
                'sutore_otp_purpose_mismatch',
                __('Verification session expired. Please request a new code.', 'sutore-marketplace')
            ));
        }

        if (($session['purpose'] ?? '') !== $purpose) {
            return $this->failAttempt($userId, new \WP_Error(
                'sutore_otp_purpose_mismatch',
                __('Verification session expired. Please request a new code.', 'sutore-marketplace')
            ));
        }

        $expectedFp = (string) ($session['payload_fp'] ?? '');
        if ($expectedFp !== '' && !hash_equals($expectedFp, self::payloadFingerprint($purpose, $payload))) {
            return $this->failAttempt($userId, new \WP_Error(
                'sutore_otp_payload_mismatch',
                __('Verification session expired. Please request a new code.', 'sutore-marketplace')
            ));
        }

        $expiresAt = (int) ($session['expires_at'] ?? 0);
        if ($expiresAt <= 0 || time() >= $expiresAt) {
            return $this->failAttempt($userId, new \WP_Error(
                'sutore_otp_expired',
                __('The verification code has expired. Please request a new code.', 'sutore-marketplace')
            ));
        }

        $hash = (string) ($session['code_hash'] ?? '');
        if ($hash === '' || !hash_equals($hash, $this->hashCode($code))) {
            return $this->failAttempt($userId, new \WP_Error(
                'sutore_otp_invalid',
                __('Incorrect verification code. Please try again.', 'sutore-marketplace')
            ));
        }

        $this->clearSession($userId, $purpose);

        return true;
    }

    /**
     * Canonical fingerprint of the sensitive fields covered by this OTP challenge.
     *
     * @param array<string, mixed> $payload
     */
    public static function payloadFingerprint(string $purpose, array $payload): string
    {
        $parts = match ($purpose) {
            OtpPurpose::ACCOUNT_DETAILS => [
                sanitize_text_field((string) ($payload['user_name'] ?? $payload['first_name'] ?? '')),
                sanitize_text_field((string) ($payload['user_lastname'] ?? $payload['last_name'] ?? '')),
                strtolower(sanitize_email((string) ($payload['user_email'] ?? $payload['email'] ?? ''))),
                OtpPhoneResolver::normalize((string) ($payload['user_phone'] ?? $payload['phone'] ?? '')),
                !empty($payload['marketing_consent']) ? '1' : '0',
            ],
            OtpPurpose::ACCOUNT_DETAILS_NEW_PHONE => [
                OtpPhoneResolver::normalize((string) ($payload['user_phone'] ?? $payload['phone'] ?? '')),
            ],
            OtpPurpose::MERCHANT_PROFILE => [
                sanitize_text_field((string) ($payload['account_name'] ?? $payload['first_name'] ?? '')),
                sanitize_text_field((string) ($payload['account_lastname'] ?? $payload['last_name'] ?? '')),
                strtolower(sanitize_email((string) ($payload['account_email'] ?? $payload['email'] ?? ''))),
                OtpPhoneResolver::normalize((string) ($payload['account_phone'] ?? $payload['phone'] ?? '')),
                preg_replace('/\D/', '', (string) ($payload['account_tckno'] ?? $payload['tckno'] ?? '')) ?? '',
                sanitize_text_field((string) ($payload['account_iban'] ?? $payload['iban'] ?? '')),
                sanitize_text_field((string) ($payload['account_birth_year'] ?? $payload['birth_year'] ?? '')),
                sanitize_text_field((string) ($payload['account_city'] ?? $payload['city'] ?? '')),
                sanitize_text_field((string) ($payload['account_state'] ?? $payload['state'] ?? '')),
            ],
            OtpPurpose::PASSWORD_CHANGE => [
                'password_change',
                hash('sha256', (string) ($payload['new_password'] ?? '')),
            ],
            OtpPurpose::ACCOUNT_DELETE => ['account_delete'],
            default => [$purpose],
        };

        return hash_hmac('sha256', $purpose . '|' . implode('|', $parts), wp_salt('auth'));
    }

    /** @param array<string, mixed> $payload */
    private function resolvePhoneForPurpose(int $userId, string $purpose, array $payload): string
    {
        if ($purpose === OtpPurpose::MERCHANT_PROFILE) {
            $validated = (new \SutoreMarketplace\Modules\Merchants\Services\MerchantProfileService())->validateInput($userId, $payload);
            if ($validated instanceof \WP_Error) {
                return '';
            }

            return OtpPhoneResolver::forUser(
                $userId,
                (string) ($validated['profile'][MerchantMeta::ACCOUNT_PHONE] ?? '')
            );
        }

        if ($purpose === OtpPurpose::ACCOUNT_DETAILS) {
            // Identity challenge always goes to the registered number — never the payload candidate.
            return OtpPhoneResolver::forUser($userId);
        }

        if ($purpose === OtpPurpose::ACCOUNT_DETAILS_NEW_PHONE) {
            return OtpPhoneResolver::normalize((string) ($payload['user_phone'] ?? $payload['phone'] ?? ''));
        }

        return OtpPhoneResolver::forUser($userId);
    }

    private function generateCode(): string
    {
        $length = OtpSettings::codeLength();
        $min = (int) str_pad('1', $length, '0');
        $max = (int) str_pad('', $length, '9');

        return (string) random_int($min, $max);
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, wp_salt('auth'));
    }

    private function assertRequestNotRateLimited(int $userId): true|\WP_Error
    {
        $state = get_transient(self::TRANSIENT_REQUEST . $userId);
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        $maxRequests = max(1, (int) ceil(OtpSettings::maxAttempts() * 2));

        if ($count >= $maxRequests) {
            return new \WP_Error(
                'sutore_otp_request_rate_limited',
                __('Too many verification requests. Please try again later.', 'sutore-marketplace'),
                ['status' => 429]
            );
        }

        return true;
    }

    private function bumpRequestCount(int $userId): void
    {
        $key = self::TRANSIENT_REQUEST . $userId;
        $state = get_transient($key);
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        $window = OtpSettings::rateLimitWindowSeconds();
        set_transient($key, ['count' => $count + 1, 'started_at' => time()], $window);
    }

    private function assertPhoneNotRateLimited(string $phone): true|\WP_Error
    {
        $state = get_transient($this->phoneRateKey($phone));
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        $maxRequests = max(1, (int) ceil(OtpSettings::maxAttempts() * 2));

        if ($count >= $maxRequests) {
            return new \WP_Error(
                'sutore_otp_request_rate_limited',
                __('Too many verification requests. Please try again later.', 'sutore-marketplace'),
                ['status' => 429]
            );
        }

        return true;
    }

    private function bumpPhoneCount(string $phone): void
    {
        $key = $this->phoneRateKey($phone);
        $state = get_transient($key);
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        set_transient($key, ['count' => $count + 1, 'started_at' => time()], OtpSettings::rateLimitWindowSeconds());
    }

    private function phoneRateKey(string $phone): string
    {
        return self::TRANSIENT_PHONE . hash('sha256', $phone);
    }

    private function assertNotRateLimited(int $userId): true|\WP_Error
    {
        $state = get_transient(self::TRANSIENT_ATTEMPTS . $userId);
        $attempts = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        $lastAt = is_array($state) ? (int) ($state['at'] ?? 0) : 0;

        if ($attempts >= OtpSettings::maxAttempts()) {
            if ($lastAt > 0 && time() - $lastAt < OtpSettings::rateLimitWindowSeconds()) {
                return new \WP_Error(
                    'sutore_otp_rate_limited',
                    __('Too many failed attempts. Please try again in 15 minutes.', 'sutore-marketplace'),
                    ['status' => 429]
                );
            }

            $this->clearAttempts($userId);
        }

        return true;
    }

    private function failAttempt(int $userId, \WP_Error $error): \WP_Error
    {
        $key = self::TRANSIENT_ATTEMPTS . $userId;
        $state = get_transient($key);
        $attempts = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        set_transient(
            $key,
            ['count' => $attempts + 1, 'at' => time()],
            OtpSettings::rateLimitWindowSeconds()
        );

        return $error;
    }

    private function sessionKey(int $userId, string $purpose = ''): string
    {
        $suffix = $purpose !== '' ? '_' . sanitize_key($purpose) : '';

        return self::TRANSIENT_SESSION . $userId . $suffix;
    }

    /** @param array{code_hash:string,expires_at:int,purpose:string,phone:string} $session */
    private function storeSession(int $userId, array $session, int $ttl): void
    {
        $purpose = (string) ($session['purpose'] ?? '');
        set_transient($this->sessionKey($userId, $purpose), $session, $ttl);
    }

    /** @return array{code_hash?:string,expires_at?:int,purpose?:string,phone?:string}|null */
    private function getSession(int $userId, string $purpose = ''): ?array
    {
        $session = get_transient($this->sessionKey($userId, $purpose));
        if (is_array($session) && ($session['code_hash'] ?? '') !== '') {
            return $session;
        }

        return null;
    }

    private function clearSession(int $userId, string $purpose = ''): void
    {
        delete_transient($this->sessionKey($userId, $purpose));
        $this->clearAttempts($userId);
    }

    private function clearAttempts(int $userId): void
    {
        delete_transient(self::TRANSIENT_ATTEMPTS . $userId);
    }
}
