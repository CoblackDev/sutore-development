<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Security;

/**
 * Encrypts secrets at rest in options using AES-256-CBC + HMAC-SHA256 (enc:v2).
 * Legacy enc:v1 (CBC without MAC) is still readable once and re-sealed on save.
 * Stored values must use an enc: prefix; plaintext is not accepted at read time.
 */
final class SecretBox
{
    private const PREFIX_V1 = 'enc:v1:';
    private const PREFIX_V2 = 'enc:v2:';

    public static function seal(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }
        if (str_starts_with($plain, self::PREFIX_V2) || str_starts_with($plain, self::PREFIX_V1)) {
            return $plain;
        }

        if (!function_exists('openssl_encrypt')) {
            return '';
        }

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', self::encKey(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return '';
        }

        $mac = hash_hmac('sha256', $iv . $cipher, self::macKey(), true);

        return self::PREFIX_V2 . base64_encode($iv . $mac . $cipher);
    }

    public static function open(string $stored): string
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return '';
        }

        if (str_starts_with($stored, self::PREFIX_V2)) {
            return self::openV2($stored);
        }

        if (str_starts_with($stored, self::PREFIX_V1)) {
            return self::openV1($stored);
        }

        return '';
    }

    public static function resolveForSave(string $submitted, string $existingSealed): string
    {
        $submitted = trim($submitted);
        if ($submitted !== '') {
            $sealed = self::seal($submitted);
            // Never wipe an existing secret when encrypt fails.
            if ($sealed === '' && self::isSealed($existingSealed)) {
                return $existingSealed;
            }

            return $sealed;
        }

        if (str_starts_with($existingSealed, self::PREFIX_V1)) {
            $plain = self::openV1($existingSealed);
            if ($plain !== '') {
                $upgraded = self::seal($plain);

                return $upgraded !== '' ? $upgraded : $existingSealed;
            }
        }

        return self::isSealed($existingSealed) ? $existingSealed : '';
    }

    public static function isSealed(string $stored): bool
    {
        return $stored !== ''
            && (str_starts_with($stored, self::PREFIX_V2) || str_starts_with($stored, self::PREFIX_V1));
    }

    private static function openV2(string $stored): string
    {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX_V2)), true);
        if ($raw === false || strlen($raw) < 49) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipher = substr($raw, 48);
        $expected = hash_hmac('sha256', $iv . $cipher, self::macKey(), true);
        if (!hash_equals($expected, $mac)) {
            return '';
        }

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::encKey(), OPENSSL_RAW_DATA, $iv);

        return is_string($plain) ? $plain : '';
    }

    private static function openV1(string $stored): string
    {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX_V1)), true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::legacyKey(), OPENSSL_RAW_DATA, $iv);

        return is_string($plain) ? $plain : '';
    }

    private static function encKey(): string
    {
        $material = (defined('AUTH_KEY') ? (string) AUTH_KEY : '') . '|sutore-marketplace-secrets|enc';

        return hash('sha256', $material, true);
    }

    private static function macKey(): string
    {
        $material = (defined('AUTH_KEY') ? (string) AUTH_KEY : '') . '|sutore-marketplace-secrets|mac';

        return hash('sha256', $material, true);
    }

    /** v1 used a single key derivation — keep for decrypt-only. */
    private static function legacyKey(): string
    {
        $material = (defined('AUTH_KEY') ? (string) AUTH_KEY : '') . '|sutore-marketplace-secrets';

        return hash('sha256', $material, true);
    }
}
