<?php
/**
 * Product + variation push (Phase 2b): WooCommerce → platform.
 *
 * Builds a full product payload — simple or variable (options + variations),
 * images, brand + category external refs, SEO — and upserts it to
 * /connect/products, async and hash-gated. Stock is intentionally not sent
 * (WooCommerce stays the stock source).
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wafi_Connector_Product_Sync {

	const HASH_META     = '_wafi_prod_hash';
	const PLATFORM_META = '_wafi_platform_id';

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
			add_action( 'woocommerce_new_product', array( $this, 'on_product' ), 20, 1 );
			add_action( 'woocommerce_update_product', array( $this, 'on_product' ), 20, 1 );
			add_action( WAFI_CONNECTOR_PRODUCT_SYNC_ACTION, array( $this, 'handle_product' ), 10, 1 );
		}
	}

	public function on_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( WAFI_CONNECTOR_PRODUCT_SYNC_ACTION, array( $product_id ), WAFI_CONNECTOR_AS_GROUP ) ) {
				return;
			}
			as_enqueue_async_action( WAFI_CONNECTOR_PRODUCT_SYNC_ACTION, array( $product_id ), WAFI_CONNECTOR_AS_GROUP );
		} else {
			$this->push_product( $product_id );
		}
	}

	public function handle_product( $product_id ) {
		$this->push_product( (int) $product_id );
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
		$payload = array_merge( $payload, Wafi_Connector_Seo::get_post_seo( $product_id ) );

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
}
