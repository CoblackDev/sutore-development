<?php

declare(strict_types=1);

namespace SutoreMarketplace\Bootstrap;

use SutoreMarketplace\Admin\AdminMenu;
use SutoreMarketplace\Admin\ProductMetaFields;
use SutoreMarketplace\Frontend\CustomerAccount;
use SutoreMarketplace\Frontend\MerchantAccount;
use SutoreMarketplace\Frontend\StaffAccount;
use SutoreMarketplace\Modules\Contracts\Module as ContractsModule;
use SutoreMarketplace\Modules\Coupons\Module as CouponsModule;
use SutoreMarketplace\Modules\Invoices\Module as InvoicesModule;
use SutoreMarketplace\Modules\Listings\Module as ListingsModule;
use SutoreMarketplace\Modules\Merchants\Module as MerchantsModule;
use SutoreMarketplace\Modules\Orders\Module as OrdersModule;
use SutoreMarketplace\Modules\Otp\Module as OtpModule;
use SutoreMarketplace\Modules\Shipping\Module as ShippingModule;
use SutoreMarketplace\Modules\Sourcing\Module as SourcingModule;
use SutoreMarketplace\Modules\Tasks\Module as TasksModule;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Hooks\CartPricingHooks;
use SutoreMarketplace\Shared\Hooks\CartQuantityHooks;
use SutoreMarketplace\Shared\Hooks\CheckoutIdentityHooks;
use SutoreMarketplace\Shared\Hooks\YouthDiscountHooks;
use SutoreMarketplace\Shared\Hooks\CloudflareTunnelHooks;
use SutoreMarketplace\Shared\Hooks\Cron;
use SutoreMarketplace\Shared\Hooks\PdpIntegration;
use SutoreMarketplace\Shared\Settings\Settings;
use SutoreMarketplace\Shared\Sms\SmsQueue;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        add_action('init', static function (): void {
            load_plugin_textdomain(
                'sutore-marketplace',
                false,
                dirname(SUTORE_MARKETPLACE_BASENAME) . '/languages'
            );
        }, 0);

        if ((int) get_option('sutore_marketplace_db_version', 0) < Schema::VERSION) {
            Schema::install();
        }

        Settings::ensureDefaults();
        SmsQueue::register();
        (new CloudflareTunnelHooks())->register();

        ListingsModule::boot();
        TasksModule::boot();
        SourcingModule::boot();
        MerchantsModule::boot();
        OtpModule::boot();
        OrdersModule::boot();
        ShippingModule::boot();
        CouponsModule::boot();
        ContractsModule::boot();
        InvoicesModule::boot();

        (new MerchantAccount())->register();
        (new CustomerAccount())->register();
        (new StaffAccount())->register();
        (new CartPricingHooks())->register();
        (new CartQuantityHooks())->register();
        (new CheckoutIdentityHooks())->register();
        (new YouthDiscountHooks())->register();
        (new Cron())->register();
        (new PdpIntegration())->register();

        if (is_admin()) {
            (new AdminMenu())->register();
            (new ProductMetaFields())->register();
        }
    }
}
