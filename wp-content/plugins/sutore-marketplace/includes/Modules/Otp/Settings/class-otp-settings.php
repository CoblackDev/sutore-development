<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Settings;

use SutoreMarketplace\Shared\Settings\Settings;

final class OtpSettings
{
    public static function isEnabled(): bool
    {
        return (bool) Settings::get('otp_enabled', true);
    }

    public static function ttlSeconds(): int
    {
        return max(60, (int) Settings::get('otp_ttl_seconds', 300));
    }

    public static function uiTimerSeconds(): int
    {
        return max(30, (int) Settings::get('otp_ui_timer_seconds', 120));
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) Settings::get('otp_max_attempts', 3));
    }

    public static function rateLimitWindowSeconds(): int
    {
        return max(60, (int) Settings::get('otp_rate_limit_window_seconds', 900));
    }

    public static function codeLength(): int
    {
        return max(4, min(8, (int) Settings::get('otp_code_length', 6)));
    }

    /** @param array<string, string|int|float|null> $vars */
    public static function smsMessage(string $code): string
    {
        $template = (string) Settings::get('otp_sms_template', '');
        if ($template === '') {
            return sprintf(
                /* translators: %s: one-time verification code */
                __('Sutore.com verification code: %s. Do not share this code with anyone.', 'sutore-marketplace'),
                $code
            );
        }

        return str_replace('{code}', $code, $template);
    }
}
