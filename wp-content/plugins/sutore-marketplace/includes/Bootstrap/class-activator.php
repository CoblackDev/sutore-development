<?php

declare(strict_types=1);

namespace SutoreMarketplace\Bootstrap;

use SutoreMarketplace\Admin\StaffCapabilities;
use SutoreMarketplace\Modules\Listings\Domain\ListingCapabilities;
use SutoreMarketplace\Frontend\CustomerAccount;
use SutoreMarketplace\Frontend\MerchantAccount;
use SutoreMarketplace\Frontend\StaffAccount;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Hooks\CronRegistry;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Modules\Shipping\Services\ShippingZoneSetup;
use SutoreMarketplace\Shared\Settings\Settings;

final class Activator
{
    public static function activate(): void
    {
        Schema::install();
        Schema::upgradeHeavy();
        Settings::ensureDefaults();
        OrderSettings::ensureDefaults();
        CronRegistry::scheduleAll();
        ShippingZoneSetup::ensure();

        if (!get_role('merchant')) {
            add_role('merchant', __('Merchant', 'sutore-marketplace'), ListingCapabilities::merchantCaps());
        }
        ListingCapabilities::reconcileMerchantRole();
        StaffCapabilities::reconcile();

        foreach (array_merge(
            MerchantAccount::endpointSlugs(),
            CustomerAccount::endpointSlugs(),
            StaffAccount::endpointSlugs()
        ) as $endpoint) {
            add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        CronRegistry::unscheduleAll();
        flush_rewrite_rules();
    }
}
