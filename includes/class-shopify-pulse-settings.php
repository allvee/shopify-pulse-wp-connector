<?php
/**
 * Settings store + admin screen for the connector.
 *
 * One WooCommerce site connects to exactly one Wafi store (an OAuth app is
 * bound to one sid). Credentials are entered once by the operator.
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Settings {

	const CAPABILITY = 'manage_woocommerce';
	const PAGE_SLUG  = 'shopify-pulse-connector';
	const NONCE      = 'shopify_pulse_settings';

	/** @var array|null */
	private $cache = null;

	const STATUS_OPTION = 'shopify_pulse_status';

	public function defaults() {
		return array(
			'active'                => 1,
			'api_base'              => '',
			'storefront_base'       => '',
			'sid'                   => '',
			'client_id'             => '',
			'client_secret'         => '',
			'enable_orders'         => 1,
			'enable_abandoned'      => 0,
			'enable_analytics'      => 0,
			'enable_fraud'          => 0,
			'fraud_action'          => 'block',
			'courier_min_ratio'     => 0,
			'courier_min_parcels'   => 3,
			'enable_customer_sync'  => 0,
			'customer_sync_dir'     => 'both',
			// Catalog is three independently-controlled entities, each with its
			// own on/off + direction (mirrors the platform, which already splits
			// them by route + scope: /connect/categories, /connect/brands,
			// /connect/products).
			'enable_category_sync'  => 0,
			'category_sync_dir'     => 'push',
			'enable_brand_sync'     => 0,
			'brand_sync_dir'        => 'push',
			'enable_product_sync'   => 0,
			'product_sync_dir'      => 'both',
			// Legacy bundled switch — kept only so a pre-split install can
			// inherit its value into the three keys above (see all()).
			'enable_catalog_sync'   => 0,
			'catalog_sync_dir'      => 'push',
			'order_statuses'        => array( 'pending', 'on-hold', 'processing', 'completed', 'refunded', 'cancelled', 'failed' ),
			'abandoned_idle_min'    => 30,
			'allow_status_writeback' => 0,
			'debug_log'             => 0,
			// WooCommerce-method → platform-shipping-rate map, keyed by the
			// shipping line code "<method_id>:<instance_id>" → platform rate id.
			'shipping_map'          => array(),
		);
	}

	public function all() {
		if ( null === $this->cache ) {
			$stored = get_option( SHOPIFY_PULSE_OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$merged = wp_parse_args( $stored, $this->defaults() );

			// One-time forward-migration: an install saved before catalog was
			// split into category/brand/product inherits its single bundled
			// switch + direction into all three granular controls, so its
			// behaviour is unchanged after upgrade.
			if ( array_key_exists( 'enable_catalog_sync', $stored ) && ! array_key_exists( 'enable_category_sync', $stored ) ) {
				$legacy_on  = empty( $stored['enable_catalog_sync'] ) ? 0 : 1;
				$legacy_dir = isset( $stored['catalog_sync_dir'] ) ? $stored['catalog_sync_dir'] : 'push';
				foreach ( array( 'category', 'brand', 'product' ) as $e ) {
					$merged[ "enable_{$e}_sync" ] = $legacy_on;
					$merged[ "{$e}_sync_dir" ]    = $legacy_dir;
				}
			}
			$this->cache = $merged;
		}
		return $this->cache;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/** Admin host root, no trailing slash. The API client appends `/api/v1`. */
	public function get_api_base() {
		return untrailingslashit( trim( (string) $this->get( 'api_base' ) ) );
	}

	/** Storefront host root (client-api: /pixel, /fraud). Blank ⇒ falls back
	 *  to the admin host for single-host deployments. */
	public function get_storefront_base() {
		return untrailingslashit( trim( (string) $this->get( 'storefront_base' ) ) );
	}

	public function get_sid() {
		return trim( (string) $this->get( 'sid' ) );
	}

	public function is_configured() {
		return '' !== $this->get_api_base() && '' !== $this->get_sid()
			&& '' !== trim( (string) $this->get( 'client_id' ) )
			&& '' !== trim( (string) $this->get( 'client_secret' ) );
	}

	/** Master switch. When off, no sync/ingest hooks are registered. */
	public function is_active() {
		return (bool) $this->get( 'active' );
	}

	/** Last successful verify result (sid, scopes, time) or empty. */
	public function status() {
		$s = get_option( self::STATUS_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Read-only dashboard KPIs (cached 5 min). Cheap, HPOS-safe: order count via
	 * WC_Order_Query pagination, queue/failures via Action Scheduler, catalog +
	 * customer counts via meta, abandoned from the capture table.
	 *
	 * @return array
	 */
	public function stats() {
		$cached = get_transient( 'shopify_pulse_dashboard_stats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$stats = array(
			'orders_synced' => 0,
			'queue'         => 0,
			'failed'        => 0,
			'abandoned'     => 0,
			'products'      => 0,
			'customers'     => 0,
		);

		if ( function_exists( 'wc_get_orders' ) ) {
			$q = wc_get_orders( array(
				'limit'        => 1,
				'paginate'     => true,
				'return'       => 'ids',
				'meta_key'     => SHOPIFY_PULSE_META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
			) );
			if ( is_object( $q ) && isset( $q->total ) ) {
				$stats['orders_synced'] = (int) $q->total;
			}
		}

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$base = array( 'group' => SHOPIFY_PULSE_AS_GROUP, 'per_page' => 500 );
			$stats['queue']  = count( (array) as_get_scheduled_actions( array_merge( $base, array( 'status' => 'pending' ) ), 'ids' ) );
			$stats['failed'] = count( (array) as_get_scheduled_actions( array_merge( $base, array( 'status' => 'failed' ) ), 'ids' ) );
		}

		if ( class_exists( 'Shopify_Pulse_Abandoned_Sync' ) ) {
			$ab = Shopify_Pulse_Abandoned_Sync::table_name();
			if ( $ab === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ab ) ) ) { // phpcs:ignore WordPress.DB
				$stats['abandoned'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ab} WHERE synced = 1" ); // phpcs:ignore WordPress.DB
			}
		}

		$stats['products'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(1) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'product'",
			'_wafi_platform_id'
		) );
		$stats['customers'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(1) FROM {$wpdb->usermeta} WHERE meta_key = %s",
			'_wafi_platform_customer_id'
		) );

		set_transient( 'shopify_pulse_dashboard_stats', $stats, 5 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/** Format a KPI count, showing "500+" when the query was capped. */
	private function kpi_num( $n, $cap = 500 ) {
		return $n >= $cap ? ( $cap . '+' ) : number_format_i18n( $n );
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'wp_ajax_shopify_pulse_test', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_shopify_pulse_sync_now', array( $this, 'ajax_sync_now' ) );
		add_filter(
			'plugin_action_links_' . SHOPIFY_PULSE_BASENAME,
			array( $this, 'action_links' )
		);
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'shopify-pulse-connector' ) . '</a>' );
		return $links;
	}

	public function add_menu() {
		add_menu_page(
			__( 'Shopify Pulse', 'shopify-pulse-connector' ),
			__( 'Shopify Pulse', 'shopify-pulse-connector' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			SHOPIFY_PULSE_URL . 'assets/img/symbol.svg',
			58
		);
	}

	/**
	 * Handle the settings form POST. Uses a manual save (not register_setting)
	 * so we can mask the secret and keep the old value when the field is blank.
	 */
	public function maybe_save() {
		if ( empty( $_POST['shopify_pulse_save'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$existing = $this->all();
		$raw      = isset( $_POST['wafi'] ) && is_array( $_POST['wafi'] ) ? wp_unslash( $_POST['wafi'] ) : array();

		$clean                          = array();
		$clean['active']                = empty( $raw['active'] ) ? 0 : 1;
		$clean['api_base']              = untrailingslashit( esc_url_raw( isset( $raw['api_base'] ) ? $raw['api_base'] : '' ) );
		$clean['storefront_base']       = untrailingslashit( esc_url_raw( isset( $raw['storefront_base'] ) ? $raw['storefront_base'] : '' ) );
		$clean['sid']                   = sanitize_text_field( isset( $raw['sid'] ) ? $raw['sid'] : '' );
		$clean['client_id']             = sanitize_text_field( isset( $raw['client_id'] ) ? $raw['client_id'] : '' );
		// Secret is write-only in the UI: blank submit keeps the stored value.
		$secret_in                      = isset( $raw['client_secret'] ) ? trim( $raw['client_secret'] ) : '';
		$clean['client_secret']         = ( '' === $secret_in ) ? $existing['client_secret'] : sanitize_text_field( $secret_in );
		$clean['enable_orders']         = empty( $raw['enable_orders'] ) ? 0 : 1;
		$clean['enable_abandoned']      = empty( $raw['enable_abandoned'] ) ? 0 : 1;
		$clean['enable_analytics']      = empty( $raw['enable_analytics'] ) ? 0 : 1;
		$clean['enable_fraud']          = empty( $raw['enable_fraud'] ) ? 0 : 1;
		$fraud_action                   = isset( $raw['fraud_action'] ) ? sanitize_key( $raw['fraud_action'] ) : 'block';
		$clean['fraud_action']          = in_array( $fraud_action, array( 'block', 'hold', 'flag' ), true ) ? $fraud_action : 'block';
		$clean['courier_min_ratio']     = max( 0, min( 100, absint( isset( $raw['courier_min_ratio'] ) ? $raw['courier_min_ratio'] : 0 ) ) );
		$clean['courier_min_parcels']   = max( 1, absint( isset( $raw['courier_min_parcels'] ) ? $raw['courier_min_parcels'] : 3 ) );
		$clean['enable_customer_sync']  = empty( $raw['enable_customer_sync'] ) ? 0 : 1;
		$cust_dir                       = isset( $raw['customer_sync_dir'] ) ? sanitize_key( $raw['customer_sync_dir'] ) : 'both';
		$clean['customer_sync_dir']     = in_array( $cust_dir, array( 'push', 'pull', 'both' ), true ) ? $cust_dir : 'both';
		$dir_wl = array( 'push', 'pull', 'both' );
		$dir_of = function ( $key, $fallback ) use ( $raw, $dir_wl ) {
			$v = isset( $raw[ $key ] ) ? sanitize_key( $raw[ $key ] ) : $fallback;
			return in_array( $v, $dir_wl, true ) ? $v : $fallback;
		};
		$clean['enable_category_sync']  = empty( $raw['enable_category_sync'] ) ? 0 : 1;
		$clean['category_sync_dir']     = $dir_of( 'category_sync_dir', 'push' );
		$clean['enable_brand_sync']     = empty( $raw['enable_brand_sync'] ) ? 0 : 1;
		$clean['brand_sync_dir']        = $dir_of( 'brand_sync_dir', 'push' );
		$clean['enable_product_sync']   = empty( $raw['enable_product_sync'] ) ? 0 : 1;
		$clean['product_sync_dir']      = $dir_of( 'product_sync_dir', 'both' );
		// Mirror into the legacy bundled keys so any not-yet-updated reader (and
		// the migration guard in all()) still resolves a sane value.
		$clean['enable_catalog_sync']   = ( $clean['enable_category_sync'] || $clean['enable_brand_sync'] || $clean['enable_product_sync'] ) ? 1 : 0;
		$clean['catalog_sync_dir']      = $clean['category_sync_dir'];
		$clean['allow_status_writeback'] = empty( $raw['allow_status_writeback'] ) ? 0 : 1;
		$clean['debug_log']             = empty( $raw['debug_log'] ) ? 0 : 1;
		$clean['abandoned_idle_min']    = max( 5, absint( isset( $raw['abandoned_idle_min'] ) ? $raw['abandoned_idle_min'] : 30 ) );

		$statuses = isset( $raw['order_statuses'] ) && is_array( $raw['order_statuses'] ) ? $raw['order_statuses'] : array();
		$clean['order_statuses'] = array_values( array_map( 'sanitize_key', $statuses ) );

		// Shipping-rate map: keep only "code => positive rate id" entries. When
		// the form doesn't post a map, preserve the stored one (so a map set by
		// a future UI / filter isn't wiped by an unrelated save).
		if ( isset( $raw['shipping_map'] ) && is_array( $raw['shipping_map'] ) ) {
			$clean['shipping_map'] = array();
			foreach ( $raw['shipping_map'] as $code => $rate_id ) {
				$rid = absint( $rate_id );
				if ( $rid > 0 ) {
					$clean['shipping_map'][ substr( sanitize_text_field( (string) $code ), 0, 64 ) ] = $rid;
				}
			}
		} else {
			$clean['shipping_map'] = isset( $existing['shipping_map'] ) && is_array( $existing['shipping_map'] ) ? $existing['shipping_map'] : array();
		}

		update_option( SHOPIFY_PULSE_OPTION, $clean );
		$this->cache = null;
		// Reset the cached token whenever credentials might have changed.
		delete_transient( SHOPIFY_PULSE_TOKEN_TRANSIENT );

		add_settings_error( 'wafi_connector', 'saved', __( 'Settings saved.', 'shopify-pulse-connector' ), 'updated' );
	}

	public function ajax_test_connection() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shopify-pulse-connector' ) ), 403 );
		}
		$api    = Shopify_Pulse_Plugin::instance()->api();
		$result = $api->get( '/connect/ping' );
		if ( is_wp_error( $result ) ) {
			update_option(
				self::STATUS_OPTION,
				array( 'ok' => 0, 'error' => $result->get_error_message(), 'time' => current_time( 'mysql' ) )
			);
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$scopes = isset( $result['scopes'] ) && is_array( $result['scopes'] ) ? $result['scopes'] : array();
		update_option(
			self::STATUS_OPTION,
			array(
				'ok'     => 1,
				'sid'    => isset( $result['sid'] ) ? $result['sid'] : '',
				'scopes' => $scopes,
				'time'   => current_time( 'mysql' ),
			)
		);
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: store sid, 2: granted scopes */
					__( 'Connected to store "%1$s". Scopes: %2$s', 'shopify-pulse-connector' ),
					isset( $result['sid'] ) ? $result['sid'] : '?',
					implode( ', ', $scopes )
				),
			)
		);
	}

	/** "Sync now" — backfill recent orders to the platform. */
	public function ajax_sync_now() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shopify-pulse-connector' ) ), 403 );
		}
		if ( ! $this->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'shopify-pulse-connector' ) ) );
		}
		if ( ! $this->get( 'enable_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Order sync is turned off.', 'shopify-pulse-connector' ) ) );
		}
		$count = Shopify_Pulse_Plugin::instance()->order_sync()->backfill( 100 );
		wp_send_json_success(
			array(
				/* translators: %d: number of orders queued */
				'message' => sprintf( _n( 'Queued %d order for sync.', 'Queued %d orders for sync.', $count, 'shopify-pulse-connector' ), $count ),
			)
		);
	}

	/**
	 * Data for the shipping-mapping card: the store's WooCommerce shipping
	 * methods (keyed by "<method_id>:<instance_id>" — the shipping line code)
	 * and the platform's shipping rates (fetched once, fail-soft).
	 *
	 * @return array{0:array<string,string>,1:array}
	 */
	private function shipping_map_data() {
		$methods = array();
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			$list = array();
			foreach ( WC_Shipping_Zones::get_zones() as $z ) {
				$list[] = array( 'name' => $z['zone_name'], 'methods' => $z['shipping_methods'] );
			}
			$rest = WC_Shipping_Zones::get_zone( 0 );
			if ( $rest ) {
				$list[] = array( 'name' => __( 'Rest of the World', 'shopify-pulse-connector' ), 'methods' => $rest->get_shipping_methods() );
			}
			foreach ( $list as $z ) {
				foreach ( (array) $z['methods'] as $mobj ) {
					if ( ! is_object( $mobj ) || ! isset( $mobj->id ) ) {
						continue;
					}
					$key             = $mobj->id . ':' . $mobj->instance_id;
					$methods[ $key ] = $z['name'] . ' — ' . $mobj->get_title();
				}
			}
		}

		$rates = array();
		if ( $this->is_configured() ) {
			$res = Shopify_Pulse_Plugin::instance()->api()->get( '/connect/shipping-rates' );
			if ( ! is_wp_error( $res ) && isset( $res['rates'] ) && is_array( $res['rates'] ) ) {
				$rates = $res['rates'];
			}
		}
		return array( $methods, $rates );
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$s          = $this->all();
		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		list( $ship_methods, $ship_rates ) = $this->shipping_map_data();
		settings_errors( 'wafi_connector' );
		?>
		<?php
		$status = $this->status();
		$active = $this->is_active();
		$k      = $this->stats();
		if ( ! $active ) {
			$badge_class = 'warn';
			$badge_text  = __( 'Paused', 'shopify-pulse-connector' );
		} elseif ( ! empty( $status['ok'] ) ) {
			$badge_class = 'ok';
			$badge_text  = sprintf( /* translators: %s: store sid */ __( 'Connected · %s', 'shopify-pulse-connector' ), isset( $status['sid'] ) ? $status['sid'] : '?' );
		} else {
			$badge_class = 'err';
			$badge_text  = __( 'Not verified', 'shopify-pulse-connector' );
		}
		?>
		<div class="wrap wafi">
			<style>
				.wafi{--pri:#2271b1;--ok:#00844a;--warn:#996800;--err:#b32d2e;--bd:#dcdcde;--muted:#646970}
				.wafi h1.wp-heading-inline{margin:0}
				.wafi-hero{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:#fff;border:1px solid var(--bd);border-radius:8px;padding:16px 20px;margin:16px 0}
				.wafi-hero__title{margin:0;font-size:20px;line-height:1.2}
				.wafi-hero__sub{margin:4px 0 0;color:var(--muted);font-size:13px}
				.wafi-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
				.wafi-badge{display:inline-flex;align-items:center;gap:7px;font-weight:600;padding:5px 13px;border-radius:999px;font-size:13px}
				.wafi-badge::before{content:'';width:8px;height:8px;border-radius:50%;background:currentColor}
				.wafi-badge.ok{background:#edfaef;color:var(--ok)}.wafi-badge.warn{background:#fcf5e6;color:var(--warn)}.wafi-badge.err{background:#fcebea;color:var(--err)}
				.wafi-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:14px;margin:0 0 22px}
				.wafi-kpi{background:#fff;border:1px solid var(--bd);border-left:3px solid var(--pri);border-radius:8px;padding:14px 16px}
				.wafi-kpi.warn{border-left-color:var(--warn)}.wafi-kpi.err{border-left-color:var(--err)}
				.wafi-kpi__label{display:flex;align-items:center;gap:6px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
				.wafi-kpi__label .dashicons{font-size:15px;width:15px;height:15px;color:var(--pri)}
				.wafi-kpi__num{font-size:26px;font-weight:700;line-height:1.15;margin-top:7px;font-variant-numeric:tabular-nums;color:#1d2327}
				.wafi-kpi__sub{font-size:12px;color:var(--muted);margin-top:2px}
				.wafi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px}
				.wafi-card{background:#fff;border:1px solid var(--bd);border-radius:8px;overflow:hidden}
				.wafi-card__head{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid var(--bd);font-weight:600;font-size:14px}
				.wafi-card__head .dashicons{color:var(--pri)}
				.wafi-card__body{padding:16px}
				.wafi-field{margin:0 0 16px}.wafi-field:last-child{margin-bottom:0}
				.wafi-field>label.h{display:block;font-weight:600;margin-bottom:5px}
				.wafi-field .description{margin:5px 0 0}
				.wafi-field input[type=url],.wafi-field input[type=text],.wafi-field input[type=password],.wafi-field select{width:100%;max-width:100%}
				.wafi-check{display:block;margin:0 0 8px}.wafi-check:last-child{margin-bottom:0}
				.wafi-help{background:#fff;border:1px solid var(--bd);border-radius:8px;padding:6px 16px;margin:0 0 16px}
				.wafi-help summary{cursor:pointer;font-weight:600;padding:8px 0}
				@media(max-width:782px){.wafi-hero{flex-direction:column;align-items:flex-start}}
			</style>

			<div class="wafi-hero">
				<div>
					<h1 class="wafi-hero__title" style="margin:0;line-height:0;">
						<img src="<?php echo esc_url( SHOPIFY_PULSE_URL . 'assets/img/logo-horizontal.svg' ); ?>" alt="<?php esc_attr_e( 'Shopify Pulse', 'shopify-pulse-connector' ); ?>" height="40" style="height:40px;width:auto;display:block;" />
					</h1>
					<p class="wafi-hero__sub"><?php echo esc_html( isset( $status['time'] ) && ! empty( $status['ok'] ) ? sprintf( __( 'Last verified %s', 'shopify-pulse-connector' ), $status['time'] ) : __( 'Two-way sync between WooCommerce and your Shopify Pulse store.', 'shopify-pulse-connector' ) ); ?></p>
				</div>
				<div class="wafi-actions">
					<span class="wafi-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span>
					<button type="button" id="wafi-test-connection" class="button"><?php esc_html_e( 'Verify connection', 'shopify-pulse-connector' ); ?></button>
					<button type="button" id="wafi-sync-now" class="button button-primary"><?php esc_html_e( 'Sync now', 'shopify-pulse-connector' ); ?></button>
					<span id="wafi-test-result" style="margin-left:4px;"></span>
				</div>
			</div>

			<div class="wafi-kpis">
				<div class="wafi-kpi">
					<div class="wafi-kpi__label"><span class="dashicons dashicons-cart"></span><?php esc_html_e( 'Orders synced', 'shopify-pulse-connector' ); ?></div>
					<div class="wafi-kpi__num"><?php echo esc_html( number_format_i18n( $k['orders_synced'] ) ); ?></div>
				</div>
				<div class="wafi-kpi <?php echo $k['queue'] > 0 ? 'warn' : ''; ?>">
					<div class="wafi-kpi__label"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'In queue', 'shopify-pulse-connector' ); ?></div>
					<div class="wafi-kpi__num"><?php echo esc_html( $this->kpi_num( $k['queue'] ) ); ?></div>
					<div class="wafi-kpi__sub"><?php esc_html_e( 'awaiting push', 'shopify-pulse-connector' ); ?></div>
				</div>
				<div class="wafi-kpi <?php echo $k['failed'] > 0 ? 'err' : ''; ?>">
					<div class="wafi-kpi__label"><span class="dashicons dashicons-warning"></span><?php esc_html_e( 'Failed', 'shopify-pulse-connector' ); ?></div>
					<div class="wafi-kpi__num"><?php echo esc_html( $this->kpi_num( $k['failed'] ) ); ?></div>
					<div class="wafi-kpi__sub"><?php esc_html_e( 'retrying w/ backoff', 'shopify-pulse-connector' ); ?></div>
				</div>
				<div class="wafi-kpi">
					<div class="wafi-kpi__label"><span class="dashicons dashicons-archive"></span><?php esc_html_e( 'Abandoned pushed', 'shopify-pulse-connector' ); ?></div>
					<div class="wafi-kpi__num"><?php echo esc_html( number_format_i18n( $k['abandoned'] ) ); ?></div>
				</div>
				<div class="wafi-kpi">
					<div class="wafi-kpi__label"><span class="dashicons dashicons-products"></span><?php esc_html_e( 'Products synced', 'shopify-pulse-connector' ); ?></div>
					<div class="wafi-kpi__num"><?php echo esc_html( number_format_i18n( $k['products'] ) ); ?></div>
				</div>
				<div class="wafi-kpi">
					<div class="wafi-kpi__label"><span class="dashicons dashicons-groups"></span><?php esc_html_e( 'Customers synced', 'shopify-pulse-connector' ); ?></div>
					<div class="wafi-kpi__num"><?php echo esc_html( number_format_i18n( $k['customers'] ) ); ?></div>
				</div>
			</div>

			<details class="wafi-help">
				<summary><?php esc_html_e( 'Quick setup guide', 'shopify-pulse-connector' ); ?></summary>
				<ol style="margin:4px 0 12px 18px;line-height:1.7;">
					<li><?php esc_html_e( 'Register an OAuth app for this store on the Shopify Pulse platform (scopes below). Copy the Client ID, Client Secret (shown once) and Store SID.', 'shopify-pulse-connector' ); ?></li>
					<li><?php esc_html_e( 'Admin API base URL = your admin host (host only — /api/v1 is added). Storefront base = your storefront host, or blank if same.', 'shopify-pulse-connector' ); ?></li>
					<li><?php esc_html_e( 'Paste credentials, tick Active, choose what to sync, Save, then Verify connection. Use Sync now to backfill recent orders.', 'shopify-pulse-connector' ); ?></li>
				</ol>
			</details>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<div class="wafi-grid">

					<div class="wafi-card">
						<div class="wafi-card__head"><span class="dashicons dashicons-admin-links"></span><?php esc_html_e( 'Connection', 'shopify-pulse-connector' ); ?></div>
						<div class="wafi-card__body">
							<div class="wafi-field">
								<label class="wafi-check"><input type="checkbox" name="wafi[active]" value="1" <?php checked( $s['active'] ); ?> /> <strong><?php esc_html_e( 'Active', 'shopify-pulse-connector' ); ?></strong> — <?php esc_html_e( 'sync orders, carts, analytics & fraud', 'shopify-pulse-connector' ); ?></label>
								<p class="description"><?php esc_html_e( 'Uncheck to pause all syncing without losing settings.', 'shopify-pulse-connector' ); ?></p>
							</div>
							<div class="wafi-field">
								<label class="h" for="wafi_api_base"><?php esc_html_e( 'Admin API base URL', 'shopify-pulse-connector' ); ?></label>
								<input name="wafi[api_base]" id="wafi_api_base" type="url" class="code" value="<?php echo esc_attr( $s['api_base'] ); ?>" placeholder="https://api.admin.yourdomain.com" />
								<p class="description"><?php esc_html_e( 'Host only — /api/v1 is appended. Handles OAuth + /connect/*.', 'shopify-pulse-connector' ); ?></p>
							</div>
							<div class="wafi-field">
								<label class="h" for="wafi_storefront_base"><?php esc_html_e( 'Storefront API base URL', 'shopify-pulse-connector' ); ?></label>
								<input name="wafi[storefront_base]" id="wafi_storefront_base" type="url" class="code" value="<?php echo esc_attr( $s['storefront_base'] ); ?>" placeholder="https://api.yourdomain.com" />
								<p class="description"><?php esc_html_e( 'Handles analytics + fraud. Blank = same host as admin.', 'shopify-pulse-connector' ); ?></p>
							</div>
							<div class="wafi-field">
								<label class="h" for="wafi_sid"><?php esc_html_e( 'Store SID', 'shopify-pulse-connector' ); ?></label>
								<input name="wafi[sid]" id="wafi_sid" type="text" class="code" value="<?php echo esc_attr( $s['sid'] ); ?>" />
							</div>
							<div class="wafi-field">
								<label class="h" for="wafi_client_id"><?php esc_html_e( 'OAuth Client ID', 'shopify-pulse-connector' ); ?></label>
								<input name="wafi[client_id]" id="wafi_client_id" type="text" class="code" value="<?php echo esc_attr( $s['client_id'] ); ?>" placeholder="wapp_..." />
							</div>
							<div class="wafi-field">
								<label class="h" for="wafi_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'shopify-pulse-connector' ); ?></label>
								<input name="wafi[client_secret]" id="wafi_client_secret" type="password" class="code" value="" placeholder="<?php echo '' !== $s['client_secret'] ? esc_attr__( '•••••••• (stored — leave blank to keep)', 'shopify-pulse-connector' ) : 'wsk_...'; ?>" autocomplete="new-password" />
							</div>
						</div>
					</div>

					<div class="wafi-card">
						<div class="wafi-card__head"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'What to sync', 'shopify-pulse-connector' ); ?></div>
						<div class="wafi-card__body">
							<div class="wafi-field">
								<label class="wafi-check"><input type="checkbox" name="wafi[enable_orders]" value="1" <?php checked( $s['enable_orders'] ); ?> /> <?php esc_html_e( 'Orders (incl. incomplete/unpaid)', 'shopify-pulse-connector' ); ?></label>
								<label class="wafi-check"><input type="checkbox" name="wafi[enable_abandoned]" value="1" <?php checked( $s['enable_abandoned'] ); ?> /> <?php esc_html_e( 'Abandoned carts (pushed instantly)', 'shopify-pulse-connector' ); ?></label>
								<label class="wafi-check"><input type="checkbox" name="wafi[enable_analytics]" value="1" <?php checked( $s['enable_analytics'] ); ?> /> <?php esc_html_e( 'Analytics events (pixel / CAPI)', 'shopify-pulse-connector' ); ?></label>
								<label class="wafi-check"><input type="checkbox" name="wafi[enable_fraud]" value="1" <?php checked( $s['enable_fraud'] ); ?> /> <?php esc_html_e( '4-layer fraud screening at checkout', 'shopify-pulse-connector' ); ?></label>
							</div>
							<div class="wafi-field">
								<label class="h" for="wafi_fraud_action"><?php esc_html_e( 'When fraud is detected', 'shopify-pulse-connector' ); ?></label>
								<select name="wafi[fraud_action]" id="wafi_fraud_action">
									<option value="block" <?php selected( $s['fraud_action'], 'block' ); ?>><?php esc_html_e( 'Block checkout', 'shopify-pulse-connector' ); ?></option>
									<option value="hold" <?php selected( $s['fraud_action'], 'hold' ); ?>><?php esc_html_e( 'Allow, set order On hold', 'shopify-pulse-connector' ); ?></option>
									<option value="flag" <?php selected( $s['fraud_action'], 'flag' ); ?>><?php esc_html_e( 'Allow, add a flag note', 'shopify-pulse-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Phone/name/address, IP velocity, courier history. Fails open if the API is unreachable.', 'shopify-pulse-connector' ); ?></p>
							</div>
							<div class="wafi-field">
								<label class="h" for="wafi_courier_ratio"><?php esc_html_e( 'Courier ratio gate', 'shopify-pulse-connector' ); ?></label>
								<span style="display:inline-flex;align-items:center;gap:6px;">
									<?php esc_html_e( 'Block orders below', 'shopify-pulse-connector' ); ?>
									<input name="wafi[courier_min_ratio]" id="wafi_courier_ratio" type="number" min="0" max="100" step="1" value="<?php echo esc_attr( $s['courier_min_ratio'] ); ?>" class="small-text" /> %
									<?php esc_html_e( 'success, once the customer has', 'shopify-pulse-connector' ); ?>
									<input name="wafi[courier_min_parcels]" type="number" min="1" step="1" value="<?php echo esc_attr( $s['courier_min_parcels'] ); ?>" class="small-text" />
									<?php esc_html_e( 'parcels', 'shopify-pulse-connector' ); ?>
								</span>
								<p class="description"><?php esc_html_e( 'Set 0 to disable. e.g. 60 or 75 — customers whose bdcourier delivery-success ratio is below this (with enough parcel history) are blocked at checkout. Fails open if the API is unreachable.', 'shopify-pulse-connector' ); ?></p>
							</div>
						</div>
					</div>

					<div class="wafi-card">
						<div class="wafi-card__head"><span class="dashicons dashicons-randomize"></span><?php esc_html_e( 'Two-way sync', 'shopify-pulse-connector' ); ?></div>
						<div class="wafi-card__body">
							<div class="wafi-field">
								<label class="wafi-check"><input type="checkbox" name="wafi[enable_customer_sync]" value="1" <?php checked( $s['enable_customer_sync'] ); ?> /> <strong><?php esc_html_e( 'Customers', 'shopify-pulse-connector' ); ?></strong></label>
								<select name="wafi[customer_sync_dir]" id="wafi_cust_dir">
									<option value="both" <?php selected( $s['customer_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'shopify-pulse-connector' ); ?></option>
									<option value="push" <?php selected( $s['customer_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'shopify-pulse-connector' ); ?></option>
									<option value="pull" <?php selected( $s['customer_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce', 'shopify-pulse-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Matched by email/phone. Needs customers.read + customers.write.', 'shopify-pulse-connector' ); ?></p>
							</div>
							<div class="wafi-field">
								<label class="wafi-check"><input type="checkbox" name="wafi[enable_category_sync]" value="1" <?php checked( $s['enable_category_sync'] ); ?> /> <strong><?php esc_html_e( 'Categories', 'shopify-pulse-connector' ); ?></strong></label>
								<select name="wafi[category_sync_dir]" id="wafi_category_dir">
									<option value="push" <?php selected( $s['category_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'shopify-pulse-connector' ); ?></option>
									<option value="both" <?php selected( $s['category_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'shopify-pulse-connector' ); ?></option>
									<option value="pull" <?php selected( $s['category_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce', 'shopify-pulse-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Product categories + hierarchy. Matched to the platform by handle/slug. Needs categories.read + categories.write.', 'shopify-pulse-connector' ); ?></p>
							</div>
							<div class="wafi-field">
								<label class="wafi-check"><input type="checkbox" name="wafi[enable_brand_sync]" value="1" <?php checked( $s['enable_brand_sync'] ); ?> /> <strong><?php esc_html_e( 'Brands', 'shopify-pulse-connector' ); ?></strong></label>
								<select name="wafi[brand_sync_dir]" id="wafi_brand_dir">
									<option value="push" <?php selected( $s['brand_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'shopify-pulse-connector' ); ?></option>
									<option value="both" <?php selected( $s['brand_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'shopify-pulse-connector' ); ?></option>
									<option value="pull" <?php selected( $s['brand_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce', 'shopify-pulse-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Any brand taxonomy (native WC, Perfect Brands, YITH…). Matched by handle/slug. Needs brands.read + brands.write.', 'shopify-pulse-connector' ); ?></p>
							</div>
							<div class="wafi-field">
								<label class="wafi-check"><input type="checkbox" name="wafi[enable_product_sync]" value="1" <?php checked( $s['enable_product_sync'] ); ?> /> <strong><?php esc_html_e( 'Products', 'shopify-pulse-connector' ); ?></strong></label>
								<select name="wafi[product_sync_dir]" id="wafi_product_dir">
									<option value="both" <?php selected( $s['product_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'shopify-pulse-connector' ); ?></option>
									<option value="push" <?php selected( $s['product_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'shopify-pulse-connector' ); ?></option>
									<option value="pull" <?php selected( $s['product_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce', 'shopify-pulse-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Products + variants, mapped to existing platform products by SKU/handle. On pull, a product’s categories + brand are linked too. Needs products.read + products.write.', 'shopify-pulse-connector' ); ?></p>
							</div>
						</div>
					</div>

					<div class="wafi-card">
						<div class="wafi-card__head"><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'Advanced', 'shopify-pulse-connector' ); ?></div>
						<div class="wafi-card__body">
							<div class="wafi-field">
								<label class="h"><?php esc_html_e( 'Order statuses to push', 'shopify-pulse-connector' ); ?></label>
								<?php foreach ( $wc_statuses as $key => $label ) : ?>
									<?php $slug = preg_replace( '/^wc-/', '', $key ); ?>
									<label class="wafi-check" style="display:inline-block;min-width:150px;margin-right:8px;">
										<input type="checkbox" name="wafi[order_statuses][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $s['order_statuses'], true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<div class="wafi-field">
								<label class="h" for="wafi_idle"><?php esc_html_e( 'Abandoned idle threshold (minutes)', 'shopify-pulse-connector' ); ?></label>
								<input name="wafi[abandoned_idle_min]" id="wafi_idle" type="number" min="5" value="<?php echo esc_attr( $s['abandoned_idle_min'] ); ?>" class="small-text" />
							</div>
							<div class="wafi-field">
								<label class="wafi-check"><input type="checkbox" name="wafi[allow_status_writeback]" value="1" <?php checked( $s['allow_status_writeback'] ); ?> /> <?php esc_html_e( 'Let the platform update WooCommerce order status', 'shopify-pulse-connector' ); ?></label>
								<label class="wafi-check"><input type="checkbox" name="wafi[debug_log]" value="1" <?php checked( $s['debug_log'] ); ?> /> <?php esc_html_e( 'Verbose debug logging (WooCommerce › Status › Logs)', 'shopify-pulse-connector' ); ?></label>
							</div>
						</div>
					</div>

					<div class="wafi-card">
						<div class="wafi-card__head"><span class="dashicons dashicons-location"></span><?php esc_html_e( 'Shipping mapping', 'shopify-pulse-connector' ); ?></div>
						<div class="wafi-card__body">
							<?php if ( empty( $ship_methods ) ) : ?>
								<p class="description" style="margin-top:0;"><?php esc_html_e( 'No WooCommerce shipping methods found. Add zones + methods in WooCommerce › Settings › Shipping.', 'shopify-pulse-connector' ); ?></p>
							<?php else : ?>
								<p class="description" style="margin-top:0;"><?php esc_html_e( 'Map each WooCommerce shipping method to a platform shipping rate. Mapped charges link to that rate on the platform; unmapped ones raise a reconciliation alert.', 'shopify-pulse-connector' ); ?></p>
								<?php $map = (array) $s['shipping_map']; ?>
								<?php foreach ( $ship_methods as $key => $label ) : ?>
									<div class="wafi-field">
										<label class="h"><?php echo esc_html( $label ); ?> <span class="description">(<?php echo esc_html( $key ); ?>)</span></label>
										<?php if ( ! empty( $ship_rates ) ) : ?>
											<select name="wafi[shipping_map][<?php echo esc_attr( $key ); ?>]">
												<option value="0"><?php esc_html_e( '— not mapped —', 'shopify-pulse-connector' ); ?></option>
												<?php foreach ( $ship_rates as $r ) : $rid = isset( $r['id'] ) ? (int) $r['id'] : 0; ?>
													<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( isset( $map[ $key ] ) ? (int) $map[ $key ] : 0, $rid ); ?>>
														<?php echo esc_html( ( isset( $r['zoneName'] ) ? $r['zoneName'] . ' / ' : '' ) . ( isset( $r['name'] ) ? $r['name'] : '' ) . ( isset( $r['amount'] ) ? ' (' . $r['amount'] . ')' : '' ) ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										<?php else : ?>
											<input type="number" min="0" name="wafi[shipping_map][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $map[ $key ] ) ? $map[ $key ] : '' ); ?>" placeholder="<?php esc_attr_e( 'platform rate id', 'shopify-pulse-connector' ); ?>" class="small-text" />
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
								<?php if ( empty( $ship_rates ) ) : ?>
									<p class="description"><?php esc_html_e( 'Could not load platform rates — Verify the connection, or create shipping rates on the platform first. You can enter rate ids manually meanwhile.', 'shopify-pulse-connector' ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>

				</div>
				<p class="submit">
					<button type="submit" name="shopify_pulse_save" value="1" class="button button-primary"><?php esc_html_e( 'Save changes', 'shopify-pulse-connector' ); ?></button>
					<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Save first, then use Verify / Sync now above.', 'shopify-pulse-connector' ); ?></span>
				</p>
			</form>
		</div>
		<script>
		( function () {
			var out   = document.getElementById( 'wafi-test-result' );
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			function call( action, pending ) {
				out.textContent = pending;
				out.style.color = '#555';
				var data = new FormData();
				data.append( 'action', action );
				data.append( 'nonce', nonce );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						out.textContent = ( j && j.data && j.data.message ) ? j.data.message : 'Error';
						out.style.color = ( j && j.success ) ? '#146c43' : '#b32d2e';
					} )
					.catch( function () { out.textContent = 'Request failed'; out.style.color = '#b32d2e'; } );
			}
			var t = document.getElementById( 'wafi-test-connection' );
			if ( t ) { t.addEventListener( 'click', function () { call( 'shopify_pulse_test', <?php echo wp_json_encode( __( 'Verifying…', 'shopify-pulse-connector' ) ); ?> ); } ); }
			var s = document.getElementById( 'wafi-sync-now' );
			if ( s ) { s.addEventListener( 'click', function () { call( 'shopify_pulse_sync_now', <?php echo wp_json_encode( __( 'Queueing…', 'shopify-pulse-connector' ) ); ?> ); } ); }
		} )();
		</script>
		<?php
	}
}
