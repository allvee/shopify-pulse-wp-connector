<?php
/**
 * Uninstall cleanup: settings, cached token, cursor, cron events and the
 * abandoned-cart capture table. Order meta (_wafi_order_id …) is intentionally
 * left in place so re-installing keeps the mapping.
 *
 * @package WafiConnector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wafi_connector_settings' );
delete_option( 'wafi_connector_poll_cursor' );
delete_option( 'wafi_connector_status' );
delete_transient( 'wafi_connector_token' );

wp_clear_scheduled_hook( 'wafi_connector_abandoned_sweep' );
wp_clear_scheduled_hook( 'wafi_connector_status_poll' );

global $wpdb;
$table = $wpdb->prefix . 'wafi_abandoned_carts';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
