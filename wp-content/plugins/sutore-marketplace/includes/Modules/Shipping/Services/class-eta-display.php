<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Services;

use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Shipping\Settings\ShippingSettings;

final class EtaDisplay
{
    public static function formatDeliveryDate(int $etaDays): string
    {
        $tz = wp_timezone();
        $dt = self::deadlineDateTime($etaDays);
        // WordPress core locale packs translate weekday/month names.
        $date = wp_date('l, F j', $dt->getTimestamp(), $tz);

        return sprintf(
            /* translators: %s: localized estimated delivery date */
            __(' — %s (estimated delivery)', 'sutore-marketplace'),
            $date
        );
    }

    /** Unix timestamp (site timezone wall clock as GMT-compatible epoch). */
    public static function deadlineTimestamp(int $etaDays): int
    {
        return self::deadlineDateTime($etaDays)->getTimestamp();
    }

    private static function deadlineDateTime(int $etaDays): \DateTimeImmutable
    {
        $tz = wp_timezone();
        $dt = new \DateTimeImmutable('now', $tz);
        if ($etaDays !== 0) {
            $dt = $dt->modify(($etaDays > 0 ? '+' : '') . $etaDays . ' days');
        }

        return $dt;
    }

    public static function standardEtaDaysForProduct(int $productId): int
    {
        $productId = max(0, $productId);
        if ($productId <= 0) {
            return 0;
        }

        $product = wc_get_product($productId);
        if (!$product instanceof \WC_Product) {
            return 0;
        }

        $parentId = $product->is_type('variation') ? (int) $product->get_parent_id() : $productId;
        $lookupId = $product->is_type('variation') ? $productId : $parentId;

        if (ImportedProductService::isVariationImported($lookupId)) {
            return ShippingSettings::etaDays('imported_free');
        }

        if (self::isInternationalLocation()) {
            return self::isInternationalEligible($lookupId) ? ShippingSettings::etaDays('international') : 0;
        }

        return ShippingSettings::etaDays('free');
    }

    public static function isInternationalLocation(): bool
    {
        if (!function_exists('WC') || !WC()->customer) {
            return false;
        }

        $country = (string) (
            WC()->customer->get_shipping_country()
            ?: WC()->customer->get_billing_country()
        );

        return $country !== '' && strtoupper($country) !== 'TR';
    }

    public static function isInternationalEligible(int $lookupId): bool
    {
        $lookupId = max(0, $lookupId);
        if ($lookupId <= 0) {
            return false;
        }

        $listing = (new ListingRepository())->findByVariationId($lookupId);

        return $listing !== null && $listing->hasInvoice;
    }
}
