<?php

declare(strict_types=1);

namespace SutoreMarketplace\Frontend;

use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Settings\Settings;

/**
 * Merchant WooCommerce My Account endpoints:
 * - listings  → Listinglerim (+ ?variation_id= manage / ?action=create|bulk modals)
 * - sourcing  → Pre-order (list + detail/accept modal)
 * - campaign-offers → Kampanya teklifleri
 * - price-offers → Müşteri fiyat teklifleri
 * - outlet → Outlet
 * - merchant-area → Satıcı Özel (profil, bakiye)
 * - tasks → Görevlerim
 * - notifications → Bildirimler
 */
final class MerchantAccount
{
    public const ENDPOINT_LISTINGS = 'listings';
    public const ENDPOINT_SOURCING = 'sourcing';
    public const ENDPOINT_CAMPAIGN_OFFERS = 'campaign-offers';
    public const ENDPOINT_PRICE_OFFERS = 'price-offers';
    public const ENDPOINT_OUTLET = 'outlet';
    public const ENDPOINT_MERCHANT_AREA = 'merchant-area';
    public const ENDPOINT_TASKS = 'tasks';
    public const ENDPOINT_NOTIFICATIONS = 'notifications';

    public function __construct(
        private readonly Assets $assets = new Assets(),
    ) {
    }

    public function register(): void
    {
        $this->assets->register();
        add_action('init', [$this, 'addEndpoints']);
        add_filter('woocommerce_account_menu_items', [$this, 'menuItems'], 99);
        add_filter('woocommerce_get_query_vars', [$this, 'queryVars']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueEndpointAssets'], 20);
        add_action('woocommerce_account_' . self::ENDPOINT_LISTINGS . '_endpoint', [$this, 'renderListings']);
        add_action('woocommerce_account_' . self::ENDPOINT_SOURCING . '_endpoint', [$this, 'renderSourcing']);
        add_action('woocommerce_account_' . self::ENDPOINT_CAMPAIGN_OFFERS . '_endpoint', [$this, 'renderCampaignOffers']);
        add_action('woocommerce_account_' . self::ENDPOINT_PRICE_OFFERS . '_endpoint', [$this, 'renderPriceOffers']);
        add_action('woocommerce_account_' . self::ENDPOINT_OUTLET . '_endpoint', [$this, 'renderOutlet']);
        add_action('woocommerce_account_' . self::ENDPOINT_MERCHANT_AREA . '_endpoint', [$this, 'renderMerchantArea']);
        add_action('woocommerce_account_' . self::ENDPOINT_TASKS . '_endpoint', [$this, 'renderTasks']);
        add_action('woocommerce_account_' . self::ENDPOINT_NOTIFICATIONS . '_endpoint', [$this, 'renderNotifications']);
        add_action('wp_loaded', [$this, 'maybeFlushRewrites']);
    }

    public function enqueueEndpointAssets(): void
    {
        if (!is_account_page() || !is_user_logged_in()) {
            return;
        }

        global $wp;
        if (isset($wp->query_vars[self::ENDPOINT_MERCHANT_AREA])) {
            $this->assets->enqueueMerchantProfile();
            return;
        }

        if (isset($wp->query_vars[self::ENDPOINT_TASKS])) {
            if (MerchantMeta::isMerchant(get_current_user_id())) {
                $this->assets->enqueueTasks();
            }
            return;
        }

        if (isset($wp->query_vars[self::ENDPOINT_NOTIFICATIONS])) {
            if (MerchantMeta::canViewMerchantDashboard(get_current_user_id())) {
                $this->assets->enqueueNotifications();
            }
            return;
        }

        $auth = ListingPolicy::assertCanManage();
        if (is_wp_error($auth)) {
            return;
        }

        if (isset($wp->query_vars[self::ENDPOINT_LISTINGS])) {
            $this->assets->enqueueListings();
            return;
        }
        if (isset($wp->query_vars[self::ENDPOINT_SOURCING])) {
            $this->assets->enqueueSourcing();
            return;
        }
        if (isset($wp->query_vars[self::ENDPOINT_CAMPAIGN_OFFERS])) {
            $this->assets->enqueueCampaignOffers();
            return;
        }
        if (isset($wp->query_vars[self::ENDPOINT_PRICE_OFFERS])) {
            $this->assets->enqueuePriceOffers();
            return;
        }
        if (isset($wp->query_vars[self::ENDPOINT_OUTLET])) {
            $this->assets->enqueueOutlet();
        }
    }

    public function addEndpoints(): void
    {
        foreach ($this->endpoints() as $endpoint) {
            add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
        }
    }

    /**
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    public function queryVars(array $vars): array
    {
        foreach ($this->endpoints() as $endpoint) {
            $vars[$endpoint] = $endpoint;
        }
        return $vars;
    }

    /**
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public function menuItems(array $items): array
    {
        if (!is_user_logged_in()) {
            return $items;
        }

        $extra = [
            self::ENDPOINT_MERCHANT_AREA => __('Merchant exclusive', 'sutore-marketplace'),
        ];

        if (MerchantMeta::isMerchant(get_current_user_id())) {
            $extra[self::ENDPOINT_TASKS] = __('Opportunities', 'sutore-marketplace');
        }

        if (MerchantMeta::canViewMerchantDashboard(get_current_user_id())) {
            $unread = (new NotificationService())->unreadCount(get_current_user_id());
            $label = __('Notifications', 'sutore-marketplace');
            if ($unread > 0) {
                $label = sprintf(
                    /* translators: %d: unread notification count */
                    __('%1$s (%2$d)', 'sutore-marketplace'),
                    $label,
                    $unread
                );
            }
            $extra[self::ENDPOINT_NOTIFICATIONS] = $label;
        }

        if (!is_wp_error(ListingPolicy::assertCanManage())) {
            $extra[self::ENDPOINT_LISTINGS] = __('My Listings', 'sutore-marketplace');
            if (ListingPolicy::canAccessSourcingBoard()) {
                $extra[self::ENDPOINT_SOURCING] = __('Pre-order', 'sutore-marketplace');
            }
            $pendingOffers = (new \SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository())
                ->countForMerchant(get_current_user_id(), 'pending');
            $offersLabel = __('Campaign offers', 'sutore-marketplace');
            if ($pendingOffers > 0) {
                $offersLabel = sprintf(
                    /* translators: %d: pending campaign offer count */
                    __('%1$s (%2$d)', 'sutore-marketplace'),
                    $offersLabel,
                    $pendingOffers
                );
            }
            $extra[self::ENDPOINT_CAMPAIGN_OFFERS] = $offersLabel;
            $pendingPriceOffers = (new \SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository())
                ->countForMerchant(get_current_user_id(), 'pending');
            $priceOffersLabel = __('Customer offers', 'sutore-marketplace');
            if ($pendingPriceOffers > 0) {
                $priceOffersLabel = sprintf(
                    /* translators: %d: pending customer offer count */
                    __('%1$s (%2$d)', 'sutore-marketplace'),
                    $priceOffersLabel,
                    $pendingPriceOffers
                );
            }
            $extra[self::ENDPOINT_PRICE_OFFERS] = $priceOffersLabel;
            $joinableOutlet = (new \SutoreMarketplace\Modules\Listings\Services\OutletService())
                ->countJoinableForMerchant(get_current_user_id());
            $outletLabel = __('Outlet', 'sutore-marketplace');
            if ($joinableOutlet > 0) {
                $outletLabel = sprintf(
                    /* translators: %d: outlet items available to join */
                    __('%1$s (%2$d)', 'sutore-marketplace'),
                    $outletLabel,
                    $joinableOutlet
                );
            }
            $extra[self::ENDPOINT_OUTLET] = $outletLabel;
        }

        $result = [];
        $inserted = false;

        foreach ($items as $key => $label) {
            $result[$key] = $label;
            if ($key === 'dashboard') {
                foreach ($extra as $endpoint => $text) {
                    if (!isset($result[$endpoint])) {
                        $result[$endpoint] = $text;
                    }
                }
                $inserted = true;
            }
        }

        if (!$inserted) {
            $result = array_merge($extra, $result);
        } else {
            foreach ($extra as $endpoint => $text) {
                if (!isset($result[$endpoint])) {
                    $result[$endpoint] = $text;
                }
            }
        }

        return $result;
    }

    public function renderListings(): void
    {
        echo $this->renderListingsHtml();
    }

    public function renderSourcing(): void
    {
        echo $this->guarded(function (): string {
            $this->assets->enqueueSourcing();
            ob_start();
            include SUTORE_MARKETPLACE_PATH . 'templates/merchant-sourcing.php';

            return (string) ob_get_clean();
        });
    }

    public function renderCampaignOffers(): void
    {
        echo $this->guarded(function (): string {
            $this->assets->enqueueCampaignOffers();
            ob_start();
            include SUTORE_MARKETPLACE_PATH . 'templates/merchant-campaign-offers.php';

            return (string) ob_get_clean();
        });
    }

    public function renderPriceOffers(): void
    {
        echo $this->guarded(function (): string {
            $this->assets->enqueuePriceOffers();
            ob_start();
            include SUTORE_MARKETPLACE_PATH . 'templates/merchant-price-offers.php';

            return (string) ob_get_clean();
        });
    }

    public function renderOutlet(): void
    {
        echo $this->guarded(function (): string {
            $this->assets->enqueueOutlet();
            ob_start();
            include SUTORE_MARKETPLACE_PATH . 'templates/merchant-outlet.php';

            return (string) ob_get_clean();
        });
    }

    public function renderMerchantArea(): void
    {
        if (!is_user_logged_in()) {
            echo '<p class="sutore-mp-error">' . esc_html__('You must log in.', 'sutore-marketplace') . '</p>';
            return;
        }

        $this->assets->enqueueMerchantProfile();
        ob_start();
        include SUTORE_MARKETPLACE_PATH . 'templates/merchant-profile.php';
        echo (string) ob_get_clean();
    }

    public function renderTasks(): void
    {
        if (!is_user_logged_in()) {
            echo '<p class="sutore-mp-error">' . esc_html__('You must log in.', 'sutore-marketplace') . '</p>';
            return;
        }

        if (!MerchantMeta::isMerchant(get_current_user_id())) {
            echo '<p class="sutore-mp-error">' . esc_html__('You do not have access to this area.', 'sutore-marketplace') . '</p>';
            return;
        }

        $this->assets->enqueueTasks();
        ob_start();
        include SUTORE_MARKETPLACE_PATH . 'templates/merchant-tasks.php';
        echo (string) ob_get_clean();
    }

    public function renderNotifications(): void
    {
        if (!is_user_logged_in()) {
            echo '<p class="sutore-mp-error">' . esc_html__('You must log in.', 'sutore-marketplace') . '</p>';
            return;
        }

        if (!MerchantMeta::canViewMerchantDashboard(get_current_user_id())) {
            echo '<p class="sutore-mp-error">' . esc_html__('You do not have access to this area.', 'sutore-marketplace') . '</p>';
            return;
        }

        $this->assets->enqueueNotifications();
        ob_start();
        include SUTORE_MARKETPLACE_PATH . 'templates/merchant-notifications.php';
        echo (string) ob_get_clean();
    }

    public function render(): string
    {
        return $this->renderListingsHtml();
    }

    private function renderListingsHtml(): string
    {
        return $this->guarded(function (): string {
            ob_start();
            include SUTORE_MARKETPLACE_PATH . 'templates/merchant-listings.php';

            return (string) ob_get_clean();
        });
    }

    private function guarded(callable $cb): string
    {
        $auth = ListingPolicy::assertCanManage();
        if (is_wp_error($auth)) {
            return '<p class="sutore-mp-error">' . esc_html($auth->get_error_message()) . '</p>';
        }

        return (string) $cb();
    }

    /** @return list<string> */
    public static function endpointSlugs(): array
    {
        return [
            self::ENDPOINT_MERCHANT_AREA,
            self::ENDPOINT_TASKS,
            self::ENDPOINT_NOTIFICATIONS,
            self::ENDPOINT_LISTINGS,
            self::ENDPOINT_SOURCING,
            self::ENDPOINT_CAMPAIGN_OFFERS,
            self::ENDPOINT_PRICE_OFFERS,
            self::ENDPOINT_OUTLET,
        ];
    }

    /** @return list<string> */
    private function endpoints(): array
    {
        return self::endpointSlugs();
    }

    private const ENDPOINTS_REVISION = '20260816-price-offers';

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
