<?php
/**
 * Plugin Name:       Wafi Commerce Connector
 * Plugin URI:        https://github.com/wafiperfume/wafi-wp-connector
 * Description:        Mirrors WooCommerce orders, incomplete/abandoned carts and analytics into the Wafi Commerce platform so a store can be managed from there. Connects any WooCommerce site to one Wafi store via OAuth.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Wafi Commerce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wafi-connector
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WAFI_CONNECTOR_VERSION', '1.0.0' );
define( 'WAFI_CONNECTOR_FILE', __FILE__ );
define( 'WAFI_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAFI_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );
define( 'WAFI_CONNECTOR_BASENAME', plugin_basename( __FILE__ ) );

// Shared identifiers used across the plugin.
define( 'WAFI_CONNECTOR_OPTION', 'wafi_connector_settings' );
define( 'WAFI_CONNECTOR_TOKEN_TRANSIENT', 'wafi_connector_token' );
define( 'WAFI_CONNECTOR_AS_GROUP', 'wafi-connector' );
define( 'WAFI_CONNECTOR_SYNC_ACTION', 'wafi_connector_sync_order' );
define( 'WAFI_CONNECTOR_ABANDONED_CRON', 'wafi_connector_abandoned_sweep' );
define( 'WAFI_CONNECTOR_ABANDONED_PUSH_ACTION', 'wafi_connector_abandoned_push' );
define( 'WAFI_CONNECTOR_POLL_CRON', 'wafi_connector_status_poll' );
define( 'WAFI_CONNECTOR_CUSTOMER_SYNC_ACTION', 'wafi_connector_sync_customer' );
define( 'WAFI_CONNECTOR_CUSTOMER_PULL_CRON', 'wafi_connector_customer_pull' );
define( 'WAFI_CONNECTOR_TERM_SYNC_ACTION', 'wafi_connector_sync_term' );
define( 'WAFI_CONNECTOR_PRODUCT_SYNC_ACTION', 'wafi_connector_sync_product' );
define( 'WAFI_CONNECTOR_CATALOG_PULL_CRON', 'wafi_connector_catalog_pull' );

// Order meta keys.
define( 'WAFI_CONNECTOR_META_ID', '_wafi_order_id' );
define( 'WAFI_CONNECTOR_META_HASH', '_wafi_sync_hash' );
define( 'WAFI_CONNECTOR_META_SYNCED_AT', '_wafi_synced_at' );
define( 'WAFI_CONNECTOR_META_ATTEMPTS', '_wafi_sync_attempts' );
define( 'WAFI_CONNECTOR_META_PIXEL_SENT', '_wafi_purchase_pixel_sent' );

require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-logger.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-settings.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-api-client.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-attribution.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-order-mapper.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-order-sync.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-abandoned-sync.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-analytics.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-fraud.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-customer-sync.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-seo.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-catalog-sync.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-product-sync.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-seo-sync.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-status-poller.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-install.php';
require_once WAFI_CONNECTOR_DIR . 'includes/class-wafi-plugin.php';

register_activation_hook( __FILE__, array( 'Wafi_Connector_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Wafi_Connector_Install', 'deactivate' ) );

// Custom cron cadences must be available on every request (wp-cron included),
// independent of whether the full plugin boots — register globally.
add_filter( 'cron_schedules', array( 'Wafi_Connector_Install', 'cron_schedules' ) );

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
					echo esc_html__( 'Wafi Commerce Connector requires WooCommerce to be installed and active.', 'wafi-connector' );
					echo '</p></div>';
				}
			);
			return;
		}
		Wafi_Connector_Plugin::instance()->init();
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
