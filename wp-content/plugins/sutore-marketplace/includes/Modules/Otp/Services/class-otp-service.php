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
        ], $ttl);
        $this->clearAttempts($userId);
        $this->bumpRequestCount($userId);
        $this->bumpPhoneCount($phone);

        OtpSmsGateway::send($phone, OtpSettings::smsMessage($code));

        $response = [
            'purpose' => $purpose,
            'expires_at' => $expiresAt,
            'ttl_seconds' => $ttl,
            'masked_phone' => OtpPhoneResolver::mask($phone),
        ];

        if (SmsSimulationSettings::isEnabled() && defined('WP_DEBUG') && WP_DEBUG) {
            $response['simulation'] = true;
            $response['debug_code'] = $code;
        } elseif (SmsSimulationSettings::isEnabled()) {
            $response['simulation'] = true;
        }

        return $response;
    }

    public function verifyAndConsume(int $userId, string $purpose, string $code): true|\WP_Error
    {
        if (!OtpSettings::isEnabled()) {
            if (wp_get_environment_type() === 'production') {
                return new \WP_Error(
                    'sutore_otp_disabled',
                    __('SMS verification is required for this action.', 'sutore-marketplace')
                );
            }

            return true;
        }

        $code = trim($code);
        if ($code === '') {
            return new \WP_Error('sutore_otp_required', __('Enter the verification code sent to your phone.', 'sutore-marketplace'));
        }

        $rateLimit = $this->assertNotRateLimited($userId);
        if ($rateLimit instanceof \WP_Error) {
            return $rateLimit;
        }

        $session = $this->getSession($userId);
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

        $this->clearSession($userId);

        return true;
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
            return OtpPhoneResolver::forUser(
                $userId,
                (string) ($payload['user_phone'] ?? $payload['phone'] ?? '')
            );
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

    /** @param array{code_hash:string,expires_at:int,purpose:string,phone:string} $session */
    private function storeSession(int $userId, array $session, int $ttl): void
    {
        set_transient(self::TRANSIENT_SESSION . $userId, $session, $ttl);
    }

    /** @return array{code_hash?:string,expires_at?:int,purpose?:string,phone?:string}|null */
    private function getSession(int $userId): ?array
    {
        $session = get_transient(self::TRANSIENT_SESSION . $userId);
        if (is_array($session) && ($session['code_hash'] ?? '') !== '') {
            return $session;
        }

        return null;
    }

    private function clearSession(int $userId): void
    {
        delete_transient(self::TRANSIENT_SESSION . $userId);
        $this->clearAttempts($userId);
    }

    private function clearAttempts(int $userId): void
    {
        delete_transient(self::TRANSIENT_ATTEMPTS . $userId);
    }
}
