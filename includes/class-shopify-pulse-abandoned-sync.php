<?php
/**
 * Incomplete/abandoned cart capture + sweep.
 *
 * WooCommerce doesn't persist abandoned carts, so we snapshot the live cart
 * into our own table keyed by the WC session id, then a WP-Cron sweep pushes
 * carts idle beyond the threshold to POST /connect/abandoned (free-text lines,
 * OAuth-guarded). Converted carts are dropped on checkout.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Abandoned_Sync {

	const SWEEP_BATCH = 25;

	/** @var Shopify_Pulse_Settings */
	private $settings;
	/** @var Shopify_Pulse_Api_Client */
	private $api;
	/** @var Shopify_Pulse_Logger */
	private $logger;

	public function __construct( Shopify_Pulse_Settings $settings, Shopify_Pulse_Api_Client $api, Shopify_Pulse_Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->logger   = $logger;
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'sp_abandoned_carts';
	}

	public function register() {
		add_action( 'woocommerce_add_to_cart', array( $this, 'capture' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'capture' ), 20 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'capture' ), 20 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_checkout' ), 20 );
		// Drop the row once the cart converts.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'mark_converted' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'mark_converted_order' ), 20 );
		// Sweep worker + instant per-cart push.
		add_action( SHOPIFY_PULSE_ABANDONED_CRON, array( $this, 'sweep' ) );
		add_action( SHOPIFY_PULSE_ABANDONED_PUSH_ACTION, array( $this, 'handle_push' ), 10, 1 );
	}

	/**
	 * Capture from the checkout AJAX review. This fires as the shopper fills the
	 * checkout form (before placing the order), so it carries the name + full
	 * billing/shipping address the customer already typed. Persist every field
	 * we recognise onto the WC customer so {@see capture()} snapshots them into
	 * the abandoned row — otherwise a cart abandoned at the address step reaches
	 * the platform with a phone but no name/address.
	 */
	public function capture_checkout( $post_data ) {
		if ( is_string( $post_data ) ) {
			parse_str( $post_data, $fields );
			if ( function_exists( 'WC' ) && WC()->customer ) {
				$cust = WC()->customer;
				// field name => customer setter. Both billing_* and shipping_*
				// (the latter used when "ship to a different address" is on).
				$map = array(
					'billing_email'       => 'set_billing_email',
					'billing_phone'       => 'set_billing_phone',
					'billing_first_name'  => 'set_billing_first_name',
					'billing_last_name'   => 'set_billing_last_name',
					'billing_company'     => 'set_billing_company',
					'billing_address_1'   => 'set_billing_address_1',
					'billing_address_2'   => 'set_billing_address_2',
					'billing_city'        => 'set_billing_city',
					'billing_state'       => 'set_billing_state',
					'billing_postcode'    => 'set_billing_postcode',
					'billing_country'     => 'set_billing_country',
					'shipping_first_name' => 'set_shipping_first_name',
					'shipping_last_name'  => 'set_shipping_last_name',
					'shipping_company'    => 'set_shipping_company',
					'shipping_address_1'  => 'set_shipping_address_1',
					'shipping_address_2'  => 'set_shipping_address_2',
					'shipping_city'       => 'set_shipping_city',
					'shipping_state'      => 'set_shipping_state',
					'shipping_postcode'   => 'set_shipping_postcode',
					'shipping_country'    => 'set_shipping_country',
				);
				foreach ( $map as $field => $setter ) {
					if ( empty( $fields[ $field ] ) || ! is_callable( array( $cust, $setter ) ) ) {
						continue;
					}
					$value = ( 'billing_email' === $field )
						? sanitize_email( $fields[ $field ] )
						: sanitize_text_field( $fields[ $field ] );
					$cust->$setter( $value );
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
				'title' => $product ? $product->get_name() : __( 'Item', 'shopify-pulse-connector' ),
				'sku'   => ( $product && $product->get_sku() ) ? $product->get_sku() : null,
				'qty'   => max( 1, $qty ),
				'price' => (float) $unit,
			);
		}

		$email   = $this->current_email();
		$phone   = ( WC()->customer ) ? WC()->customer->get_billing_phone() : '';
		$name    = $this->current_name();
		$address = $this->current_address();
		$has_addr = ! empty( $address['address1'] );
		$now     = current_time( 'mysql', true ); // GMT

		global $wpdb;
		$table = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT session_key FROM {$table} WHERE session_key = %s", $session_key ) ); // phpcs:ignore WordPress.DB
		$data = array(
			'customer_name' => $name ? $name : null,
			'email'         => $email,
			'phone'         => $phone,
			'address_json'  => $address ? wp_json_encode( $address ) : null,
			'cart_json'     => wp_json_encode( $lines ),
			'subtotal'      => (float) WC()->cart->get_subtotal(),
			'currency'      => get_woocommerce_currency(),
			// Furthest step reached: a captured shipping address means the shopper
			// got past contact. Fall back to 'contact' for a phone/email-only row.
			'furthest_step' => $has_addr ? 'address' : 'contact',
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

		// Push instantly (async) when we can reach the shopper — don't wait for
		// the sweep. Carts with no contact yet fall through to the sweep.
		if ( ! empty( $email ) || ! empty( $phone ) ) {
			$this->schedule_instant_push( $session_key );
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

	/** Best-effort customer full name: billing name, else the logged-in profile. */
	private function current_name() {
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$name = trim( (string) WC()->customer->get_billing_first_name() . ' ' . (string) WC()->customer->get_billing_last_name() );
			if ( '' === $name ) {
				$name = trim( (string) WC()->customer->get_shipping_first_name() . ' ' . (string) WC()->customer->get_shipping_last_name() );
			}
			if ( '' !== $name ) {
				return $name;
			}
		}
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			if ( $u ) {
				$name = trim( $u->first_name . ' ' . $u->last_name );
				return '' !== $name ? $name : $u->display_name;
			}
		}
		return '';
	}

	/**
	 * Snapshot the shopper's address into the free-form shape the platform's
	 * abandoned-cart reader understands (name/phone/email/address1/address2/city/
	 * province/zip/country). Prefers billing, falls back to shipping per field.
	 * Empty fields are dropped so a half-filled form still yields a useful blob.
	 *
	 * @return array<string,string>
	 */
	private function current_address() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return array();
		}
		$c   = WC()->customer;
		$get = function ( $billing_getter, $shipping_getter ) use ( $c ) {
			$v = is_callable( array( $c, $billing_getter ) ) ? trim( (string) $c->$billing_getter() ) : '';
			if ( '' === $v && is_callable( array( $c, $shipping_getter ) ) ) {
				$v = trim( (string) $c->$shipping_getter() );
			}
			return $v;
		};
		$address = array(
			'name'     => $this->current_name(),
			'phone'    => trim( (string) $c->get_billing_phone() ),
			'email'    => $this->current_email(),
			'address1' => $get( 'get_billing_address_1', 'get_shipping_address_1' ),
			'address2' => $get( 'get_billing_address_2', 'get_shipping_address_2' ),
			'city'     => $get( 'get_billing_city', 'get_shipping_city' ),
			'province' => $get( 'get_billing_state', 'get_shipping_state' ),
			'zip'      => $get( 'get_billing_postcode', 'get_shipping_postcode' ),
			'country'  => $get( 'get_billing_country', 'get_shipping_country' ),
			'company'  => $get( 'get_billing_company', 'get_shipping_company' ),
		);
		return array_filter( $address, function ( $v ) { return '' !== $v && null !== $v; } );
	}

	/**
	 * The cart's session completed checkout — mark the row recovered instead of
	 * deleting it, so it drops out of the sweep/push working set (converted = 0
	 * filter) yet still counts toward the recovery analytics on the Abandoned
	 * carts screen. The 30-day GC in {@see sweep()} prunes it later.
	 */
	public function mark_converted( $order_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$session_key = WC()->session->get_customer_id();
		if ( empty( $session_key ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table_name(),
			array( 'converted' => 1, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'session_key' => $session_key )
		);
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
			$this->push_row( $row );
		}

		// Garbage-collect stale rows (abandoned-and-never-returned OR long-since
		// recovered) so the table can't grow unbounded. Recovered rows are kept
		// 30 days for the recovery analytics, then pruned alongside dead carts.
		$gc_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", $gc_cutoff )
		);
	}

	/** Build + send one abandoned-cart row to the platform. Returns bool. */
	private function push_row( $row ) {
		$lines = json_decode( $row->cart_json, true );
		if ( empty( $lines ) || ! is_array( $lines ) || ( empty( $row->email ) && empty( $row->phone ) ) ) {
			return false;
		}
		global $wpdb;
		$payload = array(
			'fingerprint'  => hash( 'sha256', $this->settings->get_sid() . '|' . $row->session_key ),
			'email'        => $row->email ? $row->email : null,
			'msisdn'       => $row->phone ? $row->phone : null,
			'lines'        => $lines,
			'subtotal'     => (float) $row->subtotal,
			'currency'     => $row->currency ? $row->currency : 'BDT',
			'furthestStep' => $row->furthest_step ? $row->furthest_step : 'contact',
		);
		// Carry the captured name + address so the platform can show who the cart
		// belongs to and one-click convert it (omitted when never captured).
		if ( ! empty( $row->customer_name ) ) {
			$payload['customerName'] = $row->customer_name;
		}
		$address = isset( $row->address_json ) && '' !== (string) $row->address_json
			? json_decode( $row->address_json, true )
			: null;
		if ( is_array( $address ) && ! empty( $address ) ) {
			$payload['addressDraft'] = $address;
		}
		$res = $this->api->post( '/connect/abandoned', $payload );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Abandoned push failed for ' . $row->session_key . ': ' . $res->get_error_message() );
			return false; // leave synced=0 so a later sweep retries
		}
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table_name(),
			array( 'synced' => 1, 'synced_hash' => md5( (string) wp_json_encode( $payload ) ) ),
			array( 'session_key' => $row->session_key )
		);
		$this->logger->debug( 'Abandoned cart ' . $row->session_key . ' pushed.' );
		return true;
	}

	/**
	 * Instant path: schedule an async push of this session's cart right after a
	 * capture, so an abandoned cart reaches the platform in seconds instead of
	 * waiting for the sweep. Debounced per session so rapid cart edits coalesce
	 * into one call; the sweep remains the backstop for anything missed.
	 */
	private function schedule_instant_push( $session_key ) {
		if ( empty( $session_key ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( SHOPIFY_PULSE_ABANDONED_PUSH_ACTION, array( $session_key ), SHOPIFY_PULSE_AS_GROUP ) ) {
			return;
		}
		as_enqueue_async_action( SHOPIFY_PULSE_ABANDONED_PUSH_ACTION, array( $session_key ), SHOPIFY_PULSE_AS_GROUP );
	}

	/** Action Scheduler callback: push one session's cart now. */
	public function handle_push( $session_key ) {
		if ( ! $this->settings->get( 'enable_abandoned' ) ) {
			return;
		}
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE session_key = %s AND converted = 0 AND synced = 0', $session_key )
		);
		if ( $row ) {
			$this->push_row( $row );
		}
	}

	/**
	 * Force-push one captured cart to the platform now, regardless of its synced
	 * flag — the "Resync" action on the abandoned-carts admin screen. Safe to
	 * call repeatedly: the platform upserts on (sid, fingerprint) and the
	 * fingerprint is derived from the stable session key, so a resync UPDATES the
	 * existing platform row and never creates a duplicate.
	 *
	 * @param string $session_key
	 * @return bool True when the cart was (re)sent.
	 */
	public function resync( $session_key ) {
		if ( empty( $session_key ) ) {
			return false;
		}
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE session_key = %s AND converted = 0', $session_key )
		);
		return $row ? $this->push_row( $row ) : false;
	}

	/**
	 * Bulk resync every contactable, un-converted cart (oldest first). Returns
	 * the number actually (re)sent. Dedupe-safe for the same reason as {@see
	 * resync()}.
	 *
	 * @param int $limit
	 * @return int
	 */
	public function resync_pending( $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . " WHERE converted = 0 AND ( ( email IS NOT NULL AND email <> '' ) OR ( phone IS NOT NULL AND phone <> '' ) ) ORDER BY updated_at ASC LIMIT %d",
				$limit
			)
		);
		$sent = 0;
		foreach ( (array) $rows as $row ) {
			if ( $this->push_row( $row ) ) {
				$sent++;
			}
		}
		return $sent;
	}
}
