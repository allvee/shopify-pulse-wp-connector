<?php
/**
 * Checkout fraud screening. Runs each checkout through the platform's 4-layer
 * fraud engine (POST /fraud/screen) and, per the operator's chosen action,
 * blocks the order, holds it for review, or just flags it.
 *
 * Layers (evaluated on the platform, first failure wins):
 *   1. phone / name / address heuristics
 *   2. IP-velocity auto-block
 *   3. courier delivery-history gate
 *   4. pixel Purchase dedup (post-order, handled by the analytics path)
 *
 * Fails OPEN: if the API is unreachable the checkout proceeds, so a platform
 * outage never blocks legitimate sales.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Fraud {

	const SESSION_KEY = 'sp_fraud_verdict';

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

	public function register() {
		$fraud   = (bool) $this->settings->get( 'enable_fraud' );
		$courier = $this->courier_gate_enabled();
		// Nothing to enforce at checkout — stay dormant.
		if ( ! $fraud && ! $courier ) {
			return;
		}
		// Classic checkout: validate posted fields; block by adding an error.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'screen_classic' ), 20, 2 );
		// Block/Store-API checkout: screen the order before payment.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'screen_blocks' ), 20, 2 );
		// hold/flag actions are applied once the order exists (fraud only; the
		// courier gate is a hard block, never a post-order hold).
		if ( $fraud ) {
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'apply_to_order' ), 5, 1 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'apply_to_order_obj' ), 5, 1 );
		}
		// Modern block popup on classic checkout + its stash-reader endpoint.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_guard' ), 20 );
		add_action( 'wp_ajax_shopify_pulse_guard', array( $this, 'ajax_guard' ) );
		add_action( 'wp_ajax_nopriv_shopify_pulse_guard', array( $this, 'ajax_guard' ) );
	}

	/** Load the checkout-guard modal on the (classic) checkout page. */
	public function enqueue_guard() {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'sp-checkout-guard', SHOPIFY_PULSE_URL . 'assets/js/sp-checkout-guard.js', array( 'jquery' ), SHOPIFY_PULSE_VERSION, true );
		$c    = $this->support_contact();
		$help = trim( (string) $this->settings->get( 'msg_help' ) );
		if ( '' === $help ) {
			$help = __( 'Need help completing your order? Reach us:', 'shopify-pulse-connector' );
		}
		wp_localize_script(
			'sp-checkout-guard',
			'SPGuard',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'sp_guard' ),
				'phone'    => $c['phone'],
				'whatsapp' => $c['whatsapp'],
				'i18n'     => array(
					'title'   => __( 'We can’t place this order', 'shopify-pulse-connector' ),
					'callBtn' => __( 'Call', 'shopify-pulse-connector' ),
					'waBtn'   => __( 'WhatsApp us', 'shopify-pulse-connector' ),
					'close'   => __( 'Close', 'shopify-pulse-connector' ),
					'help'    => $help,
				),
			)
		);
	}

	/** Return the last checkout block stashed for this session (and clear it). */
	public function ajax_guard() {
		check_ajax_referer( 'sp_guard', 'nonce' );
		$block = null;
		if ( function_exists( 'WC' ) && WC()->session ) {
			$block = WC()->session->get( 'sp_guard_block' );
			if ( $block ) {
				WC()->session->set( 'sp_guard_block', null );
			}
		}
		if ( is_array( $block ) && ! empty( $block['message'] ) ) {
			wp_send_json_success( $block );
		}
		wp_send_json_error();
	}

	/** Stash a block for the checkout-guard modal to pick up. */
	private function stash_block( $type, $message ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'sp_guard_block', array( 'type' => $type, 'message' => wp_strip_all_tags( (string) $message ) ) );
		}
	}

	/**
	 * Resolve the support contact shown on a block: the plugin's explicit
	 * Support phone / WhatsApp settings, falling back to the connected tenant's
	 * contact number (cached from the last "Verify connection").
	 *
	 * @return array{phone:string,whatsapp:string}
	 */
	private function support_contact() {
		$phone = trim( (string) $this->settings->get( 'support_phone' ) );
		$wa    = trim( (string) $this->settings->get( 'support_whatsapp' ) );
		if ( '' === $phone || '' === $wa ) {
			$status = get_option( 'shopify_pulse_status', array() );
			$store  = ( is_array( $status ) && isset( $status['store'] ) && is_array( $status['store'] ) ) ? $status['store'] : array();
			$tenant = ! empty( $store['contactPhone'] ) ? (string) $store['contactPhone'] : '';
			if ( '' === $phone ) {
				$phone = $tenant;
			}
			if ( '' === $wa ) {
				$wa = $tenant;
			}
		}
		return array(
			'phone'    => $phone,
			// wa.me wants digits only (country code, no +/spaces).
			'whatsapp' => $wa ? preg_replace( '/\D+/', '', $wa ) : '',
		);
	}

	/** Whether the operator has armed the courier delivery-ratio gate. */
	private function courier_gate_enabled() {
		return (int) $this->settings->get( 'courier_min_ratio' ) > 0;
	}

	/**
	 * Classic checkout validation hook.
	 *
	 * @param array    $data   posted checkout fields
	 * @param WP_Error $errors
	 */
	public function screen_classic( $data, $errors ) {
		try {
		$name    = trim( ( isset( $data['billing_first_name'] ) ? $data['billing_first_name'] : '' ) . ' ' . ( isset( $data['billing_last_name'] ) ? $data['billing_last_name'] : '' ) );
		$phone   = isset( $data['billing_phone'] ) ? $data['billing_phone'] : '';
		$address = isset( $data['shipping_address_1'] ) && '' !== $data['shipping_address_1']
			? $data['shipping_address_1']
			: ( isset( $data['billing_address_1'] ) ? $data['billing_address_1'] : '' );

		// Courier delivery-ratio gate — a hard forbid regardless of fraud_action,
		// and runs even when the full fraud screen is disabled.
		$courier_msg = $this->courier_block_message( $phone );
		if ( $courier_msg ) {
			$this->stash_block( 'courier', $courier_msg );
			$errors->add( 'sp_courier', $courier_msg );
			return;
		}
		if ( ! $this->settings->get( 'enable_fraud' ) ) {
			return;
		}

		$verdict = $this->screen( $this->ctx( $name, $phone, $address ) );
		if ( ! $verdict || ! empty( $verdict['allowed'] ) ) {
			return;
		}

		$action = $this->settings->get( 'fraud_action' );
		if ( 'block' === $action ) {
			$this->stash_block( 'fraud', $this->message( $verdict ) );
			$errors->add( 'sp_fraud', $this->message( $verdict ) );
			return;
		}
		// hold / flag: let the order be created, then act on it.
		$this->stash( $verdict );
		} catch ( \Throwable $e ) {
			// A connector must NEVER break the merchant's checkout — fail open.
			$this->logger->error( 'Fraud/courier screen error (allowing checkout): ' . $e->getMessage() );
		}
	}

	/**
	 * Store API (block) checkout.
	 *
	 * @param WC_Order $order
	 * @param WP_REST_Request $request
	 */
	public function screen_blocks( $order, $request ) {
		if ( ! $order ) {
			return;
		}
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$address = $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1();

		// Courier delivery-ratio gate — hard forbid before payment.
		$courier_msg = $this->courier_block_message( $order->get_billing_phone() );
		if ( $courier_msg ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'sp_courier_blocked', $this->with_contact( $courier_msg ), 400 );
			}
			$order->update_status( 'failed', $courier_msg );
			return;
		}
		if ( ! $this->settings->get( 'enable_fraud' ) ) {
			return;
		}

		$verdict = $this->screen( $this->ctx( $name, $order->get_billing_phone(), $address ) );
		if ( ! $verdict || ! empty( $verdict['allowed'] ) ) {
			return;
		}

		$action = $this->settings->get( 'fraud_action' );
		if ( 'block' === $action ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'sp_fraud_blocked',
					$this->with_contact( $this->message( $verdict ) ),
					400
				);
			}
			// Fallback if the exception class is unavailable: fail the order.
			$order->update_status( 'failed', $this->message( $verdict ) );
			return;
		}
		// hold / flag: stash now, apply once the order is fully processed.
		$this->stash( $verdict );
	}

	/** hold/flag for classic checkout (order id). */
	public function apply_to_order( $order_id ) {
		try {
			$verdict = $this->pop();
			if ( ! $verdict ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$this->apply( $order, $verdict );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Fraud apply_to_order error (order kept): ' . $e->getMessage() );
		}
	}

	/** hold/flag for Store API checkout (order object). */
	public function apply_to_order_obj( $order ) {
		try {
			if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
				$this->apply( $order, null );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Fraud apply_to_order_obj error (order kept): ' . $e->getMessage() );
		}
	}

	/**
	 * Call the platform fraud engine. Returns the verdict array, or null on
	 * API error / when screening can't run (fail open).
	 *
	 * @param array $ctx
	 * @return array|null
	 */
	private function screen( $ctx ) {
		$res = $this->api->storefront_post( '/fraud/screen', $ctx, true );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Fraud screen unavailable (failing open): ' . $res->get_error_message() );
			return null;
		}
		return is_array( $res ) ? $res : null;
	}

	/**
	 * Apply hold/flag to an order given a verdict. When $verdict is null it is
	 * read from the session (Store API path).
	 *
	 * @param WC_Order   $order
	 * @param array|null $verdict
	 */
	private function apply( $order, $verdict ) {
		if ( null === $verdict ) {
			$verdict = $this->pop();
		}
		if ( ! $verdict || ! empty( $verdict['allowed'] ) ) {
			return;
		}
		$action = $this->settings->get( 'fraud_action' );
		$layer  = isset( $verdict['layer'] ) ? $verdict['layer'] : 'unknown';
		$reason = isset( $verdict['reason'] ) ? $verdict['reason'] : '';

		$order->update_meta_data( '_sp_fraud_flagged', '1' );
		$order->update_meta_data( '_sp_fraud_layer', $layer );
		$order->update_meta_data( '_sp_fraud_reason', $reason );

		$note = sprintf(
			/* translators: 1: fraud layer, 2: reason */
			__( 'Shopify Pulse fraud screen: flagged by layer "%1$s" (%2$s).', 'shopify-pulse-connector' ),
			$layer,
			$reason
		);
		if ( 'hold' === $action && ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
			$order->save();
		}
	}

	/** Build the /fraud/screen body, forwarding the SHOPPER's ip/ua. */
	private function ctx( $name, $phone, $address ) {
		$ctx = array(
			'ip'        => $this->client_ip(),
			'userAgent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '',
		);
		if ( '' !== trim( (string) $name ) ) {
			$ctx['name'] = substr( (string) $name, 0, 255 );
		}
		if ( '' !== trim( (string) $phone ) ) {
			$ctx['phone'] = substr( (string) $phone, 0, 32 );
		}
		if ( '' !== trim( (string) $address ) ) {
			$ctx['address'] = substr( (string) $address, 0, 500 );
		}
		return $ctx;
	}

	/** The operator-configured block message for this verdict's layer (falling
	 *  back to the platform message, then a built-in default). */
	private function message( $verdict ) {
		$layer = isset( $verdict['layer'] ) ? strtolower( (string) $verdict['layer'] ) : '';
		if ( in_array( $layer, array( '2', 'ip', 'velocity' ), true ) ) {
			$key = 'msg_fraud_velocity';
		} elseif ( in_array( $layer, array( '1', 'contact', 'phone', 'name', 'address', 'heuristic' ), true ) ) {
			$key = 'msg_fraud_contact';
		} else {
			$key = 'msg_fraud_generic';
		}
		$custom = trim( (string) $this->settings->get( $key ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		return ! empty( $verdict['message'] )
			? $verdict['message']
			: __( 'This order could not be accepted. Please contact support.', 'shopify-pulse-connector' );
	}

	/** Append the store's contact to a block message (Store API path, which has
	 *  no custom modal — the number rides in the message text). */
	private function with_contact( $message ) {
		$c = $this->support_contact();
		if ( '' === $c['phone'] ) {
			return $message;
		}
		/* translators: %s: store contact phone number */
		return $message . ' ' . sprintf( __( 'Contact us: %s', 'shopify-pulse-connector' ), $c['phone'] );
	}

	/**
	 * Courier delivery-ratio gate. Asks the platform for the buyer phone's
	 * bdcourier delivery-success ratio and returns a block message when it is
	 * below the operator's threshold (once the buyer has enough parcel history).
	 *
	 * Fails OPEN at every uncertainty — gate off, no phone, API error, unknown
	 * buyer, or too little history all return '' (allow) so the gate never
	 * blocks a legitimate sale on missing data or an outage.
	 *
	 * @param string $phone
	 * @return string block message, or '' to allow.
	 */
	private function courier_block_message( $phone ) {
		$min = (int) $this->settings->get( 'courier_min_ratio' );
		if ( $min <= 0 ) {
			return ''; // gate disabled
		}
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return ''; // nothing to check yet
		}

		$res = $this->api->get( '/connect/courier?phone=' . rawurlencode( $phone ) );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			$this->logger->error(
				'Courier gate unavailable (failing open): ' .
				( is_wp_error( $res ) ? $res->get_error_message() : 'unexpected response' )
			);
			return '';
		}

		$ratio   = isset( $res['successRatio'] ) ? $res['successRatio'] : null;
		$parcels = isset( $res['totalParcel'] ) ? $res['totalParcel'] : null;
		if ( null === $ratio || null === $parcels ) {
			return ''; // unknown buyer / no bdcourier key — allow
		}

		$min_parcels = max( 1, (int) $this->settings->get( 'courier_min_parcels' ) );
		if ( (int) $parcels < $min_parcels ) {
			return ''; // too little history to judge — allow
		}
		if ( (float) $ratio >= (float) $min ) {
			return ''; // meets the threshold
		}

		$this->logger->debug(
			sprintf(
				'Courier gate blocked %s: %s%% success over %d parcels (min %d%%).',
				$phone,
				(string) $ratio,
				(int) $parcels,
				$min
			)
		);
		$template = trim( (string) $this->settings->get( 'msg_courier' ) );
		if ( '' === $template ) {
			$template = __( 'We are unable to accept this order for delivery right now (courier delivery-success rate {ratio}% over {parcels} past parcels). Please contact us to complete your purchase.', 'shopify-pulse-connector' );
		}
		return strtr(
			$template,
			array(
				'{ratio}'   => (string) round( (float) $ratio ),
				'{parcels}' => (string) (int) $parcels,
			)
		);
	}

	private function stash( $verdict ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $verdict );
		}
	}

	private function pop() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$v = WC()->session->get( self::SESSION_KEY );
		if ( $v ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
		return is_array( $v ) ? $v : null;
	}

	private function client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw   = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$parts = explode( ',', $raw );
			$ip    = trim( $parts[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '';
	}
}
