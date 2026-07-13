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
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Catalog_Sync {

	const HASH_META     = '_sp_term_hash';
	const PLATFORM_META = '_sp_platform_id';

	/** WooCommerce category taxonomy. */
	private static $cat_tax = array( 'product_cat' );
	/** Brand taxonomies across the common brand plugins + native WC (9.6+). */
	private static $brand_tax = array( 'product_brand', 'pwb-brand', 'pa_brand', 'yith_product_brand' );

	/** True while applying a pulled change, so the term hooks don't echo it back. */
	private static $suppress = false;

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
		// Categories and brands are independently toggled + directioned. The term
		// hooks are shared (WordPress fires created_term/edited_term for every
		// taxonomy), so register them if EITHER entity pushes; on_term() then
		// gates per-taxonomy. Likewise the pull cron is added if EITHER pulls.
		$cat_push   = $this->cat_enabled() && $this->dir_pushes( $this->cat_dir() );
		$brand_push = $this->brand_enabled() && $this->dir_pushes( $this->brand_dir() );
		$cat_pull   = $this->cat_enabled() && $this->dir_pulls( $this->cat_dir() );
		$brand_pull = $this->brand_enabled() && $this->dir_pulls( $this->brand_dir() );

		if ( $cat_push || $brand_push ) {
			add_action( 'created_term', array( $this, 'on_term' ), 20, 3 );
			add_action( 'edited_term', array( $this, 'on_term' ), 20, 3 );
			add_action( SHOPIFY_PULSE_TERM_SYNC_ACTION, array( $this, 'handle_term' ), 10, 2 );
		}
		if ( $cat_pull || $brand_pull ) {
			add_action( SHOPIFY_PULSE_CATALOG_PULL_CRON, array( $this, 'pull' ) );
		}
	}

	private function cat_enabled() {
		return (bool) $this->settings->get( 'enable_category_sync' );
	}
	private function cat_dir() {
		return $this->settings->get( 'category_sync_dir', 'push' );
	}
	private function brand_enabled() {
		return (bool) $this->settings->get( 'enable_brand_sync' );
	}
	private function brand_dir() {
		return $this->settings->get( 'brand_sync_dir', 'push' );
	}
	private function dir_pushes( $dir ) {
		return 'push' === $dir || 'both' === $dir;
	}
	private function dir_pulls( $dir ) {
		return 'pull' === $dir || 'both' === $dir;
	}
	private function is_brand_tax( $taxonomy ) {
		return in_array( $taxonomy, self::$brand_tax, true );
	}

	/**
	 * Manual backfill: enqueue the store's product categories for a push (the
	 * "Sync categories" button). Returns how many were queued. No-op unless
	 * category sync is enabled with a push direction.
	 *
	 * @param int $limit
	 * @return int
	 */
	public function backfill_categories( $limit = 500 ) {
		if ( ! $this->cat_enabled() || ! $this->dir_pushes( $this->cat_dir() ) ) {
			return 0;
		}
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => max( 1, (int) $limit ),
			'fields'     => 'ids',
		) );
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		$n = 0;
		foreach ( (array) $terms as $tid ) {
			$this->on_term( (int) $tid, 0, 'product_cat' );
			$n++;
		}
		$this->logger->debug( 'Backfill queued ' . $n . ' categories.' );
		return $n;
	}

	public function on_term( $term_id, $tt_id, $taxonomy ) {
		if ( self::$suppress || ! $this->tax_pushes( $taxonomy ) ) {
			return;
		}
		$args = array( (int) $term_id, (string) $taxonomy );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( SHOPIFY_PULSE_TERM_SYNC_ACTION, $args, SHOPIFY_PULSE_AS_GROUP ) ) {
				return;
			}
			as_enqueue_async_action( SHOPIFY_PULSE_TERM_SYNC_ACTION, $args, SHOPIFY_PULSE_AS_GROUP );
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
		$payload = array_merge( $payload, Shopify_Pulse_Seo::get_term_seo( $term_id, $taxonomy ) );

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

	/**
	 * Whether a change to this taxonomy should be pushed right now — i.e. it is
	 * one of our taxonomies AND its entity is enabled with a push direction.
	 */
	private function tax_pushes( $taxonomy ) {
		if ( $this->is_brand_tax( $taxonomy ) ) {
			return $this->brand_enabled() && $this->dir_pushes( $this->brand_dir() );
		}
		if ( in_array( $taxonomy, self::$cat_tax, true ) ) {
			return $this->cat_enabled() && $this->dir_pushes( $this->cat_dir() );
		}
		return false;
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
		if ( $this->cat_enabled() && $this->dir_pulls( $this->cat_dir() ) ) {
			$this->pull_taxonomy( '/connect/categories', 'categories', 'product_cat', 'cat' );
		}
		if ( $this->brand_enabled() && $this->dir_pulls( $this->brand_dir() ) ) {
			$brand_tax = $this->target_brand_tax();
			if ( $brand_tax ) {
				$this->pull_taxonomy( '/connect/brands', 'brands', $brand_tax, 'brand' );
			}
		}
	}

	private function pull_taxonomy( $path, $key, $taxonomy, $cursor_key ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$opt    = 'sp_cat_pull_' . $cursor_key;
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
			$last = get_term_meta( $term_id, '_sp_term_platform_updated', true );
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

		Shopify_Pulse_Seo::set_term_seo(
			$term_id,
			$taxonomy,
			isset( $t['seoTitle'] ) ? $t['seoTitle'] : '',
			isset( $t['seoDescription'] ) ? $t['seoDescription'] : ''
		);
		update_term_meta( $term_id, self::PLATFORM_META, $platform_id );
		update_term_meta( $term_id, '_sp_term_platform_updated', $platform_updated );
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
