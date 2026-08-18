<?php

declare(strict_types=1);

namespace SutoreMarketplace\Frontend;

use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;

/**
 * Customer WooCommerce My Account: my-offers + notifications (shell + REST).
 */
final class CustomerAccount
{
    public const ENDPOINT_MY_OFFERS = 'my-offers';
    public const ENDPOINT_NOTIFICATIONS = 'notifications';

    private const ENDPOINTS_REVISION = '20260818-customer-notifications';

    public function __construct(
        private readonly Assets $assets = new Assets(),
    ) {
    }

    public function register(): void
    {
        $this->assets->register();
        add_action('init', [$this, 'addEndpoints']);
        add_filter('woocommerce_account_menu_items', [$this, 'menuItems'], 98);
        add_filter('woocommerce_get_query_vars', [$this, 'queryVars']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueEndpointAssets'], 20);
        add_action('woocommerce_account_' . self::ENDPOINT_MY_OFFERS . '_endpoint', [$this, 'renderMyOffers']);
        add_action('wp_loaded', [$this, 'maybeFlushRewrites']);
    }

    public function enqueueEndpointAssets(): void
    {
        if (!is_account_page() || !is_user_logged_in()) {
            return;
        }

        global $wp;
        if (isset($wp->query_vars[self::ENDPOINT_MY_OFFERS])) {
            $this->assets->enqueueMyOffers();
        }
        if (isset($wp->query_vars[self::ENDPOINT_NOTIFICATIONS])) {
            $this->assets->enqueueNotifications();
        }
    }

    public function addEndpoints(): void
    {
        add_rewrite_endpoint(self::ENDPOINT_MY_OFFERS, EP_ROOT | EP_PAGES);
        add_rewrite_endpoint(self::ENDPOINT_NOTIFICATIONS, EP_ROOT | EP_PAGES);
    }

    /**
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    public function queryVars(array $vars): array
    {
        $vars[self::ENDPOINT_MY_OFFERS] = self::ENDPOINT_MY_OFFERS;
        $vars[self::ENDPOINT_NOTIFICATIONS] = self::ENDPOINT_NOTIFICATIONS;

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
            self::ENDPOINT_MY_OFFERS => __('My offers', 'sutore-marketplace'),
        ];
        if (!MerchantMeta::canViewMerchantDashboard(get_current_user_id())) {
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
        }

        return $result;
    }

    public function renderMyOffers(): void
    {
        if (!is_user_logged_in()) {
            echo '<p class="sutore-mp-error">' . esc_html__('You must log in.', 'sutore-marketplace') . '</p>';
            return;
        }

        $this->assets->enqueueMyOffers();
        ob_start();
        include SUTORE_MARKETPLACE_PATH . 'templates/customer-my-offers.php';
        echo (string) ob_get_clean();
    }

    /** @return list<string> */
    public static function endpointSlugs(): array
    {
        return [self::ENDPOINT_MY_OFFERS, self::ENDPOINT_NOTIFICATIONS];
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
