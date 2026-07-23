<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Data;

use SutoreMarketplace\Shared\Database\Schema;

final class TrDistricts
{
    private const TRANSIENT_PREFIX = 'sutore_mp_tr_districts_';

    /** @return list<string> */
    public static function forCity(string $cityCode): array
    {
        $cityCode = trim($cityCode);
        if ($cityCode === '') {
            return [];
        }

        $key = self::TRANSIENT_PREFIX . sanitize_key($cityCode);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return array_values(array_map('strval', $cached));
        }

        global $wpdb;
        $table = Schema::table('tr_districts');
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT district_name FROM {$table} WHERE city_code = %s ORDER BY district_name ASC",
            $cityCode
        ));

        $districts = is_array($rows) ? array_values(array_map('strval', $rows)) : [];
        set_transient($key, $districts, DAY_IN_SECONDS);

        return $districts;
    }
}
