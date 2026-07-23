<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings;

use SutoreMarketplace\Modules\Listings\Hooks\CampaignCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\ListingBulkImportScheduler;
use SutoreMarketplace\Modules\Listings\Hooks\ListingPriceGuardHooks;
use SutoreMarketplace\Modules\Listings\Rest\AdminCampaignsController;
use SutoreMarketplace\Modules\Listings\Rest\CampaignOffersController;
use SutoreMarketplace\Modules\Listings\Rest\ImportedProductsController;
use SutoreMarketplace\Modules\Listings\Rest\ListingsBulkController;
use SutoreMarketplace\Modules\Listings\Rest\ListingsController;

final class Module
{
    public static function boot(): void
    {
        (new ImportedProductsController())->register();
        (new ListingsController())->register();
        (new ListingsBulkController())->register();
        (new AdminCampaignsController())->register();
        (new CampaignOffersController())->register();
        (new ListingBulkImportScheduler())->register();
        (new ListingPriceGuardHooks())->register();
        (new CampaignCronHooks())->register();
    }

    public static function activate(): void
    {
        CampaignCronHooks::schedule();
    }

    public static function deactivate(): void
    {
        CampaignCronHooks::unschedule();
    }
}
