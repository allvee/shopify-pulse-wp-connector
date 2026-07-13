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

class Shopify_Pulse_Install {

	/** Register custom cron cadences. Hooked on `cron_schedules` globally. */
	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['wafi_10min'] ) ) {
			$schedules['wafi_10min'] = array(
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 10 minutes (Wafi)', 'shopify-pulse-connector' ),
			);
		}
		if ( ! isset( $schedules['wafi_15min'] ) ) {
			$schedules['wafi_15min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (Wafi)', 'shopify-pulse-connector' ),
			);
		}
		return $schedules;
	}

	public static function activate() {
		self::migrate_from_wafi();
		self::create_table();
		self::schedule_crons();
		update_option( 'shopify_pulse_version', SHOPIFY_PULSE_VERSION, false );
	}

	/**
	 * Carry state over from the pre-rename "Wafi Commerce Connector" plugin.
	 * The order/term/product meta keys and the abandoned-cart table name are
	 * deliberately unchanged by the rename, so every already-synced order keeps
	 * its platform link with no data migration — only the option keys moved.
	 * Idempotent: runs on activate + on every version change, copies only when
	 * the new option is absent, and clears the old plugin's orphaned WP-Cron
	 * hooks (their handlers were renamed).
	 */
	private static function migrate_from_wafi() {
		$options = array(
			'shopify_pulse_settings' => 'wafi_connector_settings',
			'shopify_pulse_status'   => 'wafi_connector_status',
		);
		foreach ( $options as $new => $old ) {
			if ( false === get_option( $new, false ) ) {
				$val = get_option( $old, null );
				if ( null !== $val ) {
					update_option( $new, $val, false );
				}
			}
		}
		$old_hooks = array(
			'wafi_connector_abandoned_sweep',
			'wafi_connector_status_poll',
			'wafi_connector_customer_pull',
			'wafi_connector_catalog_pull',
		);
		foreach ( $old_hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Self-heal on update-in-place. WordPress does NOT fire the activation hook
	 * when a plugin is updated by replacing its files, so re-run the idempotent
	 * setup (dbDelta table + cron scheduling) whenever the stored version differs
	 * from the running one. Cheap: after the first post-update request stores the
	 * new version, this is a single get_option no-op.
	 */
	public static function maybe_upgrade() {
		if ( SHOPIFY_PULSE_VERSION === get_option( 'shopify_pulse_version' ) ) {
			return;
		}
		self::migrate_from_wafi();
		self::create_table();
		self::schedule_crons();
		update_option( 'shopify_pulse_version', SHOPIFY_PULSE_VERSION, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( SHOPIFY_PULSE_ABANDONED_CRON );
		wp_clear_scheduled_hook( SHOPIFY_PULSE_POLL_CRON );
		wp_clear_scheduled_hook( SHOPIFY_PULSE_CUSTOMER_PULL_CRON );
		wp_clear_scheduled_hook( SHOPIFY_PULSE_CATALOG_PULL_CRON );
	}

	public static function schedule_crons() {
		if ( ! wp_next_scheduled( SHOPIFY_PULSE_ABANDONED_CRON ) ) {
			wp_schedule_event( time() + 300, 'wafi_15min', SHOPIFY_PULSE_ABANDONED_CRON );
		}
		if ( ! wp_next_scheduled( SHOPIFY_PULSE_POLL_CRON ) ) {
			wp_schedule_event( time() + 300, 'wafi_10min', SHOPIFY_PULSE_POLL_CRON );
		}
		if ( ! wp_next_scheduled( SHOPIFY_PULSE_CUSTOMER_PULL_CRON ) ) {
			wp_schedule_event( time() + 300, 'wafi_15min', SHOPIFY_PULSE_CUSTOMER_PULL_CRON );
		}
		if ( ! wp_next_scheduled( SHOPIFY_PULSE_CATALOG_PULL_CRON ) ) {
			wp_schedule_event( time() + 300, 'wafi_15min', SHOPIFY_PULSE_CATALOG_PULL_CRON );
		}
	}

	public static function create_table() {
		global $wpdb;
		$table           = Shopify_Pulse_Abandoned_Sync::table_name();
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
