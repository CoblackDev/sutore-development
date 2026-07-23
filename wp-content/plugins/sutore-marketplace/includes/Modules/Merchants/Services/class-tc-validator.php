<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

final class TcValidator
{
    public static function isValid(string $tc): bool
    {
        $tc = preg_replace('/\D/', '', $tc) ?? '';
        if (strlen($tc) !== 11) {
            return false;
        }
        if ($tc[0] === '0') {
            return false;
        }

        $digits = array_map('intval', str_split($tc));
        $plus = ($digits[0] + $digits[2] + $digits[4] + $digits[6] + $digits[8]) * 7;
        $minus = $plus - ($digits[1] + $digits[3] + $digits[5] + $digits[7]);
        if ($minus % 10 !== $digits[9]) {
            return false;
        }

        $all = array_sum(array_slice($digits, 0, 10));
        return $all % 10 === $digits[10];
    }
}
