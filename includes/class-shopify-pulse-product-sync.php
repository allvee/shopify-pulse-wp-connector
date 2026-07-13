<?php
/**
 * Product + variation push (Phase 2b): WooCommerce → platform.
 *
 * Builds a full product payload — simple or variable (options + variations),
 * images, brand + category external refs, SEO — and upserts it to
 * /connect/products, async and hash-gated. Stock is intentionally not sent
 * (WooCommerce stays the stock source).
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Product_Sync {

	const HASH_META     = '_sp_prod_hash';
	const PLATFORM_META = '_sp_platform_id';

	private static $brand_tax = array( 'product_brand', 'pwb-brand', 'pa_brand', 'yith_product_brand' );

	/** True while applying a pulled change, so the product hooks don't echo it back. */
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
		if ( ! $this->settings->get( 'enable_product_sync' ) ) {
			return;
		}
		$dir = $this->settings->get( 'product_sync_dir', 'both' );
		if ( 'push' === $dir || 'both' === $dir ) {
			add_action( 'woocommerce_new_product', array( $this, 'on_product' ), 20, 1 );
			add_action( 'woocommerce_update_product', array( $this, 'on_product' ), 20, 1 );
			add_action( SHOPIFY_PULSE_PRODUCT_SYNC_ACTION, array( $this, 'handle_product' ), 10, 1 );
		}
		if ( 'pull' === $dir || 'both' === $dir ) {
			add_action( SHOPIFY_PULSE_CATALOG_PULL_CRON, array( $this, 'pull' ) );
		}
	}

	public function on_product( $product_id ) {
		if ( self::$suppress ) {
			return;
		}
		$product_id = (int) $product_id;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( SHOPIFY_PULSE_PRODUCT_SYNC_ACTION, array( $product_id ), SHOPIFY_PULSE_AS_GROUP ) ) {
				return;
			}
			as_enqueue_async_action( SHOPIFY_PULSE_PRODUCT_SYNC_ACTION, array( $product_id ), SHOPIFY_PULSE_AS_GROUP );
		} else {
			$this->push_product( $product_id );
		}
	}

	public function handle_product( $product_id ) {
		$this->push_product( (int) $product_id );
	}

	/**
	 * Manual backfill: enqueue the most recent products for a push (the "Sync
	 * products" button). Returns how many were queued. No-op unless product sync
	 * is enabled with a push direction.
	 *
	 * @param int $limit
	 * @return int
	 */
	public function backfill( $limit = 200 ) {
		$dir = $this->settings->get( 'product_sync_dir', 'both' );
		if ( ! $this->settings->get( 'enable_product_sync' ) || ( 'push' !== $dir && 'both' !== $dir ) ) {
			return 0;
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}
		$ids = wc_get_products( array(
			'limit'   => max( 1, (int) $limit ),
			'status'  => array( 'publish', 'private' ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		) );
		$n = 0;
		foreach ( (array) $ids as $id ) {
			$this->on_product( (int) $id );
			$n++;
		}
		$this->logger->debug( 'Backfill queued ' . $n . ' products.' );
		return $n;
	}

	public function push_product( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		list( $options, $variants ) = $product->is_type( 'variable' )
			? $this->variable( $product )
			: array( array(), array( $this->simple_variant( $product ) ) );

		if ( empty( $variants ) ) {
			return; // nothing to sync
		}

		$payload = array(
			'externalSource'  => 'woocommerce',
			'externalId'      => (string) $product_id,
			'title'           => $product->get_name(),
			'handle'          => $product->get_slug(),
			'descriptionHtml' => $product->get_description() ? $product->get_description() : null,
			'status'          => $product->get_status(),
			'productType'     => $product->get_type(),
			'tags'            => wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) ),
			'variants'        => $variants,
			'sourceUpdatedAt' => gmdate( 'c' ),
		);
		if ( ! empty( $options ) ) {
			$payload['options'] = $options;
		}
		$payload = array_merge( $payload, Shopify_Pulse_Seo::get_post_seo( $product_id ) );

		$images = $this->images( $product );
		if ( ! empty( $images ) ) {
			$payload['images'] = $images;
		}
		$cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( $cats && ! is_wp_error( $cats ) ) {
			$payload['categoryExternalIds'] = array_map( 'strval', $cats );
		}
		$brand = $this->brand_term( $product_id );
		if ( $brand ) {
			$payload['brandExternalId'] = (string) $brand;
		}

		$payload = $this->prune( $payload );

		$hash = md5( (string) wp_json_encode( $payload ) );
		if ( get_post_meta( $product_id, self::HASH_META, true ) === $hash ) {
			return;
		}
		$res = $this->api->post( '/connect/products', $payload );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Product ' . $product_id . ' push failed: ' . $res->get_error_message() );
			return;
		}
		update_post_meta( $product_id, self::HASH_META, $hash );
		if ( ! empty( $res['id'] ) ) {
			update_post_meta( $product_id, self::PLATFORM_META, (int) $res['id'] );
		}
		$this->logger->debug( 'Product ' . $product_id . ' synced (platform id ' . ( isset( $res['id'] ) ? $res['id'] : '?' ) . ').' );
	}

	private function simple_variant( $product ) {
		return array(
			'optionValues'   => array(),
			'sku'            => $product->get_sku(),
			'price'          => (float) $product->get_price(),
			'compareAtPrice' => $this->compare_at( $product ),
			'weight'         => $product->get_weight() ? (float) $product->get_weight() : null,
			'weightUnit'     => $this->weight_unit(),
		);
	}

	/**
	 * @param WC_Product_Variable $product
	 * @return array{0:array,1:array} [options, variants]
	 */
	private function variable( $product ) {
		$options   = array();
		$attr_keys = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr->get_variation() ) {
				continue;
			}
			$slug_to_name = array();
			if ( $attr->is_taxonomy() ) {
				$terms  = wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'all' ) );
				$values = array();
				foreach ( $terms as $t ) {
					$values[]              = $t->name;
					$slug_to_name[ $t->slug ] = $t->name;
				}
			} else {
				$values = $attr->get_options();
			}
			$options[]   = array( 'name' => wc_attribute_label( $attr->get_name() ), 'values' => array_values( $values ) );
			$attr_keys[] = array( 'key' => 'attribute_' . sanitize_title( $attr->get_name() ), 'map' => $slug_to_name );
		}

		$variants = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			$vattrs = $v->get_attributes();
			$combo  = array();
			foreach ( $attr_keys as $ak ) {
				$val = isset( $vattrs[ $ak['key'] ] ) ? $vattrs[ $ak['key'] ] : '';
				if ( '' !== $val && isset( $ak['map'][ $val ] ) ) {
					$val = $ak['map'][ $val ]; // taxonomy slug → display name
				}
				$combo[] = $val;
			}
			$variants[] = array(
				'optionValues'   => $combo,
				'sku'            => $v->get_sku(),
				'price'          => (float) $v->get_price(),
				'compareAtPrice' => $this->compare_at( $v ),
				'weight'         => $v->get_weight() ? (float) $v->get_weight() : null,
				'weightUnit'     => $this->weight_unit(),
			);
		}
		return array( $options, $variants );
	}

	private function compare_at( $product ) {
		$regular = $product->get_regular_price();
		$price   = $product->get_price();
		return ( '' !== $regular && (float) $regular > (float) $price ) ? (float) $regular : null;
	}

	private function weight_unit() {
		$u = get_option( 'woocommerce_weight_unit', 'kg' );
		return in_array( $u, array( 'g', 'kg', 'lb', 'oz' ), true ) ? $u : null;
	}

	private function images( $product ) {
		$images = array();
		$fid    = $product->get_image_id();
		if ( $fid ) {
			$url = wp_get_attachment_url( $fid );
			if ( $url ) {
				$images[] = array( 'src' => $url, 'position' => 0, 'alt' => (string) get_post_meta( $fid, '_wp_attachment_image_alt', true ) );
			}
		}
		$pos = 1;
		foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
			$url = wp_get_attachment_url( $gid );
			if ( $url ) {
				$images[] = array( 'src' => $url, 'position' => $pos++, 'alt' => (string) get_post_meta( $gid, '_wp_attachment_image_alt', true ) );
			}
		}
		return $images;
	}

	private function brand_term( $product_id ) {
		foreach ( self::$brand_tax as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = wp_get_post_terms( $product_id, $tax, array( 'fields' => 'ids' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				return (int) $terms[0];
			}
		}
		return 0;
	}

	/** Drop null/'' leaves so the payload stays lean (keeps 0 and arrays). */
	private function prune( $payload ) {
		foreach ( $payload as $k => $v ) {
			if ( null === $v || '' === $v ) {
				unset( $payload[ $k ] );
			}
		}
		return $payload;
	}

	// ── Pull (platform → WooCommerce) ───────────────────────────────────────

	public function pull() {
		$dir = $this->settings->get( 'product_sync_dir', 'both' );
		if ( ( 'pull' !== $dir && 'both' !== $dir ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$cursor = get_option( 'sp_prod_pull_cursor', '' );
		$res    = $this->api->get( '/connect/products?limit=50' . ( $cursor ? '&updatedSince=' . rawurlencode( $cursor ) : '' ) );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Product pull failed: ' . $res->get_error_message() );
			return;
		}
		$rows = isset( $res['products'] ) && is_array( $res['products'] ) ? $res['products'] : array();
		$max  = $cursor;
		foreach ( $rows as $p ) {
			$this->apply_product( $p );
			if ( ! empty( $p['updatedAt'] ) && $p['updatedAt'] > $max ) {
				$max = $p['updatedAt'];
			}
		}
		if ( $max && $max !== $cursor ) {
			update_option( 'sp_prod_pull_cursor', $max, false );
		}
	}

	private function apply_product( $p ) {
		$platform_id      = isset( $p['id'] ) ? (int) $p['id'] : 0;
		$platform_updated = isset( $p['updatedAt'] ) ? (string) $p['updatedAt'] : '';
		$variants         = isset( $p['variants'] ) && is_array( $p['variants'] ) ? $p['variants'] : array();

		$wc_id = 0;
		if ( ! empty( $p['externalId'] ) && ( isset( $p['externalSource'] ) && 'woocommerce' === $p['externalSource'] ) ) {
			if ( wc_get_product( (int) $p['externalId'] ) ) {
				$wc_id = (int) $p['externalId'];
			}
		}
		if ( ! $wc_id && ! empty( $p['handle'] ) ) {
			$post = get_page_by_path( $p['handle'], OBJECT, 'product' );
			if ( $post ) {
				$wc_id = (int) $post->ID;
			}
		}
		if ( ! $wc_id ) {
			foreach ( $variants as $v ) {
				if ( ! empty( $v['sku'] ) ) {
					$id = wc_get_product_id_by_sku( $v['sku'] );
					if ( $id ) {
						$vp    = wc_get_product( $id );
						$wc_id = ( $vp && $vp->get_parent_id() ) ? $vp->get_parent_id() : $id;
						break;
					}
				}
			}
		}

		// Last-write-wins.
		if ( $wc_id ) {
			$last = get_post_meta( $wc_id, '_sp_prod_platform_updated', true );
			if ( $last && $platform_updated && $platform_updated <= $last ) {
				return;
			}
		}

		self::$suppress = true;
		if ( $wc_id ) {
			$product = wc_get_product( $wc_id );
			if ( $product ) {
				if ( ! empty( $p['title'] ) ) {
					$product->set_name( $p['title'] );
				}
				if ( isset( $p['descriptionHtml'] ) ) {
					$product->set_description( $p['descriptionHtml'] );
				}
				if ( ! empty( $p['status'] ) ) {
					$product->set_status( $this->wc_status( $p['status'] ) );
				}
				if ( $product->is_type( 'simple' ) && 1 === count( $variants ) ) {
					if ( isset( $variants[0]['price'] ) ) {
						$product->set_regular_price( (string) $variants[0]['price'] );
					}
				}
				$product->save();
				if ( $product->is_type( 'variable' ) ) {
					$this->apply_variation_prices( $product, $variants );
				}
				$this->stamp_product( $wc_id, $platform_id, $platform_updated, $p );
			}
		} elseif ( count( $variants ) <= 1 ) {
			$product = new WC_Product_Simple();
			$product->set_name( ! empty( $p['title'] ) ? $p['title'] : 'Product' );
			if ( ! empty( $p['handle'] ) ) {
				$product->set_slug( $p['handle'] );
			}
			if ( isset( $p['descriptionHtml'] ) ) {
				$product->set_description( $p['descriptionHtml'] );
			}
			$product->set_status( $this->wc_status( isset( $p['status'] ) ? $p['status'] : 'draft' ) );
			if ( ! empty( $variants ) ) {
				if ( isset( $variants[0]['price'] ) ) {
					$product->set_regular_price( (string) $variants[0]['price'] );
				}
				if ( ! empty( $variants[0]['sku'] ) ) {
					$product->set_sku( $variants[0]['sku'] );
				}
			}
			$new_id = $product->save();
			if ( $new_id ) {
				$this->stamp_product( $new_id, $platform_id, $platform_updated, $p );
			}
		} else {
			$this->logger->debug( 'Skipped creating multi-variant product ' . $platform_id . ' from platform (create the variable product manually first).' );
		}
		self::$suppress = false;
	}

	private function apply_variation_prices( $product, $variants ) {
		$by_sku = array();
		foreach ( $product->get_children() as $vid ) {
			$vp = wc_get_product( $vid );
			if ( $vp && $vp->get_sku() ) {
				$by_sku[ $vp->get_sku() ] = $vp;
			}
		}
		foreach ( $variants as $v ) {
			if ( ! empty( $v['sku'] ) && isset( $by_sku[ $v['sku'] ] ) && isset( $v['price'] ) ) {
				$by_sku[ $v['sku'] ]->set_regular_price( (string) $v['price'] );
				$by_sku[ $v['sku'] ]->save();
			}
		}
	}

	private function stamp_product( $wc_id, $platform_id, $platform_updated, $p ) {
		Shopify_Pulse_Seo::set_post_seo(
			$wc_id,
			isset( $p['seoTitle'] ) ? $p['seoTitle'] : '',
			isset( $p['seoDescription'] ) ? $p['seoDescription'] : ''
		);
		$this->apply_taxonomy( $wc_id, $p );
		update_post_meta( $wc_id, self::PLATFORM_META, $platform_id );
		update_post_meta( $wc_id, '_sp_prod_platform_updated', $platform_updated );
	}

	/**
	 * Assign categories + brand on a pulled product. The platform returns the
	 * terms' external ids, which ARE the WooCommerce term ids they synced from,
	 * so map them straight to product_cat / the brand taxonomy (only ids that
	 * still resolve to a real term are kept).
	 */
	private function apply_taxonomy( $wc_id, $p ) {
		if ( ! empty( $p['categoryExternalIds'] ) && is_array( $p['categoryExternalIds'] ) ) {
			$ids = array();
			foreach ( $p['categoryExternalIds'] as $ext ) {
				$term = get_term( (int) $ext, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = (int) $ext;
				}
			}
			if ( $ids ) {
				wp_set_object_terms( $wc_id, $ids, 'product_cat' );
			}
		}

		if ( ! empty( $p['brandExternalId'] ) ) {
			foreach ( self::$brand_tax as $tax ) {
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$term = get_term( (int) $p['brandExternalId'], $tax );
				if ( $term && ! is_wp_error( $term ) ) {
					wp_set_object_terms( $wc_id, array( (int) $p['brandExternalId'] ), $tax );
					break;
				}
			}
		}
	}

	private function wc_status( $status ) {
		return ( 'active' === $status || 'publish' === $status ) ? 'publish' : 'draft';
	}
}
