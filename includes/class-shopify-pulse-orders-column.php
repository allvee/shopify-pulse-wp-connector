<?php
/**
 * "Shopify Pulse" column in the WooCommerce orders list. Shows a green
 * "Synced" badge once an order carries the platform id, otherwise a per-order
 * "Sync" button that force-pushes just that order. Works on both the legacy
 * (posts) orders screen and the HPOS (`wc-orders`) screen.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Orders_Column {

	const COL   = 'shopify_pulse_sync';
	const NONCE = 'shopify_pulse_order_col';

	/** @var Shopify_Pulse_Settings */
	private $settings;
	/** @var Shopify_Pulse_Logger */
	private $logger;

	public function __construct( Shopify_Pulse_Settings $settings, Shopify_Pulse_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register() {
		// Legacy (posts) orders table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy' ), 20, 2 );
		// HPOS (custom-table) orders table.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos' ), 20, 2 );
		// Click handler (inline, only on the orders screen) + the AJAX endpoint.
		add_action( 'admin_footer', array( $this, 'footer_js' ) );
		add_action( 'wp_ajax_shopify_pulse_order_sync', array( $this, 'ajax_sync_order' ) );
	}

	/** Append the column. */
	public function add_column( $columns ) {
		$columns[ self::COL ] = __( 'Shopify Pulse', 'shopify-pulse-connector' );
		return $columns;
	}

	/** Legacy screen passes ($column, $post_id). */
	public function render_legacy( $column, $post_id ) {
		if ( self::COL === $column ) {
			echo $this->cell( wc_get_order( $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- cell() escapes.
		}
	}

	/** HPOS screen passes ($column, $order). */
	public function render_hpos( $column, $order ) {
		if ( self::COL === $column ) {
			echo $this->cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- cell() escapes.
		}
	}

	/** Synced badge, or the per-order Sync button. */
	private function cell( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}
		$platform_id = (string) $order->get_meta( SHOPIFY_PULSE_META_ID );
		if ( '' !== $platform_id ) {
			$at    = (string) $order->get_meta( SHOPIFY_PULSE_META_SYNCED_AT );
			$title = trim( sprintf( 'Platform #%s %s', $platform_id, $at ? '· ' . $at : '' ) );
			return '<span class="sp-order-synced" style="color:#00844a;font-weight:600;white-space:nowrap;" title="' . esc_attr( $title ) . '">&#10003; ' . esc_html__( 'Synced', 'shopify-pulse-connector' ) . '</span>';
		}
		return '<button type="button" class="button button-small sp-sync-order" data-order="' . esc_attr( (string) $order->get_id() ) . '">' . esc_html__( 'Sync', 'shopify-pulse-connector' ) . '</button>';
	}

	/** Inline click handler — only printed on the orders list screens. */
	public function footer_js() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
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
				var b = e.target && e.target.classList && e.target.classList.contains( 'sp-sync-order' ) ? e.target : null;
				if ( ! b ) { return; }
				e.preventDefault();
				b.disabled = true;
				var orig = b.textContent;
				b.textContent = '<?php echo $syncing; // phpcs:ignore ?>';
				var data = new FormData();
				data.append( 'action', 'shopify_pulse_order_sync' );
				data.append( 'nonce', nonce );
				data.append( 'order_id', b.getAttribute( 'data-order' ) );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( j && j.success ) {
							var s = document.createElement( 'span' );
							s.className = 'sp-order-synced';
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

	/** Force-sync a single order (per-order Sync button). */
	public function ajax_sync_order() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shopify-pulse-connector' ) ), 403 );
		}
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'shopify-pulse-connector' ) ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing order.', 'shopify-pulse-connector' ) ) );
		}
		$res = Shopify_Pulse_Plugin::instance()->order_sync()->sync_one( $order_id );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['message'] ) );
		}
		wp_send_json_success( array( 'message' => $res['message'], 'id' => isset( $res['id'] ) ? $res['id'] : '' ) );
	}
}
