<?php
/**
 * Activation / deactivation: the abandoned-cart capture table and the two
 * WP-Cron schedules (abandoned sweep, status poll).
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Install {

	/** Register custom cron cadences. Hooked on `cron_schedules` globally. */
	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['sp_10min'] ) ) {
			$schedules['sp_10min'] = array(
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 10 minutes (Shopify Pulse)', 'shopify-pulse-connector' ),
			);
		}
		if ( ! isset( $schedules['sp_15min'] ) ) {
			$schedules['sp_15min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (Shopify Pulse)', 'shopify-pulse-connector' ),
			);
		}
		return $schedules;
	}

	public static function activate() {
		self::migrate_legacy();
		self::create_table();
		self::schedule_crons();
		// Drop any cached access token so the next request re-mints with the
		// current scope logic (an old token may carry a stale narrow scope).
		delete_transient( SHOPIFY_PULSE_TOKEN_TRANSIENT );
		update_option( 'shopify_pulse_version', SHOPIFY_PULSE_VERSION, false );
	}

	/**
	 * Carry state + data over from the pre-rename "Wafi Commerce Connector"
	 * plugin (and its interim Shopify Pulse build). Copies the old settings +
	 * status options, RENAMES the abandoned-cart table, and RE-KEYS our meta
	 * from the old `_wafi_` prefix to `_sp_` across every meta store (posts /
	 * products, users, terms, HPOS order meta) so already-synced orders keep
	 * their platform link. Idempotent: runs on activate + on every version
	 * change; each step is guarded so re-runs are cheap no-ops.
	 */
	private static function migrate_legacy() {
		global $wpdb;

		// 1. Options from the original Wafi plugin.
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

		// 2. Rename the abandoned-cart table (preserves rows).
		$old_table = $wpdb->prefix . 'wafi_abandoned_carts';
		$new_table = $wpdb->prefix . 'sp_abandoned_carts';
		if ( $old_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) // phpcs:ignore WordPress.DB
			&& $new_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) ) { // phpcs:ignore WordPress.DB
			$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" ); // phpcs:ignore WordPress.DB
		}

		// 3. Re-key our meta `_wafi_*` -> `_sp_*` in every meta store.
		$meta_tables = array( $wpdb->postmeta, $wpdb->usermeta, $wpdb->termmeta );
		$hpos        = $wpdb->prefix . 'wc_orders_meta';
		if ( $hpos === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) ) { // phpcs:ignore WordPress.DB
			$meta_tables[] = $hpos;
		}
		foreach ( $meta_tables as $t ) {
			$wpdb->query( "UPDATE `{$t}` SET meta_key = CONCAT('_sp_', SUBSTRING(meta_key, 7)) WHERE SUBSTRING(meta_key, 1, 6) = '_wafi_'" ); // phpcs:ignore WordPress.DB
		}

		// 4. Clear the original plugin's cron hooks + our own (so schedule_crons
		//    re-registers them on the renamed cadence, not the stale one).
		$stale = array(
			'wafi_connector_abandoned_sweep',
			'wafi_connector_status_poll',
			'wafi_connector_customer_pull',
			'wafi_connector_catalog_pull',
			SHOPIFY_PULSE_ABANDONED_CRON,
			SHOPIFY_PULSE_POLL_CRON,
			SHOPIFY_PULSE_CUSTOMER_PULL_CRON,
			SHOPIFY_PULSE_CATALOG_PULL_CRON,
		);
		foreach ( $stale as $hook ) {
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
		self::migrate_legacy();
		self::create_table();
		self::schedule_crons();
		// Drop any cached access token so the next request re-mints with the
		// current scope logic (an old token may carry a stale narrow scope).
		delete_transient( SHOPIFY_PULSE_TOKEN_TRANSIENT );
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
			wp_schedule_event( time() + 300, 'sp_15min', SHOPIFY_PULSE_ABANDONED_CRON );
		}
		if ( ! wp_next_scheduled( SHOPIFY_PULSE_POLL_CRON ) ) {
			wp_schedule_event( time() + 300, 'sp_10min', SHOPIFY_PULSE_POLL_CRON );
		}
		if ( ! wp_next_scheduled( SHOPIFY_PULSE_CUSTOMER_PULL_CRON ) ) {
			wp_schedule_event( time() + 300, 'sp_15min', SHOPIFY_PULSE_CUSTOMER_PULL_CRON );
		}
		if ( ! wp_next_scheduled( SHOPIFY_PULSE_CATALOG_PULL_CRON ) ) {
			wp_schedule_event( time() + 300, 'sp_15min', SHOPIFY_PULSE_CATALOG_PULL_CRON );
		}
	}

	public static function create_table() {
		global $wpdb;
		$table           = Shopify_Pulse_Abandoned_Sync::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  session_key varchar(64) NOT NULL,
  customer_name varchar(191) DEFAULT NULL,
  email varchar(191) DEFAULT NULL,
  phone varchar(64) DEFAULT NULL,
  address_json longtext,
  cart_json longtext,
  subtotal decimal(18,4) NOT NULL DEFAULT 0,
  currency varchar(8) DEFAULT NULL,
  furthest_step varchar(32) DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  wc_order_id bigint(20) unsigned DEFAULT NULL,
  converted tinyint(1) NOT NULL DEFAULT 0,
  synced tinyint(1) NOT NULL DEFAULT 0,
  synced_hash varchar(64) DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (session_key),
  KEY status_synced_updated (status, synced, updated_at),
  KEY converted_synced_updated (converted, synced, updated_at)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Back-fill the disposition column on installs upgrading from a build
		// that only had the `converted` flag, so an already-recovered cart keeps
		// its status after the column is added (dbDelta defaults it to 'active').
		$col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'status' ) ); // phpcs:ignore WordPress.DB
		if ( 'status' === $col ) {
			$wpdb->query( "UPDATE {$table} SET status = 'converted' WHERE converted = 1 AND status = 'active'" ); // phpcs:ignore WordPress.DB
		}
	}
}
