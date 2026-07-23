<?php
/**
 * Plugin Name: Sutore Marketplace
 * Plugin URI:  https://sutore.com
 * Description: Merchant listings, pricing, order fulfillment workflow, campaigns, cart pricing and admin marketplace tools.
 * Version:     2.1.11
 * Author:      Sutore
 * Text Domain: sutore-marketplace
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SUTORE_MARKETPLACE_VERSION', '2.1.11');
define('SUTORE_MARKETPLACE_FILE', __FILE__);
define('SUTORE_MARKETPLACE_PATH', plugin_dir_path(__FILE__));
define('SUTORE_MARKETPLACE_URL', plugin_dir_url(__FILE__));
define('SUTORE_MARKETPLACE_BASENAME', plugin_basename(__FILE__));

require_once SUTORE_MARKETPLACE_PATH . 'includes/Bootstrap/class-autoloader.php';
SutoreMarketplace\Bootstrap\Autoloader::register();

register_activation_hook(__FILE__, ['SutoreMarketplace\\Bootstrap\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['SutoreMarketplace\\Bootstrap\\Activator', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Sutore Marketplace requires WooCommerce to be active.', 'sutore-marketplace')
                . '</p></div>';
        });
        return;
    }

    SutoreMarketplace\Bootstrap\Plugin::instance()->boot();
});
