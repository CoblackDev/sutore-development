<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Domain;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Shared\Settings\Settings;

final class MarketplacePricing
{
    public static function activeAsking(Listing $listing): float
    {
        return max(0.0, (float) $listing->asking);
    }

    public static function guvenceFeeForAsking(float $asking): float
    {
        $percent = Settings::guvenceBedeli();
        if ($percent <= 0 || $asking <= 0) {
            return 0.0;
        }

        return self::roundMoney($asking * $percent / 100);
    }

    public static function listingComparePrice(float $asking): float
    {
        return self::roundMoney($asking + Settings::hizmetBedeli() + self::guvenceFeeForAsking($asking));
    }

    /**
     * Customer-facing unit price (fees included, platform campaign waiver applied when active).
     */
    public static function customerPrice(Listing $listing): float
    {
        $asking = self::activeAsking($listing);
        $breakdown = self::feeBreakdownForListing($listing);

        return self::roundMoney(max(0.0, $asking + $breakdown['hizmet'] + $breakdown['guvence']));
    }

    /**
     * Strikethrough / regular display price.
     */
    public static function compareAtPrice(Listing $listing): float
    {
        if ($listing->campaignStatus === 'active' && $listing->id) {
            $offer = (new CampaignOfferRepository())->findAcceptedForListing((int) $listing->id);
            if ($offer && isset($offer->compare_regular) && (float) $offer->compare_regular > 0) {
                return self::roundMoney((float) $offer->compare_regular);
            }
            if ($offer && isset($offer->asking_before)) {
                return self::listingComparePrice((float) $offer->asking_before);
            }
        }

        return self::listingComparePrice(self::activeAsking($listing));
    }

    /**
     * Platform fee waiver (TL) for an accepted campaign listing only.
     * Capped by hizmet + güvence — Sutore does not subsidize below asking.
     */
    public static function platformWaiverForListing(Listing $listing): float
    {
        if ($listing->campaignStatus !== 'active' || !$listing->id) {
            return 0.0;
        }

        $offer = (new CampaignOfferRepository())->findAcceptedForListing((int) $listing->id);
        if (!$offer) {
            return 0.0;
        }

        $asking = self::activeAsking($listing);
        $feesFull = Settings::hizmetBedeli() + self::guvenceFeeForAsking($asking);
        $requested = max(0.0, (float) ($offer->platform_discount ?? 0));

        return self::roundMoney(min($requested, $feesFull));
    }

    /**
     * @return array{hizmet: float, guvence: float, waiver: float, hizmet_full: float, guvence_full: float}
     */
    public static function feeBreakdownForListing(Listing $listing): array
    {
        $asking = self::activeAsking($listing);
        $hizmetFull = self::roundMoney(Settings::hizmetBedeli());
        $guvenceFull = self::guvenceFeeForAsking($asking);
        $waiver = self::platformWaiverForListing($listing);

        $hizmet = $hizmetFull;
        $guvence = $guvenceFull;
        $remaining = $waiver;

        if ($remaining > 0 && $hizmet > 0) {
            $cut = min($hizmet, $remaining);
            $hizmet = self::roundMoney($hizmet - $cut);
            $remaining = self::roundMoney($remaining - $cut);
        }
        if ($remaining > 0 && $guvence > 0) {
            $cut = min($guvence, $remaining);
            $guvence = self::roundMoney($guvence - $cut);
        }

        return [
            'hizmet' => $hizmet,
            'guvence' => $guvence,
            'waiver' => $waiver,
            'hizmet_full' => $hizmetFull,
            'guvence_full' => $guvenceFull,
        ];
    }

    /**
     * Alias for platformWaiverForListing.
     */
    public static function platformDiscountForListing(Listing $listing): float
    {
        return self::platformWaiverForListing($listing);
    }

    /**
     * Resolve campaign discount config (fixed TL or %) into TL amounts for one listing asking.
     *
     * Seller % = of asking. Platform % = of (hizmet + güvence) after seller cut.
     * Platform TL waiver is always capped by those fees.
     *
     * @return array{
     *   seller_discount: float,
     *   platform_discount: float,
     *   asking_effective: float,
     *   compare_regular: float,
     *   customer_sale: float,
     *   platform_waiver: float,
     *   fees_full: float
     * }
     */
    public static function resolveCampaignOfferMath(
        float $askingBefore,
        string $sellerType,
        float $sellerValue,
        string $platformType,
        float $platformValue
    ): array {
        $sellerType = CampaignDiscountType::normalize($sellerType);
        $platformType = CampaignDiscountType::normalize($platformType);
        $askingBefore = max(0.0, $askingBefore);
        $sellerValue = max(0.0, $sellerValue);
        $platformValue = max(0.0, $platformValue);

        if ($sellerType === CampaignDiscountType::PERCENT) {
            $sellerTl = self::roundMoney($askingBefore * min(100.0, $sellerValue) / 100);
        } else {
            $sellerTl = self::roundMoney($sellerValue);
        }

        $math = self::campaignAcceptMath($askingBefore, $sellerTl, 0.0);
        $askingEffective = $math['asking_effective'];
        $hizmet = Settings::hizmetBedeli();
        $guvence = self::guvenceFeeForAsking($askingEffective);
        $feesFull = $hizmet + $guvence;

        if ($platformType === CampaignDiscountType::PERCENT) {
            $platformTl = self::roundMoney($feesFull * min(100.0, $platformValue) / 100);
        } else {
            $platformTl = self::roundMoney($platformValue);
        }

        return self::campaignAcceptMath($askingBefore, $sellerTl, $platformTl) + [
            'fees_full' => self::roundMoney($feesFull),
        ];
    }

    /**
     * Compute customer sale + compare for a hypothetical accept (before asking is mutated).
     *
     * Seller discount lowers asking. Platform discount waives fees (capped by hizmet+güvence).
     *
     * @return array{asking_effective: float, compare_regular: float, customer_sale: float, platform_waiver: float, seller_discount: float, platform_discount: float}
     */
    public static function campaignAcceptMath(float $askingBefore, float $sellerDiscount, float $platformDiscount): array
    {
        $step = Settings::listingPriceStep();
        $rawEffective = max(0.0, $askingBefore - max(0.0, $sellerDiscount));
        $askingEffective = (float) ListingPriceValidator::roundDownToStep($rawEffective, $step);
        if ($askingEffective < $step) {
            $askingEffective = 0.0;
        }
        $hizmet = Settings::hizmetBedeli();
        $guvence = self::guvenceFeeForAsking($askingEffective);
        $feesFull = $hizmet + $guvence;
        $sellerTl = self::roundMoney(max(0.0, $askingBefore - $askingEffective));
        $waiver = min(max(0.0, $platformDiscount), $feesFull);
        $customerSale = self::roundMoney(max(0.0, $askingEffective + $feesFull - $waiver));
        $compareRegular = self::listingComparePrice($askingBefore);

        return [
            'asking_effective' => self::roundMoney($askingEffective),
            'compare_regular' => $compareRegular,
            'customer_sale' => $customerSale,
            'platform_waiver' => self::roundMoney($waiver),
            'seller_discount' => $sellerTl,
            'platform_discount' => self::roundMoney($waiver),
        ];
    }

    public static function merchantPayout(Listing $listing, ?float $commissionPercent = null): float
    {
        $asking = self::activeAsking($listing);
        if ($commissionPercent === null) {
            $commissionPercent = MerchantLevels::commissionPercentForUser($listing->merchantId);
        }

        return self::netFromAsking($asking, $commissionPercent);
    }

    public static function netFromAsking(float $asking, float $commissionPercent): float
    {
        $commission = $asking * (max(0.0, $commissionPercent) / 100);

        return self::roundMoney(max(0.0, $asking - $commission));
    }

    public static function firstPlaceAsking(float $winnerAsking): ?float
    {
        $step = Settings::listingPriceStep();
        $suggested = $winnerAsking - $step;
        if ($suggested < $step) {
            return null;
        }

        return (float) ListingPriceValidator::roundDownToStep($suggested, $step);
    }

    public static function formatTl(float $amount): string
    {
        return sprintf(
            __('%s TL', 'sutore-marketplace'),
            number_format($amount, 0, ',', '.')
        );
    }

    private static function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }
}
