<?php

declare(strict_types=1);

namespace SutoreMarketplace\Bootstrap;

use SutoreMarketplace\Admin\StaffCapabilities;
use SutoreMarketplace\Modules\Listings\Domain\ListingCapabilities;
use SutoreMarketplace\Frontend\CustomerAccount;
use SutoreMarketplace\Frontend\MerchantAccount;
use SutoreMarketplace\Frontend\StaffAccount;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Hooks\Cron;
use SutoreMarketplace\Modules\Listings\Module as ListingsModule;
use SutoreMarketplace\Modules\Invoices\Module as InvoicesModule;
use SutoreMarketplace\Modules\Merchants\Module as MerchantsModule;
use SutoreMarketplace\Modules\Orders\Module;
use SutoreMarketplace\Modules\Sourcing\Module as SourcingModule;
use SutoreMarketplace\Modules\Shipping\Services\ShippingZoneSetup;
use SutoreMarketplace\Shared\Settings\Settings;

final class Activator
{
    public static function activate(): void
    {
        Schema::install();
        Settings::ensureDefaults();
        Cron::schedule();
        ListingsModule::activate();
        InvoicesModule::activate();
        Module::activate();
        SourcingModule::activate();
        MerchantsModule::activate();
        ShippingZoneSetup::ensure();

        if (!get_role('merchant')) {
            add_role('merchant', 'Merchant', ListingCapabilities::merchantCaps());
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
        Cron::unschedule();
        ListingsModule::deactivate();
        InvoicesModule::deactivate();
        Module::deactivate();
        SourcingModule::deactivate();
        MerchantsModule::deactivate();
        flush_rewrite_rules();
    }
}
