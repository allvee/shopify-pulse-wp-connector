<?php
/**
 * Activation / deactivation: the abandoned-cart capture table and the two
 * WP-Cron schedules (abandoned sweep, status poll).
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wafi_Connector_Install {

	/** Register custom cron cadences. Hooked on `cron_schedules` globally. */
	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['wafi_10min'] ) ) {
			$schedules['wafi_10min'] = array(
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 10 minutes (Wafi)', 'wafi-connector' ),
			);
		}
		if ( ! isset( $schedules['wafi_15min'] ) ) {
			$schedules['wafi_15min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (Wafi)', 'wafi-connector' ),
			);
		}
		return $schedules;
	}

	public static function activate() {
		self::create_table();
		self::schedule_crons();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( WAFI_CONNECTOR_ABANDONED_CRON );
		wp_clear_scheduled_hook( WAFI_CONNECTOR_POLL_CRON );
	}

	public static function schedule_crons() {
		if ( ! wp_next_scheduled( WAFI_CONNECTOR_ABANDONED_CRON ) ) {
			wp_schedule_event( time() + 300, 'wafi_15min', WAFI_CONNECTOR_ABANDONED_CRON );
		}
		if ( ! wp_next_scheduled( WAFI_CONNECTOR_POLL_CRON ) ) {
			wp_schedule_event( time() + 300, 'wafi_10min', WAFI_CONNECTOR_POLL_CRON );
		}
	}

	public static function create_table() {
		global $wpdb;
		$table           = Wafi_Connector_Abandoned_Sync::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  session_key varchar(64) NOT NULL,
  email varchar(191) DEFAULT NULL,
  phone varchar(64) DEFAULT NULL,
  cart_json longtext,
  subtotal decimal(18,4) NOT NULL DEFAULT 0,
  currency varchar(8) DEFAULT NULL,
  furthest_step varchar(32) DEFAULT NULL,
  converted tinyint(1) NOT NULL DEFAULT 0,
  synced tinyint(1) NOT NULL DEFAULT 0,
  synced_hash varchar(64) DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (session_key),
  KEY converted_synced_updated (converted, synced, updated_at)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
