<?php

declare(strict_types=1);

namespace SutoreMarketplace\Bootstrap;

use SutoreMarketplace\Frontend\MerchantAccount;
use SutoreMarketplace\Frontend\StaffAccount;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Hooks\Cron;
use SutoreMarketplace\Modules\Listings\Module as ListingsModule;
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
        Module::activate();
        SourcingModule::activate();
        ShippingZoneSetup::ensure();

        if (!get_role('merchant')) {
            add_role('merchant', 'Merchant', [
                'read' => true,
                'edit_products' => true,
                'upload_files' => true,
            ]);
        }

        foreach (array_merge(MerchantAccount::endpointSlugs(), StaffAccount::endpointSlugs()) as $endpoint) {
            add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        Cron::unschedule();
        ListingsModule::deactivate();
        Module::deactivate();
        SourcingModule::deactivate();
        flush_rewrite_rules();
    }
}
