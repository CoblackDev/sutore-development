<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings;

use SutoreMarketplace\Modules\Listings\Hooks\CampaignCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\CustomerOfferCheckoutHooks;
use SutoreMarketplace\Modules\Listings\Hooks\CustomerOfferCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\ListingBulkImportScheduler;
use SutoreMarketplace\Modules\Listings\Hooks\ListingPriceGuardHooks;
use SutoreMarketplace\Modules\Listings\Hooks\OutletCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\ProductConditionHooks;
use SutoreMarketplace\Modules\Listings\Hooks\VariationLifecycleGuard;
use SutoreMarketplace\Modules\Listings\Rest\AdminCampaignsController;
use SutoreMarketplace\Modules\Listings\Rest\AdminCatalogProductRequestsController;
use SutoreMarketplace\Modules\Listings\Rest\AdminOutletController;
use SutoreMarketplace\Modules\Listings\Rest\CampaignOffersController;
use SutoreMarketplace\Modules\Listings\Rest\CatalogProductRequestsController;
use SutoreMarketplace\Modules\Listings\Rest\CustomerOffersController;
use SutoreMarketplace\Modules\Listings\Rest\ListingsBulkController;
use SutoreMarketplace\Modules\Listings\Rest\ListingsController;
use SutoreMarketplace\Modules\Listings\Rest\OutletController;
use SutoreMarketplace\Modules\Listings\Rest\PriceOffersController;

final class Module
{
    public static function boot(): void
    {
        (new ListingsController())->register();
        (new ListingsBulkController())->register();
        (new AdminCampaignsController())->register();
        (new AdminOutletController())->register();
        (new CampaignOffersController())->register();
        (new CustomerOffersController())->register();
        (new PriceOffersController())->register();
        (new OutletController())->register();
        (new CatalogProductRequestsController())->register();
        (new AdminCatalogProductRequestsController())->register();
        (new ListingBulkImportScheduler())->register();
        (new ListingPriceGuardHooks())->register();
        (new VariationLifecycleGuard())->register();
        (new ProductConditionHooks())->register();
        (new CampaignCronHooks())->register();
        (new OutletCronHooks())->register();
        (new CustomerOfferCronHooks())->register();
        (new CustomerOfferCheckoutHooks())->register();
    }

    public static function activate(): void
    {
        CampaignCronHooks::schedule();
        OutletCronHooks::schedule();
        CustomerOfferCronHooks::schedule();
    }

    public static function deactivate(): void
    {
        CampaignCronHooks::unschedule();
        OutletCronHooks::unschedule();
        CustomerOfferCronHooks::unschedule();
    }
}
