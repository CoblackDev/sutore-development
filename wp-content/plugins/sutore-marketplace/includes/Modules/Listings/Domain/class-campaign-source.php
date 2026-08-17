<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class CampaignSource
{
    public const ADMIN = 'admin';
    public const SYSTEM = 'system';
    public const MERCHANT = 'merchant';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ADMIN, self::SYSTEM, self::MERCHANT];
    }

    public static function isValid(string $source): bool
    {
        return in_array($source, self::all(), true);
    }

    public static function normalize(mixed $raw): string
    {
        $source = sanitize_key((string) $raw);

        return self::isValid($source) ? $source : self::ADMIN;
    }

    public static function label(string $source): string
    {
        return match (self::normalize($source)) {
            self::SYSTEM => __('Suggestion', 'sutore-marketplace'),
            self::MERCHANT => __('Seller campaign', 'sutore-marketplace'),
            default => __('Platform', 'sutore-marketplace'),
        };
    }
}
