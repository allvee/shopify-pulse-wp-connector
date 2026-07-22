<?php
/**
 * Block (Store API) checkout abandoned-cart beacon.
 *
 * WooCommerce Blocks has no server-side hook that fires while the shopper fills
 * the checkout form (unlike the classic checkout's
 * `woocommerce_checkout_update_order_review`), so a small front-end script
 * ({@see assets/js/sp-block-beacon.js}) reads the contact + cart from the
 * checkout data store and POSTs a snapshot to the REST route here, which hands
 * it to {@see Shopify_Pulse_Abandoned_Sync::capture_beacon()}. Only loaded on a
 * block-based checkout; classic checkouts are already captured server-side.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Block_Beacon {

	const REST_NS    = 'shopify-pulse/v1';
	const REST_ROUTE = '/abandoned-beacon';

	/** @var Shopify_Pulse_Settings */
	private $settings;
	/** @var Shopify_Pulse_Abandoned_Sync */
	private $abandoned;
	/** @var Shopify_Pulse_Logger */
	private $logger;

	public function __construct( Shopify_Pulse_Settings $settings, Shopify_Pulse_Abandoned_Sync $abandoned, Shopify_Pulse_Logger $logger ) {
		$this->settings  = $settings;
		$this->abandoned = $abandoned;
		$this->logger    = $logger;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	public function routes() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // public storefront beacon; nonce-checked in handle()
			)
		);
	}

	/**
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function handle( $req ) {
		// CSRF guard: the storefront localises a fresh wp_rest nonce. A missing /
		// stale nonce just no-ops the beacon (never an error the shopper sees).
		$nonce = $req->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		if ( ! $this->settings->is_active() || ! $this->settings->get( 'enable_abandoned' ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		$data = $req->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$ok = $this->abandoned->capture_beacon( $data );
		return new WP_REST_Response( array( 'ok' => (bool) $ok ), 200 );
	}

	public function enqueue() {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! $this->settings->is_active() || ! $this->settings->get( 'enable_abandoned' ) ) {
			return;
		}
		// Classic checkout is captured server-side; only the block checkout needs
		// this front-end beacon.
		if ( function_exists( 'has_block' ) && ! has_block( 'woocommerce/checkout' ) ) {
			return;
		}
		wp_enqueue_script(
			'sp-block-beacon',
			SHOPIFY_PULSE_URL . 'assets/js/sp-block-beacon.js',
			array( 'wp-data' ),
			SHOPIFY_PULSE_VERSION,
			true
		);
		wp_localize_script(
			'sp-block-beacon',
			'SPBeacon',
			array(
				'url'      => rest_url( self::REST_NS . self::REST_ROUTE ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'debounce' => 4000,
			)
		);
	}
}
