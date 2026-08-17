<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Security;

/**
 * Encrypts secrets at rest in options using AES-256-CBC keyed from AUTH_KEY.
 * Stored values must use the enc:v1: prefix; plaintext is not accepted at read time.
 */
final class SecretBox
{
    private const PREFIX = 'enc:v1:';

    public static function seal(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }
        if (str_starts_with($plain, self::PREFIX)) {
            return $plain;
        }

        if (!function_exists('openssl_encrypt')) {
            return '';
        }

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return '';
        }

        return self::PREFIX . base64_encode($iv . $cipher);
    }

    public static function open(string $stored): string
    {
        $stored = (string) $stored;
        if ($stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return '';
        }

        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);

        return is_string($plain) ? $plain : '';
    }

    public static function resolveForSave(string $submitted, string $existingSealed): string
    {
        $submitted = trim($submitted);
        if ($submitted !== '') {
            return self::seal($submitted);
        }

        return str_starts_with($existingSealed, self::PREFIX) ? $existingSealed : '';
    }

    public static function isSealed(string $stored): bool
    {
        return $stored !== '' && str_starts_with($stored, self::PREFIX);
    }

    private static function key(): string
    {
        $material = (defined('AUTH_KEY') ? (string) AUTH_KEY : '') . '|sutore-marketplace-secrets';

        return hash('sha256', $material, true);
    }
}
