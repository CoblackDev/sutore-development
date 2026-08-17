<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Domain;

/**
 * Sales e-Archive kinds only. Paraşüt credit / return invoices are not issued.
 */
final class InvoiceKind
{
    public const CUSTOMER_FEES = 'customer_fees';
    public const SELLER_COMMISSION = 'seller_commission';

    /** Order-level customer invoice (one per WC order). */
    public const ORDER_SCOPE_VARIATION = 0;

    public static function label(string $kind): string
    {
        return match ($kind) {
            self::CUSTOMER_FEES => __('Customer platform fees', 'sutore-marketplace'),
            self::SELLER_COMMISSION => __('Seller commission', 'sutore-marketplace'),
            default => $kind,
        };
    }
}
