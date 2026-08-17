<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Data;

/**
 * Canonical TR city → district list. Source: tr-districts-data.php (no DB table).
 */
final class TrDistricts
{
    /** @var array<string, list<string>>|null */
    private static ?array $byCity = null;

    /** @return list<string> */
    public static function forCity(string $cityCode): array
    {
        $cityCode = trim($cityCode);
        if ($cityCode === '') {
            return [];
        }

        $districts = self::all()[$cityCode] ?? [];
        if (!is_array($districts)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $districts),
            static fn (string $name): bool => $name !== ''
        ));
    }

    /** @return array<string, list<string>> */
    private static function all(): array
    {
        if (self::$byCity !== null) {
            return self::$byCity;
        }

        $file = __DIR__ . '/tr-districts-data.php';
        $data = is_readable($file) ? require $file : [];
        self::$byCity = is_array($data) ? $data : [];

        return self::$byCity;
    }
}
