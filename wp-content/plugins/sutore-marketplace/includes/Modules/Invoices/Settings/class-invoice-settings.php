<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Settings;

use SutoreMarketplace\Shared\Security\SecretBox;
use SutoreMarketplace\Shared\Settings\Settings;

final class InvoiceSettings
{
    public const TOKEN_OPTION = 'sutore_marketplace_parasut_tokens';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'company_id' => '',
            'client_id' => '',
            'client_secret' => '',
            'username' => '',
            'password' => '',
            'vat_rate' => 20.0,
            'product_hizmet_id' => '',
            'product_guvence_id' => '',
            'product_commission_id' => '',
            'shop_url' => '',
        ];
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $stored = Settings::get('invoices', self::defaults());
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::defaults(), $stored);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return $all[$key] ?? $default;
    }

    public static function enabled(): bool
    {
        return (bool) self::get('enabled', false) && self::isConfigured();
    }

    public static function isConfigured(): bool
    {
        return self::companyId() > 0
            && self::clientId() !== ''
            && self::clientSecret() !== ''
            && self::username() !== ''
            && self::password() !== '';
    }

    public static function companyId(): int
    {
        return max(0, (int) self::get('company_id', 0));
    }

    public static function clientId(): string
    {
        return trim((string) self::get('client_id', ''));
    }

    public static function clientSecret(): string
    {
        return SecretBox::open((string) self::get('client_secret', ''));
    }

    public static function username(): string
    {
        return trim((string) self::get('username', ''));
    }

    public static function password(): string
    {
        return SecretBox::open((string) self::get('password', ''));
    }

    public static function vatRate(): float
    {
        return max(0.0, min(100.0, (float) self::get('vat_rate', 20)));
    }

    public static function shopUrl(): string
    {
        $url = trim((string) self::get('shop_url', ''));
        if ($url !== '') {
            return $url;
        }

        return home_url('/');
    }

    public static function productId(string $code): string
    {
        $key = match ($code) {
            'hizmet' => 'product_hizmet_id',
            'guvence' => 'product_guvence_id',
            'commission' => 'product_commission_id',
            default => '',
        };

        return $key !== '' ? trim((string) self::get($key, '')) : '';
    }

    public static function setProductId(string $code, string $id): void
    {
        $key = match ($code) {
            'hizmet' => 'product_hizmet_id',
            'guvence' => 'product_guvence_id',
            'commission' => 'product_commission_id',
            default => '',
        };
        if ($key === '' || $id === '') {
            return;
        }

        $all = self::all();
        $all[$key] = $id;
        Settings::update(['invoices' => $all]);
    }

    public static function hasStoredSecret(string $key): bool
    {
        return trim((string) self::get($key, '')) !== '';
    }

    /** @param array<string, mixed> $input */
    public static function sanitizeFromInput(array $input): array
    {
        $current = self::all();

        return [
            'enabled' => !empty($input['invoices_enabled']),
            'company_id' => preg_replace('/\D/', '', (string) ($input['invoices_company_id'] ?? '')) ?? '',
            'client_id' => sanitize_text_field((string) ($input['invoices_client_id'] ?? '')),
            'client_secret' => SecretBox::resolveForSave(
                (string) ($input['invoices_client_secret'] ?? ''),
                (string) ($current['client_secret'] ?? '')
            ),
            'username' => sanitize_text_field((string) ($input['invoices_username'] ?? '')),
            'password' => SecretBox::resolveForSave(
                (string) ($input['invoices_password'] ?? ''),
                (string) ($current['password'] ?? '')
            ),
            'vat_rate' => max(0.0, min(100.0, (float) ($input['invoices_vat_rate'] ?? 20))),
            'product_hizmet_id' => sanitize_text_field((string) ($current['product_hizmet_id'] ?? '')),
            'product_guvence_id' => sanitize_text_field((string) ($current['product_guvence_id'] ?? '')),
            'product_commission_id' => sanitize_text_field((string) ($current['product_commission_id'] ?? '')),
            'shop_url' => esc_url_raw((string) ($input['invoices_shop_url'] ?? '')),
        ];
    }
}
