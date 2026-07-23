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
		try {
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
				// product_id lets the admin worklist filter carts by product and
				// lets Convert re-add the exact product to the WooCommerce order.
				'product_id' => $product ? $product->get_id() : null,
				'title'      => $this->clamp( $product ? $product->get_name() : __( 'Item', 'shopify-pulse-connector' ), 255 ),
				'sku'        => ( $product && $product->get_sku() ) ? $this->clamp( $product->get_sku(), 64 ) : null,
				'qty'        => max( 1, $qty ),
				'price'      => max( 0, (float) $unit ),
			);
		}

		// Normalize contact to the platform's standard up front — a malformed
		// email / phone would be dropped at push and could leave a cart wrongly
		// "reachable". Clean here so the contact gate + worklist are accurate too.
		$email   = $this->clean_email( $this->current_email() );
		$phone   = $this->clean_msisdn( ( WC()->customer ) ? WC()->customer->get_billing_phone() : '' );

		// An abandoned cart is only an actionable "incomplete order" once we have
		// a way to reach the shopper. `woocommerce_add_to_cart` fires long before
		// the checkout contact step, so capturing then would store blank-customer
		// rows that clutter the worklist and can never be recovered. Skip until an
		// email or phone exists; once the shopper types one at checkout, capture()
		// runs again (with contact) and the cart is stored then. A row that lost
		// its cart entirely is still cleaned up above.
		if ( '' === trim( (string) $email ) && '' === trim( (string) $phone ) ) {
			return;
		}

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
		} catch ( \Throwable $e ) {
			// Abandoned capture must never disrupt the cart/checkout experience.
			$this->logger->error( 'Abandoned capture error (ignored): ' . $e->getMessage() );
		}
	}

	/**
	 * Capture from the block (Store API) checkout beacon. WooCommerce Blocks has
	 * no server hook that fires while the shopper fills the form — only at order
	 * placement — so the storefront JS posts the contact + address + cart it read
	 * from the checkout data store here. Mirrors {@see capture()}'s DB write,
	 * keyed by the browser-stable beacon key, and honours the same contact gate.
	 *
	 * @param array $data Decoded beacon payload.
	 * @return bool True when a row was written.
	 */
	public function capture_beacon( array $data ) {
		if ( ! $this->settings->get( 'enable_abandoned' ) ) {
			return false;
		}
		$key = isset( $data['key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $data['key'] ) : '';
		if ( '' === $key ) {
			return false;
		}
		$session_key = 'blk_' . substr( $key, 0, 60 );

		$email = isset( $data['email'] ) ? $this->clean_email( $data['email'] ) : '';
		$phone = isset( $data['phone'] ) ? $this->clean_msisdn( $data['phone'] ) : '';
		// An incomplete order needs a way to reach the shopper (same gate as capture()).
		if ( '' === $email && '' === $phone ) {
			return false;
		}

		$lines = array();
		foreach ( (array) ( isset( $data['lines'] ) ? $data['lines'] : array() ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$lines[] = array(
				'product_id' => isset( $l['product_id'] ) ? (int) $l['product_id'] : null,
				'title'      => isset( $l['title'] ) ? $this->clamp( sanitize_text_field( (string) $l['title'] ), 255 ) : __( 'Item', 'shopify-pulse-connector' ),
				'sku'        => ! empty( $l['sku'] ) ? $this->clamp( sanitize_text_field( (string) $l['sku'] ), 64 ) : null,
				'qty'        => max( 1, isset( $l['qty'] ) ? (int) $l['qty'] : 1 ),
				'price'      => isset( $l['price'] ) ? max( 0, (float) $l['price'] ) : 0.0,
			);
		}
		if ( empty( $lines ) ) {
			return false;
		}

		$name = trim(
			( isset( $data['first_name'] ) ? sanitize_text_field( (string) $data['first_name'] ) : '' ) . ' ' .
			( isset( $data['last_name'] ) ? sanitize_text_field( (string) $data['last_name'] ) : '' )
		);
		$field = function ( $k ) use ( $data ) {
			return isset( $data[ $k ] ) ? sanitize_text_field( (string) $data[ $k ] ) : '';
		};
		$address = array_filter(
			array(
				'name'     => $name,
				'phone'    => $phone,
				'email'    => $email,
				'address1' => $field( 'address_1' ),
				'address2' => $field( 'address_2' ),
				'city'     => $field( 'city' ),
				'province' => $field( 'state' ),
				'zip'      => $field( 'postcode' ),
				'country'  => $field( 'country' ),
			),
			function ( $v ) { return '' !== $v && null !== $v; }
		);

		$has_addr = ! empty( $address['address1'] );
		$subtotal = isset( $data['subtotal'] ) ? max( 0, (float) $data['subtotal'] ) : 0.0;
		$currency = isset( $data['currency'] ) ? substr( sanitize_text_field( (string) $data['currency'] ), 0, 8 ) : get_woocommerce_currency();
		$now      = current_time( 'mysql', true );

		global $wpdb;
		$table  = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT session_key FROM {$table} WHERE session_key = %s", $session_key ) ); // phpcs:ignore WordPress.DB
		// No `status` key: insert defaults to 'active', updates preserve any
		// operator disposition — exactly like capture().
		$shared = array(
			'customer_name' => $name ? $name : null,
			'email'         => $email ? $email : null,
			'phone'         => $phone ? $phone : null,
			'address_json'  => $address ? wp_json_encode( $address ) : null,
			'cart_json'     => wp_json_encode( $lines ),
			'subtotal'      => $subtotal,
			'currency'      => $currency ? $currency : 'BDT',
			'furthest_step' => $has_addr ? 'address' : 'contact',
			'synced'        => 0,
			'updated_at'    => $now,
		);
		if ( $exists ) {
			$wpdb->update( $table, $shared, array( 'session_key' => $session_key ) ); // phpcs:ignore WordPress.DB
		} else {
			$shared['session_key'] = $session_key;
			$shared['created_at']  = $now;
			$wpdb->insert( $table, $shared ); // phpcs:ignore WordPress.DB
		}
		$this->schedule_instant_push( $session_key );
		return true;
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
	 * The cart's session completed checkout — mark the row recovered (status =
	 * converted) instead of deleting it, so it drops out of the sweep/push
	 * working set yet still counts toward the recovery analytics on the
	 * Abandoned carts screen (and links to the order it became). The 30-day GC
	 * in {@see sweep()} prunes it later.
	 */
	public function mark_converted( $order_id ) {
		try {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return;
			}
			$session_key = WC()->session->get_customer_id();
			if ( empty( $session_key ) ) {
				return;
			}
			$data = array( 'status' => 'converted', 'converted' => 1, 'updated_at' => current_time( 'mysql', true ) );
			if ( $order_id ) {
				$data['wc_order_id'] = (int) $order_id;
			}
			global $wpdb;
			$wpdb->update( self::table_name(), $data, array( 'session_key' => $session_key ) ); // phpcs:ignore WordPress.DB

			// Stamp the cart fingerprint on the order so the order push can tell the
			// platform which abandoned checkout this order recovered — same key the
			// abandoned push used (sha256(sid|session_key)) — so the platform closes
			// the matching recovery-inbox row instead of chasing an already-bought cart.
			if ( $order_id ) {
				$sid = $this->settings->get_sid();
				if ( ! empty( $sid ) ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						$order->update_meta_data( '_sp_cart_fingerprint', hash( 'sha256', $sid . '|' . $session_key ) );
						$order->save();
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Never let cart bookkeeping break the order the shopper just placed.
			$this->logger->error( 'Abandoned mark_converted error (order kept): ' . $e->getMessage() );
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
				"SELECT * FROM {$table} WHERE status = 'active' AND synced = 0 AND updated_at < %s ORDER BY updated_at ASC LIMIT %d",
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

	/** Clamp a string to a max length, multibyte-safe, matching the platform DTO. */
	private function clamp( $s, $len ) {
		$s = (string) $s;
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $len ) : substr( $s, 0, $len );
	}

	/**
	 * Length-bound the email, keeping whatever the shopper actually entered.
	 * Email is NOT mandatory (a phone-only cart is the BD norm) — but if it's
	 * there, catch it. The platform stores it as a plain string (no format
	 * check), so we don't reject valid-but-unusual addresses; we only drop a
	 * blank or an obvious non-email (no "@").
	 */
	public function clean_email( $email ) {
		$email = sanitize_email( (string) $email );
		return ( '' !== $email && false !== strpos( $email, '@' ) ) ? $this->clamp( $email, 255 ) : '';
	}

	/** Normalize a phone to `+`?digits, clamped to the platform's 32-char limit. */
	public function clean_msisdn( $phone ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}
		$plus   = ( '+' === substr( $phone, 0, 1 ) ) ? '+' : '';
		$digits = preg_replace( '/\D+/', '', $phone );
		return '' === $digits ? '' : $this->clamp( $plus . $digits, 32 );
	}

	/**
	 * Map stored cart lines to the platform's line contract, clamped to its
	 * limits (title ≤255, sku ≤64, qty int ≥1, price ≥0). Drops the plugin-local
	 * `product_id` (not part of the platform DTO) and any non-array/blank line.
	 *
	 * @param mixed $lines
	 * @return array
	 */
	private function standardize_lines( $lines ) {
		$out = array();
		foreach ( (array) $lines as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$title = isset( $l['title'] ) ? $this->clamp( $l['title'], 255 ) : '';
			if ( '' === $title ) {
				$title = __( 'Item', 'shopify-pulse-connector' );
			}
			$line = array(
				'title' => $title,
				'qty'   => max( 1, isset( $l['qty'] ) ? (int) $l['qty'] : 1 ),
				'price' => isset( $l['price'] ) ? max( 0, (float) $l['price'] ) : 0.0,
			);
			if ( ! empty( $l['sku'] ) ) {
				$line['sku'] = $this->clamp( $l['sku'], 64 );
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Build + send one abandoned-cart row to the platform. Returns bool. Every
	 * field is normalized + clamped to the platform's IngestAbandonedDto limits
	 * here, so a push is never rejected (and never retry-loops) on bad data.
	 */
	private function push_row( $row ) {
		$lines  = $this->standardize_lines( json_decode( $row->cart_json, true ) );
		$email  = $this->clean_email( $row->email );
		$msisdn = $this->clean_msisdn( $row->phone );
		// Need at least one valid line AND one reachable contact.
		if ( empty( $lines ) || ( '' === $email && '' === $msisdn ) ) {
			return false;
		}
		global $wpdb;
		$payload = array(
			'fingerprint'  => hash( 'sha256', $this->settings->get_sid() . '|' . $row->session_key ),
			'email'        => '' !== $email ? $email : null,
			'msisdn'       => '' !== $msisdn ? $msisdn : null,
			'lines'        => $lines,
			'subtotal'     => max( 0, (float) $row->subtotal ),
			'currency'     => $this->clamp( $row->currency ? $row->currency : 'BDT', 3 ),
			'furthestStep' => $this->clamp( $row->furthest_step ? $row->furthest_step : 'contact', 32 ),
		);
		// Carry the captured name + address so the platform can show who the cart
		// belongs to and one-click convert it (omitted when never captured).
		if ( ! empty( $row->customer_name ) ) {
			$payload['customerName'] = $this->clamp( $row->customer_name, 255 );
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
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . " WHERE session_key = %s AND status = 'active' AND synced = 0", $session_key )
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
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . " WHERE session_key = %s AND status = 'active'", $session_key )
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
				'SELECT * FROM ' . self::table_name() . " WHERE status = 'active' AND ( ( email IS NOT NULL AND email <> '' ) OR ( phone IS NOT NULL AND phone <> '' ) ) ORDER BY updated_at ASC LIMIT %d",
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

	/** Fetch one captured cart row by session key (admin worklist). */
	public function get_row( $session_key ) {
		if ( empty( $session_key ) ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE session_key = %s', $session_key )
		);
	}

	/**
	 * Operator disposition change from the worklist (cancel / fake / reopen).
	 * A local-only status flip: the platform already has its copy from the
	 * capture-time push, so this never calls the API. A non-active status drops
	 * the cart out of the sweep/resync working set.
	 *
	 * @param string $session_key
	 * @param string $status active|cancelled|fake
	 * @return bool
	 */
	public function set_status( $session_key, $status ) {
		$allowed = array( 'active', 'cancelled', 'fake' );
		if ( empty( $session_key ) || ! in_array( $status, $allowed, true ) ) {
			return false;
		}
		global $wpdb;
		return false !== $wpdb->update( // phpcs:ignore WordPress.DB
			self::table_name(),
			array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'session_key' => $session_key )
		);
	}

	/** Delete one captured cart from the local worklist (local-only). */
	public function delete_cart( $session_key ) {
		if ( empty( $session_key ) ) {
			return false;
		}
		global $wpdb;
		return false !== $wpdb->delete( self::table_name(), array( 'session_key' => $session_key ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Convert a captured cart into a native WooCommerce order (pending payment),
	 * so it flows through the normal order pipeline — including the existing
	 * order-sync that mirrors it to the platform. Catalog lines are re-added by
	 * product id / SKU; a line whose product no longer exists is added as a
	 * priced fee so the total still matches. Marks the cart converted and links
	 * the new order id. Returns the order id or a WP_Error.
	 *
	 * @param string $session_key
	 * @return int|WP_Error
	 */
	public function convert_to_wc_order( $session_key ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'sp_no_wc', __( 'WooCommerce is not active.', 'shopify-pulse-connector' ) );
		}
		$row = $this->get_row( $session_key );
		if ( ! $row ) {
			return new WP_Error( 'sp_no_row', __( 'Cart not found.', 'shopify-pulse-connector' ) );
		}
		if ( 'converted' === $row->status && $row->wc_order_id ) {
			return (int) $row->wc_order_id;
		}
		$lines = json_decode( (string) $row->cart_json, true );
		if ( empty( $lines ) || ! is_array( $lines ) ) {
			return new WP_Error( 'sp_empty_cart', __( 'This cart has no items to convert.', 'shopify-pulse-connector' ) );
		}

		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		foreach ( $lines as $l ) {
			$qty     = isset( $l['qty'] ) ? max( 1, (int) $l['qty'] ) : 1;
			$price   = isset( $l['price'] ) ? (float) $l['price'] : 0.0;
			$product = null;
			if ( ! empty( $l['product_id'] ) ) {
				$product = wc_get_product( (int) $l['product_id'] );
			}
			if ( ! $product && ! empty( $l['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$pid = wc_get_product_id_by_sku( (string) $l['sku'] );
				if ( $pid ) {
					$product = wc_get_product( $pid );
				}
			}
			if ( $product ) {
				// Pin the captured unit price (it may differ from the current
				// catalog price the cart was abandoned at).
				$order->add_product( $product, $qty, array(
					'subtotal' => $price * $qty,
					'total'    => $price * $qty,
				) );
			} else {
				$item = new WC_Order_Item_Fee();
				$item->set_name( ! empty( $l['title'] ) ? (string) $l['title'] : __( 'Item', 'shopify-pulse-connector' ) );
				$item->set_amount( $price * $qty );
				$item->set_total( $price * $qty );
				$order->add_item( $item );
			}
		}

		// Address from the captured draft (billing + shipping).
		$addr = json_decode( (string) $row->address_json, true );
		$addr = is_array( $addr ) ? $addr : array();
		$name = (string) ( $row->customer_name ?? '' );
		if ( '' === $name && ! empty( $addr['name'] ) ) {
			$name = (string) $addr['name'];
		}
		$parts = preg_split( '/\s+/', trim( $name ), 2 );
		$billing = array(
			'first_name' => isset( $parts[0] ) ? $parts[0] : '',
			'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
			'email'      => (string) ( $row->email ?? ( $addr['email'] ?? '' ) ),
			'phone'      => (string) ( $row->phone ?? ( $addr['phone'] ?? '' ) ),
			'address_1'  => (string) ( $addr['address1'] ?? '' ),
			'address_2'  => (string) ( $addr['address2'] ?? '' ),
			'city'       => (string) ( $addr['city'] ?? '' ),
			'state'      => (string) ( $addr['province'] ?? '' ),
			'postcode'   => (string) ( $addr['zip'] ?? '' ),
			'country'    => (string) ( $addr['country'] ?? '' ),
			'company'    => (string) ( $addr['company'] ?? '' ),
		);
		$order->set_address( $billing, 'billing' );
		$order->set_address( $billing, 'shipping' );
		if ( $row->currency ) {
			$order->set_currency( $row->currency );
		}
		$order->add_order_note( __( 'Created from an abandoned cart by Shopify Pulse.', 'shopify-pulse-connector' ) );
		$order->calculate_totals();
		$order->set_status( 'pending', __( 'Recovered from abandoned cart.', 'shopify-pulse-connector' ) );
		$order->save();

		$order_id = $order->get_id();
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table_name(),
			array(
				'status'      => 'converted',
				'converted'   => 1,
				'wc_order_id' => $order_id,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'session_key' => $session_key )
		);
		return $order_id;
	}
}
