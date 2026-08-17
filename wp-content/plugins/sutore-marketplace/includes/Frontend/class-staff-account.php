<?php

declare(strict_types=1);

namespace SutoreMarketplace\Frontend;

use SutoreMarketplace\Admin\AdminMenu;
use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CatalogProductRequestRepository;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentType;

/**
 * Staff-only WooCommerce My Account endpoints for marketplace ops.
 * Access: AdminMenu::CAP (manage_woocommerce) — shop_manager + administrator.
 */
final class StaffAccount
{
    public const ENDPOINT_MANAGE_PRODUCTS = 'manage-products';
    public const ENDPOINT_MANAGE_ORDERS = 'manage-orders';
    public const ENDPOINT_MERCHANTS = 'merchants';
    public const ENDPOINT_CATALOG_REQUESTS = 'catalog-product-requests';

    private const ENDPOINTS_REVISION = '20260815-catalog-product-requests';

    public function __construct(
        private readonly Assets $assets = new Assets(),
    ) {
    }

    public function register(): void
    {
        add_action('init', [$this, 'addEndpoints']);
        add_filter('woocommerce_get_query_vars', [$this, 'queryVars']);
        add_filter('woocommerce_account_menu_items', [$this, 'menuItems'], 100);
        add_action('wp_enqueue_scripts', [$this, 'enqueueEndpointAssets'], 25);
        add_action(
            'woocommerce_account_' . self::ENDPOINT_MANAGE_PRODUCTS . '_endpoint',
            [$this, 'renderManageProducts']
        );
        add_action(
            'woocommerce_account_' . self::ENDPOINT_MANAGE_ORDERS . '_endpoint',
            [$this, 'renderManageOrders']
        );
        add_action(
            'woocommerce_account_' . self::ENDPOINT_MERCHANTS . '_endpoint',
            [$this, 'renderMerchants']
        );
        add_action(
            'woocommerce_account_' . self::ENDPOINT_CATALOG_REQUESTS . '_endpoint',
            [$this, 'renderCatalogRequests']
        );
        add_action('wp_loaded', [$this, 'maybeFlushRewrites']);
    }

    /** @return list<string> */
    public static function endpointSlugs(): array
    {
        return [
            self::ENDPOINT_MANAGE_PRODUCTS,
            self::ENDPOINT_MANAGE_ORDERS,
            self::ENDPOINT_MERCHANTS,
            self::ENDPOINT_CATALOG_REQUESTS,
        ];
    }

    public function addEndpoints(): void
    {
        add_rewrite_endpoint(self::ENDPOINT_MANAGE_PRODUCTS, EP_ROOT | EP_PAGES);
        add_rewrite_endpoint(self::ENDPOINT_MANAGE_ORDERS, EP_ROOT | EP_PAGES);
        add_rewrite_endpoint(self::ENDPOINT_MERCHANTS, EP_ROOT | EP_PAGES);
        add_rewrite_endpoint(self::ENDPOINT_CATALOG_REQUESTS, EP_ROOT | EP_PAGES);
    }

    /**
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    public function queryVars(array $vars): array
    {
        $vars[self::ENDPOINT_MANAGE_PRODUCTS] = self::ENDPOINT_MANAGE_PRODUCTS;
        $vars[self::ENDPOINT_MANAGE_ORDERS] = self::ENDPOINT_MANAGE_ORDERS;
        $vars[self::ENDPOINT_MERCHANTS] = self::ENDPOINT_MERCHANTS;
        $vars[self::ENDPOINT_CATALOG_REQUESTS] = self::ENDPOINT_CATALOG_REQUESTS;

        return $vars;
    }

    /**
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public function menuItems(array $items): array
    {
        if (!$this->currentUserCanManage()) {
            return $items;
        }

        $catalogLabel = __('Catalog requests', 'sutore-marketplace');
        $pendingRequests = (new CatalogProductRequestRepository())
            ->countForStaff(['status' => CatalogProductRequestStatus::PENDING]);
        if ($pendingRequests > 0) {
            $catalogLabel = sprintf(
                /* translators: 1: menu label, 2: pending request count */
                __('%1$s (%2$d)', 'sutore-marketplace'),
                $catalogLabel,
                $pendingRequests
            );
        }

        $result = [];
        $inserted = false;
        foreach ($items as $key => $text) {
            $result[$key] = $text;
            if (!$inserted && $key === 'dashboard') {
                $result[self::ENDPOINT_MANAGE_PRODUCTS] = __('Manage Products', 'sutore-marketplace');
                $result[self::ENDPOINT_MANAGE_ORDERS] = __('Manage Orders', 'sutore-marketplace');
                $result[self::ENDPOINT_MERCHANTS] = __('Sellers', 'sutore-marketplace');
                $result[self::ENDPOINT_CATALOG_REQUESTS] = $catalogLabel;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $result[self::ENDPOINT_MANAGE_PRODUCTS] = __('Manage Products', 'sutore-marketplace');
            $result[self::ENDPOINT_MANAGE_ORDERS] = __('Manage Orders', 'sutore-marketplace');
            $result[self::ENDPOINT_MERCHANTS] = __('Sellers', 'sutore-marketplace');
            $result[self::ENDPOINT_CATALOG_REQUESTS] = $catalogLabel;
        }

        return $result;
    }

    public function enqueueEndpointAssets(): void
    {
        if (!is_account_page() || !$this->currentUserCanManage()) {
            return;
        }

        global $wp;
        if (isset($wp->query_vars[self::ENDPOINT_MANAGE_PRODUCTS])) {
            $this->assets->enqueueStaffManageProducts();
        }
        if (isset($wp->query_vars[self::ENDPOINT_MANAGE_ORDERS])) {
            $this->assets->enqueueStaffOrders();
        }
        if (isset($wp->query_vars[self::ENDPOINT_MERCHANTS])) {
            $this->assets->enqueueStaffMerchants();
        }
        if (isset($wp->query_vars[self::ENDPOINT_CATALOG_REQUESTS])) {
            $this->assets->enqueueStaffCatalogRequests();
        }
    }

    public function renderManageProducts(): void
    {
        if (!is_user_logged_in() || !$this->currentUserCanManage()) {
            echo '<p class="sutore-mp-error">' . esc_html__('You do not have access to this area.', 'sutore-marketplace') . '</p>';
            return;
        }

        $this->assets->enqueueStaffManageProducts();

        $orderby = sanitize_key((string) ($_GET['orderby'] ?? 'id_desc'));
        $allowedOrderby = [
            'id_desc',
            'id_asc',
            'deadline_asc',
            'deadline_desc',
            'sold_at_desc',
            'sold_at_asc',
            'status_asc',
        ];
        if (!in_array($orderby, $allowedOrderby, true)) {
            $orderby = 'id_desc';
        }

        $payoutStatus = sanitize_key((string) ($_GET['payout_status'] ?? ''));
        if ($payoutStatus !== 'none' && !PayoutStatus::isValid($payoutStatus)) {
            $payoutStatus = '';
        }

        $campaign = sanitize_key((string) ($_GET['campaign'] ?? ''));
        if (!in_array($campaign, ['none', 'offer', 'active'], true)) {
            $campaign = '';
        }
        $isSourcing = sanitize_key((string) ($_GET['is_sourcing'] ?? ''));
        if (!in_array($isSourcing, ['yes', 'no'], true)) {
            $isSourcing = '';
        }
        $shipmentType = sanitize_key((string) ($_GET['shipment_type'] ?? ''));
        if ($shipmentType !== 'none' && !ShipmentType::isValid($shipmentType)) {
            $shipmentType = '';
        }
        $isImported = sanitize_key((string) ($_GET['is_imported'] ?? ''));
        if (!in_array($isImported, ['yes', 'no'], true)) {
            $isImported = '';
        }
        $payoutDue = sanitize_key((string) ($_GET['payout_due'] ?? ''));
        if ($payoutDue !== '1') {
            $payoutDue = '';
        }
        $soldFrom = \SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule::normalizeDate($_GET['sold_from'] ?? '');
        $soldTo = \SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule::normalizeDate($_GET['sold_to'] ?? '');

        $view = [
            'detail_id' => absint($_GET['variation_id'] ?? 0),
            'search' => sanitize_text_field((string) ($_GET['search'] ?? '')),
            'status_filter' => sanitize_key((string) ($_GET['status'] ?? '')),
            'queue_filter' => sanitize_key((string) ($_GET['queue'] ?? '')),
            'payout_status' => $payoutStatus,
            'campaign' => $campaign,
            'is_sourcing' => $isSourcing,
            'shipment_type' => $shipmentType,
            'is_imported' => $isImported,
            'payout_due' => $payoutDue,
            'sold_from' => $soldFrom,
            'sold_to' => $soldTo,
            'orderby' => $orderby,
            'page' => max(1, absint($_GET['paged'] ?? 1)),
            'base_url' => wc_get_account_endpoint_url(self::ENDPOINT_MANAGE_PRODUCTS),
        ];

        include SUTORE_MARKETPLACE_PATH . 'templates/staff-manage-products.php';
    }

    public function renderManageOrders(): void
    {
        if (!is_user_logged_in() || !$this->currentUserCanManage()) {
            echo '<p class="sutore-mp-error">' . esc_html__('You do not have access to this area.', 'sutore-marketplace') . '</p>';
            return;
        }

        $this->assets->enqueueStaffOrders();

        $orderby = sanitize_key((string) ($_GET['orderby'] ?? 'date_desc'));
        $allowedOrderby = [
            'date_desc',
            'date_asc',
            'id_desc',
            'id_asc',
            'total_desc',
            'total_asc',
            'deadline_asc',
            'deadline_desc',
        ];
        if (!in_array($orderby, $allowedOrderby, true)) {
            $orderby = 'date_desc';
        }

        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        if (str_starts_with($status, 'wc-')) {
            $status = substr($status, 3);
        }

        $view = [
            'detail_id' => absint($_GET['order_id'] ?? 0),
            'search' => sanitize_text_field((string) ($_GET['search'] ?? '')),
            'status_filter' => $status,
            'orderby' => $orderby,
            'page' => max(1, absint($_GET['paged'] ?? 1)),
            'base_url' => wc_get_account_endpoint_url(self::ENDPOINT_MANAGE_ORDERS),
            'status_labels' => wc_get_order_statuses(),
        ];

        include SUTORE_MARKETPLACE_PATH . 'templates/staff-orders.php';
    }

    public function renderMerchants(): void
    {
        if (!is_user_logged_in() || !$this->currentUserCanManage()) {
            echo '<p class="sutore-mp-error">' . esc_html__('You do not have access to this area.', 'sutore-marketplace') . '</p>';
            return;
        }

        $this->assets->enqueueStaffMerchants();

        $view = [
            'detail_id' => absint($_GET['merchant_id'] ?? 0),
            'search' => sanitize_text_field((string) ($_GET['search'] ?? '')),
            'level' => sanitize_key((string) ($_GET['level'] ?? '')),
            'tc_verified' => sanitize_text_field((string) ($_GET['tc_verified'] ?? '')),
            'has_restriction' => sanitize_text_field((string) ($_GET['has_restriction'] ?? '')),
            'balance' => sanitize_key((string) ($_GET['balance'] ?? '')),
            'sales' => sanitize_key((string) ($_GET['sales'] ?? '')),
            'orderby' => sanitize_key((string) ($_GET['orderby'] ?? 'id_desc')),
            'page' => max(1, absint($_GET['paged'] ?? 1)),
            'base_url' => wc_get_account_endpoint_url(self::ENDPOINT_MERCHANTS),
        ];

        include SUTORE_MARKETPLACE_PATH . 'templates/staff-merchants.php';
    }

    public function renderCatalogRequests(): void
    {
        if (!is_user_logged_in() || !$this->currentUserCanManage()) {
            echo '<p class="sutore-mp-error">' . esc_html__('You do not have access to this area.', 'sutore-marketplace') . '</p>';
            return;
        }

        $this->assets->enqueueStaffCatalogRequests();

        $status = sanitize_key((string) ($_GET['status'] ?? CatalogProductRequestStatus::PENDING));
        if ($status !== '' && !CatalogProductRequestStatus::isValid($status)) {
            $status = CatalogProductRequestStatus::PENDING;
        }

        $view = [
            'search' => sanitize_text_field((string) ($_GET['search'] ?? '')),
            'status_filter' => $status,
            'page' => max(1, absint($_GET['paged'] ?? 1)),
            'base_url' => wc_get_account_endpoint_url(self::ENDPOINT_CATALOG_REQUESTS),
            'status_labels' => CatalogProductRequestStatus::labels(),
        ];

        include SUTORE_MARKETPLACE_PATH . 'templates/staff-catalog-product-requests.php';
    }

    private function currentUserCanManage(): bool
    {
        return is_user_logged_in() && current_user_can(AdminMenu::CAP);
    }

    public function maybeFlushRewrites(): void
    {
        $flag = 'sutore_marketplace_endpoints_' . self::ENDPOINTS_REVISION;
        if (get_option($flag)) {
            return;
        }

        flush_rewrite_rules(false);
        update_option($flag, 1, false);
    }
}
