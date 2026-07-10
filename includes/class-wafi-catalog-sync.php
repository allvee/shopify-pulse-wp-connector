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

	/** True while applying a pulled change, so the term hooks don't echo it back. */
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
		if ( ! $this->settings->get( 'enable_catalog_sync' ) ) {
			return;
		}
		$dir = $this->settings->get( 'catalog_sync_dir', 'push' );
		if ( 'push' === $dir || 'both' === $dir ) {
			add_action( 'created_term', array( $this, 'on_term' ), 20, 3 );
			add_action( 'edited_term', array( $this, 'on_term' ), 20, 3 );
			add_action( WAFI_CONNECTOR_TERM_SYNC_ACTION, array( $this, 'handle_term' ), 10, 2 );
		}
		if ( 'pull' === $dir || 'both' === $dir ) {
			add_action( WAFI_CONNECTOR_CATALOG_PULL_CRON, array( $this, 'pull' ) );
		}
	}

	public function on_term( $term_id, $tt_id, $taxonomy ) {
		if ( self::$suppress || ! $this->is_synced_tax( $taxonomy ) ) {
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

	// ── Pull (platform → WooCommerce) ───────────────────────────────────────

	public function pull() {
		$dir = $this->settings->get( 'catalog_sync_dir', 'push' );
		if ( 'pull' !== $dir && 'both' !== $dir ) {
			return;
		}
		$this->pull_taxonomy( '/connect/categories', 'categories', 'product_cat', 'cat' );
		$brand_tax = $this->target_brand_tax();
		if ( $brand_tax ) {
			$this->pull_taxonomy( '/connect/brands', 'brands', $brand_tax, 'brand' );
		}
	}

	private function pull_taxonomy( $path, $key, $taxonomy, $cursor_key ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$opt    = 'wafi_cat_pull_' . $cursor_key;
		$cursor = get_option( $opt, '' );
		$res    = $this->api->get( $path . '?limit=100' . ( $cursor ? '&updatedSince=' . rawurlencode( $cursor ) : '' ) );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Pull ' . $key . ' failed: ' . $res->get_error_message() );
			return;
		}
		$rows = isset( $res[ $key ] ) && is_array( $res[ $key ] ) ? $res[ $key ] : array();
		$max  = $cursor;
		foreach ( $rows as $row ) {
			$this->apply_term( $row, $taxonomy );
			if ( ! empty( $row['updatedAt'] ) && $row['updatedAt'] > $max ) {
				$max = $row['updatedAt'];
			}
		}
		if ( $max && $max !== $cursor ) {
			update_option( $opt, $max, false );
		}
	}

	private function apply_term( $t, $taxonomy ) {
		$platform_id      = isset( $t['id'] ) ? (int) $t['id'] : 0;
		$platform_updated = isset( $t['updatedAt'] ) ? (string) $t['updatedAt'] : '';
		$name             = isset( $t['name'] ) ? $t['name'] : ( isset( $t['title'] ) ? $t['title'] : '' );
		$handle           = isset( $t['handle'] ) && $t['handle'] ? $t['handle'] : sanitize_title( $name );
		if ( '' === (string) $name && '' === (string) $handle ) {
			return;
		}

		$term_id = 0;
		if ( ! empty( $t['externalId'] ) && ( isset( $t['externalSource'] ) && 'woocommerce' === $t['externalSource'] ) ) {
			$ex = get_term( (int) $t['externalId'], $taxonomy );
			if ( $ex && ! is_wp_error( $ex ) ) {
				$term_id = $ex->term_id;
			}
		}
		if ( ! $term_id && $platform_id ) {
			$term_id = $this->find_term_by_platform_id( $platform_id, $taxonomy );
		}
		if ( ! $term_id && $handle ) {
			$ex = get_term_by( 'slug', $handle, $taxonomy );
			if ( $ex ) {
				$term_id = $ex->term_id;
			}
		}

		// Last-write-wins: skip anything we've already applied or older.
		if ( $term_id ) {
			$last = get_term_meta( $term_id, '_wafi_term_platform_updated', true );
			if ( $last && $platform_updated && $platform_updated <= $last ) {
				return;
			}
		}

		self::$suppress = true;
		$args = array( 'slug' => $handle, 'description' => isset( $t['descriptionHtml'] ) ? $t['descriptionHtml'] : '' );
		if ( isset( $t['parentId'] ) && $t['parentId'] ) {
			$parent = $this->find_term_by_platform_id( (int) $t['parentId'], $taxonomy );
			if ( $parent ) {
				$args['parent'] = $parent;
			}
		}
		if ( $term_id ) {
			$args['name'] = $name;
			wp_update_term( $term_id, $taxonomy, $args );
		} else {
			$r = wp_insert_term( $name, $taxonomy, $args );
			if ( is_wp_error( $r ) ) {
				$ex = get_term_by( 'slug', $handle, $taxonomy );
				if ( $ex ) {
					$term_id = $ex->term_id;
					wp_update_term( $term_id, $taxonomy, $args );
				} else {
					self::$suppress = false;
					$this->logger->error( 'Pull term insert failed (' . $taxonomy . '/' . $handle . '): ' . $r->get_error_message() );
					return;
				}
			} else {
				$term_id = $r['term_id'];
			}
		}

		Wafi_Connector_Seo::set_term_seo(
			$term_id,
			$taxonomy,
			isset( $t['seoTitle'] ) ? $t['seoTitle'] : '',
			isset( $t['seoDescription'] ) ? $t['seoDescription'] : ''
		);
		update_term_meta( $term_id, self::PLATFORM_META, $platform_id );
		update_term_meta( $term_id, '_wafi_term_platform_updated', $platform_updated );
		self::$suppress = false;
		$this->logger->debug( 'Pulled term ' . $taxonomy . '#' . $term_id . ' from platform.' );
	}

	private function find_term_by_platform_id( $platform_id, $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'meta_key'   => self::PLATFORM_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $platform_id,        // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'     => 'ids',
			)
		);
		return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? (int) $terms[0] : 0;
	}

	private function target_brand_tax() {
		foreach ( self::$brand_tax as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				return $tax;
			}
		}
		return '';
	}
}
