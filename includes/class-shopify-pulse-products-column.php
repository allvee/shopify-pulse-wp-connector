<?php
/**
 * "Shopify Pulse" column in the WooCommerce products list. Shows a green
 * "Synced" badge once a product carries the platform id, otherwise a per-row
 * "Sync" button that force-pushes just that product. (Products are a CPT, so
 * only the classic posts list — no HPOS equivalent.)
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Products_Column {

	const COL   = 'shopify_pulse_sync';
	const NONCE = 'shopify_pulse_product_col';

	/** @var Shopify_Pulse_Settings */
	private $settings;
	/** @var Shopify_Pulse_Logger */
	private $logger;

	public function __construct( Shopify_Pulse_Settings $settings, Shopify_Pulse_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register() {
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render' ), 20, 2 );
		add_action( 'admin_footer', array( $this, 'footer_js' ) );
		add_action( 'wp_ajax_shopify_pulse_product_sync', array( $this, 'ajax_sync_product' ) );
	}

	/** Insert the column right after the SKU column (fallback: append). */
	public function add_column( $columns ) {
		$label = __( 'Shopify Pulse', 'shopify-pulse-connector' );
		$out   = array();
		foreach ( $columns as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'sku' === $key ) {
				$out[ self::COL ] = $label;
			}
		}
		if ( ! isset( $out[ self::COL ] ) ) {
			$out[ self::COL ] = $label;
		}
		return $out;
	}

	public function render( $column, $post_id ) {
		if ( self::COL === $column ) {
			echo $this->cell( (int) $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- cell() escapes.
		}
	}

	/** Synced badge, or the per-product Sync button. */
	private function cell( $post_id ) {
		$platform_id = (string) get_post_meta( $post_id, Shopify_Pulse_Product_Sync::PLATFORM_META, true );
		if ( '' !== $platform_id ) {
			return '<span class="sp-prod-synced" style="color:#00844a;font-weight:600;white-space:nowrap;" title="' . esc_attr( 'Platform #' . $platform_id ) . '">&#10003; ' . esc_html__( 'Synced', 'shopify-pulse-connector' ) . '</span>';
		}
		return '<button type="button" class="button button-small sp-sync-product" data-product="' . esc_attr( (string) $post_id ) . '">' . esc_html__( 'Sync', 'shopify-pulse-connector' ) . '</button>';
	}

	/** Inline click handler — only on the products list screen. */
	public function footer_js() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		$nonce   = wp_create_nonce( self::NONCE );
		$syncing = esc_js( __( 'Syncing…', 'shopify-pulse-connector' ) );
		$synced  = esc_js( __( 'Synced', 'shopify-pulse-connector' ) );
		$failed  = esc_js( __( 'Sync failed', 'shopify-pulse-connector' ) );
		?>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			document.addEventListener( 'click', function ( e ) {
				var b = e.target && e.target.classList && e.target.classList.contains( 'sp-sync-product' ) ? e.target : null;
				if ( ! b ) { return; }
				e.preventDefault();
				b.disabled = true;
				var orig = b.textContent;
				b.textContent = '<?php echo $syncing; // phpcs:ignore ?>';
				var data = new FormData();
				data.append( 'action', 'shopify_pulse_product_sync' );
				data.append( 'nonce', nonce );
				data.append( 'product_id', b.getAttribute( 'data-product' ) );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( j && j.success ) {
							var s = document.createElement( 'span' );
							s.className = 'sp-prod-synced';
							s.style.cssText = 'color:#00844a;font-weight:600;white-space:nowrap';
							s.innerHTML = '✓ <?php echo $synced; // phpcs:ignore ?>';
							b.parentNode.replaceChild( s, b );
						} else {
							b.disabled = false;
							b.textContent = orig;
							window.alert( ( j && j.data && j.data.message ) || '<?php echo $failed; // phpcs:ignore ?>' );
						}
					} )
					.catch( function () { b.disabled = false; b.textContent = orig; window.alert( '<?php echo $failed; // phpcs:ignore ?>' ); } );
			} );
		} )();
		</script>
		<?php
	}

	/** Force-sync a single product (per-row Sync button). */
	public function ajax_sync_product() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shopify-pulse-connector' ) ), 403 );
		}
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'shopify-pulse-connector' ) ) );
		}
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing product.', 'shopify-pulse-connector' ) ) );
		}
		$res = Shopify_Pulse_Plugin::instance()->product_sync()->sync_one( $product_id );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['message'] ) );
		}
		wp_send_json_success( array( 'message' => $res['message'], 'id' => isset( $res['id'] ) ? $res['id'] : '' ) );
	}
}
