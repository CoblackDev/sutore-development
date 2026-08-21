<?php

declare(strict_types=1);

namespace SutoreMarketplace\Frontend;

use SutoreMarketplace\Modules\Listings\Domain\CampaignGuardrails;
use SutoreMarketplace\Modules\Orders\Module as FulfillmentModule;
use SutoreMarketplace\Modules\Otp\Settings\OtpSettings;
use SutoreMarketplace\Shared\Settings\Settings;

final class Assets
{
    public const STYLE_CORE = 'sutore-marketplace-core';
    public const STYLE_LISTINGS = 'sutore-marketplace-listings';
    public const STYLE_MERCHANT_PROFILE = 'sutore-marketplace-merchant-profile';
    public const STYLE_ACCOUNT = 'sutore-marketplace-account';
    public const STYLE_TASKS = 'sutore-marketplace-tasks';
    public const SCRIPT_CORE = 'sutore-marketplace-core';
    public const SCRIPT_LISTINGS = 'sutore-marketplace-listings';
    public const SCRIPT_LISTINGS_BULK = 'sutore-marketplace-listings-bulk';
    public const SCRIPT_SOURCING = 'sutore-marketplace-sourcing';
    public const SCRIPT_CAMPAIGN_OFFERS = 'sutore-marketplace-campaign-offers';
    public const SCRIPT_PRICE_OFFERS = 'sutore-marketplace-price-offers';
    public const SCRIPT_MY_OFFERS = 'sutore-marketplace-my-offers';
    public const SCRIPT_OUTLET = 'sutore-marketplace-outlet';
    public const SCRIPT_MERCHANT_PROFILE = 'sutore-marketplace-merchant-profile';
    public const SCRIPT_MERCHANT_BALANCE = 'sutore-marketplace-merchant-balance';
    public const STYLE_MERCHANT_BALANCE = 'sutore-marketplace-merchant-balance';
    public const SCRIPT_ACCOUNT = 'sutore-marketplace-account';
    public const SCRIPT_TASKS = 'sutore-marketplace-tasks';
    public const STYLE_NOTIFICATIONS = 'sutore-marketplace-notifications';
    public const SCRIPT_NOTIFICATIONS = 'sutore-marketplace-notifications';
    public const STYLE_STAFF_MANAGE = 'sutore-marketplace-staff-manage';
    public const SCRIPT_STAFF_MANAGE = 'sutore-marketplace-staff-manage';
    public const STYLE_STAFF_MERCHANTS = 'sutore-marketplace-staff-merchants';
    public const SCRIPT_STAFF_MERCHANTS = 'sutore-marketplace-staff-merchants';
    public const STYLE_STAFF_ORDERS = 'sutore-marketplace-staff-orders';
    public const SCRIPT_STAFF_ORDERS = 'sutore-marketplace-staff-orders';
    public const SCRIPT_STAFF_CATALOG_REQUESTS = 'sutore-marketplace-staff-catalog-requests';

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'registerAssets'], 5);
        add_action('admin_enqueue_scripts', [$this, 'registerAssets'], 5);
    }

    private static function assetVersion(string $relativePath): string
    {
        $path = SUTORE_MARKETPLACE_PATH . ltrim($relativePath, '/');
        $mtime = is_file($path) ? (int) filemtime($path) : 0;

        return $mtime > 0 ? (string) $mtime : SUTORE_MARKETPLACE_VERSION;
    }

    public function registerAssets(): void
    {
        wp_register_style(
            self::STYLE_CORE,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-core.css',
            [],
            self::assetVersion('assets/css/marketplace-core.css')
        );

        wp_register_style(
            self::STYLE_LISTINGS,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-listings.css',
            [self::STYLE_CORE, 'wc-blocks-packages-style'],
            self::assetVersion('assets/css/marketplace-listings.css')
        );

        wp_register_style(
            self::STYLE_MERCHANT_PROFILE,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-merchant-profile.css',
            [self::STYLE_CORE],
            self::assetVersion('assets/css/marketplace-merchant-profile.css')
        );

        wp_register_style(
            self::STYLE_ACCOUNT,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-account.css',
            [self::STYLE_CORE],
            SUTORE_MARKETPLACE_VERSION
        );

        wp_register_style(
            self::STYLE_TASKS,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-tasks.css',
            [self::STYLE_CORE],
            SUTORE_MARKETPLACE_VERSION
        );

        wp_register_script(
            self::SCRIPT_CORE,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-core.js',
            ['jquery'],
            self::assetVersion('assets/js/marketplace-core.js'),
            true
        );

        wp_register_script(
            self::SCRIPT_LISTINGS,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-listings.js',
            ['jquery', self::SCRIPT_CORE, 'sutore-marketplace-fulfillment'],
            self::assetVersion('assets/js/marketplace-listings.js'),
            true
        );

        wp_register_script(
            self::SCRIPT_LISTINGS_BULK,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-listings-bulk.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-listings-bulk.js'),
            true
        );

        wp_register_script(
            self::SCRIPT_SOURCING,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-sourcing.js',
            ['jquery', self::SCRIPT_CORE],
            SUTORE_MARKETPLACE_VERSION,
            true
        );

        wp_register_script(
            self::SCRIPT_CAMPAIGN_OFFERS,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-campaign-offers.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-campaign-offers.js'),
            true
        );

        wp_register_script(
            self::SCRIPT_PRICE_OFFERS,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-price-offers.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-price-offers.js'),
            true
        );

        wp_register_script(
            self::SCRIPT_MY_OFFERS,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-my-offers.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-my-offers.js'),
            true
        );

        wp_register_script(
            self::SCRIPT_OUTLET,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-outlet.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-outlet.js'),
            true
        );

        wp_register_script(
            self::SCRIPT_MERCHANT_PROFILE,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-merchant-profile.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-merchant-profile.js'),
            true
        );

        wp_register_style(
            self::STYLE_MERCHANT_BALANCE,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-merchant-balance.css',
            [self::STYLE_CORE],
            self::assetVersion('assets/css/marketplace-merchant-balance.css')
        );

        wp_register_script(
            self::SCRIPT_MERCHANT_BALANCE,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-merchant-balance.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-merchant-balance.js'),
            true
        );

        wp_register_script(
            self::SCRIPT_ACCOUNT,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-account.js',
            ['jquery', self::SCRIPT_CORE],
            SUTORE_MARKETPLACE_VERSION,
            true
        );

        wp_register_script(
            self::SCRIPT_TASKS,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-tasks.js',
            ['jquery', self::SCRIPT_CORE],
            SUTORE_MARKETPLACE_VERSION,
            true
        );

        wp_register_style(
            self::STYLE_NOTIFICATIONS,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-notifications.css',
            [self::STYLE_CORE],
            SUTORE_MARKETPLACE_VERSION
        );

        wp_register_script(
            self::SCRIPT_NOTIFICATIONS,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-notifications.js',
            ['jquery', self::SCRIPT_CORE],
            SUTORE_MARKETPLACE_VERSION,
            true
        );

        wp_register_style(
            self::STYLE_STAFF_MANAGE,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-staff-manage.css',
            [self::STYLE_CORE, self::STYLE_LISTINGS],
            self::assetVersion('assets/css/marketplace-staff-manage.css')
        );

        wp_register_script(
            self::SCRIPT_STAFF_MANAGE,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-staff-manage.js',
            ['jquery', self::SCRIPT_CORE, self::SCRIPT_LISTINGS, self::SCRIPT_LISTINGS_BULK],
            self::assetVersion('assets/js/marketplace-staff-manage.js'),
            true
        );

        wp_register_style(
            self::STYLE_STAFF_MERCHANTS,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-staff-merchants.css',
            [self::STYLE_STAFF_MANAGE],
            self::assetVersion('assets/css/marketplace-staff-merchants.css')
        );

        wp_register_script(
            self::SCRIPT_STAFF_MERCHANTS,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-staff-merchants.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-staff-merchants.js'),
            true
        );

        wp_register_style(
            self::STYLE_STAFF_ORDERS,
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-staff-orders.css',
            [self::STYLE_STAFF_MANAGE],
            self::assetVersion('assets/css/marketplace-staff-orders.css')
        );

        wp_register_script(
            self::SCRIPT_STAFF_ORDERS,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-staff-orders.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-staff-orders.js'),
            true
        );

        wp_register_script(
            self::SCRIPT_STAFF_CATALOG_REQUESTS,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-staff-catalog-requests.js',
            ['jquery', self::SCRIPT_CORE],
            self::assetVersion('assets/js/marketplace-staff-catalog-requests.js'),
            true
        );

        FulfillmentModule::registerAssets();
    }

    public function enqueueListings(): void
    {
        wp_enqueue_style(self::STYLE_LISTINGS);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore(array_merge($this->listingsI18n(), $this->bulkListingsI18n()));
        FulfillmentModule::enqueueAssets();
        wp_enqueue_script(self::SCRIPT_LISTINGS);
        wp_enqueue_script(self::SCRIPT_LISTINGS_BULK);
    }

    public function enqueueListingsBulk(): void
    {
        $this->enqueueListings();
    }

    public function enqueueSourcing(): void
    {
        wp_enqueue_style(self::STYLE_LISTINGS);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->sourcingI18n());
        wp_enqueue_script(self::SCRIPT_SOURCING);
    }

    public function enqueueCampaignOffers(): void
    {
        wp_enqueue_style(self::STYLE_LISTINGS);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->campaignOffersI18n());
        wp_enqueue_script(self::SCRIPT_CAMPAIGN_OFFERS);
    }

    public function enqueuePriceOffers(): void
    {
        wp_enqueue_style(self::STYLE_LISTINGS);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->priceOffersI18n());
        wp_enqueue_script(self::SCRIPT_PRICE_OFFERS);
    }

    public function enqueueMyOffers(): void
    {
        wp_enqueue_style(self::STYLE_LISTINGS);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->myOffersI18n());
        wp_enqueue_script(self::SCRIPT_MY_OFFERS);
    }

    public function enqueueOutlet(): void
    {
        wp_enqueue_style(self::STYLE_LISTINGS);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->outletI18n());
        wp_enqueue_script(self::SCRIPT_OUTLET);
    }

    public function enqueueMerchantProfile(): void
    {
        wp_enqueue_style(self::STYLE_MERCHANT_PROFILE);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->merchantProfileI18n());
        wp_enqueue_script(self::SCRIPT_MERCHANT_PROFILE);
    }

    public function enqueueMerchantBalance(): void
    {
        wp_enqueue_style(self::STYLE_MERCHANT_BALANCE);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->merchantBalanceI18n());
        wp_enqueue_script(self::SCRIPT_MERCHANT_BALANCE);
    }

    public function enqueueAccountSecurity(): void
    {
        wp_enqueue_style(self::STYLE_ACCOUNT);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->accountI18n());
        wp_enqueue_script(self::SCRIPT_ACCOUNT);
    }

    public function enqueueTasks(): void
    {
        wp_enqueue_style(self::STYLE_TASKS);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->tasksI18n());
        wp_enqueue_script(self::SCRIPT_TASKS);
    }

    public function enqueueNotifications(): void
    {
        wp_enqueue_style(self::STYLE_NOTIFICATIONS);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->notificationsI18n());
        wp_enqueue_script(self::SCRIPT_NOTIFICATIONS);
    }

    public function enqueueStaffOrders(): void
    {
        $this->enqueueStaffOrderDetail();
        $this->enqueueStaffMerchantDetail();
        $this->enqueueStaffProductDetail();
    }

    /**
     * Order detail modal (shared by Manage Orders and Manage Products).
     * Opens in-place over the current staff page without navigation.
     */
    public function enqueueStaffOrderDetail(): void
    {
        if (!\SutoreMarketplace\Admin\StaffCapabilities::canManageOps()) {
            return;
        }

        if (!wp_script_is(self::SCRIPT_STAFF_ORDERS, 'registered')) {
            $this->registerAssets();
        }

        if (wp_script_is(self::SCRIPT_STAFF_ORDERS, 'enqueued')) {
            return;
        }

        wp_enqueue_style(self::STYLE_STAFF_ORDERS);
        if (!wp_script_is(self::SCRIPT_CORE, 'enqueued')) {
            wp_enqueue_script(self::SCRIPT_CORE);
            $this->localizeCore([
                'cancel' => __('Cancel', 'sutore-marketplace'),
                'close' => __('Close', 'sutore-marketplace'),
                'error' => __('Error', 'sutore-marketplace'),
                'ok' => __('OK', 'sutore-marketplace'),
                'yes' => __('Yes', 'sutore-marketplace'),
            ]);
        } else {
            wp_enqueue_script(self::SCRIPT_CORE);
        }
        wp_enqueue_script(self::SCRIPT_STAFF_ORDERS);
        wp_localize_script(self::SCRIPT_STAFF_ORDERS, 'SutoreMarketplaceStaffOrders', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'manageOrdersUrl' => esc_url_raw(wc_get_account_endpoint_url(StaffAccount::ENDPOINT_MANAGE_ORDERS)),
            'manageProductsUrl' => esc_url_raw(wc_get_account_endpoint_url(StaffAccount::ENDPOINT_MANAGE_PRODUCTS)),
            'merchantsUrl' => esc_url_raw(wc_get_account_endpoint_url(StaffAccount::ENDPOINT_MERCHANTS)),
            'i18n' => [
                'loading' => __('Loading…', 'sutore-marketplace'),
                'error' => __('Error', 'sutore-marketplace'),
                'noRecords' => __('No orders found.', 'sutore-marketplace'),
                'order' => __('Order', 'sutore-marketplace'),
                'orderTitle' => __('Order #%s', 'sutore-marketplace'),
                'date' => __('Date', 'sutore-marketplace'),
                'status' => __('Status', 'sutore-marketplace'),
                'sellers' => __('Sellers', 'sutore-marketplace'),
                'sellerOne' => __('1 seller', 'sutore-marketplace'),
                'sellerMany' => __('%d sellers', 'sutore-marketplace'),
                'items' => __('Items', 'sutore-marketplace'),
                'shipmentType' => __('Shipment type', 'sutore-marketplace'),
                'deliveryDeadline' => __('Delivery deadline', 'sutore-marketplace'),
                'total' => __('Total', 'sutore-marketplace'),
                'customerPrice' => __('Customer', 'sutore-marketplace'),
                'sellerPrice' => __('Seller', 'sutore-marketplace'),
                'serviceFee' => __('Service fee', 'sutore-marketplace'),
                'guaranteeFee' => __('Guarantee fee', 'sutore-marketplace'),
                'priceDifference' => __('Price difference', 'sutore-marketplace'),
                'selectedProduct' => __('Selected product', 'sutore-marketplace'),
                'confirmChange' => __('Confirm change', 'sutore-marketplace'),
                'subtotal' => __('Subtotal', 'sutore-marketplace'),
                'shipping' => __('Shipping', 'sutore-marketplace'),
                'discount' => __('Discount', 'sutore-marketplace'),
                'paymentMethod' => __('Payment method', 'sutore-marketplace'),
                'customerNote' => __('Customer note', 'sutore-marketplace'),
                'detail' => __('Detail', 'sutore-marketplace'),
                'details' => __('Details', 'sutore-marketplace'),
                'products' => __('Products', 'sutore-marketplace'),
                'customer' => __('Customer', 'sutore-marketplace'),
                'billing' => __('Billing', 'sutore-marketplace'),
                'shippingAddress' => __('Shipping', 'sutore-marketplace'),
                'billingShippingAddress' => __('Billing & shipping address', 'sutore-marketplace'),
                'noAddress' => __('No address.', 'sutore-marketplace'),
                'noProducts' => __('No products on this order.', 'sutore-marketplace'),
                'noListing' => __('Not a marketplace product', 'sutore-marketplace'),
                'viewCustomerInvoice' => __('View customer invoice', 'sutore-marketplace'),
                'viewSellerInvoice' => __('View seller invoice', 'sutore-marketplace'),
                'openListingDetail' => __('Open product detail', 'sutore-marketplace'),
                'openSellerDetail' => __('Open seller detail', 'sutore-marketplace'),
                'updateStatus' => __('Update status', 'sutore-marketplace'),
                'updateOrder' => __('Update order', 'sutore-marketplace'),
                'doneEditing' => __('Done', 'sutore-marketplace'),
                'addProduct' => __('Add product', 'sutore-marketplace'),
                'searchListing' => __('Search product', 'sutore-marketplace'),
                'searchReplacement' => __('Search replacement', 'sutore-marketplace'),
                'searchListingPlaceholder' => __('Product, seller, variation ID…', 'sutore-marketplace'),
                'removeFromOrder' => __('Remove', 'sutore-marketplace'),
                'replacedByStaff' => __('Replaced by staff.', 'sutore-marketplace'),
                'confirmRemoveItem' => __('Remove this product from the order?', 'sutore-marketplace'),
                'confirmOrderUpdate' => __('Confirm order update', 'sutore-marketplace'),
                'confirmUpdate' => __('Confirm update', 'sutore-marketplace'),
                'noPendingChanges' => __('No changes to apply.', 'sutore-marketplace'),
                'willAddOne' => __('%d product will be added', 'sutore-marketplace'),
                'willAddMany' => __('%d products will be added', 'sutore-marketplace'),
                'willDetachOne' => __('%d product will be detached', 'sutore-marketplace'),
                'willDetachMany' => __('%d products will be detached', 'sutore-marketplace'),
                'willRemoveOne' => __('%d product will be removed', 'sutore-marketplace'),
                'willRemoveMany' => __('%d products will be removed', 'sutore-marketplace'),
                'willChangeStatus' => __('Status will change to %s', 'sutore-marketplace'),
                'pendingAdd' => __('To be added', 'sutore-marketplace'),
                'pendingDetach' => __('To be detached', 'sutore-marketplace'),
                'pendingRemove' => __('To be removed', 'sutore-marketplace'),
                'updateProduct' => __('Update product', 'sutore-marketplace'),
                'replacesProduct' => __('Replaces: %s', 'sutore-marketplace'),
                'undo' => __('Undo', 'sutore-marketplace'),
                'orderTotals' => __('Order totals', 'sutore-marketplace'),
                'estimatedTotals' => __('Estimated', 'sutore-marketplace'),
                'coupon' => __('Coupon', 'sutore-marketplace'),
                'fee' => __('Fee', 'sutore-marketplace'),
                'tax' => __('Tax', 'sutore-marketplace'),
                'changeStatus' => __('Change status', 'sutore-marketplace'),
                'changeProduct' => __('Change', 'sutore-marketplace'),
                'detach' => __('Detach from order', 'sutore-marketplace'),
                'actions' => __('Actions', 'sutore-marketplace'),
                'selectReplacement' => __('Select a replacement product.', 'sutore-marketplace'),
                'noCandidates' => __('No eligible products found.', 'sutore-marketplace'),
                'noPriceDiff' => __('No price difference.', 'sutore-marketplace'),
                'priceHigher' => __('Replacement is %s higher.', 'sutore-marketplace'),
                'priceLower' => __('Replacement is %s lower.', 'sutore-marketplace'),
                'differentProductNoteRequired' => __('A staff note is required when replacing with a different product.', 'sutore-marketplace'),
                'staffNoteRequired' => __('A staff note is required for this action.', 'sutore-marketplace'),
                'returnToQueue' => __('Return detached product to the sale queue', 'sutore-marketplace'),
                'returnToQueueHint' => __('If eligible, the winner algorithm may put it back on sale.', 'sutore-marketplace'),
                'replaceDetachHint' => __('The current product will be detached from the order.', 'sutore-marketplace'),
                'confirmAction' => __('Confirm', 'sutore-marketplace'),
                'orderUpdated' => __('Order updated.', 'sutore-marketplace'),
                'bulkActions' => __('Bulk actions', 'sutore-marketplace'),
                'bulkConfirm' => __('Update status for %d orders?', 'sutore-marketplace'),
                'apply' => __('Apply', 'sutore-marketplace'),
                'selectAll' => __('Select all', 'sutore-marketplace'),
                'selectedCount' => __('%d selected', 'sutore-marketplace'),
                'pagination' => __('Pagination', 'sutore-marketplace'),
                'previous' => __('Previous', 'sutore-marketplace'),
                'next' => __('Next', 'sutore-marketplace'),
                'pageOf' => __('Page %1$d / %2$d', 'sutore-marketplace'),
            ],
        ]);
    }

    /**
     * Seller detail modal (shared by Sellers, Manage Orders, Manage Products).
     * Opens in-place over the current staff page without navigation.
     */
    public function enqueueStaffMerchantDetail(): void
    {
        if (!\SutoreMarketplace\Admin\StaffCapabilities::canManageOps()) {
            return;
        }

        if (!wp_script_is(self::SCRIPT_STAFF_MERCHANTS, 'registered')) {
            $this->registerAssets();
        }

        if (wp_script_is(self::SCRIPT_STAFF_MERCHANTS, 'enqueued')) {
            return;
        }

        wp_enqueue_style(self::STYLE_STAFF_MERCHANTS);
        if (!wp_script_is(self::SCRIPT_CORE, 'enqueued')) {
            wp_enqueue_script(self::SCRIPT_CORE);
            $this->localizeCore([
                'cancel' => __('Cancel', 'sutore-marketplace'),
                'close' => __('Close', 'sutore-marketplace'),
                'error' => __('Error', 'sutore-marketplace'),
                'ok' => __('OK', 'sutore-marketplace'),
                'yes' => __('Yes', 'sutore-marketplace'),
            ]);
        } else {
            wp_enqueue_script(self::SCRIPT_CORE);
        }
        wp_enqueue_script(self::SCRIPT_STAFF_MERCHANTS);
        wp_localize_script(self::SCRIPT_STAFF_MERCHANTS, 'SutoreMarketplaceStaffMerchants', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'merchantsUrl' => esc_url_raw(wc_get_account_endpoint_url(StaffAccount::ENDPOINT_MERCHANTS)),
            'manageProductsUrl' => esc_url_raw(wc_get_account_endpoint_url(StaffAccount::ENDPOINT_MANAGE_PRODUCTS)),
            'i18n' => [
                'loading' => __('Loading…', 'sutore-marketplace'),
                'error' => __('Error', 'sutore-marketplace'),
                'ok' => __('OK', 'sutore-marketplace'),
                'confirm' => __('Confirm', 'sutore-marketplace'),
                'close' => __('Close', 'sutore-marketplace'),
                'notFound' => __('Seller not found.', 'sutore-marketplace'),
                'noRecords' => __('No sellers found.', 'sutore-marketplace'),
                'search' => __('Search', 'sutore-marketplace'),
                'searchPlaceholder' => __('First name, last name, email, phone, ID…', 'sutore-marketplace'),
                'openListingDetail' => __('Open product detail', 'sutore-marketplace'),
                'level' => __('Level', 'sutore-marketplace'),
                'allLevels' => __('All levels', 'sutore-marketplace'),
                'filter' => __('Filter', 'sutore-marketplace'),
                'resetFilters' => __('Reset', 'sutore-marketplace'),
                'seller' => __('Seller', 'sutore-marketplace'),
                'email' => __('Email', 'sutore-marketplace'),
                'phone' => __('Phone', 'sutore-marketplace'),
                'listings' => __('Products', 'sutore-marketplace'),
                'sold' => __('Sold', 'sutore-marketplace'),
                'pendingBalance' => __('Pending balance', 'sutore-marketplace'),
                'paidTotal' => __('Paid total', 'sutore-marketplace'),
                'tc' => __('TC identity', 'sutore-marketplace'),
                'tcVerified' => __('TC verified', 'sutore-marketplace'),
                'tcNotVerified' => __('TC not verified', 'sutore-marketplace'),
                'allTc' => __('All TC statuses', 'sutore-marketplace'),
                'restricted' => __('Restricted', 'sutore-marketplace'),
                'allRestrictions' => __('All restriction statuses', 'sutore-marketplace'),
                'balanceFilter' => __('Balance', 'sutore-marketplace'),
                'allBalances' => __('All balances', 'sutore-marketplace'),
                'hasPendingBalance' => __('Has pending balance', 'sutore-marketplace'),
                'noPendingBalance' => __('No pending balance', 'sutore-marketplace'),
                'hasPaidBalance' => __('Has paid total', 'sutore-marketplace'),
                'salesFilter' => __('Sales', 'sutore-marketplace'),
                'allSales' => __('All sales', 'sutore-marketplace'),
                'hasSales' => __('Has sales', 'sutore-marketplace'),
                'noSales' => __('No sales', 'sutore-marketplace'),
                'sortBy' => __('Sort by', 'sutore-marketplace'),
                'sortNewest' => __('Newest first', 'sutore-marketplace'),
                'sortName' => __('Name A–Z', 'sutore-marketplace'),
                'sortPending' => __('Pending balance', 'sutore-marketplace'),
                'sortPaid' => __('Paid total', 'sutore-marketplace'),
                'sortSold' => __('Sold count', 'sutore-marketplace'),
                'detail' => __('Detail', 'sutore-marketplace'),
                'pagination' => __('Pagination', 'sutore-marketplace'),
                'previous' => __('Previous', 'sutore-marketplace'),
                'next' => __('Next', 'sutore-marketplace'),
                'pageOf' => __('Page %1$d / %2$d', 'sutore-marketplace'),
                'profile' => __('Profile', 'sutore-marketplace'),
                'profileDesc' => __('Edit seller profile fields. Sensitive changes are recorded in activity history.', 'sutore-marketplace'),
                'balance' => __('Balance', 'sutore-marketplace'),
                'activity' => __('Activity', 'sutore-marketplace'),
                'activityHistory' => __('Activity history', 'sutore-marketplace'),
                'activityHistoryDesc' => __('Profile, level, and restriction changes for this seller.', 'sutore-marketplace'),
                'details' => __('Details', 'sutore-marketplace'),
                'noActivity' => __('No activity recorded yet.', 'sutore-marketplace'),
                'restrictions' => __('Restrictions', 'sutore-marketplace'),
                'restrictionStatus' => __('Restrictions', 'sutore-marketplace'),
                'noActiveRestriction' => __('None', 'sutore-marketplace'),
                'recentPayouts' => __('Recent payouts', 'sutore-marketplace'),
                'recentPayoutsDesc' => __('Latest payout lines for this seller.', 'sutore-marketplace'),
                'actions' => __('Actions', 'sutore-marketplace'),
                'actionsDesc' => __('Update seller level, commission overrides, and account restrictions.', 'sutore-marketplace'),
                'type' => __('Type', 'sutore-marketplace'),
                'save' => __('Save', 'sutore-marketplace'),
                'saved' => __('Saved', 'sutore-marketplace'),
                'firstName' => __('Account Holder First Name', 'sutore-marketplace'),
                'lastName' => __('Account Holder Last Name', 'sutore-marketplace'),
                'iban' => __('IBAN', 'sutore-marketplace'),
                'tckno' => __('TC Identity Number', 'sutore-marketplace'),
                'birthYear' => __('Year of Birth', 'sutore-marketplace'),
                'city' => __('City', 'sutore-marketplace'),
                'district' => __('District', 'sutore-marketplace'),
                'pickDistrict' => __('Select', 'sutore-marketplace'),
                'emailAddress' => __('Email Address', 'sutore-marketplace'),
                'phoneNumber' => __('Phone Number', 'sutore-marketplace'),
                'updateLevel' => __('Update level', 'sutore-marketplace'),
                'markTcVerified' => __('Mark TC as verified', 'sutore-marketplace'),
                'addRestriction' => __('Add restriction', 'sutore-marketplace'),
                'deactivate' => __('Remove restriction', 'sutore-marketplace'),
                'deactivateConfirm' => __('Remove this restriction?', 'sutore-marketplace'),
                'removing' => __('Removing…', 'sutore-marketplace'),
                'reason' => __('Reason', 'sutore-marketplace'),
                'key' => __('Key', 'sutore-marketplace'),
                'expiresAt' => __('Expires at', 'sutore-marketplace'),
                'expiresAtHelp' => __('Optional. Leave empty for no end date.', 'sutore-marketplace'),
                'startsAt' => __('Starts at', 'sutore-marketplace'),
                'startsAtHelp' => __('Optional. Leave empty to start immediately.', 'sutore-marketplace'),
                'noExpiry' => __('No end date', 'sutore-marketplace'),
                'scheduled' => __('Scheduled', 'sutore-marketplace'),
                'adjustment' => __('Rate type', 'sutore-marketplace'),
                'adjustmentAbsolute' => __('Absolute rate', 'sutore-marketplace'),
                'adjustmentPercentOff' => __('Percent off current rate', 'sutore-marketplace'),
                'adjustmentPointsOff' => __('Points off current rate', 'sutore-marketplace'),
                'raisesLevelWarn' => __('This rate is higher than the seller level and will increase commission.', 'sutore-marketplace'),
                'platformCampaigns' => __('Platform commission campaigns', 'sutore-marketplace'),
                'platformCampaignsHelp' => __('One record applies to every seller. Relative discounts follow each seller’s level rate.', 'sutore-marketplace'),
                'noPlatformCampaigns' => __('No platform commission campaigns.', 'sutore-marketplace'),
                'addPlatformCampaign' => __('Set platform commission campaign', 'sutore-marketplace'),
                'allSellers' => __('All sellers', 'sutore-marketplace'),
                'commissionValue' => __('Value', 'sutore-marketplace'),
                'noExpiry' => __('No end date', 'sutore-marketplace'),
                'active' => __('Active', 'sutore-marketplace'),
                'inactive' => __('Inactive', 'sutore-marketplace'),
                'expired' => __('Expired', 'sutore-marketplace'),
                'date' => __('Date', 'sutore-marketplace'),
                'event' => __('Event', 'sutore-marketplace'),
                'actor' => __('Actor', 'sutore-marketplace'),
                'summary' => __('Summary', 'sutore-marketplace'),
                'noPayouts' => __('No payout lines yet.', 'sutore-marketplace'),
                'noRestrictions' => __('No restrictions.', 'sutore-marketplace'),
                'commission' => __('Commission', 'sutore-marketplace'),
                'levelCommission' => __('Level', 'sutore-marketplace'),
                'commissionOverrides' => __('Commission overrides', 'sutore-marketplace'),
                'noCommissionOverrides' => __('No active commission overrides.', 'sutore-marketplace'),
                'commissionPercent' => __('Commission %', 'sutore-marketplace'),
                'addCommissionOverride' => __('Set commission override', 'sutore-marketplace'),
                'deleteOverride' => __('Delete', 'sutore-marketplace'),
                'deleteOverrideConfirm' => __('Delete this commission override?', 'sutore-marketplace'),
                'deleting' => __('Deleting…', 'sutore-marketplace'),
                'source' => __('Source', 'sutore-marketplace'),
                'inviteCode' => __('Invite code', 'sutore-marketplace'),
                'referredBy' => __('Referred by', 'sutore-marketplace'),
                'referralRewarded' => __('Referral rewarded', 'sutore-marketplace'),
                'note' => __('Note', 'sutore-marketplace'),
                'login' => __('Username', 'sutore-marketplace'),
                'userId' => __('User ID', 'sutore-marketplace'),
                'registered' => __('Registered', 'sutore-marketplace'),
                'product' => __('Product', 'sutore-marketplace'),
                'amount' => __('Amount', 'sutore-marketplace'),
                'status' => __('Status', 'sutore-marketplace'),
                'saving' => __('Saving…', 'sutore-marketplace'),
                'listingCreateBan' => __('Ban creating products', 'sutore-marketplace'),
                'priceUpdateBan' => __('Ban price updates', 'sutore-marketplace'),
                'disabledAccount' => __('Disable account', 'sutore-marketplace'),
            ],
        ]);
    }

    public function enqueueStaffMerchants(): void
    {
        $this->enqueueStaffMerchantDetail();
        $this->enqueueStaffProductDetail();
    }

    public function enqueueStaffCatalogRequests(): void
    {
        if (!\SutoreMarketplace\Admin\StaffCapabilities::canManageOps()) {
            return;
        }

        if (!wp_script_is(self::SCRIPT_STAFF_CATALOG_REQUESTS, 'registered')) {
            $this->registerAssets();
        }

        wp_enqueue_style(self::STYLE_STAFF_MANAGE);
        wp_enqueue_style(self::STYLE_LISTINGS);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore(array_merge($this->commonI18n(), [
            'noRecords' => __('No catalog requests.', 'sutore-marketplace'),
            'previous' => __('Previous', 'sutore-marketplace'),
            'next' => __('Next', 'sutore-marketplace'),
            'pageOf' => __('Page %1$d / %2$d', 'sutore-marketplace'),
            'seller' => __('Seller', 'sutore-marketplace'),
            'size' => __('Size', 'sutore-marketplace'),
            'catalogRequestSku' => __('SKU or product link', 'sutore-marketplace'),
            'catalogRequestNote' => __('Note', 'sutore-marketplace'),
            'catalogRequestFulfill' => __('Mark added', 'sutore-marketplace'),
            'catalogRequestReject' => __('Decline', 'sutore-marketplace'),
            'catalogRequestFulfillTitle' => __('Mark this product as added to the catalog?', 'sutore-marketplace'),
            'catalogRequestFulfillText' => __('The seller will be notified that they can open a product. Optionally search and link the WooCommerce catalog product.', 'sutore-marketplace'),
            'catalogRequestParentSearch' => __('Catalog product (optional)', 'sutore-marketplace'),
            'searchNameOrSku' => __('Search by product name or SKU…', 'sutore-marketplace'),
            'noMatchingProducts' => __('No matching products.', 'sutore-marketplace'),
            'catalogRequestRejectTitle' => __('Decline this catalog request?', 'sutore-marketplace'),
            'catalogRequestRejectText' => __('The seller will be notified. You can include a short reason.', 'sutore-marketplace'),
            'catalogRequestStaffNote' => __('Note to seller (optional)', 'sutore-marketplace'),
            'updated' => __('Updated.', 'sutore-marketplace'),
        ]));
        wp_enqueue_script(self::SCRIPT_STAFF_CATALOG_REQUESTS);
    }

    public function enqueueStaffManageProducts(): void
    {
        if (!\SutoreMarketplace\Admin\StaffCapabilities::canManageOps()) {
            return;
        }

        if (!wp_script_is(self::SCRIPT_STAFF_MANAGE, 'registered')) {
            $this->registerAssets();
        }

        wp_enqueue_style(self::STYLE_STAFF_MANAGE);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore(array_merge(
            $this->listingsI18n(),
            $this->bulkListingsI18n(),
            [
                'selectSeller' => __('Select a seller.', 'sutore-marketplace'),
                'sellerRequired' => __('Select a seller before continuing.', 'sutore-marketplace'),
                'searchSeller' => __('Search seller by name, email, ID…', 'sutore-marketplace'),
                'selectedSeller' => __('Selected seller: %s', 'sutore-marketplace'),
                'noSellersFound' => __('No sellers found.', 'sutore-marketplace'),
                'seller' => __('Seller', 'sutore-marketplace'),
            ]
        ));
        wp_enqueue_script(self::SCRIPT_LISTINGS);
        wp_enqueue_script(self::SCRIPT_LISTINGS_BULK);
        $this->enqueueStaffMerchantDetail();
        $this->enqueueStaffProductDetail();
        $this->enqueueStaffOrderDetail();
    }

    /**
     * Product (fulfillment) detail modal — shared by Manage Products and Manage Orders.
     */
    public function enqueueStaffProductDetail(): void
    {
        if (!\SutoreMarketplace\Admin\StaffCapabilities::canManageOps()) {
            return;
        }

        if (!wp_script_is(self::SCRIPT_STAFF_MANAGE, 'registered')) {
            $this->registerAssets();
        }

        if (wp_script_is(self::SCRIPT_STAFF_MANAGE, 'enqueued')) {
            return;
        }

        wp_enqueue_style(self::STYLE_STAFF_MANAGE);
        wp_enqueue_script(self::SCRIPT_CORE);
        if (!wp_script_is(self::SCRIPT_LISTINGS, 'enqueued')) {
            wp_enqueue_script(self::SCRIPT_LISTINGS);
        }
        if (!wp_script_is(self::SCRIPT_LISTINGS_BULK, 'enqueued')) {
            wp_enqueue_script(self::SCRIPT_LISTINGS_BULK);
        }
        wp_enqueue_script(self::SCRIPT_STAFF_MANAGE);
        wp_localize_script(self::SCRIPT_STAFF_MANAGE, 'SutoreMarketplaceStaff', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'manageProductsUrl' => esc_url_raw(wc_get_account_endpoint_url(StaffAccount::ENDPOINT_MANAGE_PRODUCTS)),
            'manageOrdersUrl' => esc_url_raw(wc_get_account_endpoint_url(StaffAccount::ENDPOINT_MANAGE_ORDERS)),
            'merchantsUrl' => esc_url_raw(wc_get_account_endpoint_url(StaffAccount::ENDPOINT_MERCHANTS)),
            'i18n' => [
                'loading' => __('Loading…', 'sutore-marketplace'),
                'error' => __('Error', 'sutore-marketplace'),
                'notFound' => __('Record not found.', 'sutore-marketplace'),
                'openSellerDetail' => __('Open seller detail', 'sutore-marketplace'),
                'openListingDetail' => __('Open product detail', 'sutore-marketplace'),
                'openOrderDetail' => __('Open order detail', 'sutore-marketplace'),
                'confirmPayment' => __('Are you sure you want to confirm this sale? The seller will be notified.', 'sutore-marketplace'),
                'confirmDelete' => __('Are you sure you want to permanently remove this product?', 'sutore-marketplace'),
                'confirmDetach' => __('Will be unlinked from the order. Continue?', 'sutore-marketplace'),
                'transactionTitle' => __('Transaction #%d', 'sutore-marketplace'),
                'product' => __('Product', 'sutore-marketplace'),
                'listingStatus' => __('Status', 'sutore-marketplace'),
                'fulfillmentStatus' => __('Status', 'sutore-marketplace'),
                'order' => __('Order', 'sutore-marketplace'),
                'seller' => __('Seller', 'sutore-marketplace'),
                'listing' => __('Product', 'sutore-marketplace'),
                'variationId' => __('Variation ID', 'sutore-marketplace'),
                'parentProductId' => __('Parent product ID', 'sutore-marketplace'),
                'createdAt' => __('Created at', 'sutore-marketplace'),
                'price' => __('Price', 'sutore-marketplace'),
                'paymentStatus' => __('Payment status', 'sutore-marketplace'),
                'payoutNotCreated' => __('Not created yet', 'sutore-marketplace'),
                'invoice' => __('Invoice', 'sutore-marketplace'),
                'invoiceError' => __('Invoice error', 'sutore-marketplace'),
                'viewInvoice' => __('View invoice', 'sutore-marketplace'),
                'viewCustomerInvoice' => __('View customer invoice', 'sutore-marketplace'),
                'viewSellerInvoice' => __('View seller invoice', 'sutore-marketplace'),
                'openPdf' => __('Open PDF', 'sutore-marketplace'),
                'campaign' => __('Campaign', 'sutore-marketplace'),
                'campaignActiveTag' => __('On campaign', 'sutore-marketplace'),
                'campaignOfferTag' => __('Campaign offer', 'sutore-marketplace'),
                'preOrder' => __('Pre-order', 'sutore-marketplace'),
                'preOrderProduct' => __('Pre-order', 'sutore-marketplace'),
                'shipmentType' => __('Shipment type', 'sutore-marketplace'),
                'customerShipping' => __('Customer shipping', 'sutore-marketplace'),
                'shippingStandard' => __('Standard', 'sutore-marketplace'),
                'expressShipping' => __('Fast / Express', 'sutore-marketplace'),
                'internationalShipping' => __('International', 'sutore-marketplace'),
                'imported' => __('Imported', 'sutore-marketplace'),
                'importedProduct' => __('Imported', 'sutore-marketplace'),
                'close' => __('Close', 'sutore-marketplace'),
                'shippingDetails' => __('Shipping details', 'sutore-marketplace'),
                'sellerTracking' => __('Seller shipping tracking number', 'sutore-marketplace'),
                'sutoreTracking' => __('Sutore shipping tracking number', 'sutore-marketplace'),
                'sellerShippedAt' => __('Seller shipping date', 'sutore-marketplace'),
                'sutoreShippedAt' => __('Shipped to customer date', 'sutore-marketplace'),
                'deliveredAt' => __('Delivered to customer', 'sutore-marketplace'),
                'noShippingDetails' => __('No shipping details for this product yet.', 'sutore-marketplace'),
                'sellerPaymentDetails' => __('Seller payment details (at sale)', 'sutore-marketplace'),
                'noPaymentDetails' => __('No payment details were recorded at sale time.', 'sutore-marketplace'),
                'accountHolder' => __('Account holder', 'sutore-marketplace'),
                'iban' => __('IBAN', 'sutore-marketplace'),
                'tc' => __('TC Identity Number', 'sutore-marketplace'),
                'city' => __('City', 'sutore-marketplace'),
                'birthYear' => __('Year of Birth', 'sutore-marketplace'),
                'phone' => __('Phone Number', 'sutore-marketplace'),
                'email' => __('Email Address', 'sutore-marketplace'),
                'recordedAt' => __('Recorded at', 'sutore-marketplace'),
                'actions' => __('Actions', 'sutore-marketplace'),
                'noActions' => __('No further actions are available for this product status.', 'sutore-marketplace'),
                'staffNote' => __('Staff note (visible to merchant)', 'sutore-marketplace'),
                'staffNotePlaceholder' => __('Explain why this action is taken…', 'sutore-marketplace'),
                'staffNoteRequired' => __('A staff note is required for this action.', 'sutore-marketplace'),
                'returnToQueue' => __('Return detached product to the sale queue', 'sutore-marketplace'),
                'returnToQueueHint' => __('If eligible, the winner algorithm may put it back on sale.', 'sutore-marketplace'),
                'putOnSale' => __('Put on sale', 'sutore-marketplace'),
                'approveListing' => __('Approve & put on sale', 'sutore-marketplace'),
                'approveListingConfirm' => __('Approve this product and put it on sale for customers?', 'sutore-marketplace'),
                'sendCampaignOffer' => __('Send campaign offer', 'sutore-marketplace'),
                'sendCampaignOfferConfirm' => __('Choose a campaign to send an offer to this seller for this product.', 'sutore-marketplace'),
                'sendCampaignOfferBulkConfirm' => __('Choose a campaign to send an offer to the selected products.', 'sutore-marketplace'),
                'selectCampaign' => __('Campaign', 'sutore-marketplace'),
                'selectCampaignPlaceholder' => __('Select a campaign…', 'sutore-marketplace'),
                'noSendableCampaigns' => __('No sendable campaigns found. Create one with start and end dates first.', 'sutore-marketplace'),
                'campaignOfferSent' => __('Campaign offer sent.', 'sutore-marketplace'),
                'delete' => __('Delete', 'sutore-marketplace'),
                'confirmSale' => __('Confirm Sale', 'sutore-marketplace'),
                'newListingId' => __('New product ID (swap)', 'sutore-marketplace'),
                'replacementListing' => __('Replacement product', 'sutore-marketplace'),
                'selectReplacementListing' => __('Select a replacement product…', 'sutore-marketplace'),
                'changeSeller' => __('Change Seller', 'sutore-marketplace'),
                'changeSellerConfirm' => __('Replace this sale with another eligible product. Same product is listed by default; search to pick a different product.', 'sutore-marketplace'),
                'searchDifferentProduct' => __('Search a different product…', 'sutore-marketplace'),
                'searchOtherOrder' => __('Search another order…', 'sutore-marketplace'),
                'showDefaultMatches' => __('Show default matches', 'sutore-marketplace'),
                'enterSearchTerm' => __('Enter a search term.', 'sutore-marketplace'),
                'noSwapCandidates' => __('No eligible replacement products found.', 'sutore-marketplace'),
                'differentProductNoteRequired' => __('A staff note is required when replacing with a different product.', 'sutore-marketplace'),
                'attachToOrder' => __('Add to order', 'sutore-marketplace'),
                'attachToOrderConfirm' => __('Add this product to an order. Paid orders start as sold (seller is notified). Unpaid pending/on-hold orders wait for payment confirmation — no sold SMS.', 'sutore-marketplace'),
                'selectOrder' => __('Processing order', 'sutore-marketplace'),
                'selectOrderPlaceholder' => __('Select a processing order…', 'sutore-marketplace'),
                'noProcessingOrders' => __('No processing orders found.', 'sutore-marketplace'),
                'detach' => __('Detach from Order', 'sutore-marketplace'),
                'markPreOrder' => __('Mark as pre-order', 'sutore-marketplace'),
                'markPreOrderConfirm' => __('Open this sale on the pre-order board for other merchants. Continue?', 'sutore-marketplace'),
                'closePreOrder' => __('Could not be sourced', 'sutore-marketplace'),
                'closePreOrderConfirm' => __('Mark this pre-order as could not be sourced, detach it from the order, and refund the line if the order is paid. Continue?', 'sutore-marketplace'),
                'sutoreShippingCode' => __('Sutore shipping code', 'sutore-marketplace'),
                'paymentRef' => __('Payment reference (EFT/receipt)', 'sutore-marketplace'),
                'payoutPaidAt' => __('Paid at', 'sutore-marketplace'),
                'markPaid' => __('Mark as Paid to Seller', 'sutore-marketplace'),
                'markPaidConfirm' => __('Record that the seller has been paid for this sale. Optional payment reference is stored on the payout line.', 'sutore-marketplace'),
                'adjustCommission' => __('Adjust commission', 'sutore-marketplace'),
                'adjustCommissionConfirm' => __('Set a new commission percent for this pending payout only. Net amount is recalculated from the sale price.', 'sutore-marketplace'),
                'commissionPercent' => __('Commission %', 'sutore-marketplace'),
                'save' => __('Save', 'sutore-marketplace'),
                'saved' => __('Saved', 'sutore-marketplace'),
                'saving' => __('Saving…', 'sutore-marketplace'),
                'listingCommission' => __('Product commission %', 'sutore-marketplace'),
                'listingCommissionHelp' => __('Optional. Leave empty for the normal seller rate. 0 means no commission on this product.', 'sutore-marketplace'),
                'listingCommissionClear' => __('Clear product rate', 'sutore-marketplace'),
                'saleLockedCommission' => __('Locked at sale', 'sutore-marketplace'),
                'liveCommission' => __('Live rate', 'sutore-marketplace'),
                'payoutCommission' => __('Payout commission', 'sutore-marketplace'),
                'levelCommission' => __('Level', 'sutore-marketplace'),
                'markImported' => __('Mark as imported', 'sutore-marketplace'),
                'unmarkImported' => __('Mark as not imported', 'sutore-marketplace'),
                'markArrived' => __('Arrived at Sutore', 'sutore-marketplace'),
                'markVerified' => __('Verify product', 'sutore-marketplace'),
                'markReady' => __('Ready to ship', 'sutore-marketplace'),
                'markShippedCustomer' => __('Ship to customer', 'sutore-marketplace'),
                'markDelivered' => __('Delivered to customer', 'sutore-marketplace'),
                'markNotForSale' => __('Not for sale', 'sutore-marketplace'),
                'markNotForSaleConfirm' => __('This sale will be taken off the order and the product will be marked as detached from order.', 'sutore-marketplace'),
                'chargeback' => __('Refund / chargeback', 'sutore-marketplace'),
                'chargebackConfirm' => __('This sale will be marked as refunded and any seller payout will be reversed.', 'sutore-marketplace'),
                'cancel' => __('Cancel', 'sutore-marketplace'),
                'moreActions' => __('More actions', 'sutore-marketplace'),
                'confirmAction' => __('Confirm', 'sutore-marketplace'),
                'fieldRequired' => __('This field is required.', 'sutore-marketplace'),
                'noPrimaryAction' => __('No next step for this status.', 'sutore-marketplace'),
                'activityHistory' => __('Activity history', 'sutore-marketplace'),
                'activityHistoryDesc' => __('All logged interventions for this product from creation through shipping.', 'sutore-marketplace'),
                'noActivity' => __('No activity recorded yet.', 'sutore-marketplace'),
                'date' => __('Date', 'sutore-marketplace'),
                'event' => __('Event', 'sutore-marketplace'),
                'actor' => __('Actor', 'sutore-marketplace'),
                'details' => __('Details', 'sutore-marketplace'),
                'status' => __('Status', 'sutore-marketplace'),
                'allStatuses' => __('All statuses', 'sutore-marketplace'),
                'allQueues' => __('All queues', 'sutore-marketplace'),
                'queue' => __('Queue', 'sutore-marketplace'),
                'filter' => __('Filter', 'sutore-marketplace'),
                'manage' => __('Detail', 'sutore-marketplace'),
                'detail' => __('Detail', 'sutore-marketplace'),
                'viewCustomerInvoice' => __('View customer invoice', 'sutore-marketplace'),
                'viewSellerInvoice' => __('View seller invoice', 'sutore-marketplace'),
                'noRecords' => __('No records.', 'sutore-marketplace'),
                'previous' => __('Previous', 'sutore-marketplace'),
                'next' => __('Next', 'sutore-marketplace'),
                'pageOf' => __('Page %1$d / %2$d', 'sutore-marketplace'),
                'pagination' => __('Pagination', 'sutore-marketplace'),
                'bulkActions' => __('Bulk actions', 'sutore-marketplace'),
                'apply' => __('Apply', 'sutore-marketplace'),
                'selectAll' => __('Select all', 'sutore-marketplace'),
                'selectedCount' => __('%d selected', 'sutore-marketplace'),
                'noCommonBulkActions' => __('No common actions for this selection', 'sutore-marketplace'),
                'bulkConfirm' => __('Apply “%1$s” to %2$d selected products?', 'sutore-marketplace'),
                'bulkConfirmCount' => __('%d products will be updated.', 'sutore-marketplace'),
                'removeFromSale' => __('Remove from sale', 'sutore-marketplace'),
                'removeFromSaleConfirm' => __('This product will be taken off sale and marked as not for sale.', 'sutore-marketplace'),
                'updated' => __('Updated.', 'sutore-marketplace'),
                'exportCsv' => __('Export CSV', 'sutore-marketplace'),
                'exportEmpty' => __('No payout rows to export for this filter.', 'sutore-marketplace'),
                'dueForPayout' => __('Due for payout', 'sutore-marketplace'),
                'soldFrom' => __('Sold from', 'sutore-marketplace'),
                'soldTo' => __('Sold to', 'sutore-marketplace'),
                'scheduledPayoutDate' => __('Scheduled payout date', 'sutore-marketplace'),
                'due' => __('Due', 'sutore-marketplace'),
                'sellerLevel' => __('Seller level', 'sutore-marketplace'),
                'bulkMarkPaidRef' => __('Optional. The same payment reference is stored on every selected payout.', 'sutore-marketplace'),
            ],
        ]);
    }

    /** @param array<string, string> $i18n */
    private function localizeCore(array $i18n): void
    {
        wp_localize_script(self::SCRIPT_CORE, 'SutoreMarketplace', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'homeUrl' => esc_url_raw(home_url('/')),
            'listingsUrl' => function_exists('wc_get_account_endpoint_url')
                ? esc_url_raw(wc_get_account_endpoint_url('listings'))
                : '',
            'sourcingUrl' => function_exists('wc_get_account_endpoint_url')
                ? esc_url_raw(wc_get_account_endpoint_url('sourcing'))
                : '',
            'campaignOffersUrl' => function_exists('wc_get_account_endpoint_url')
                ? esc_url_raw(wc_get_account_endpoint_url('campaign-offers'))
                : '',
            'priceOffersUrl' => function_exists('wc_get_account_endpoint_url')
                ? esc_url_raw(wc_get_account_endpoint_url('price-offers'))
                : '',
            'myOffersUrl' => function_exists('wc_get_account_endpoint_url')
                ? esc_url_raw(wc_get_account_endpoint_url('my-offers'))
                : '',
            'priceStep' => Settings::listingPriceStep(),
            'campaignStart' => CampaignGuardrails::toArray(),
            'otpEnabled' => OtpSettings::isEnabled(),
            'otpUiTimer' => OtpSettings::uiTimerSeconds(),
            'i18n' => $i18n,
        ]);
    }

    /** @return array<string, string> */
    private function commonI18n(): array
    {
        return [
            'cancel' => __('Cancel', 'sutore-marketplace'),
            'yes' => __('Yes', 'sutore-marketplace'),
            'ok' => __('OK', 'sutore-marketplace'),
            'close' => __('Close', 'sutore-marketplace'),
            'loading' => __('Loading…', 'sutore-marketplace'),
            'error' => __('Error', 'sutore-marketplace'),
            'emptyResponse' => __('Empty response', 'sutore-marketplace'),
            'restRouteMissing' => __('REST route missing', 'sutore-marketplace'),
            'size' => __('Size', 'sutore-marketplace'),
            'color' => __('Color', 'sutore-marketplace'),
            'variation' => __('Variation', 'sutore-marketplace'),
            'otpTitle' => __('SMS verification', 'sutore-marketplace'),
            'otpPromptPrefix' => __('Enter the verification code sent to your phone. Time remaining:', 'sutore-marketplace'),
            'otpSecondsSuffix' => __('sec.', 'sutore-marketplace'),
            'otpPlaceholder' => __('Verification code', 'sutore-marketplace'),
            'otpConfirm' => __('Verify', 'sutore-marketplace'),
            'otpSending' => __('Sending verification code…', 'sutore-marketplace'),
            'otpNewPhoneSending' => __('Sending a code to your new phone…', 'sutore-marketplace'),
            'otpMaskedPhone' => __('Code sent to %s', 'sutore-marketplace'),
            'otpDebugLabel' => __('Test code (simulation):', 'sutore-marketplace'),
        ];
    }

    /** @return array<string, string> */
    private function listingsI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'confirmDelete' => __('Are you sure you want to permanently remove this product?', 'sutore-marketplace'),
            'deleteTitle' => __('Delete this product?', 'sutore-marketplace'),
            'cannotDelete' => __('This product cannot be deleted right now. (In order or payment process.)', 'sutore-marketplace'),
            'notFound' => __('The product you searched for was not found', 'sutore-marketplace'),
            'catalogRequestTitle' => __('Request this product', 'sutore-marketplace'),
            'catalogRequestLead' => __('Leave the SKU or a product link, the size, and a short note. We will notify you when it is added to the catalog.', 'sutore-marketplace'),
            'catalogRequestSku' => __('SKU or product link', 'sutore-marketplace'),
            'catalogRequestSize' => __('Size', 'sutore-marketplace'),
            'catalogRequestNote' => __('Short note', 'sutore-marketplace'),
            'catalogRequestSubmit' => __('Send request', 'sutore-marketplace'),
            'catalogRequestSending' => __('Sending…', 'sutore-marketplace'),
            'catalogRequestLevel' => __('Catalog product requests require Confirmed seller level.', 'sutore-marketplace'),
            'catalogRequestSkuRequired' => __('Enter a product SKU or link.', 'sutore-marketplace'),
            'catalogRequestSizeRequired' => __('Enter a size.', 'sutore-marketplace'),
            'emptyListings' => __('You have not added a product yet.', 'sutore-marketplace'),
            'noResults' => __('No results found.', 'sutore-marketplace'),
            'manage' => __('Detail', 'sutore-marketplace'),
            'viewSellerInvoice' => __('View seller invoice', 'sutore-marketplace'),
            'viewCustomerInvoice' => __('View customer invoice', 'sutore-marketplace'),
            'moreActions' => __('More actions', 'sutore-marketplace'),
            'delete' => __('Delete', 'sutore-marketplace'),
            'addTitle' => __('Add Product', 'sutore-marketplace'),
            'editTitle' => __('Edit Product', 'sutore-marketplace'),
            'submit' => __('Submit', 'sutore-marketplace'),
            'update' => __('Update', 'sutore-marketplace'),
            'next' => __('Next', 'sutore-marketplace'),
            'previous' => __('Previous', 'sutore-marketplace'),
            'createSuccessTitle' => __('Product added', 'sutore-marketplace'),
            'createSuccessForSale' => __('Your product is now for sale.', 'sutore-marketplace'),
            'createSuccessQueued' => __('Your product was added to the queue for this size.', 'sutore-marketplace'),
            'createSuccessPending' => __('Your product was submitted and will go on sale after approval.', 'sutore-marketplace'),
            'wizardStepOf' => __('Step %1$d of %2$d', 'sutore-marketplace'),
            'wizardStepProduct' => __('Product', 'sutore-marketplace'),
            'wizardStepSize' => __('Size', 'sutore-marketplace'),
            'wizardStepVariation' => __('Variation', 'sutore-marketplace'),
            'wizardStepDetails' => __('Details', 'sutore-marketplace'),
            'wizardStepPrice' => __('Price', 'sutore-marketplace'),
            'listingDuration' => __('Sale duration', 'sutore-marketplace'),
            'durationPreview' => __('Expires in about %d days if saved now.', 'sutore-marketplace'),
            'durationDay' => __('%d day', 'sutore-marketplace'),
            'durationDays' => __('%d days', 'sutore-marketplace'),
            'saved' => __('Saved', 'sutore-marketplace'),
            'savedTitle' => __('Product updated', 'sutore-marketplace'),
            'editListing' => __('Edit Product', 'sutore-marketplace'),
            'pickProduct' => __('Select a product to continue.', 'sutore-marketplace'),
            'pickSize' => __('Select a size to continue.', 'sutore-marketplace'),
            'pickAxis' => __('Select a %s to continue.', 'sutore-marketplace'),
            'chooseAxisHint' => __('Choose the %s for this product.', 'sutore-marketplace'),
            'priceStepError' => __('Price must be in multiples of %d TL. Decimal prices are not allowed.', 'sutore-marketplace'),
            'priceRequired' => __('Enter a price.', 'sutore-marketplace'),
            'belowRetailWarn' => __('This product will go on sale below the product’s starting price (≈ %s TL).', 'sutore-marketplace'),
            'startingPrice' => __('Starting price', 'sutore-marketplace'),
            'lowestPrice' => __('Lowest Price', 'sutore-marketplace'),
            'currentQueue' => __('Current queue', 'sutore-marketplace'),
            'netEarnings' => __('Your net earnings', 'sutore-marketplace'),
            'sizePriceList' => __('Size price list', 'sutore-marketplace'),
            'sizePriceListEmpty' => __('No other product for sale or in queue for this size.', 'sutore-marketplace'),
            'firstPlaceAlertForSale' => __('At this price you will move to #1 — the product will be for sale.', 'sutore-marketplace'),
            'firstPlaceAlertAwaitingApproval' => __('At this price you will move to #1 — awaiting approval before going on sale.', 'sutore-marketplace'),
            'statusPublish' => __('For sale', 'sutore-marketplace'),
            'statusQueued' => __('In queue', 'sutore-marketplace'),
            'statusPending' => __('Awaiting approval', 'sutore-marketplace'),
            'statusExpired' => __('Expired', 'sutore-marketplace'),
            'statusNotSale' => __('Not for sale', 'sutore-marketplace'),
            'statusOrderDetached' => __('Detached from order / Could not be sourced', 'sutore-marketplace'),
            'statusSourcing' => __('Pre-order — awaiting order', 'sutore-marketplace'),
            'statusPayment' => __('Awaiting payment confirmation', 'sutore-marketplace'),
            'statusSold' => __('Awaiting merchant confirmation', 'sutore-marketplace'),
            'putOnSale' => __('Put on sale', 'sutore-marketplace'),
            'putOnSaleTitle' => __('Put this product back on sale?', 'sutore-marketplace'),
            'putOnSaleConfirm' => __('The product will re-enter the sale queue with a fresh expiry window.', 'sutore-marketplace'),
            'putOnSaleFailed' => __('This product cannot be put back on sale right now.', 'sutore-marketplace'),
            'putOnCampaign' => __('Put on campaign', 'sutore-marketplace'),
            'putOnCampaignTitle' => __('Put this product on campaign', 'sutore-marketplace'),
            'putOnCampaignHint' => __('Lowering the price is permanent and has no strikethrough. A campaign is timed and shows the previous price crossed out.', 'sutore-marketplace'),
            'putOnCampaignPercent' => __('Discount', 'sutore-marketplace'),
            'putOnCampaignDuration' => __('Duration', 'sutore-marketplace'),
            'putOnCampaignPreview' => __('Price %1$s TL → %2$s TL for %3$s days. Customers see a strikethrough until it ends.', 'sutore-marketplace'),
            'putOnCampaignSuccess' => __('Campaign started. Customers will see a strikethrough price until it ends.', 'sutore-marketplace'),
            'campaignCooldownUntil' => __('Campaign cooldown until', 'sutore-marketplace'),
            'campaignSource' => __('Source', 'sutore-marketplace'),
            'removeFromSale' => __('Remove from sale', 'sutore-marketplace'),
            'removeFromSaleTitle' => __('Remove this product from sale?', 'sutore-marketplace'),
            'removeFromSaleConfirm' => __('The product will leave the sale queue without being deleted.', 'sutore-marketplace'),
            'removeFromSaleFailed' => __('This product cannot be removed from sale right now.', 'sutore-marketplace'),
            'bulkActions' => __('Bulk actions', 'sutore-marketplace'),
            'apply' => __('Apply', 'sutore-marketplace'),
            'selectAll' => __('Select all', 'sutore-marketplace'),
            'selectListing' => __('Select product', 'sutore-marketplace'),
            'selectedCount' => __('%d selected', 'sutore-marketplace'),
            'noCommonBulkActions' => __('No common actions for this selection', 'sutore-marketplace'),
            'bulkUpdated' => __('%d products updated.', 'sutore-marketplace'),
            'bulkPutOnSaleTitle' => __('Put selected products on sale?', 'sutore-marketplace'),
            'bulkPutOnSaleConfirm' => __('Selected products will re-enter the sale queue with a fresh expiry window.', 'sutore-marketplace'),
            'bulkRemoveFromSaleTitle' => __('Remove selected products from sale?', 'sutore-marketplace'),
            'bulkRemoveFromSaleConfirm' => __('Selected products will leave the sale queue without being deleted.', 'sutore-marketplace'),
            'bulkDeleteTitle' => __('Delete selected products?', 'sutore-marketplace'),
            'bulkDeleteConfirm' => __('This cannot be undone for the selected products.', 'sutore-marketplace'),
            'bulkConfirmSaleTitle' => __('Confirm selected sales?', 'sutore-marketplace'),
            'bulkConfirmSaleConfirm' => __('Selected sales will be confirmed and shipping deadlines will start.', 'sutore-marketplace'),
            'confirmSale' => __('Confirm Sale', 'sutore-marketplace'),
            'returnWindowEnds' => __('Return / dispute window ends', 'sutore-marketplace'),
            'condNoBox' => __('No box', 'sutore-marketplace'),
            'condBoxDamaged' => __('Box damaged', 'sutore-marketplace'),
            'condMissingAccessory' => __('Missing accessory', 'sutore-marketplace'),
            'condDamaged' => __('Damaged', 'sutore-marketplace'),
            'expressShipping' => __('Fast / Express', 'sutore-marketplace'),
            'internationalShipping' => __('International', 'sutore-marketplace'),
            'condition' => __('Condition', 'sutore-marketplace'),
            'conditionNone' => __('No defects', 'sutore-marketplace'),
            'shippingOptions' => __('Shipping', 'sutore-marketplace'),
            'shippingStandard' => __('Standard', 'sutore-marketplace'),
            'preOrderProduct' => __('Pre-order', 'sutore-marketplace'),
            'regularProduct' => __('Regular product', 'sutore-marketplace'),
            'importedProduct' => __('Imported', 'sutore-marketplace'),
            'listingSource' => __('Product source', 'sutore-marketplace'),
            'expressIneligible' => __('You are not eligible for fast shipping.', 'sutore-marketplace'),
            'timeLeft' => __('Time remaining', 'sutore-marketplace'),
            'confirmTimeLeft' => __('Confirmation time remaining', 'sutore-marketplace'),
            'shipTimeLeft' => __('Shipping time remaining', 'sutore-marketplace'),
            'timeExpired' => __('Expired', 'sutore-marketplace'),
            'yourListing' => __('Yours', 'sutore-marketplace'),
            'currentListing' => __('This product', 'sutore-marketplace'),
            'saleFulfillment' => __('Sale & shipping', 'sutore-marketplace'),
            'saleTab' => __('Sale', 'sutore-marketplace'),
            'noSaleDetails' => __('No sale details for this product.', 'sutore-marketplace'),
            'saleDetails' => __('Sale details', 'sutore-marketplace'),
            'product' => __('Product', 'sutore-marketplace'),
            'productCode' => __('Product code', 'sutore-marketplace'),
            'listingStatus' => __('Status', 'sutore-marketplace'),
            'fulfillmentStatus' => __('Status', 'sutore-marketplace'),
            'listing' => __('Product', 'sutore-marketplace'),
            'price' => __('Price', 'sutore-marketplace'),
            'queue' => __('Queue', 'sutore-marketplace'),
            'status' => __('Status', 'sutore-marketplace'),
            'statusConfirmed' => __('Merchant confirmed', 'sutore-marketplace'),
            'statusShippedToSutore' => __('Shipped to Sutore', 'sutore-marketplace'),
            'statusArrivedToSutore' => __('Arrived at Sutore', 'sutore-marketplace'),
            'statusVerified' => __('Verified', 'sutore-marketplace'),
            'statusReadyToShipping' => __('Ready to ship', 'sutore-marketplace'),
            'statusShipped' => __('Shipped to customer', 'sutore-marketplace'),
            'statusDeliveredToCustomer' => __('Delivered to customer', 'sutore-marketplace'),
            'statusChargeback' => __('Refunded', 'sutore-marketplace'),
            'commission' => __('Commission', 'sutore-marketplace'),
            'estimatedPayout' => __('Estimated payout', 'sutore-marketplace'),
            'fulfillmentDetailsDesc' => __('Order and shipping deadlines for this sale.', 'sutore-marketplace'),
            'activityHistory' => __('Activity history', 'sutore-marketplace'),
            'activityHistoryDesc' => __('Product activity and status changes are shown chronologically.', 'sutore-marketplace'),
            'noActivity' => __('No activity recorded yet.', 'sutore-marketplace'),
            'date' => __('Date', 'sutore-marketplace'),
            'event' => __('Event', 'sutore-marketplace'),
            'actor' => __('Actor', 'sutore-marketplace'),
            'order' => __('Order', 'sutore-marketplace'),
            'confirmDeadline' => __('Confirmation deadline', 'sutore-marketplace'),
            'shipDeadline' => __('Shipping deadline', 'sutore-marketplace'),
            'sellerConfirmedAt' => __('Seller confirmation date', 'sutore-marketplace'),
            'sellerShippedAt' => __('Seller shipping date', 'sutore-marketplace'),
            'trackingNumber' => __('Tracking number', 'sutore-marketplace'),
            'merchantTrackingNumber' => __('Tracking to Sutore', 'sutore-marketplace'),
            'sutoreTrackingNumber' => __('Tracking to customer', 'sutore-marketplace'),
            'soldAt' => __('Sold at', 'sutore-marketplace'),
            'createdAt' => __('Created at', 'sutore-marketplace'),
            'deliveredAt' => __('Delivered to customer', 'sutore-marketplace'),
            'payoutStatus' => __('Payout status', 'sutore-marketplace'),
            'payoutPaidAt' => __('Paid at', 'sutore-marketplace'),
            'scheduledPayoutDate' => __('Scheduled payout date', 'sutore-marketplace'),
            'salePosition' => __('Sale position', 'sutore-marketplace'),
            'currentlyFirstForSale' => __('Currently #1 for sale', 'sutore-marketplace'),
            'campaign' => __('Campaign', 'sutore-marketplace'),
            'campaignOfferTag' => __('Campaign offer', 'sutore-marketplace'),
            'campaignActiveTag' => __('On campaign', 'sutore-marketplace'),
            'reviewCampaignOffer' => __('Review offer', 'sutore-marketplace'),
            'campaignEndsAt' => __('Campaign ends', 'sutore-marketplace'),
            'campaignStartsAt' => __('Campaign starts', 'sutore-marketplace'),
            'campaignSellerDiscount' => __('Your discount', 'sutore-marketplace'),
            'campaignPlatformDiscount' => __('Platform discount', 'sutore-marketplace'),
            'campaignCustomerPrice' => __('Customer price', 'sutore-marketplace'),
            'campaignComparePrice' => __('Compare-at price', 'sutore-marketplace'),
            'campaignOfferBlocksEdit' => __('Respond to the campaign offer before updating this product.', 'sutore-marketplace'),
            'campaignAskingRaiseBlocked' => __('This product is in a campaign, so you cannot increase the price.', 'sutore-marketplace'),
            'cargoExpiredTitle' => __('Shipping alert', 'sutore-marketplace'),
            'cargoExpiredHint' => __('The shipping deadline has passed. Contact Sutore to avoid being taken off sale.', 'sutore-marketplace'),
            'saleRefunded' => __('Sale refunded', 'sutore-marketplace'),
            'saleRefundedHint' => __('This sale was refunded.', 'sutore-marketplace'),
            'confirmSale' => __('Confirm Sale', 'sutore-marketplace'),
            'ship' => __('Ship to Sutore', 'sutore-marketplace'),
            'tracking' => __('Tracking', 'sutore-marketplace'),
            'listingNotFound' => __('Product not found.', 'sutore-marketplace'),
            'viewProduct' => __('View product', 'sutore-marketplace'),
            'noDetails' => __('No details available.', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function bulkListingsI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'bulkStatusReady' => __('Ready', 'sutore-marketplace'),
            'bulkStatusWarning' => __('Warning', 'sutore-marketplace'),
            'bulkStatusError' => __('Error', 'sutore-marketplace'),
            'bulkPickFile' => __('Choose a CSV file.', 'sutore-marketplace'),
            'bulkPickSeller' => __('Choose a seller to see the queue preview and create the products.', 'sutore-marketplace'),
            'bulkSummaryTitle' => __('Import preview', 'sutore-marketplace'),
            'bulkSummaryTotal' => __('Total rows', 'sutore-marketplace'),
            'bulkSummaryReady' => __('Ready', 'sutore-marketplace'),
            'bulkSummaryWarning' => __('Warnings', 'sutore-marketplace'),
            'bulkSummaryError' => __('Errors', 'sutore-marketplace'),
            'bulkPreviewReady' => __('Review the rows below, then confirm to create products.', 'sutore-marketplace'),
            'bulkNoValidRows' => __('No valid rows to import. Fix the CSV and try again.', 'sutore-marketplace'),
            'bulkCommitting' => __('Queuing import…', 'sutore-marketplace'),
            'bulkJobQueuedNotify' => __('Your import has been queued. You will receive a notification when it is finished.', 'sutore-marketplace'),
            'bulkQueuedRowCount' => __('%d products queued for import.', 'sutore-marketplace'),
            'bulkClose' => __('Close', 'sutore-marketplace'),
            'bulkNoActiveSale' => __('No active sale for this size', 'sutore-marketplace'),
            'bulkWillBeFirstForSale' => __('Will be #1 (for sale)', 'sutore-marketplace'),
            'bulkWillBeFirstAwaitingApproval' => __('Will be #1 (awaiting approval)', 'sutore-marketplace'),
            'bulkWillBeQueued' => __('Queued (#%1$d of %2$d)', 'sutore-marketplace'),
            'bulkMoveToFirstPlace' => __('Move to First Place', 'sutore-marketplace'),
            'bulkDeleteRow' => __('Remove row', 'sutore-marketplace'),
            'bulkUpdatingPrice' => __('Updating price…', 'sutore-marketplace'),
            'bulkUpdatingPreview' => __('Updating preview…', 'sutore-marketplace'),
            'bulkWizardStepUpload' => __('Upload', 'sutore-marketplace'),
            'bulkWizardStepReview' => __('Review', 'sutore-marketplace'),
            'bulkWizardStepOf' => __('Step %1$d of %2$d', 'sutore-marketplace'),
            'bulkUploadTitle' => __('Bulk upload', 'sutore-marketplace'),
            'bulkSuccessTitle' => __('Import queued', 'sutore-marketplace'),
            'bulkNext' => __('Next', 'sutore-marketplace'),
            'bulkPrevious' => __('Previous', 'sutore-marketplace'),
            'bulkCreateListings' => __('Create products', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function sourcingI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'sourcingOpen' => __('Open', 'sutore-marketplace'),
            'sourcingEmpty' => __('There are no open pre-orders at the moment.', 'sutore-marketplace'),
            'sourcingConfirmAccept' => __('Accept sale', 'sutore-marketplace'),
            'sourcingAcceptConfirmCreate' => __('A new product will be created for this pre-order. Continue?', 'sutore-marketplace'),
            /* translators: 1: variation label, 2: existing listing price */
            'sourcingAcceptConfirmKeepExisting' => __('Your existing product (%1$s, %2$s) will stay unchanged, and a new product will be created for this pre-order. Continue?', 'sutore-marketplace'),
            /* translators: 1: variation label, 2: existing listing price */
            'sourcingAcceptConfirmReuse' => __('Your existing product (%1$s, %2$s) will be used for this pre-order. A new product will not be created. Continue?', 'sutore-marketplace'),
            /* translators: 1: variation label, 2: existing price, 3: pre-order price */
            'sourcingAcceptConfirmReusePriceChange' => __('Your existing product (%1$s) will be used for this pre-order, and its price will be updated from %2$s to %3$s. Continue?', 'sutore-marketplace'),
            'sourcingNotFound' => __('Pre-order not found.', 'sutore-marketplace'),
            'sourcingProduct' => __('Product', 'sutore-marketplace'),
            'sourcingOffer' => __('Pre-order', 'sutore-marketplace'),
            /* translators: 1: linked variation label, 2: existing listing price */
            'sourcingExistingListingNotice' => __('You already have a product for this product and size (%1$s, %2$s). It will be used for this pre-order; a new product will not be created.', 'sutore-marketplace'),
            /* translators: 1: existing listing price, 2: pre-order price */
            'sourcingExistingPriceUpdate' => __('Its price will be updated from %1$s to the pre-order price of %2$s when you accept.', 'sutore-marketplace'),
            /* translators: %d: variation ID */
            'variationNumber' => __('Variation #%d', 'sutore-marketplace'),
            'variationId' => __('Variation ID', 'sutore-marketplace'),
            'sourcingKeepExistingListing' => __('Keep my existing product; I will supply a new product.', 'sutore-marketplace'),
            'productCode' => __('Product code', 'sutore-marketplace'),
            'status' => __('Status', 'sutore-marketplace'),
            'sourcingOfferPrice' => __('Sale price', 'sutore-marketplace'),
            'sourcingNetPayout' => __('Est. net payout', 'sutore-marketplace'),
            'sourcingDeliveryDeadline' => __('Delivery deadline', 'sutore-marketplace'),
            'sourcingCommitment' => __('I confirm that the product is original and compliant, and that I will deliver it complete and undamaged to the Sutore control center by the delivery deadline. I accept sole responsibility for any cancellation, return, or damages resulting from non-compliance.', 'sutore-marketplace'),
            /* translators: %s: delivery deadline date */
            'sourcingCommitmentWithDate' => __('I confirm that the product is original and compliant, and that I will deliver it complete and undamaged to the Sutore control center by %s. I accept sole responsibility for any cancellation, return, or damages resulting from non-compliance.', 'sutore-marketplace'),
            'sourcingCommitmentRequired' => __('Please confirm the commitment to continue.', 'sutore-marketplace'),
            'sourcingOrder' => __('Order', 'sutore-marketplace'),
            'saved' => __('Saved', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function campaignOffersI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'campaignOffersEmpty' => __('You have no campaign offers.', 'sutore-marketplace'),
            'campaignOfferTitle' => __('Campaign offer', 'sutore-marketplace'),
            'campaignOfferTag' => __('Campaign offer', 'sutore-marketplace'),
            'reviewCampaignOffer' => __('Review offer', 'sutore-marketplace'),
            'campaignOfferAccept' => __('Accept', 'sutore-marketplace'),
            'campaignOfferDecline' => __('Decline', 'sutore-marketplace'),
            'campaignOfferAcceptConfirm' => __('Accept this campaign offer? Your product price will be updated for the campaign period.', 'sutore-marketplace'),
            'campaignOfferDeclineConfirm' => __('Decline this campaign offer?', 'sutore-marketplace'),
            'campaignNotNow' => __('Not now', 'sutore-marketplace'),
            'campaignSource' => __('Source', 'sutore-marketplace'),
            'campaignHeadline' => __('Suggestion', 'sutore-marketplace'),
            'campaignSellerDiscount' => __('Your discount', 'sutore-marketplace'),
            'campaignPlatformDiscount' => __('Platform discount', 'sutore-marketplace'),
            'campaignAskingBefore' => __('Current price', 'sutore-marketplace'),
            'campaignAskingAfter' => __('Price after accept', 'sutore-marketplace'),
            'campaignStartsAt' => __('Campaign starts', 'sutore-marketplace'),
            'campaignEndsAt' => __('Campaign ends', 'sutore-marketplace'),
            'preOrderProduct' => __('Pre-order', 'sutore-marketplace'),
            'productCode' => __('Product code', 'sutore-marketplace'),
            'size' => __('Size', 'sutore-marketplace'),
            'variationId' => __('Variation ID', 'sutore-marketplace'),
            'campaignFilterAll' => __('All', 'sutore-marketplace'),
            'campaignFilterPending' => __('Pending', 'sutore-marketplace'),
            'campaignFilterAccepted' => __('Accepted', 'sutore-marketplace'),
            'campaignFilterDeclined' => __('Declined', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function priceOffersI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'priceOffersEmpty' => __('You have no customer offers.', 'sutore-marketplace'),
            'priceOfferTitle' => __('Customer offer', 'sutore-marketplace'),
            'priceOfferTag' => __('Customer offer', 'sutore-marketplace'),
            'reviewPriceOffer' => __('Review offer', 'sutore-marketplace'),
            'priceOfferAccept' => __('Accept', 'sutore-marketplace'),
            'priceOfferDecline' => __('Decline', 'sutore-marketplace'),
            'priceOfferAcceptConfirm' => __('Accept this offer? A personal coupon will be issued. Your public price will not change.', 'sutore-marketplace'),
            'priceOfferDeclineConfirm' => __('Decline this offer? It may be sent to the next seller in the queue.', 'sutore-marketplace'),
            'priceOfferBid' => __('Customer bid', 'sutore-marketplace'),
            'priceOfferAsking' => __('Your price', 'sutore-marketplace'),
            'priceOfferPay' => __('Customer would pay', 'sutore-marketplace'),
            'priceOfferForwarded' => __('Forwarded from the previous seller', 'sutore-marketplace'),
            'productCode' => __('Product code', 'sutore-marketplace'),
            'size' => __('Size', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function myOffersI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'myOffersEmpty' => __('You have not sent any offers yet.', 'sutore-marketplace'),
            'myOfferTitle' => __('My offer', 'sutore-marketplace'),
            'myOfferCancel' => __('Cancel offer', 'sutore-marketplace'),
            'myOfferCancelConfirm' => __('Cancel this pending offer?', 'sutore-marketplace'),
            'myOfferBid' => __('Your bid', 'sutore-marketplace'),
            'myOfferCoupon' => __('Coupon', 'sutore-marketplace'),
            'myOfferAddToCart' => __('Add to cart', 'sutore-marketplace'),
            'copied' => __('Copied', 'sutore-marketplace'),
            'productCode' => __('Product code', 'sutore-marketplace'),
            'size' => __('Size', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function outletI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'outletEmpty' => __('There is no open outlet window right now.', 'sutore-marketplace'),
            'outletJoin' => __('Join at this price', 'sutore-marketplace'),
            'outletCancel' => __('Cancel', 'sutore-marketplace'),
            'outletJoinConfirm' => __('Join this outlet item at the listed price?', 'sutore-marketplace'),
            'outletCancelConfirm' => __('Cancel this outlet opt-in?', 'sutore-marketplace'),
            'outletCustomerSale' => __('Customer sale', 'sutore-marketplace'),
            'outletSellerAsking' => __('Your price', 'sutore-marketplace'),
            'outletWindow' => __('Window', 'sutore-marketplace'),
            'size' => __('Size', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function tasksI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'taskNotStarted' => __('Not started', 'sutore-marketplace'),
            'taskInProgress' => __('In progress', 'sutore-marketplace'),
            'taskCompleted' => __('Completed', 'sutore-marketplace'),
            'opportunitiesEmpty' => __('No opportunity cards this month yet.', 'sutore-marketplace'),
            'rewardCommission' => __('Commission discount', 'sutore-marketplace'),
            'rewardScoreRecovery' => __('Reward: score recovery', 'sutore-marketplace'),
            'rewardEngagement' => __('Reward: marketplace engagement', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function merchantProfileI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'profileSaved' => __('Saved', 'sutore-marketplace'),
            'pickDistrict' => __('Select', 'sutore-marketplace'),
            'merchantSummary' => __('Merchant summary', 'sutore-marketplace'),
            'level' => __('Level', 'sutore-marketplace'),
            'behaviorScore' => __('Behavior score', 'sutore-marketplace'),
            'tcVerified' => __('Your TC identity has been verified.', 'sutore-marketplace'),
            'billing' => __('Billing', 'sutore-marketplace'),
            'accountName' => __('Account Holder First Name', 'sutore-marketplace'),
            'accountLastname' => __('Account Holder Last Name', 'sutore-marketplace'),
            'iban' => __('IBAN', 'sutore-marketplace'),
            'tc' => __('TC Identity Number', 'sutore-marketplace'),
            'birthYear' => __('Year of Birth', 'sutore-marketplace'),
            'email' => __('Email Address', 'sutore-marketplace'),
            'phone' => __('Phone Number', 'sutore-marketplace'),
            'city' => __('City', 'sutore-marketplace'),
            'district' => __('District', 'sutore-marketplace'),
            'currentPassword' => __('Your current password', 'sutore-marketplace'),
            'saveInfo' => __('Save My Info', 'sutore-marketplace'),
            'inviteCode' => __('Invite code (optional)', 'sutore-marketplace'),
            'inviteSellers' => __('Invite sellers', 'sutore-marketplace'),
            'yourInviteCode' => __('Your invite code', 'sutore-marketplace'),
            'copyLink' => __('Copy link', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function merchantBalanceI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'commission' => __('Commission', 'sutore-marketplace'),
            'commissionDiscountActive' => __('Commission discount active', 'sutore-marketplace'),
            'commissionLevelToEffective' => __('Level %1$s%% → Effective %2$s%%', 'sutore-marketplace'),
            'expiresAt' => __('Expires', 'sutore-marketplace'),
            'noExpiry' => __('No end date', 'sutore-marketplace'),
            'paidPayout' => __('Paid payout', 'sutore-marketplace'),
            'pendingPayout' => __('Pending payout', 'sutore-marketplace'),
            'salesCount' => __('%d sales', 'sutore-marketplace'),
            'recentPayouts' => __('Recent payouts', 'sutore-marketplace'),
            'product' => __('Product', 'sutore-marketplace'),
            'listing' => __('Product', 'sutore-marketplace'),
            'net' => __('Net', 'sutore-marketplace'),
            'payment' => __('Payment', 'sutore-marketplace'),
            'noPayouts' => __('No payout lines yet.', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function accountI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'saved' => __('Saved', 'sutore-marketplace'),
            'deleteAccountTitle' => __('Delete your account?', 'sutore-marketplace'),
            'deleteAccountConfirm' => __('This will permanently delete your account and products. You cannot undo this action.', 'sutore-marketplace'),
            'deleteAccountConfirmButton' => __('Delete account', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function notificationsI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'notifEmpty' => __('You have no notifications yet.', 'sutore-marketplace'),
            'notifMenuLabel' => __('Notifications', 'sutore-marketplace'),
            'notifUnread' => __('New', 'sutore-marketplace'),
            'notifPrev' => __('Previous', 'sutore-marketplace'),
            'notifNext' => __('Next', 'sutore-marketplace'),
            'notifCategorySales' => __('Sale', 'sutore-marketplace'),
            'notifCategoryFulfillment' => __('Shipping', 'sutore-marketplace'),
            'notifCategoryPayout' => __('Payout', 'sutore-marketplace'),
            'notifCategoryListing' => __('Product', 'sutore-marketplace'),
            'notifCategoryCustomer' => __('Offers', 'sutore-marketplace'),
            'notifCategorySystem' => __('System', 'sutore-marketplace'),
        ]);
    }
}
