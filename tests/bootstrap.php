<?php
/**
 * PHPUnit bootstrap: boot the WordPress test suite, load WooCommerce (if
 * present) and this plugin, and install WooCommerce's tables so the
 * order-mapper tests can build real WC_Order objects.
 *
 * @package ShopifyPulse
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php — run bin/install-wp-tests.sh first." . PHP_EOL; // phpcs:ignore
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/** Load WooCommerce (optional) then this plugin as a must-use plugin. */
function _sp_manually_load_plugins() {
	$wc = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if ( file_exists( $wc ) ) {
		require $wc;
	}
	require dirname( __DIR__ ) . '/shopify-pulse-connector.php';
}
tests_add_filter( 'muplugins_loaded', '_sp_manually_load_plugins' );

/** Create WooCommerce's DB tables so WC_Order / WC_Product work in tests. */
function _sp_install_woocommerce() {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}
	define( 'WP_UNINSTALL_PLUGIN', true );
	WC_Install::install();
	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	wp_roles();
}
tests_add_filter( 'setup_theme', '_sp_install_woocommerce' );

require "{$_tests_dir}/includes/bootstrap.php";
