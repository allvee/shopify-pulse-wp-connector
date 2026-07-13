<?php
/**
 * Order push: WooCommerce order hooks → Action Scheduler job → POST
 * /connect/orders. Idempotent (payload hash skip + platform-side dedupe on
 * externalId) with exponential backoff retry.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Order_Sync {

	const MAX_ATTEMPTS = 5;

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
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( SHOPIFY_PULSE_SYNC_ACTION, array( $this, 'handle_job' ), 10, 2 );
	}

	public function on_new_order( $order_id ) {
		$this->enqueue( (int) $order_id );
	}

	public function on_status_changed( $order_id, $from, $to, $order ) {
		$this->enqueue( (int) $order_id );
	}

	/**
	 * Queue (or, without Action Scheduler, run) a push for an order.
	 */
	public function enqueue( $order_id, $is_backfill = false ) {
		if ( ! $this->settings->get( 'enable_orders' ) ) {
			return;
		}
		// Don't echo back a change the platform just wrote to us.
		if ( Shopify_Pulse_Status_Poller::is_writing_back() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$allowed = (array) $this->settings->get( 'order_statuses' );
		if ( ! empty( $allowed ) && ! in_array( $order->get_status(), $allowed, true ) ) {
			return;
		}

		$args = array( $order_id, $is_backfill ? 1 : 0 );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( SHOPIFY_PULSE_SYNC_ACTION, $args, SHOPIFY_PULSE_AS_GROUP ) ) {
				return; // already queued
			}
			as_enqueue_async_action( SHOPIFY_PULSE_SYNC_ACTION, $args, SHOPIFY_PULSE_AS_GROUP );
		} else {
			$this->push_order( $order_id, (bool) $is_backfill );
		}
	}

	/** Action Scheduler callback. */
	public function handle_job( $order_id, $is_backfill = 0 ) {
		$this->push_order( (int) $order_id, (bool) $is_backfill );
	}

	/**
	 * Manual backfill: enqueue the most recent orders (in the configured status
	 * set) for a push. Used by the "Sync now" button. Returns how many were
	 * queued.
	 *
	 * @param int $limit
	 * @return int
	 */
	public function backfill( $limit = 100 ) {
		if ( ! $this->settings->get( 'enable_orders' ) ) {
			return 0;
		}
		$args = array(
			'limit'   => max( 1, (int) $limit ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		);
		$statuses = (array) $this->settings->get( 'order_statuses' );
		if ( ! empty( $statuses ) ) {
			$args['status'] = $statuses;
		}
		$ids = wc_get_orders( $args );
		$n   = 0;
		foreach ( (array) $ids as $id ) {
			$this->enqueue( (int) $id, true ); // backfill: mirror WooCommerce status as-is
			$n++;
		}
		$this->logger->debug( 'Backfill queued ' . $n . ' orders.' );
		return $n;
	}

	/**
	 * Build + send the order. Skips when the payload is byte-identical to the
	 * last successful push (status polls / meta saves won't re-send).
	 */
	public function push_order( $order_id, $is_backfill = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! $this->settings->get( 'enable_orders' ) ) {
			return;
		}

		$payload = Shopify_Pulse_Order_Mapper::map( $order, (bool) $is_backfill );
		$hash    = md5( (string) wp_json_encode( $payload ) );
		if ( $order->get_meta( SHOPIFY_PULSE_META_HASH ) === $hash ) {
			$this->logger->debug( 'Order ' . $order_id . ' unchanged since last sync — skipping.' );
			return;
		}

		$res = $this->api->post( '/connect/orders', $payload );
		if ( is_wp_error( $res ) ) {
			$this->handle_failure( $order, $res, (bool) $is_backfill );
			return;
		}

		$order->update_meta_data( SHOPIFY_PULSE_META_HASH, $hash );
		if ( ! empty( $res['id'] ) ) {
			$order->update_meta_data( SHOPIFY_PULSE_META_ID, (string) $res['id'] );
		}
		$order->update_meta_data( SHOPIFY_PULSE_META_SYNCED_AT, current_time( 'mysql' ) );
		$order->delete_meta_data( SHOPIFY_PULSE_META_ATTEMPTS );
		$order->save();

		$this->logger->debug(
			'Order ' . $order_id . ' synced (platform id ' . ( isset( $res['id'] ) ? $res['id'] : '?' ) .
			', deduped=' . ( empty( $res['deduped'] ) ? '0' : '1' ) . ').'
		);
	}

	private function handle_failure( WC_Order $order, WP_Error $err, $is_backfill = false ) {
		$attempts = (int) $order->get_meta( SHOPIFY_PULSE_META_ATTEMPTS ) + 1;
		$order->update_meta_data( SHOPIFY_PULSE_META_ATTEMPTS, $attempts );
		$order->save();

		$this->logger->error(
			'Order ' . $order->get_id() . ' push failed (attempt ' . $attempts . '): ' . $err->get_error_message()
		);

		if ( $attempts < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			$delay = min( 3600, 60 * (int) pow( 2, $attempts ) ); // 2,4,8,16 min, capped 1h
			as_schedule_single_action(
				time() + $delay,
				SHOPIFY_PULSE_SYNC_ACTION,
				array( $order->get_id(), $is_backfill ? 1 : 0 ), // preserve backfill flag on retry
				SHOPIFY_PULSE_AS_GROUP
			);
		}
	}
}
