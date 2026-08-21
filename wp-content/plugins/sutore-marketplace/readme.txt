=== Sutore Marketplace ===
Contributors: sutore
Tags: woocommerce, marketplace, multi-vendor
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 9.0
Stable tag: 2.1.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Merchant listings, pricing, order fulfillment, campaigns, cart pricing, and marketplace admin tools for WooCommerce.

== Description ==

Sutore Marketplace turns WooCommerce into a multi-seller marketplace: listings with a linear status pipeline, campaigns, outlet windows, customer price offers, sourcing/pre-order, merchant tasks, payouts, SMS/OTP, and e-Archive invoicing.

Requires WooCommerce. HTTP surface is REST-first (`sutore-marketplace/v1`); My Account UIs are shell + client fetch.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/sutore-marketplace`.
2. Activate WooCommerce, then activate Sutore Marketplace.
3. Open **Sutore Marketplace → Settings** and configure pricing, SMS, and order flow.

== Frequently Asked Questions ==

= Does uninstall remove data? =

By default, no. Set `SUTORE_MARKETPLACE_PURGE_ON_UNINSTALL` to true in `wp-config.php` before deleting the plugin to drop custom tables and options.

= How do I run cron jobs manually? =

With WP-CLI: `wp sutore-marketplace cron list`, `wp sutore-marketplace cron run <job>`, or `wp sutore-marketplace cron run-all`.

== Changelog ==

= 2.1.11 =
* Development baseline aligned with schema version 102.
* Settings API registration, centralized cron registry, staff capabilities, WP-CLI cron runners.

== Upgrade Notice ==

= 2.1.11 =
Re-activate the plugin (or run a schema install) after upgrade so cron schedules and staff capabilities reconcile.
