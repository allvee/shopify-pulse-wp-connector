<?php
/**
 * Catalog sync — taxonomy (Phase 2a): WooCommerce product categories + brands
 * → platform. Products/variants are Phase 2b.
 *
 * Push: created_term / edited_term for the category + brand taxonomies enqueue
 * an async upsert to /connect/categories or /connect/brands, carrying handle,
 * description, image, SEO (Yoast/Rank Math/AIOSEO) and — for categories — the
 * parent's external id for hierarchy. Hash-gated so nothing re-sends unchanged.
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wafi_Connector_Catalog_Sync {

	const HASH_META     = '_wafi_term_hash';
	const PLATFORM_META = '_wafi_platform_id';

	/** WooCommerce category taxonomy. */
	private static $cat_tax = array( 'product_cat' );
	/** Brand taxonomies across the common brand plugins + native WC (9.6+). */
	private static $brand_tax = array( 'product_brand', 'pwb-brand', 'pa_brand', 'yith_product_brand' );

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
		if ( ! $this->settings->get( 'enable_catalog_sync' ) ) {
			return;
		}
		$dir = $this->settings->get( 'catalog_sync_dir', 'push' );
		if ( 'push' === $dir || 'both' === $dir ) {
			add_action( 'created_term', array( $this, 'on_term' ), 20, 3 );
			add_action( 'edited_term', array( $this, 'on_term' ), 20, 3 );
			add_action( WAFI_CONNECTOR_TERM_SYNC_ACTION, array( $this, 'handle_term' ), 10, 2 );
		}
	}

	public function on_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! $this->is_synced_tax( $taxonomy ) ) {
			return;
		}
		$args = array( (int) $term_id, (string) $taxonomy );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( WAFI_CONNECTOR_TERM_SYNC_ACTION, $args, WAFI_CONNECTOR_AS_GROUP ) ) {
				return;
			}
			as_enqueue_async_action( WAFI_CONNECTOR_TERM_SYNC_ACTION, $args, WAFI_CONNECTOR_AS_GROUP );
		} else {
			$this->push_term( (int) $term_id, (string) $taxonomy );
		}
	}

	public function handle_term( $term_id, $taxonomy ) {
		$this->push_term( (int) $term_id, (string) $taxonomy );
	}

	public function push_term( $term_id, $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$is_brand = in_array( $taxonomy, self::$brand_tax, true );
		$image    = $this->term_image( $term_id );

		$payload = array(
			'externalSource'  => 'woocommerce',
			'externalId'      => (string) $term_id,
			'name'            => $term->name,
			'handle'          => $term->slug,
			'descriptionHtml' => $term->description ? $term->description : null,
			'isActive'        => true,
			'sourceUpdatedAt' => gmdate( 'c' ),
		);
		$payload = array_merge( $payload, Wafi_Connector_Seo::get_term_seo( $term_id, $taxonomy ) );

		if ( $is_brand ) {
			$path = '/connect/brands';
			if ( $image ) {
				$payload['logoSrc'] = $image;
			}
		} else {
			$path = '/connect/categories';
			if ( $image ) {
				$payload['imageSrc'] = $image;
			}
			if ( $term->parent ) {
				// The parent's WooCommerce term id IS its external id on the platform.
				$payload['parentExternalId'] = (string) $term->parent;
			}
		}

		$payload = array_filter(
			$payload,
			function ( $v ) {
				return '' !== $v && null !== $v;
			}
		);

		$hash = md5( (string) wp_json_encode( $payload ) );
		if ( get_term_meta( $term_id, self::HASH_META, true ) === $hash ) {
			return;
		}
		$res = $this->api->post( $path, $payload );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Term ' . $term_id . ' (' . $taxonomy . ') push failed: ' . $res->get_error_message() );
			return;
		}
		update_term_meta( $term_id, self::HASH_META, $hash );
		if ( ! empty( $res['id'] ) ) {
			update_term_meta( $term_id, self::PLATFORM_META, (int) $res['id'] );
		}
		$this->logger->debug( 'Term ' . $term_id . ' (' . $taxonomy . ') synced.' );
	}

	private function is_synced_tax( $taxonomy ) {
		return in_array( $taxonomy, self::$cat_tax, true ) || in_array( $taxonomy, self::$brand_tax, true );
	}

	private function term_image( $term_id ) {
		$thumb = get_term_meta( $term_id, 'thumbnail_id', true );
		if ( $thumb ) {
			$url = wp_get_attachment_url( $thumb );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}
}
