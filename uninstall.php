<?php
/**
 * Uninstall cleanup: settings, cached token, cursor, cron events and the
 * abandoned-cart capture table. Order meta (_sp_order_id …) is intentionally
 * left in place so re-installing keeps the mapping.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'shopify_pulse_settings' );
delete_option( 'shopify_pulse_poll_cursor' );
delete_option( 'shopify_pulse_status' );
delete_transient( 'shopify_pulse_token' );

wp_clear_scheduled_hook( 'shopify_pulse_abandoned_sweep' );
wp_clear_scheduled_hook( 'shopify_pulse_status_poll' );

global $wpdb;
$table = $wpdb->prefix . 'sp_abandoned_carts';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
