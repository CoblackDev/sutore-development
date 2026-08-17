<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Merchant listing mutation rules while a campaign offer is pending or active.
 * Uses only fields already on {@see Listing} — no extra DB reads.
 */
final class ListingCampaignPolicy
{
    public static function isPendingOffer(Listing $listing): bool
    {
        return $listing->campaignStatus === 'offer';
    }

    public static function isActiveCampaign(Listing $listing): bool
    {
        return $listing->campaignStatus === 'active';
    }

    /**
     * Pending offer: no listing updates (matches legacy update_product_price lock).
     */
    public static function blocksAllUpdates(Listing $listing): bool
    {
        return self::isPendingOffer($listing);
    }

    public static function allowsAskingIncrease(Listing $listing): bool
    {
        return !self::isActiveCampaign($listing);
    }

    public static function isCoolingDown(Listing $listing): bool
    {
        $until = $listing->campaignCooledUntil;
        if ($until === null || $until === '') {
            return false;
        }

        return !CampaignDatetime::isPast($until);
    }

    public static function canStart(Listing $listing): bool
    {
        return !is_wp_error(self::assertCanStart($listing));
    }

    /**
     * @return true|\WP_Error
     */
    public static function assertCanStart(Listing $listing): true|\WP_Error
    {
        if (self::isPendingOffer($listing)) {
            return new \WP_Error(
                'sutore_marketplace_campaign_offer_pending',
                __('Respond to the campaign offer before starting a campaign.', 'sutore-marketplace')
            );
        }

        if (self::isActiveCampaign($listing)) {
            return new \WP_Error(
                'sutore_marketplace_campaign_active',
                __('This listing is already in a campaign.', 'sutore-marketplace')
            );
        }

        if (!in_array($listing->listingStatus, [ListingStatus::PUBLISH, ListingStatus::QUEUED], true)) {
            return new \WP_Error(
                'sutore_campaign_listing_status',
                __('Only products that are for sale or in queue can start a campaign.', 'sutore-marketplace')
            );
        }

        if (self::isCoolingDown($listing)) {
            return new \WP_Error(
                'sutore_campaign_cooldown',
                sprintf(
                    /* translators: %s: cooldown end datetime */
                    __('This listing can join another campaign after %s.', 'sutore-marketplace'),
                    CampaignDatetime::formatLabel((string) $listing->campaignCooledUntil)
                )
            );
        }

        return true;
    }

    /**
     * @return true|\WP_Error
     */
    public static function assertAskingChange(Listing $listing, float $newAsking): true|\WP_Error
    {
        if (self::isPendingOffer($listing)) {
            return new \WP_Error(
                'sutore_marketplace_campaign_offer_pending',
                __('Respond to the campaign offer before updating this listing price.', 'sutore-marketplace')
            );
        }

        if (self::isActiveCampaign($listing) && $newAsking > (float) $listing->asking) {
            return new \WP_Error(
                'sutore_marketplace_campaign_asking_raise',
                __('This listing is in a campaign, so you cannot increase the asking price.', 'sutore-marketplace')
            );
        }

        return true;
    }

    /**
     * @return true|\WP_Error
     */
    public static function assertCanUpdate(Listing $listing): true|\WP_Error
    {
        if (!self::blocksAllUpdates($listing)) {
            return true;
        }

        return new \WP_Error(
            'sutore_marketplace_campaign_offer_pending',
            __('Respond to the campaign offer before updating this listing.', 'sutore-marketplace')
        );
    }
}
