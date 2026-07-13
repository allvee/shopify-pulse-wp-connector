<?php
/**
 * Plugin Name:       Shopify Pulse Connector
 * Plugin URI:        https://github.com/allvee/shopify-pulse-wp-connector
 * Description:        Mirrors WooCommerce orders, incomplete/abandoned carts and analytics into the Shopify Pulse platform so a store can be managed from there. Connects any WooCommerce site to one Shopify Pulse store via OAuth.
 * Version:           1.2.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Shopify Pulse
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shopify-pulse-connector
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'SHOPIFY_PULSE_VERSION', '1.2.2' );
define( 'SHOPIFY_PULSE_FILE', __FILE__ );
define( 'SHOPIFY_PULSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHOPIFY_PULSE_URL', plugin_dir_url( __FILE__ ) );
define( 'SHOPIFY_PULSE_BASENAME', plugin_basename( __FILE__ ) );

// Shared identifiers used across the plugin.
define( 'SHOPIFY_PULSE_OPTION', 'shopify_pulse_settings' );
define( 'SHOPIFY_PULSE_TOKEN_TRANSIENT', 'shopify_pulse_token' );
define( 'SHOPIFY_PULSE_AS_GROUP', 'shopify-pulse-connector' );
define( 'SHOPIFY_PULSE_SYNC_ACTION', 'shopify_pulse_sync_order' );
define( 'SHOPIFY_PULSE_ABANDONED_CRON', 'shopify_pulse_abandoned_sweep' );
define( 'SHOPIFY_PULSE_ABANDONED_PUSH_ACTION', 'shopify_pulse_abandoned_push' );
define( 'SHOPIFY_PULSE_POLL_CRON', 'shopify_pulse_status_poll' );
define( 'SHOPIFY_PULSE_CUSTOMER_SYNC_ACTION', 'shopify_pulse_sync_customer' );
define( 'SHOPIFY_PULSE_CUSTOMER_PULL_CRON', 'shopify_pulse_customer_pull' );
define( 'SHOPIFY_PULSE_TERM_SYNC_ACTION', 'shopify_pulse_sync_term' );
define( 'SHOPIFY_PULSE_PRODUCT_SYNC_ACTION', 'shopify_pulse_sync_product' );
define( 'SHOPIFY_PULSE_CATALOG_PULL_CRON', 'shopify_pulse_catalog_pull' );

// Order meta keys.
define( 'SHOPIFY_PULSE_META_ID', '_sp_order_id' );
define( 'SHOPIFY_PULSE_META_HASH', '_sp_sync_hash' );
define( 'SHOPIFY_PULSE_META_SYNCED_AT', '_sp_synced_at' );
define( 'SHOPIFY_PULSE_META_ATTEMPTS', '_sp_sync_attempts' );
define( 'SHOPIFY_PULSE_META_PIXEL_SENT', '_sp_purchase_pixel_sent' );

require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-logger.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-settings.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-api-client.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-attribution.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-order-mapper.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-order-sync.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-abandoned-sync.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-analytics.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-fraud.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-customer-sync.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-seo.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-catalog-sync.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-product-sync.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-seo-sync.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-status-poller.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-orders-column.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-install.php';
require_once SHOPIFY_PULSE_DIR . 'includes/class-shopify-pulse-plugin.php';

register_activation_hook( __FILE__, array( 'Shopify_Pulse_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Shopify_Pulse_Install', 'deactivate' ) );

// Custom cron cadences must be available on every request (wp-cron included),
// independent of whether the full plugin boots — register globally.
add_filter( 'cron_schedules', array( 'Shopify_Pulse_Install', 'cron_schedules' ) );

/**
 * Boot the plugin once WooCommerce is loaded. If WooCommerce is absent we show
 * an admin notice and stay dormant — every flow here is WooCommerce-specific.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Shopify Pulse Connector requires WooCommerce to be installed and active.', 'shopify-pulse-connector' );
					echo '</p></div>';
				}
			);
			return;
		}
		// Self-heal DB table + crons after an update-in-place (the activation
		// hook doesn't fire when plugin files are replaced).
		Shopify_Pulse_Install::maybe_upgrade();
		Shopify_Pulse_Plugin::instance()->init();
	},
	20
);

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
