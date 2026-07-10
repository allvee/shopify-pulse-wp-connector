<?php
/**
 * Incomplete/abandoned cart capture + sweep.
 *
 * WooCommerce doesn't persist abandoned carts, so we snapshot the live cart
 * into our own table keyed by the WC session id, then a WP-Cron sweep pushes
 * carts idle beyond the threshold to POST /connect/abandoned (free-text lines,
 * OAuth-guarded). Converted carts are dropped on checkout.
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wafi_Connector_Abandoned_Sync {

	const SWEEP_BATCH = 25;

	/** @var Wafi_Connector_Settings */
	private $settings;
	/** @var Wafi_Connector_Api_Client */
	private $api;
	/** @var Wafi_Connector_Logger */
	private $logger;

	public function __construct( Wafi_Connector_Settings $settings, Wafi_Connector_Api_Client $api, Wafi_Connector_Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->logger   = $logger;
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wafi_abandoned_carts';
	}

	public function register() {
		add_action( 'woocommerce_add_to_cart', array( $this, 'capture' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'capture' ), 20 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'capture' ), 20 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_checkout' ), 20 );
		// Drop the row once the cart converts.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'mark_converted' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'mark_converted_order' ), 20 );
		// Sweep worker.
		add_action( WAFI_CONNECTOR_ABANDONED_CRON, array( $this, 'sweep' ) );
	}

	/** Capture from the checkout AJAX review (carries email/phone early). */
	public function capture_checkout( $post_data ) {
		if ( is_string( $post_data ) ) {
			parse_str( $post_data, $fields );
			if ( function_exists( 'WC' ) && WC()->customer ) {
				if ( ! empty( $fields['billing_email'] ) ) {
					WC()->customer->set_billing_email( sanitize_email( $fields['billing_email'] ) );
				}
				if ( ! empty( $fields['billing_phone'] ) ) {
					WC()->customer->set_billing_phone( sanitize_text_field( $fields['billing_phone'] ) );
				}
			}
		}
		$this->capture();
	}

	/** Snapshot the current cart into the capture table. */
	public function capture() {
		if ( ! $this->settings->get( 'enable_abandoned' ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}
		$session_key = WC()->session->get_customer_id();
		if ( empty( $session_key ) ) {
			return;
		}
		if ( WC()->cart->is_empty() ) {
			$this->delete_row( $session_key );
			return;
		}

		$lines = array();
		foreach ( WC()->cart->get_cart() as $ci ) {
			$product = isset( $ci['data'] ) ? $ci['data'] : null;
			$qty     = isset( $ci['quantity'] ) ? (int) $ci['quantity'] : 1;
			$subtotal = isset( $ci['line_subtotal'] ) ? (float) $ci['line_subtotal'] : 0.0;
			$unit    = $qty > 0 ? round( $subtotal / $qty, 2 ) : $subtotal;
			$lines[] = array(
				'title' => $product ? $product->get_name() : __( 'Item', 'wafi-connector' ),
				'sku'   => ( $product && $product->get_sku() ) ? $product->get_sku() : null,
				'qty'   => max( 1, $qty ),
				'price' => (float) $unit,
			);
		}

		$email = $this->current_email();
		$phone = ( WC()->customer ) ? WC()->customer->get_billing_phone() : '';
		$now   = current_time( 'mysql', true ); // GMT

		global $wpdb;
		$table = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT session_key FROM {$table} WHERE session_key = %s", $session_key ) ); // phpcs:ignore WordPress.DB
		$data = array(
			'email'         => $email,
			'phone'         => $phone,
			'cart_json'     => wp_json_encode( $lines ),
			'subtotal'      => (float) WC()->cart->get_subtotal(),
			'currency'      => get_woocommerce_currency(),
			'furthest_step' => $email ? 'address' : 'contact',
			'converted'     => 0,
			// A changed cart must be re-pushed: clear the synced flag so the
			// next sweep picks it up again (the sweep filters on synced=0).
			'synced'        => 0,
			'updated_at'    => $now,
		);
		if ( $exists ) {
			$wpdb->update( $table, $data, array( 'session_key' => $session_key ) ); // phpcs:ignore WordPress.DB
		} else {
			$data['session_key'] = $session_key;
			$data['created_at']  = $now;
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB
		}
	}

	private function current_email() {
		if ( function_exists( 'WC' ) && WC()->customer && WC()->customer->get_billing_email() ) {
			return WC()->customer->get_billing_email();
		}
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			return $u ? $u->user_email : '';
		}
		return '';
	}

	public function mark_converted( $order_id ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$this->delete_row( WC()->session->get_customer_id() );
		}
	}

	public function mark_converted_order( $order ) {
		$this->mark_converted( is_object( $order ) && method_exists( $order, 'get_id' ) ? $order->get_id() : 0 );
	}

	private function delete_row( $session_key ) {
		if ( empty( $session_key ) ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'session_key' => $session_key ) ); // phpcs:ignore WordPress.DB
	}

	/** WP-Cron: push carts idle past the threshold. */
	public function sweep() {
		if ( ! $this->settings->get( 'enable_abandoned' ) ) {
			return;
		}
		global $wpdb;
		$table  = self::table_name();
		$idle   = max( 5, (int) $this->settings->get( 'abandoned_idle_min' ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $idle * MINUTE_IN_SECONDS );

		// Only un-pushed rows (synced = 0). A changed cart resets synced=0 in
		// capture(), so it re-queues automatically. Filtering in SQL (not with
		// a PHP `continue`) is essential: otherwise already-pushed rows would
		// permanently occupy the oldest-first LIMIT window and starve newer
		// carts.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE converted = 0 AND synced = 0 AND updated_at < %s ORDER BY updated_at ASC LIMIT %d",
				$cutoff,
				self::SWEEP_BATCH
			)
		);

		foreach ( (array) $rows as $row ) {
			$lines = json_decode( $row->cart_json, true );
			if ( empty( $lines ) || ! is_array( $lines ) ) {
				continue;
			}
			// Unreachable carts (no contact) can't be recovered — mark synced
			// so they leave the working set instead of being re-scanned forever.
			if ( empty( $row->email ) && empty( $row->phone ) ) {
				$wpdb->update( $table, array( 'synced' => 1 ), array( 'session_key' => $row->session_key ) ); // phpcs:ignore WordPress.DB
				continue;
			}
			$payload = array(
				'fingerprint'  => hash( 'sha256', $this->settings->get_sid() . '|' . $row->session_key ),
				'email'        => $row->email ? $row->email : null,
				'msisdn'       => $row->phone ? $row->phone : null,
				'lines'        => $lines,
				'subtotal'     => (float) $row->subtotal,
				'currency'     => $row->currency ? $row->currency : 'BDT',
				'furthestStep' => $row->furthest_step ? $row->furthest_step : 'contact',
			);
			$res = $this->api->post( '/connect/abandoned', $payload );
			if ( is_wp_error( $res ) ) {
				$this->logger->error( 'Abandoned push failed for ' . $row->session_key . ': ' . $res->get_error_message() );
				continue; // leave synced=0 so a later sweep retries
			}
			$wpdb->update( // phpcs:ignore WordPress.DB
				$table,
				array( 'synced' => 1, 'synced_hash' => md5( (string) wp_json_encode( $payload ) ) ),
				array( 'session_key' => $row->session_key )
			);
			$this->logger->debug( 'Abandoned cart ' . $row->session_key . ' pushed.' );
		}

		// Garbage-collect carts that were abandoned long ago and never came
		// back, so the table can't grow unbounded.
		$gc_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "DELETE FROM {$table} WHERE converted = 0 AND updated_at < %s", $gc_cutoff )
		);
	}
}
