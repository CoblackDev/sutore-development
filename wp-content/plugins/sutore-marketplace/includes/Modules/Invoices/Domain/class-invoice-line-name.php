<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Domain;

/**
 * e-Archive line names are always Turkish (GIB). Not UI copy — do not run through locale.
 */
final class InvoiceLineName
{
    public const HIZMET = 'Hizmet Bedeli';
    public const GUVENCE = 'Güvence Bedeli';
    public const COMMISSION = 'Komisyon Bedeli';

    public static function forCode(string $code): string
    {
        return match ($code) {
            'hizmet' => self::HIZMET,
            'guvence' => self::GUVENCE,
            'commission' => self::COMMISSION,
            default => $code,
        };
    }

    public static function description(string $code, string $productTitle = ''): string
    {
        $label = self::forCode($code);
        $productTitle = trim($productTitle);

        return $productTitle !== '' ? $label . ' — ' . $productTitle : $label;
    }
}
