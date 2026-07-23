<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Database;

final class TrDistrictsSeeder
{
    public static function seedIfEmpty(): void
    {
        global $wpdb;
        $table = Schema::table('tr_districts');
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        $file = dirname(__DIR__) . '/Data/tr-districts-data.php';
        if (!is_readable($file)) {
            return;
        }

        $data = require $file;
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $cityCode => $districts) {
            if (!is_string($cityCode) || !is_array($districts)) {
                continue;
            }
            foreach ($districts as $districtName) {
                if (!is_string($districtName) || $districtName === '') {
                    continue;
                }
                $wpdb->insert($table, [
                    'city_code' => $cityCode,
                    'district_name' => $districtName,
                ]);
            }
        }
    }
}
