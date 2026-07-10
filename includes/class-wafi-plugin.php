<?php
/**
 * Orchestrator: wires the components and registers their hooks. One singleton,
 * constructed on `plugins_loaded` once WooCommerce is present.
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wafi_Connector_Plugin {

	/** @var Wafi_Connector_Plugin|null */
	private static $instance = null;

	private $initialized = false;

	/** @var Wafi_Connector_Settings */
	private $settings;
	/** @var Wafi_Connector_Logger */
	private $logger;
	/** @var Wafi_Connector_Api_Client */
	private $api;
	/** @var Wafi_Connector_Attribution */
	private $attribution;
	/** @var Wafi_Connector_Order_Sync */
	private $order_sync;
	/** @var Wafi_Connector_Abandoned_Sync */
	private $abandoned_sync;
	/** @var Wafi_Connector_Analytics */
	private $analytics;
	/** @var Wafi_Connector_Fraud */
	private $fraud;
	/** @var Wafi_Connector_Customer_Sync */
	private $customer_sync;
	/** @var Wafi_Connector_Catalog_Sync */
	private $catalog_sync;
	/** @var Wafi_Connector_Product_Sync */
	private $product_sync;
	/** @var Wafi_Connector_Status_Poller */
	private $poller;

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

		$this->settings       = new Wafi_Connector_Settings();
		$this->logger         = new Wafi_Connector_Logger( $this->settings );
		$this->api            = new Wafi_Connector_Api_Client( $this->settings, $this->logger );
		$this->attribution    = new Wafi_Connector_Attribution( $this->settings );
		$this->order_sync     = new Wafi_Connector_Order_Sync( $this->settings, $this->api, $this->logger );
		$this->abandoned_sync = new Wafi_Connector_Abandoned_Sync( $this->settings, $this->api, $this->logger );
		$this->analytics      = new Wafi_Connector_Analytics( $this->settings, $this->api, $this->logger );
		$this->fraud          = new Wafi_Connector_Fraud( $this->settings, $this->api, $this->logger );
		$this->customer_sync  = new Wafi_Connector_Customer_Sync( $this->settings, $this->api, $this->logger );
		$this->catalog_sync   = new Wafi_Connector_Catalog_Sync( $this->settings, $this->api, $this->logger );
		$this->product_sync   = new Wafi_Connector_Product_Sync( $this->settings, $this->api, $this->logger );
		$this->poller         = new Wafi_Connector_Status_Poller( $this->settings, $this->api, $this->logger );

		// The settings screen (with Verify / Activate / Sync) is ALWAYS wired so
		// the operator can re-activate a paused connection. The sync/ingest
		// components only hook when the connection is Active — flipping the
		// master switch off fully pauses order/abandoned/analytics/fraud/poll.
		$this->settings->register();

		if ( $this->settings->is_active() ) {
			$this->order_sync->register();
			$this->abandoned_sync->register();
			$this->analytics->register();
			$this->fraud->register();
			$this->customer_sync->register();
			$this->catalog_sync->register();
			$this->product_sync->register();
			$this->poller->register();
		}

		// Self-heal cron schedules after a plugin update (activation may not run).
		Wafi_Connector_Install::schedule_crons();
	}

	/** @return Wafi_Connector_Api_Client */
	public function api() {
		return $this->api;
	}

	/** @return Wafi_Connector_Settings */
	public function settings() {
		return $this->settings;
	}

	/** @return Wafi_Connector_Order_Sync */
	public function order_sync() {
		return $this->order_sync;
	}
}
