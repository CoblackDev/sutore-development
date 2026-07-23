<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms\Settings;

use SutoreMarketplace\Shared\Security\SecretBox;
use SutoreMarketplace\Shared\Settings\Settings;

final class NetgsmSettings
{
    public static function usercode(): string
    {
        return trim((string) Settings::get('netgsm_usercode', ''));
    }

    public static function password(): string
    {
        return SecretBox::open((string) Settings::get('netgsm_password', ''));
    }

    public static function header(): string
    {
        $header = trim((string) Settings::get('netgsm_header', 'SUTORE'));

        return $header !== '' ? $header : 'SUTORE';
    }

    public static function encoding(): string
    {
        $encoding = strtoupper(trim((string) Settings::get('netgsm_encoding', 'TR')));

        return in_array($encoding, ['TR', 'EN'], true) ? $encoding : 'TR';
    }

    public static function isConfigured(): bool
    {
        return self::usercode() !== '' && self::password() !== '';
    }

    public static function hasStoredPassword(): bool
    {
        return (string) Settings::get('netgsm_password', '') !== '';
    }

    public static function resolvePasswordForSave(string $submitted): string
    {
        return SecretBox::resolveForSave(
            $submitted,
            (string) Settings::get('netgsm_password', '')
        );
    }
}
