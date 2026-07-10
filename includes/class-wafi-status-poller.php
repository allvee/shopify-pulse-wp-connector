<?php
/**
 * Sync-back: poll GET /connect/orders and reconcile WooCommerce order status
 * from the platform (the operator manages fulfillment/cancellation there).
 * Off by default — enabled via "allow_status_writeback".
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wafi_Connector_Status_Poller {

	const CURSOR_OPTION = 'wafi_connector_poll_cursor';
	const PAGE_SIZE     = 100;

	/**
	 * True while we are applying a platform-driven status change, so the order
	 * sync hooks don't immediately echo that change back to the platform.
	 *
	 * @var bool
	 */
	private static $writing_back = false;

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

	public static function is_writing_back() {
		return self::$writing_back;
	}

	public function register() {
		add_action( WAFI_CONNECTOR_POLL_CRON, array( $this, 'poll' ) );
	}

	public function poll() {
		if ( ! $this->settings->get( 'allow_status_writeback' ) ) {
			return;
		}
		$cursor = get_option( self::CURSOR_OPTION, '' );
		$path   = '/connect/orders?source=woocommerce&limit=' . self::PAGE_SIZE;
		if ( $cursor ) {
			$path .= '&updatedSince=' . rawurlencode( $cursor );
		}

		$res = $this->api->get( $path );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Status poll failed: ' . $res->get_error_message() );
			return;
		}
		$orders = isset( $res['orders'] ) && is_array( $res['orders'] ) ? $res['orders'] : array();
		$max    = $cursor;

		foreach ( $orders as $o ) {
			$ext = isset( $o['externalId'] ) ? (int) $o['externalId'] : 0;
			if ( $ext > 0 ) {
				$wc = wc_get_order( $ext );
				$target = $this->map_status( $o );
				if ( $wc && $target && $wc->get_status() !== $target && $this->should_apply( $wc->get_status(), $target ) ) {
					self::$writing_back = true;
					$wc->update_status( $target, __( 'Updated from Wafi platform.', 'wafi-connector' ) );
					self::$writing_back = false;
					$this->logger->debug( 'Order ' . $ext . ' status set to ' . $target . ' from platform.' );
				}
			}
			if ( ! empty( $o['updatedAt'] ) && $o['updatedAt'] > $max ) {
				$max = $o['updatedAt'];
			}
		}

		// Advance the cursor to the newest updatedAt seen. The platform filters
		// with `updatedSince >=`, so the boundary rows are re-included next
		// poll (idempotent status writes) — no order is skipped on a timestamp
		// tie that straddles a page boundary.
		if ( $max && $max !== $cursor ) {
			update_option( self::CURSOR_OPTION, $max, false );
		}
	}

	/** Order the WooCommerce statuses so writeback never moves an order
	 *  backward (e.g. completed -> processing). refunded/cancelled are terminal
	 *  and always allowed to land. */
	private function should_apply( $current, $target ) {
		if ( in_array( $target, array( 'refunded', 'cancelled' ), true ) ) {
			return true;
		}
		// Don't un-do a terminal local state with a lagging non-terminal push.
		if ( in_array( $current, array( 'completed', 'refunded', 'cancelled' ), true ) ) {
			return false;
		}
		$rank = array( 'pending' => 0, 'failed' => 0, 'on-hold' => 1, 'processing' => 2, 'completed' => 3 );
		$c = isset( $rank[ $current ] ) ? $rank[ $current ] : 0;
		$t = isset( $rank[ $target ] ) ? $rank[ $target ] : 0;
		return $t > $c; // forward only
	}

	/**
	 * Map a platform order snapshot to a WooCommerce status, or null to leave
	 * the WooCommerce order untouched.
	 *
	 * @param array $o
	 * @return string|null
	 */
	private function map_status( $o ) {
		$status = isset( $o['status'] ) ? $o['status'] : '';
		$fin    = isset( $o['financialStatus'] ) ? $o['financialStatus'] : '';
		$ful    = isset( $o['fulfillmentStatus'] ) ? $o['fulfillmentStatus'] : '';

		if ( 'cancelled' === $status ) {
			return 'cancelled';
		}
		if ( 'refunded' === $fin ) {
			return 'refunded';
		}
		if ( 'fulfilled' === $ful ) {
			return 'completed';
		}
		if ( 'paid' === $fin ) {
			return 'processing';
		}
		return null;
	}
}
