<?php
/**
 * Orchestrator: wires the components and registers their hooks. One singleton,
 * constructed on `plugins_loaded` once WooCommerce is present.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Plugin {

	/** @var Shopify_Pulse_Plugin|null */
	private static $instance = null;

	private $initialized = false;

	/** @var Shopify_Pulse_Settings */
	private $settings;
	/** @var Shopify_Pulse_Logger */
	private $logger;
	/** @var Shopify_Pulse_Api_Client */
	private $api;
	/** @var Shopify_Pulse_Attribution */
	private $attribution;
	/** @var Shopify_Pulse_Order_Sync */
	private $order_sync;
	/** @var Shopify_Pulse_Abandoned_Sync */
	private $abandoned_sync;
	/** @var Shopify_Pulse_Abandoned_Admin */
	private $abandoned_admin;
	/** @var Shopify_Pulse_Analytics */
	private $analytics;
	/** @var Shopify_Pulse_Fraud */
	private $fraud;
	/** @var Shopify_Pulse_Customer_Sync */
	private $customer_sync;
	/** @var Shopify_Pulse_Catalog_Sync */
	private $catalog_sync;
	/** @var Shopify_Pulse_Product_Sync */
	private $product_sync;
	/** @var Shopify_Pulse_Seo_Sync */
	private $seo_sync;
	/** @var Shopify_Pulse_Status_Poller */
	private $poller;

	private $orders_column;

	private $products_column;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		$this->settings       = new Shopify_Pulse_Settings();
		$this->logger         = new Shopify_Pulse_Logger( $this->settings );
		$this->api            = new Shopify_Pulse_Api_Client( $this->settings, $this->logger );
		$this->attribution    = new Shopify_Pulse_Attribution( $this->settings );
		$this->order_sync     = new Shopify_Pulse_Order_Sync( $this->settings, $this->api, $this->logger );
		$this->abandoned_sync  = new Shopify_Pulse_Abandoned_Sync( $this->settings, $this->api, $this->logger );
		$this->abandoned_admin = new Shopify_Pulse_Abandoned_Admin( $this->settings, $this->abandoned_sync, $this->logger );
		$this->analytics      = new Shopify_Pulse_Analytics( $this->settings, $this->api, $this->logger );
		$this->fraud          = new Shopify_Pulse_Fraud( $this->settings, $this->api, $this->logger );
		$this->customer_sync  = new Shopify_Pulse_Customer_Sync( $this->settings, $this->api, $this->logger );
		$this->catalog_sync   = new Shopify_Pulse_Catalog_Sync( $this->settings, $this->api, $this->logger );
		$this->product_sync   = new Shopify_Pulse_Product_Sync( $this->settings, $this->api, $this->logger );
		$this->seo_sync       = new Shopify_Pulse_Seo_Sync( $this->settings, $this->api, $this->logger );
		$this->poller         = new Shopify_Pulse_Status_Poller( $this->settings, $this->api, $this->logger );
		$this->orders_column  = new Shopify_Pulse_Orders_Column( $this->settings, $this->logger );
		$this->products_column = new Shopify_Pulse_Products_Column( $this->settings, $this->logger );

		// The settings screen (with Verify / Activate / Sync) is ALWAYS wired so
		// the operator can re-activate a paused connection. The sync/ingest
		// components only hook when the connection is Active — flipping the
		// master switch off fully pauses order/abandoned/analytics/fraud/poll.
		$this->settings->register();
		// The abandoned-carts worklist + Resync screen is ALWAYS registered so the
		// operator can review captured carts even while the connection is paused
		// (Resync itself is gated on an active connection inside the handler).
		$this->abandoned_admin->register();

		if ( $this->settings->is_active() ) {
			$this->order_sync->register();
			$this->abandoned_sync->register();
			$this->analytics->register();
			$this->fraud->register();
			$this->customer_sync->register();
			$this->catalog_sync->register();
			$this->product_sync->register();
			$this->seo_sync->register();
			$this->poller->register();
			$this->orders_column->register();
			$this->products_column->register();
		}

		// Self-heal cron schedules after a plugin update (activation may not run).
		Shopify_Pulse_Install::schedule_crons();
	}

	/** @return Shopify_Pulse_Api_Client */
	public function api() {
		return $this->api;
	}

	/** @return Shopify_Pulse_Settings */
	public function settings() {
		return $this->settings;
	}

	/** @return Shopify_Pulse_Order_Sync */
	public function order_sync() {
		return $this->order_sync;
	}

	/** @return Shopify_Pulse_Product_Sync */
	public function product_sync() {
		return $this->product_sync;
	}

	/** @return Shopify_Pulse_Customer_Sync */
	public function customer_sync() {
		return $this->customer_sync;
	}

	/** @return Shopify_Pulse_Catalog_Sync */
	public function catalog_sync() {
		return $this->catalog_sync;
	}
}
