<?php
/**
 * The v1.2.0 data migration — the one path that touches persisted synced-order
 * data. Proves settings carry over, the capture table is renamed (rows kept),
 * our `_wafi_*` meta is re-keyed to `_sp_*`, and stale crons are cleared.
 *
 * @package ShopifyPulse
 */

class Test_Migrate_Legacy extends WP_UnitTestCase {

	/** Invoke the private static Shopify_Pulse_Install::migrate_legacy(). */
	private function run_migrate() {
		$m = new ReflectionMethod( 'Shopify_Pulse_Install', 'migrate_legacy' );
		$m->setAccessible( true );
		$m->invoke( null );
	}

	public function test_copies_legacy_settings_and_status() {
		update_option( 'wafi_connector_settings', array( 'sid' => 'store1', 'active' => 1 ) );
		update_option( 'wafi_connector_status', array( 'ok' => 1, 'sid' => 'store1' ) );
		delete_option( 'shopify_pulse_settings' );
		delete_option( 'shopify_pulse_status' );

		$this->run_migrate();

		$this->assertSame( array( 'sid' => 'store1', 'active' => 1 ), get_option( 'shopify_pulse_settings' ) );
		$this->assertSame( array( 'ok' => 1, 'sid' => 'store1' ), get_option( 'shopify_pulse_status' ) );
	}

	public function test_does_not_overwrite_existing_new_settings() {
		update_option( 'wafi_connector_settings', array( 'sid' => 'old' ) );
		update_option( 'shopify_pulse_settings', array( 'sid' => 'new' ) );

		$this->run_migrate();

		$this->assertSame( array( 'sid' => 'new' ), get_option( 'shopify_pulse_settings' ) );
	}

	public function test_rekeys_wafi_meta_to_sp_across_stores() {
		$post_id = self::factory()->post->create();
		$user_id = self::factory()->user->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );

		update_post_meta( $post_id, '_wafi_order_id', '12345' );
		update_post_meta( $post_id, '_wafi_sync_hash', 'abc' );
		update_user_meta( $user_id, '_wafi_platform_customer_id', '77' );
		update_term_meta( $term_id, '_wafi_platform_id', '9' );

		$this->run_migrate();

		$this->assertSame( '12345', get_post_meta( $post_id, '_sp_order_id', true ) );
		$this->assertSame( 'abc', get_post_meta( $post_id, '_sp_sync_hash', true ) );
		$this->assertSame( '77', get_user_meta( $user_id, '_sp_platform_customer_id', true ) );
		$this->assertSame( '9', get_term_meta( $term_id, '_sp_platform_id', true ) );

		// Old keys gone (so the sync dedup reads the new key, never a stale one).
		$this->assertSame( '', get_post_meta( $post_id, '_wafi_order_id', true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_wafi_platform_customer_id', true ) );
	}

	public function test_leaves_unrelated_meta_untouched() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_edit_lock', '123' );
		update_post_meta( $post_id, '_wafi_order_id', '5' );

		$this->run_migrate();

		$this->assertSame( '123', get_post_meta( $post_id, '_edit_lock', true ) );
		$this->assertSame( '5', get_post_meta( $post_id, '_sp_order_id', true ) );
	}

	public function test_renames_capture_table_preserving_rows() {
		global $wpdb;
		// WP_UnitTestCase rewrites CREATE TABLE -> CREATE TEMPORARY TABLE (a
		// `query` filter), which SHOW TABLES / RENAME TABLE can't see. Drop those
		// filters so this test exercises a real table like production.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$old = $wpdb->prefix . 'wafi_abandoned_carts';
		$new = $wpdb->prefix . 'sp_abandoned_carts';
		$wpdb->query( "DROP TABLE IF EXISTS `$new`" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS `$old`" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE `$old` (session_key varchar(64) NOT NULL, email varchar(191), PRIMARY KEY (session_key))" ); // phpcs:ignore
		$wpdb->query( "INSERT INTO `$old` (session_key, email) VALUES ('k1', 'a@b.com')" ); // phpcs:ignore
		// Persist the seed row before migrate's RENAME (DDL) implicit-commits —
		// otherwise the INSERT is still inside WP_UnitTestCase's open transaction.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore

		$this->run_migrate();

		$this->assertSame( $new, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) );
		$this->assertSame( 'a@b.com', $wpdb->get_var( "SELECT email FROM `$new` WHERE session_key = 'k1'" ) ); // phpcs:ignore

		$wpdb->query( "DROP TABLE IF EXISTS `$new`" ); // phpcs:ignore
	}

	public function test_clears_legacy_cron_hooks() {
		wp_schedule_event( time() + 3600, 'daily', 'wafi_connector_abandoned_sweep' );
		$this->assertNotFalse( wp_next_scheduled( 'wafi_connector_abandoned_sweep' ) );

		$this->run_migrate();

		$this->assertFalse( wp_next_scheduled( 'wafi_connector_abandoned_sweep' ) );
	}
}
