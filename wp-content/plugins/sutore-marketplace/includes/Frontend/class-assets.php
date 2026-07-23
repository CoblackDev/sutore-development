<?php

declare(strict_types=1);

namespace SutoreMarketplace\Frontend;

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
    public const SCRIPT_MERCHANT_PROFILE = 'sutore-marketplace-merchant-profile';
    public const SCRIPT_ACCOUNT = 'sutore-marketplace-account';
    public const SCRIPT_TASKS = 'sutore-marketplace-tasks';
    public const STYLE_NOTIFICATIONS = 'sutore-marketplace-notifications';
    public const SCRIPT_NOTIFICATIONS = 'sutore-marketplace-notifications';
    public const STYLE_STAFF_MANAGE = 'sutore-marketplace-staff-manage';
    public const SCRIPT_STAFF_MANAGE = 'sutore-marketplace-staff-manage';
    public const STYLE_STAFF_MERCHANTS = 'sutore-marketplace-staff-merchants';
    public const SCRIPT_STAFF_MERCHANTS = 'sutore-marketplace-staff-merchants';

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
            SUTORE_MARKETPLACE_VERSION
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
            self::SCRIPT_MERCHANT_PROFILE,
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-merchant-profile.js',
            ['jquery', self::SCRIPT_CORE],
            SUTORE_MARKETPLACE_VERSION,
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
            ['jquery', self::SCRIPT_CORE],
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

    public function enqueueMerchantProfile(): void
    {
        wp_enqueue_style(self::STYLE_MERCHANT_PROFILE);
        wp_enqueue_script(self::SCRIPT_CORE);
        $this->localizeCore($this->merchantProfileI18n());
        wp_enqueue_script(self::SCRIPT_MERCHANT_PROFILE);
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

    public function enqueueStaffMerchants(): void
    {
        if (!current_user_can(\SutoreMarketplace\Admin\AdminMenu::CAP)) {
            return;
        }

        if (!wp_script_is(self::SCRIPT_STAFF_MERCHANTS, 'registered')) {
            $this->registerAssets();
        }

        wp_enqueue_style(self::STYLE_STAFF_MERCHANTS);
        wp_enqueue_script(self::SCRIPT_CORE);
        wp_enqueue_script(self::SCRIPT_STAFF_MERCHANTS);
        wp_localize_script(self::SCRIPT_STAFF_MERCHANTS, 'SutoreMarketplaceStaffMerchants', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'loading' => __('Loading…', 'sutore-marketplace'),
                'error' => __('Error', 'sutore-marketplace'),
                'notFound' => __('Seller not found.', 'sutore-marketplace'),
                'noRecords' => __('No sellers found.', 'sutore-marketplace'),
                'search' => __('Search', 'sutore-marketplace'),
                'searchPlaceholder' => __('First name, last name, email, phone, ID…', 'sutore-marketplace'),
                'level' => __('Level', 'sutore-marketplace'),
                'allLevels' => __('All levels', 'sutore-marketplace'),
                'filter' => __('Filter', 'sutore-marketplace'),
                'resetFilters' => __('Reset', 'sutore-marketplace'),
                'seller' => __('Seller', 'sutore-marketplace'),
                'email' => __('Email', 'sutore-marketplace'),
                'phone' => __('Phone', 'sutore-marketplace'),
                'listings' => __('Listings', 'sutore-marketplace'),
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
                'district' => __('District / Neighborhood', 'sutore-marketplace'),
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
                'note' => __('Note', 'sutore-marketplace'),
                'login' => __('Username', 'sutore-marketplace'),
                'userId' => __('User ID', 'sutore-marketplace'),
                'product' => __('Product', 'sutore-marketplace'),
                'amount' => __('Amount', 'sutore-marketplace'),
                'status' => __('Status', 'sutore-marketplace'),
                'saving' => __('Saving…', 'sutore-marketplace'),
                'listingCreateBan' => __('Ban creating listings', 'sutore-marketplace'),
                'priceUpdateBan' => __('Ban price updates', 'sutore-marketplace'),
                'disabledAccount' => __('Disable account', 'sutore-marketplace'),
            ],
        ]);
    }

    public function enqueueStaffManageProducts(): void
    {
        if (!current_user_can(\SutoreMarketplace\Admin\AdminMenu::CAP)) {
            return;
        }

        if (!wp_script_is(self::SCRIPT_STAFF_MANAGE, 'registered')) {
            $this->registerAssets();
        }

        wp_enqueue_style(self::STYLE_STAFF_MANAGE);
        wp_enqueue_script(self::SCRIPT_CORE);
        wp_enqueue_script(self::SCRIPT_STAFF_MANAGE);
        wp_localize_script(self::SCRIPT_STAFF_MANAGE, 'SutoreMarketplaceStaff', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'loading' => __('Loading…', 'sutore-marketplace'),
                'error' => __('Error', 'sutore-marketplace'),
                'notFound' => __('Record not found.', 'sutore-marketplace'),
                'confirmPayment' => __('Are you sure you want to confirm this sale? The seller will be notified.', 'sutore-marketplace'),
                'confirmDelete' => __('Are you sure you want to permanently remove this product Listing?', 'sutore-marketplace'),
                'confirmDetach' => __('Will be unlinked from the order. Continue?', 'sutore-marketplace'),
                'transactionTitle' => __('Transaction #%d', 'sutore-marketplace'),
                'product' => __('Product', 'sutore-marketplace'),
                'listingStatus' => __('Status', 'sutore-marketplace'),
                'fulfillmentStatus' => __('Status', 'sutore-marketplace'),
                'order' => __('Order', 'sutore-marketplace'),
                'seller' => __('Seller', 'sutore-marketplace'),
                'listing' => __('Listing', 'sutore-marketplace'),
                'variationId' => __('Variation ID', 'sutore-marketplace'),
                'parentProductId' => __('Parent product ID', 'sutore-marketplace'),
                'price' => __('Price', 'sutore-marketplace'),
                'paymentStatus' => __('Payment status', 'sutore-marketplace'),
                'payoutNotCreated' => __('Not created yet', 'sutore-marketplace'),
                'campaign' => __('Campaign', 'sutore-marketplace'),
                'campaignActiveTag' => __('On campaign', 'sutore-marketplace'),
                'campaignOfferTag' => __('Campaign offer', 'sutore-marketplace'),
                'preOrder' => __('Pre-order', 'sutore-marketplace'),
                'preOrderProduct' => __('Pre-order', 'sutore-marketplace'),
                'shipmentType' => __('Shipment type', 'sutore-marketplace'),
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
                'birthYear' => __('Year of Birth', 'sutore-marketplace'),
                'phone' => __('Phone Number', 'sutore-marketplace'),
                'email' => __('Email Address', 'sutore-marketplace'),
                'recordedAt' => __('Recorded at', 'sutore-marketplace'),
                'actions' => __('Actions', 'sutore-marketplace'),
                'noActions' => __('No further actions are available for this product status.', 'sutore-marketplace'),
                'staffNote' => __('Staff note (visible to merchant)', 'sutore-marketplace'),
                'staffNotePlaceholder' => __('Explain why this action is taken…', 'sutore-marketplace'),
                'staffNoteRequired' => __('A staff note is required for this action.', 'sutore-marketplace'),
                'putOnSale' => __('Put on sale', 'sutore-marketplace'),
                'delete' => __('Delete', 'sutore-marketplace'),
                'confirmSale' => __('Confirm Sale', 'sutore-marketplace'),
                'newListingId' => __('New listing ID (swap)', 'sutore-marketplace'),
                'changeSeller' => __('Change Seller', 'sutore-marketplace'),
                'attachToOrder' => __('Add to order', 'sutore-marketplace'),
                'attachToOrderConfirm' => __('Add this listing to a processing order and start the sale as sold (awaiting merchant confirmation).', 'sutore-marketplace'),
                'selectOrder' => __('Processing order', 'sutore-marketplace'),
                'selectOrderPlaceholder' => __('Select a processing order…', 'sutore-marketplace'),
                'noProcessingOrders' => __('No processing orders found.', 'sutore-marketplace'),
                'detach' => __('Detach from Order', 'sutore-marketplace'),
                'sutoreShippingCode' => __('Sutore shipping code', 'sutore-marketplace'),
                'paymentRef' => __('Payment reference (EFT/receipt)', 'sutore-marketplace'),
                'markPaid' => __('Mark as Paid to Seller', 'sutore-marketplace'),
                'markPaidConfirm' => __('Record that the seller has been paid for this sale. Optional payment reference is stored on the payout line.', 'sutore-marketplace'),
                'markArrived' => __('Arrived at Sutore', 'sutore-marketplace'),
                'markVerified' => __('Verify product', 'sutore-marketplace'),
                'markReady' => __('Ready to ship', 'sutore-marketplace'),
                'markShippedCustomer' => __('Ship to customer', 'sutore-marketplace'),
                'markDelivered' => __('Delivered to customer', 'sutore-marketplace'),
                'markNotForSale' => __('Not for sale', 'sutore-marketplace'),
                'markNotForSaleConfirm' => __('This sale will be taken off the order and the listing will become not for sale.', 'sutore-marketplace'),
                'chargeback' => __('Refund / chargeback', 'sutore-marketplace'),
                'chargebackConfirm' => __('This sale will be marked as refunded and any seller payout will be reversed.', 'sutore-marketplace'),
                'cancel' => __('Cancel', 'sutore-marketplace'),
                'moreActions' => __('More actions', 'sutore-marketplace'),
                'confirmAction' => __('Confirm', 'sutore-marketplace'),
                'fieldRequired' => __('This field is required.', 'sutore-marketplace'),
                'noPrimaryAction' => __('No next step for this status.', 'sutore-marketplace'),
                'activityHistory' => __('Activity history', 'sutore-marketplace'),
                'activityHistoryDesc' => __('All logged interventions for this listing from creation through fulfillment.', 'sutore-marketplace'),
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
                'noRecords' => __('No records.', 'sutore-marketplace'),
                'previous' => __('Previous', 'sutore-marketplace'),
                'next' => __('Next', 'sutore-marketplace'),
                'pageOf' => __('Page %1$d / %2$d', 'sutore-marketplace'),
                'pagination' => __('Pagination', 'sutore-marketplace'),
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
            'priceStep' => Settings::listingPriceStep(),
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
            'otpTitle' => __('SMS verification', 'sutore-marketplace'),
            'otpPromptPrefix' => __('Enter the verification code sent to your phone. Time remaining:', 'sutore-marketplace'),
            'otpSecondsSuffix' => __('sec.', 'sutore-marketplace'),
            'otpPlaceholder' => __('Verification code', 'sutore-marketplace'),
            'otpConfirm' => __('Verify', 'sutore-marketplace'),
            'otpSending' => __('Sending verification code…', 'sutore-marketplace'),
            'otpMaskedPhone' => __('Code sent to %s', 'sutore-marketplace'),
            'otpDebugLabel' => __('Test code (simulation):', 'sutore-marketplace'),
        ];
    }

    /** @return array<string, string> */
    private function listingsI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'confirmDelete' => __('Are you sure you want to permanently remove this product Listing?', 'sutore-marketplace'),
            'deleteTitle' => __('Delete this Listing?', 'sutore-marketplace'),
            'cannotDelete' => __('This listing cannot be deleted right now. (In order or payment process.)', 'sutore-marketplace'),
            'notFound' => __('The product you searched for was not found', 'sutore-marketplace'),
            'emptyListings' => __('You have not added a product yet.', 'sutore-marketplace'),
            'noResults' => __('No results found.', 'sutore-marketplace'),
            'manage' => __('Manage', 'sutore-marketplace'),
            'delete' => __('Delete', 'sutore-marketplace'),
            'addTitle' => __('Add Product', 'sutore-marketplace'),
            'editTitle' => __('Edit Listing', 'sutore-marketplace'),
            'submit' => __('Submit', 'sutore-marketplace'),
            'update' => __('Update', 'sutore-marketplace'),
            'next' => __('Next', 'sutore-marketplace'),
            'previous' => __('Previous', 'sutore-marketplace'),
            'createSuccessTitle' => __('Listing added', 'sutore-marketplace'),
            'createSuccessForSale' => __('Your listing is now for sale.', 'sutore-marketplace'),
            'createSuccessQueued' => __('Your listing was added to the queue for this size.', 'sutore-marketplace'),
            'createSuccessPending' => __('Your listing was submitted and will go on sale after approval.', 'sutore-marketplace'),
            'wizardStepOf' => __('Step %1$d of %2$d', 'sutore-marketplace'),
            'wizardStepProduct' => __('Product', 'sutore-marketplace'),
            'wizardStepSize' => __('Size', 'sutore-marketplace'),
            'wizardStepDetails' => __('Details', 'sutore-marketplace'),
            'wizardStepPrice' => __('Price', 'sutore-marketplace'),
            'saved' => __('Saved', 'sutore-marketplace'),
            'savedTitle' => __('Listing updated', 'sutore-marketplace'),
            'editListing' => __('Edit listing', 'sutore-marketplace'),
            'pickProduct' => __('Select a product to continue.', 'sutore-marketplace'),
            'pickSize' => __('Select a size to continue.', 'sutore-marketplace'),
            'priceStepError' => __('Price must be in multiples of %d TL. Decimal prices are not allowed.', 'sutore-marketplace'),
            'priceRequired' => __('Enter a price.', 'sutore-marketplace'),
            'belowRetailWarn' => __('This listing will go on sale below the product’s starting price (≈ %s TL).', 'sutore-marketplace'),
            'startingPrice' => __('Starting price', 'sutore-marketplace'),
            'lowestPrice' => __('Lowest Price', 'sutore-marketplace'),
            'currentQueue' => __('Current queue', 'sutore-marketplace'),
            'netEarnings' => __('Your net earnings', 'sutore-marketplace'),
            'sizePriceList' => __('Size price list', 'sutore-marketplace'),
            'sizePriceListEmpty' => __('No other Listing for sale or in queue for this size.', 'sutore-marketplace'),
            'firstPlaceAlertForSale' => __('At this price you will move to #1 — the product will be for sale.', 'sutore-marketplace'),
            'firstPlaceAlertAwaitingApproval' => __('At this price you will move to #1 — awaiting approval before going on sale.', 'sutore-marketplace'),
            'blockedByFlawlessWarn' => __('Defective products cannot go for sale until undamaged products are sold — they wait in queue regardless of price.', 'sutore-marketplace'),
            'statusPublish' => __('For sale', 'sutore-marketplace'),
            'statusQueued' => __('In queue', 'sutore-marketplace'),
            'statusPending' => __('Awaiting approval', 'sutore-marketplace'),
            'statusExpired' => __('Expired', 'sutore-marketplace'),
            'statusNotSale' => __('Not for sale', 'sutore-marketplace'),
            'statusPayment' => __('Awaiting payment confirmation', 'sutore-marketplace'),
            'statusSold' => __('Awaiting merchant confirmation', 'sutore-marketplace'),
            'putOnSale' => __('Put on sale', 'sutore-marketplace'),
            'putOnSaleTitle' => __('Put this listing back on sale?', 'sutore-marketplace'),
            'putOnSaleConfirm' => __('The listing will re-enter the sale queue with a fresh expiry window.', 'sutore-marketplace'),
            'putOnSaleFailed' => __('This listing cannot be put back on sale right now.', 'sutore-marketplace'),
            'removeFromSale' => __('Remove from sale', 'sutore-marketplace'),
            'removeFromSaleTitle' => __('Remove this listing from sale?', 'sutore-marketplace'),
            'removeFromSaleConfirm' => __('The listing will leave the sale queue without being deleted.', 'sutore-marketplace'),
            'removeFromSaleFailed' => __('This listing cannot be removed from sale right now.', 'sutore-marketplace'),
            'returnWindowEnds' => __('Return / dispute window ends', 'sutore-marketplace'),
            'condNoBox' => __('No box', 'sutore-marketplace'),
            'condBoxDamaged' => __('Box damaged', 'sutore-marketplace'),
            'condMissingAccessory' => __('Missing accessory', 'sutore-marketplace'),
            'condDamaged' => __('Damaged', 'sutore-marketplace'),
            'condUsed' => __('Used', 'sutore-marketplace'),
            'expressShipping' => __('Fast / Express', 'sutore-marketplace'),
            'internationalShipping' => __('International', 'sutore-marketplace'),
            'condition' => __('Condition', 'sutore-marketplace'),
            'conditionNone' => __('No defects', 'sutore-marketplace'),
            'shippingOptions' => __('Shipping', 'sutore-marketplace'),
            'shippingStandard' => __('Standard', 'sutore-marketplace'),
            'preOrderProduct' => __('Pre-order', 'sutore-marketplace'),
            'regularProduct' => __('Regular product', 'sutore-marketplace'),
            'importedProduct' => __('Imported', 'sutore-marketplace'),
            'listingSource' => __('Listing source', 'sutore-marketplace'),
            'expressIneligible' => __('You are not eligible for fast shipping.', 'sutore-marketplace'),
            'timeLeft' => __('Time remaining', 'sutore-marketplace'),
            'confirmTimeLeft' => __('Confirmation time remaining', 'sutore-marketplace'),
            'shipTimeLeft' => __('Shipping time remaining', 'sutore-marketplace'),
            'timeExpired' => __('Expired', 'sutore-marketplace'),
            'yourListing' => __('Yours', 'sutore-marketplace'),
            'currentListing' => __('This Listing', 'sutore-marketplace'),
            'saleFulfillment' => __('Sale / fulfillment', 'sutore-marketplace'),
            'saleTab' => __('Sale', 'sutore-marketplace'),
            'noSaleDetails' => __('No sale details for this listing.', 'sutore-marketplace'),
            'saleDetails' => __('Sale details', 'sutore-marketplace'),
            'product' => __('Product', 'sutore-marketplace'),
            'productCode' => __('Product code', 'sutore-marketplace'),
            'listingStatus' => __('Status', 'sutore-marketplace'),
            'fulfillmentStatus' => __('Status', 'sutore-marketplace'),
            'listing' => __('Listing', 'sutore-marketplace'),
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
            'activityHistoryDesc' => __('Listing activity and status changes are shown chronologically.', 'sutore-marketplace'),
            'noActivity' => __('No activity recorded yet.', 'sutore-marketplace'),
            'date' => __('Date', 'sutore-marketplace'),
            'event' => __('Event', 'sutore-marketplace'),
            'actor' => __('Actor', 'sutore-marketplace'),
            'details' => __('Details', 'sutore-marketplace'),
            'order' => __('Order', 'sutore-marketplace'),
            'confirmDeadline' => __('Confirmation deadline', 'sutore-marketplace'),
            'shipDeadline' => __('Shipping deadline', 'sutore-marketplace'),
            'sellerConfirmedAt' => __('Seller confirmation date', 'sutore-marketplace'),
            'sellerShippedAt' => __('Seller shipping date', 'sutore-marketplace'),
            'trackingNumber' => __('Tracking number', 'sutore-marketplace'),
            'merchantTrackingNumber' => __('Tracking to Sutore', 'sutore-marketplace'),
            'sutoreTrackingNumber' => __('Tracking to customer', 'sutore-marketplace'),
            'soldAt' => __('Sold at', 'sutore-marketplace'),
            'deliveredAt' => __('Delivered to customer', 'sutore-marketplace'),
            'payoutStatus' => __('Payout status', 'sutore-marketplace'),
            'payoutPaidAt' => __('Paid at', 'sutore-marketplace'),
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
            'campaignOfferBlocksEdit' => __('Respond to the campaign offer before updating this listing.', 'sutore-marketplace'),
            'campaignAskingRaiseBlocked' => __('This listing is in a campaign, so you cannot increase the asking price.', 'sutore-marketplace'),
            'cargoExpiredTitle' => __('Shipping alert', 'sutore-marketplace'),
            'cargoExpiredHint' => __('The shipping deadline has passed. Contact Sutore to avoid being taken off sale.', 'sutore-marketplace'),
            'saleRefunded' => __('Sale refunded', 'sutore-marketplace'),
            'saleRefundedHint' => __('This sale was refunded.', 'sutore-marketplace'),
            'confirmSale' => __('Confirm Sale', 'sutore-marketplace'),
            'ship' => __('Ship to Sutore', 'sutore-marketplace'),
            'tracking' => __('Tracking', 'sutore-marketplace'),
            'listingNotFound' => __('Listing not found.', 'sutore-marketplace'),
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
            'bulkSummaryTitle' => __('Import preview', 'sutore-marketplace'),
            'bulkSummaryTotal' => __('Total rows', 'sutore-marketplace'),
            'bulkSummaryReady' => __('Ready', 'sutore-marketplace'),
            'bulkSummaryWarning' => __('Warnings', 'sutore-marketplace'),
            'bulkSummaryError' => __('Errors', 'sutore-marketplace'),
            'bulkPreviewReady' => __('Review the rows below, then confirm to create listings.', 'sutore-marketplace'),
            'bulkNoValidRows' => __('No valid rows to import. Fix the CSV and try again.', 'sutore-marketplace'),
            'bulkCommitting' => __('Queuing import…', 'sutore-marketplace'),
            'bulkJobQueuedNotify' => __('Your import has been queued. You will receive a notification when it is finished.', 'sutore-marketplace'),
            'bulkQueuedRowCount' => __('%d listings queued for import.', 'sutore-marketplace'),
            'bulkClose' => __('Close', 'sutore-marketplace'),
            'bulkNoActiveSale' => __('No active sale for this size', 'sutore-marketplace'),
            'bulkWillBeFirstForSale' => __('Will be #1 (for sale)', 'sutore-marketplace'),
            'bulkWillBeFirstAwaitingApproval' => __('Will be #1 (awaiting approval)', 'sutore-marketplace'),
            'bulkWillBeQueued' => __('Queued (#%1$d of %2$d)', 'sutore-marketplace'),
            'bulkBlockedByFlawless' => __('Blocked by undamaged listings ahead in queue', 'sutore-marketplace'),
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
            'bulkCreateListings' => __('Create listings', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function sourcingI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'sourcingOpen' => __('Open', 'sutore-marketplace'),
            'sourcingAccepted' => __('Accepted', 'sutore-marketplace'),
            'sourcingFulfilled' => __('Completed', 'sutore-marketplace'),
            'sourcingCancelled' => __('Cancel', 'sutore-marketplace'),
            'sourcingEmpty' => __('There are no open pre-orders at the moment.', 'sutore-marketplace'),
            'sourcingViewOffer' => __('View offer', 'sutore-marketplace'),
            'sourcingConfirmAccept' => __('Accept sale', 'sutore-marketplace'),
            'sourcingAcceptConfirmCreate' => __('A new listing will be created for this pre-order. Continue?', 'sutore-marketplace'),
            /* translators: 1: variation label, 2: existing listing price */
            'sourcingAcceptConfirmKeepExisting' => __('Your existing listing (%1$s, %2$s) will stay unchanged, and a new listing will be created for this pre-order. Continue?', 'sutore-marketplace'),
            /* translators: 1: variation label, 2: existing listing price */
            'sourcingAcceptConfirmReuse' => __('Your existing listing (%1$s, %2$s) will be used for this pre-order. A new listing will not be created. Continue?', 'sutore-marketplace'),
            /* translators: 1: variation label, 2: existing price, 3: pre-order price */
            'sourcingAcceptConfirmReusePriceChange' => __('Your existing listing (%1$s) will be used for this pre-order, and its price will be updated from %2$s to %3$s. Continue?', 'sutore-marketplace'),
            'sourcingNotFound' => __('Pre-order not found.', 'sutore-marketplace'),
            'sourcingAcceptedMine' => __('You have accepted this pre-order.', 'sutore-marketplace'),
            'sourcingProduct' => __('Product', 'sutore-marketplace'),
            'sourcingOffer' => __('Pre-order', 'sutore-marketplace'),
            /* translators: 1: linked variation label, 2: existing listing price */
            'sourcingExistingListingNotice' => __('You already have a listing for this product and size (%1$s, %2$s). It will be used for this pre-order; a new listing will not be created.', 'sutore-marketplace'),
            /* translators: 1: existing listing price, 2: pre-order price */
            'sourcingExistingPriceUpdate' => __('Its price will be updated from %1$s to the pre-order price of %2$s when you accept.', 'sutore-marketplace'),
            /* translators: %d: variation ID */
            'variationNumber' => __('Variation #%d', 'sutore-marketplace'),
            'variationId' => __('Variation ID', 'sutore-marketplace'),
            'sourcingKeepExistingListing' => __('Keep my existing listing; I will supply a new product.', 'sutore-marketplace'),
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
            'campaignOfferAcceptConfirm' => __('Accept this campaign offer? Your listing price will be updated for the campaign period.', 'sutore-marketplace'),
            'campaignOfferDeclineConfirm' => __('Decline this campaign offer?', 'sutore-marketplace'),
            'campaignSellerDiscount' => __('Your discount', 'sutore-marketplace'),
            'campaignPlatformDiscount' => __('Platform discount', 'sutore-marketplace'),
            'campaignAskingBefore' => __('Current asking', 'sutore-marketplace'),
            'campaignAskingAfter' => __('Asking after accept', 'sutore-marketplace'),
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
    private function tasksI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'taskNotStarted' => __('Not started', 'sutore-marketplace'),
            'taskInProgress' => __('In progress', 'sutore-marketplace'),
            'taskCompleted' => __('Completed', 'sutore-marketplace'),
            'tasksEmpty' => __('No tasks defined yet.', 'sutore-marketplace'),
            'rewardsEmpty' => __('No rewards yet.', 'sutore-marketplace'),
            'reward' => __('Reward', 'sutore-marketplace'),
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
            'listing' => __('Listing', 'sutore-marketplace'),
            'net' => __('Net', 'sutore-marketplace'),
            'payment' => __('Payment', 'sutore-marketplace'),
            'tcVerified' => __('Your TC identity has been verified — Confirmed seller level', 'sutore-marketplace'),
            'billing' => __('Billing', 'sutore-marketplace'),
            'accountName' => __('Account Holder First Name', 'sutore-marketplace'),
            'accountLastname' => __('Account Holder Last Name', 'sutore-marketplace'),
            'iban' => __('IBAN', 'sutore-marketplace'),
            'tc' => __('TC Identity Number', 'sutore-marketplace'),
            'birthYear' => __('Year of Birth', 'sutore-marketplace'),
            'email' => __('Email Address', 'sutore-marketplace'),
            'phone' => __('Phone Number', 'sutore-marketplace'),
            'city' => __('City', 'sutore-marketplace'),
            'district' => __('District / Neighborhood', 'sutore-marketplace'),
            'currentPassword' => __('Your current password', 'sutore-marketplace'),
            'saveInfo' => __('Save My Info', 'sutore-marketplace'),
        ]);
    }

    /** @return array<string, string> */
    private function accountI18n(): array
    {
        return array_merge($this->commonI18n(), [
            'saved' => __('Saved', 'sutore-marketplace'),
            'deleteAccountTitle' => __('Delete your account?', 'sutore-marketplace'),
            'deleteAccountConfirm' => __('This will permanently delete your account and listings. You cannot undo this action.', 'sutore-marketplace'),
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
            'notifCategoryListing' => __('Listing', 'sutore-marketplace'),
            'notifCategorySystem' => __('System', 'sutore-marketplace'),
        ]);
    }
}
