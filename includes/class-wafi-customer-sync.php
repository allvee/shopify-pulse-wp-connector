<?php
/**
 * Bidirectional customer sync.
 *
 *  - Push (WC → platform): WordPress user hooks enqueue an async POST to
 *    /connect/customers. Gated on a content hash so a pull-applied change never
 *    bounces back.
 *  - Pull (platform → WC): a WP-Cron poll of GET /connect/customers reconciles
 *    WordPress users, applying only changes newer than what we last saw
 *    (last-write-wins), under a suppression flag so the write doesn't re-push.
 *
 * Direction is operator-controlled (push / pull / both).
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wafi_Connector_Customer_Sync {

	const CURSOR_OPTION        = 'wafi_connector_customer_cursor';
	const META_PLATFORM_ID     = '_wafi_platform_customer_id';
	const META_HASH            = '_wafi_cust_hash';
	const META_PLATFORM_UPDATED = '_wafi_cust_platform_updated';

	/** True while applying a platform change, so the WP user hooks don't echo it back. */
	private static $suppress = false;

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

	public function register() {
		if ( ! $this->settings->get( 'enable_customer_sync' ) ) {
			return;
		}
		$dir = $this->settings->get( 'customer_sync_dir', 'both' );

		if ( 'push' === $dir || 'both' === $dir ) {
			add_action( 'user_register', array( $this, 'on_change' ), 20, 1 );
			add_action( 'profile_update', array( $this, 'on_change' ), 20, 1 );
			add_action( 'woocommerce_created_customer', array( $this, 'on_change' ), 20, 1 );
			add_action( 'woocommerce_save_account_details', array( $this, 'on_change' ), 20, 1 );
			add_action( WAFI_CONNECTOR_CUSTOMER_SYNC_ACTION, array( $this, 'handle_push' ), 10, 1 );
		}
		if ( 'pull' === $dir || 'both' === $dir ) {
			add_action( WAFI_CONNECTOR_CUSTOMER_PULL_CRON, array( $this, 'pull' ) );
		}
	}

	public function on_change( $user_id ) {
		if ( self::$suppress ) {
			return;
		}
		$user_id = (int) $user_id;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( WAFI_CONNECTOR_CUSTOMER_SYNC_ACTION, array( $user_id ), WAFI_CONNECTOR_AS_GROUP ) ) {
				return;
			}
			as_enqueue_async_action( WAFI_CONNECTOR_CUSTOMER_SYNC_ACTION, array( $user_id ), WAFI_CONNECTOR_AS_GROUP );
		} else {
			$this->push_user( $user_id );
		}
	}

	public function handle_push( $user_id ) {
		$this->push_user( (int) $user_id );
	}

	public function push_user( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$payload = $this->map_user( $user );
		$hash    = md5( (string) wp_json_encode( $payload ) );
		if ( get_user_meta( $user_id, self::META_HASH, true ) === $hash ) {
			return; // unchanged since last sync (incl. a value we just pulled)
		}
		$res = $this->api->post( '/connect/customers', $payload );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Customer ' . $user_id . ' push failed: ' . $res->get_error_message() );
			return;
		}
		update_user_meta( $user_id, self::META_HASH, $hash );
		if ( ! empty( $res['id'] ) ) {
			update_user_meta( $user_id, self::META_PLATFORM_ID, (int) $res['id'] );
		}
		update_user_meta( $user_id, '_wafi_cust_synced_at', current_time( 'mysql' ) );
		$this->logger->debug( 'Customer ' . $user_id . ' pushed (platform id ' . ( isset( $res['id'] ) ? $res['id'] : '?' ) . ').' );
	}

	private function map_user( $user ) {
		$id   = $user->ID;
		$name = trim( (string) $user->display_name );
		if ( '' === $name ) {
			$name = trim( get_user_meta( $id, 'first_name', true ) . ' ' . get_user_meta( $id, 'last_name', true ) );
		}
		return array_filter(
			array(
				'externalSource'  => 'woocommerce',
				'externalId'      => (string) $id,
				'email'           => $user->user_email,
				'phone'           => get_user_meta( $id, 'billing_phone', true ),
				'name'            => $name,
				'state'           => 'enabled',
				// WP users carry no modified timestamp; the change is happening
				// now, so "now" is the correct last-write-wins clock.
				'sourceUpdatedAt' => gmdate( 'c' ),
			),
			function ( $v ) {
				return '' !== $v && null !== $v;
			}
		);
	}

	/** WP-Cron: reconcile WordPress users from the platform. */
	public function pull() {
		$dir = $this->settings->get( 'customer_sync_dir', 'both' );
		if ( 'pull' !== $dir && 'both' !== $dir ) {
			return;
		}
		$cursor = get_option( self::CURSOR_OPTION, '' );
		$path   = '/connect/customers?limit=100' . ( $cursor ? '&updatedSince=' . rawurlencode( $cursor ) : '' );
		$res    = $this->api->get( $path );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Customer pull failed: ' . $res->get_error_message() );
			return;
		}
		$rows = isset( $res['customers'] ) && is_array( $res['customers'] ) ? $res['customers'] : array();
		$max  = $cursor;
		foreach ( $rows as $c ) {
			$this->apply_platform_customer( $c );
			if ( ! empty( $c['updatedAt'] ) && $c['updatedAt'] > $max ) {
				$max = $c['updatedAt'];
			}
		}
		if ( $max && $max !== $cursor ) {
			update_option( self::CURSOR_OPTION, $max, false );
		}
	}

	private function apply_platform_customer( $c ) {
		$platform_updated = isset( $c['updatedAt'] ) ? (string) $c['updatedAt'] : '';
		$source           = isset( $c['externalSource'] ) ? $c['externalSource'] : '';

		$user = null;
		if ( ! empty( $c['externalId'] ) && 'woocommerce' === $source ) {
			$user = get_user_by( 'id', (int) $c['externalId'] );
		}
		if ( ! $user && ! empty( $c['email'] ) ) {
			$user = get_user_by( 'email', $c['email'] );
		}

		if ( $user ) {
			// Last-write-wins: skip anything we've already applied or older.
			$last = get_user_meta( $user->ID, self::META_PLATFORM_UPDATED, true );
			if ( $last && $platform_updated && $platform_updated <= $last ) {
				return;
			}
			self::$suppress = true;
			$fields = array( 'ID' => $user->ID );
			if ( ! empty( $c['email'] ) ) {
				$fields['user_email'] = $c['email'];
			}
			if ( ! empty( $c['name'] ) ) {
				$fields['display_name'] = $c['name'];
			}
			wp_update_user( $fields );
			if ( ! empty( $c['phone'] ) ) {
				update_user_meta( $user->ID, 'billing_phone', $c['phone'] );
			}
			update_user_meta( $user->ID, self::META_PLATFORM_ID, (int) $c['id'] );
			update_user_meta( $user->ID, self::META_PLATFORM_UPDATED, $platform_updated );
			// Refresh the push hash so this pulled state isn't pushed straight back.
			$fresh = get_userdata( $user->ID );
			if ( $fresh ) {
				update_user_meta( $user->ID, self::META_HASH, md5( (string) wp_json_encode( $this->map_user( $fresh ) ) ) );
			}
			self::$suppress = false;
			$this->logger->debug( 'Customer WP#' . $user->ID . ' updated from platform.' );
			return;
		}

		// No WP user — create one for a platform-native customer (both-way only).
		if ( ! empty( $c['email'] ) && 'both' === $this->settings->get( 'customer_sync_dir', 'both' ) && function_exists( 'wc_create_new_customer' ) ) {
			self::$suppress = true;
			$uid = wc_create_new_customer( $c['email'], '', '', array( 'display_name' => isset( $c['name'] ) ? $c['name'] : '' ) );
			if ( ! is_wp_error( $uid ) ) {
				if ( ! empty( $c['phone'] ) ) {
					update_user_meta( $uid, 'billing_phone', $c['phone'] );
				}
				update_user_meta( $uid, self::META_PLATFORM_ID, (int) $c['id'] );
				update_user_meta( $uid, self::META_PLATFORM_UPDATED, $platform_updated );
				$this->logger->debug( 'Customer created in WP (#' . $uid . ') from platform.' );
			}
			self::$suppress = false;
		}
	}
}
