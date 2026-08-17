<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Live outlet listings keep the committed asking until the window ends.
 */
final class ListingOutletPolicy
{
    public static function assertUnlocked(?OutletOptin $optin): true|\WP_Error
    {
        if ($optin === null || $optin->status !== OutletOptinStatus::LIVE) {
            return true;
        }

        return new \WP_Error(
            'sutore_outlet_listing_locked',
            __('This listing is in an outlet window, so it cannot be changed until the window ends.', 'sutore-marketplace')
        );
    }
}
