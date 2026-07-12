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

class Wafi_Connector_Settings {

	const CAPABILITY = 'manage_woocommerce';
	const PAGE_SLUG  = 'wafi-connector';
	const NONCE      = 'wafi_connector_settings';

	/** @var array|null */
	private $cache = null;

	const STATUS_OPTION = 'wafi_connector_status';

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
			'enable_customer_sync'  => 0,
			'customer_sync_dir'     => 'both',
			'enable_catalog_sync'   => 0,
			'catalog_sync_dir'      => 'push',
			'product_sync_dir'      => 'both',
			'order_statuses'        => array( 'pending', 'on-hold', 'processing', 'completed', 'refunded', 'cancelled', 'failed' ),
			'abandoned_idle_min'    => 30,
			'allow_status_writeback' => 0,
			'debug_log'             => 0,
		);
	}

	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( WAFI_CONNECTOR_OPTION, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );
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

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'wp_ajax_wafi_connector_test', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wafi_connector_sync_now', array( $this, 'ajax_sync_now' ) );
		add_filter(
			'plugin_action_links_' . WAFI_CONNECTOR_BASENAME,
			array( $this, 'action_links' )
		);
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wafi-connector' ) . '</a>' );
		return $links;
	}

	public function add_menu() {
		add_menu_page(
			__( 'Wafi Connector', 'wafi-connector' ),
			__( 'Wafi Connector', 'wafi-connector' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-cart',
			58
		);
	}

	/**
	 * Handle the settings form POST. Uses a manual save (not register_setting)
	 * so we can mask the secret and keep the old value when the field is blank.
	 */
	public function maybe_save() {
		if ( empty( $_POST['wafi_connector_save'] ) ) {
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
		$clean['enable_customer_sync']  = empty( $raw['enable_customer_sync'] ) ? 0 : 1;
		$cust_dir                       = isset( $raw['customer_sync_dir'] ) ? sanitize_key( $raw['customer_sync_dir'] ) : 'both';
		$clean['customer_sync_dir']     = in_array( $cust_dir, array( 'push', 'pull', 'both' ), true ) ? $cust_dir : 'both';
		$clean['enable_catalog_sync']   = empty( $raw['enable_catalog_sync'] ) ? 0 : 1;
		$cat_dir                        = isset( $raw['catalog_sync_dir'] ) ? sanitize_key( $raw['catalog_sync_dir'] ) : 'push';
		$clean['catalog_sync_dir']      = in_array( $cat_dir, array( 'push', 'pull', 'both' ), true ) ? $cat_dir : 'push';
		$prod_dir                       = isset( $raw['product_sync_dir'] ) ? sanitize_key( $raw['product_sync_dir'] ) : 'both';
		$clean['product_sync_dir']      = in_array( $prod_dir, array( 'push', 'pull', 'both' ), true ) ? $prod_dir : 'both';
		$clean['allow_status_writeback'] = empty( $raw['allow_status_writeback'] ) ? 0 : 1;
		$clean['debug_log']             = empty( $raw['debug_log'] ) ? 0 : 1;
		$clean['abandoned_idle_min']    = max( 5, absint( isset( $raw['abandoned_idle_min'] ) ? $raw['abandoned_idle_min'] : 30 ) );

		$statuses = isset( $raw['order_statuses'] ) && is_array( $raw['order_statuses'] ) ? $raw['order_statuses'] : array();
		$clean['order_statuses'] = array_values( array_map( 'sanitize_key', $statuses ) );

		update_option( WAFI_CONNECTOR_OPTION, $clean );
		$this->cache = null;
		// Reset the cached token whenever credentials might have changed.
		delete_transient( WAFI_CONNECTOR_TOKEN_TRANSIENT );

		add_settings_error( 'wafi_connector', 'saved', __( 'Settings saved.', 'wafi-connector' ), 'updated' );
	}

	public function ajax_test_connection() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wafi-connector' ) ), 403 );
		}
		$api    = Wafi_Connector_Plugin::instance()->api();
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
					__( 'Connected to store "%1$s". Scopes: %2$s', 'wafi-connector' ),
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
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wafi-connector' ) ), 403 );
		}
		if ( ! $this->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'wafi-connector' ) ) );
		}
		if ( ! $this->get( 'enable_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Order sync is turned off.', 'wafi-connector' ) ) );
		}
		$count = Wafi_Connector_Plugin::instance()->order_sync()->backfill( 100 );
		wp_send_json_success(
			array(
				/* translators: %d: number of orders queued */
				'message' => sprintf( _n( 'Queued %d order for sync.', 'Queued %d orders for sync.', $count, 'wafi-connector' ), $count ),
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$s          = $this->all();
		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		settings_errors( 'wafi_connector' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wafi Commerce Connector', 'wafi-connector' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Push WooCommerce orders, incomplete carts and analytics to your Wafi store. Register an OAuth app on the platform (scopes: orders.read, orders.write) and paste the credentials below.', 'wafi-connector' ); ?>
			</p>

			<details style="margin:12px 0;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:8px 14px;">
				<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Quick setup guide', 'wafi-connector' ); ?></summary>
				<ol style="margin:10px 0 6px 18px;line-height:1.7;">
					<li><?php esc_html_e( 'On the Wafi platform, register an OAuth app for this store with scopes orders.read and orders.write. Copy the Client ID, the Client Secret (shown once) and your Store SID.', 'wafi-connector' ); ?></li>
					<li><?php esc_html_e( 'Admin API base URL = your admin API host, e.g. https://api.admin.yourdomain.com (host only — /api/v1 is added automatically).', 'wafi-connector' ); ?></li>
					<li><?php esc_html_e( 'Storefront API base URL = your storefront/client API host, e.g. https://api.yourdomain.com. Leave blank if it is the same host as the admin API.', 'wafi-connector' ); ?></li>
					<li><?php esc_html_e( 'Paste Store SID, Client ID and Client Secret.', 'wafi-connector' ); ?></li>
					<li><?php esc_html_e( 'Tick "Active", choose what to sync (orders / carts / analytics / fraud) and the order statuses to push.', 'wafi-connector' ); ?></li>
					<li><?php esc_html_e( 'Save changes, then click "Verify connection". A green status means you are connected.', 'wafi-connector' ); ?></li>
					<li><?php esc_html_e( 'Click "Sync now" to backfill your recent orders to the platform.', 'wafi-connector' ); ?></li>
				</ol>
				<p class="description" style="margin-left:4px;">
					<?php esc_html_e( 'Not sure of your host URLs? They are the public domains your Wafi admin API and storefront API are served on. If everything runs on one domain, put it in the Admin API base URL and leave the Storefront field blank.', 'wafi-connector' ); ?>
				</p>
			</details>

			<?php
			$status = $this->status();
			$active = $this->is_active();
			if ( ! $active ) {
				$badge = '<span style="color:#8a6d3b;">● ' . esc_html__( 'Paused', 'wafi-connector' ) . '</span>';
			} elseif ( ! empty( $status['ok'] ) ) {
				$badge = '<span style="color:#146c43;">● ' . sprintf(
					/* translators: 1: store sid, 2: verified time */
					esc_html__( 'Connected to store "%1$s" — verified %2$s', 'wafi-connector' ),
					esc_html( isset( $status['sid'] ) ? $status['sid'] : '?' ),
					esc_html( isset( $status['time'] ) ? $status['time'] : '' )
				) . '</span>';
			} else {
				$badge = '<span style="color:#b32d2e;">● ' . esc_html__( 'Not verified yet — click "Verify connection"', 'wafi-connector' ) . '</span>';
			}
			?>
			<div class="notice notice-info inline" style="padding:10px 12px;margin:12px 0;">
				<strong><?php esc_html_e( 'Status:', 'wafi-connector' ); ?></strong>
				<?php echo wp_kses_post( $badge ); ?>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Connection', 'wafi-connector' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wafi[active]" value="1" <?php checked( $s['active'] ); ?> />
								<?php esc_html_e( 'Active — sync orders, carts, analytics and fraud checks', 'wafi-connector' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Uncheck to pause all syncing without losing your settings.', 'wafi-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wafi_api_base"><?php esc_html_e( 'Platform API base URL', 'wafi-connector' ); ?></label></th>
						<td>
							<input name="wafi[api_base]" id="wafi_api_base" type="url" class="regular-text code" value="<?php echo esc_attr( $s['api_base'] ); ?>" placeholder="https://api.admin.wafiperfume.com" />
							<p class="description"><?php esc_html_e( 'Host only — the plugin appends /api/v1.', 'wafi-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wafi_storefront_base"><?php esc_html_e( 'Storefront API base URL', 'wafi-connector' ); ?></label></th>
						<td>
							<input name="wafi[storefront_base]" id="wafi_storefront_base" type="url" class="regular-text code" value="<?php echo esc_attr( $s['storefront_base'] ); ?>" placeholder="https://api.wafiperfume.com" />
							<p class="description"><?php esc_html_e( 'Host for analytics + fraud (the storefront/client API). Leave blank if it is the same host as the admin API.', 'wafi-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wafi_sid"><?php esc_html_e( 'Store SID', 'wafi-connector' ); ?></label></th>
						<td><input name="wafi[sid]" id="wafi_sid" type="text" class="regular-text code" value="<?php echo esc_attr( $s['sid'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wafi_client_id"><?php esc_html_e( 'OAuth Client ID', 'wafi-connector' ); ?></label></th>
						<td><input name="wafi[client_id]" id="wafi_client_id" type="text" class="regular-text code" value="<?php echo esc_attr( $s['client_id'] ); ?>" placeholder="wapp_..." /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wafi_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'wafi-connector' ); ?></label></th>
						<td>
							<input name="wafi[client_secret]" id="wafi_client_secret" type="password" class="regular-text code" value="" placeholder="<?php echo '' !== $s['client_secret'] ? esc_attr__( '•••••••• (stored — leave blank to keep)', 'wafi-connector' ) : 'wsk_...'; ?>" autocomplete="new-password" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'What to sync', 'wafi-connector' ); ?></th>
						<td>
							<label><input type="checkbox" name="wafi[enable_orders]" value="1" <?php checked( $s['enable_orders'] ); ?> /> <?php esc_html_e( 'Orders (and incomplete/unpaid orders)', 'wafi-connector' ); ?></label><br />
							<label><input type="checkbox" name="wafi[enable_abandoned]" value="1" <?php checked( $s['enable_abandoned'] ); ?> /> <?php esc_html_e( 'Abandoned carts', 'wafi-connector' ); ?></label><br />
							<label><input type="checkbox" name="wafi[enable_analytics]" value="1" <?php checked( $s['enable_analytics'] ); ?> /> <?php esc_html_e( 'Analytics events (pixel/CAPI)', 'wafi-connector' ); ?></label><br />
							<label><input type="checkbox" name="wafi[enable_fraud]" value="1" <?php checked( $s['enable_fraud'] ); ?> /> <?php esc_html_e( '4-layer fraud screening at checkout', 'wafi-connector' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wafi_fraud_action"><?php esc_html_e( 'When fraud is detected', 'wafi-connector' ); ?></label></th>
						<td>
							<select name="wafi[fraud_action]" id="wafi_fraud_action">
								<option value="block" <?php selected( $s['fraud_action'], 'block' ); ?>><?php esc_html_e( 'Block checkout (reject the order)', 'wafi-connector' ); ?></option>
								<option value="hold" <?php selected( $s['fraud_action'], 'hold' ); ?>><?php esc_html_e( 'Allow but set order On hold for review', 'wafi-connector' ); ?></option>
								<option value="flag" <?php selected( $s['fraud_action'], 'flag' ); ?>><?php esc_html_e( 'Allow and just add a flag note', 'wafi-connector' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Runs the store\'s fraud rules (phone/name/address, IP velocity, courier history). Requires fraud prevention enabled on the platform. Fails open if the API is unreachable.', 'wafi-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Customer sync', 'wafi-connector' ); ?></th>
						<td>
							<label><input type="checkbox" name="wafi[enable_customer_sync]" value="1" <?php checked( $s['enable_customer_sync'] ); ?> /> <?php esc_html_e( 'Sync customers with the platform', 'wafi-connector' ); ?></label>
							<p style="margin-top:6px;">
								<label for="wafi_cust_dir"><?php esc_html_e( 'Direction:', 'wafi-connector' ); ?></label>
								<select name="wafi[customer_sync_dir]" id="wafi_cust_dir">
									<option value="both" <?php selected( $s['customer_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'wafi-connector' ); ?></option>
									<option value="push" <?php selected( $s['customer_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform only', 'wafi-connector' ); ?></option>
									<option value="pull" <?php selected( $s['customer_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce only', 'wafi-connector' ); ?></option>
								</select>
							</p>
							<p class="description"><?php esc_html_e( 'Matches by email/phone. Requires customers.read + customers.write scopes on the OAuth app.', 'wafi-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Catalog sync', 'wafi-connector' ); ?></th>
						<td>
							<label><input type="checkbox" name="wafi[enable_catalog_sync]" value="1" <?php checked( $s['enable_catalog_sync'] ); ?> /> <?php esc_html_e( 'Sync product categories & brands with the platform', 'wafi-connector' ); ?></label>
							<p style="margin-top:6px;">
								<label for="wafi_cat_dir"><?php esc_html_e( 'Direction:', 'wafi-connector' ); ?></label>
								<select name="wafi[catalog_sync_dir]" id="wafi_cat_dir">
									<option value="push" <?php selected( $s['catalog_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'wafi-connector' ); ?></option>
									<option value="both" <?php selected( $s['catalog_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (outbound in a later release)', 'wafi-connector' ); ?></option>
									<option value="pull" <?php selected( $s['catalog_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce (later release)', 'wafi-connector' ); ?></option>
								</select>
							</p>
							<p style="margin-top:6px;">
								<label for="wafi_prod_dir"><?php esc_html_e( 'Products & variants direction:', 'wafi-connector' ); ?></label>
								<select name="wafi[product_sync_dir]" id="wafi_prod_dir">
									<option value="both" <?php selected( $s['product_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'wafi-connector' ); ?></option>
									<option value="push" <?php selected( $s['product_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'wafi-connector' ); ?></option>
									<option value="pull" <?php selected( $s['product_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce', 'wafi-connector' ); ?></option>
								</select>
							</p>
							<p class="description"><?php esc_html_e( 'Categories/brands and products have independent sync directions. Requires products.write, brands.write, categories.write, collections.write scopes.', 'wafi-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Order statuses to push', 'wafi-connector' ); ?></th>
						<td>
							<?php foreach ( $wc_statuses as $key => $label ) : ?>
								<?php $slug = preg_replace( '/^wc-/', '', $key ); ?>
								<label style="display:inline-block;min-width:160px;">
									<input type="checkbox" name="wafi[order_statuses][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $s['order_statuses'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'A push fires when an order is created or transitions into one of these statuses.', 'wafi-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wafi_idle"><?php esc_html_e( 'Abandoned cart idle threshold (minutes)', 'wafi-connector' ); ?></label></th>
						<td><input name="wafi[abandoned_idle_min]" id="wafi_idle" type="number" min="5" value="<?php echo esc_attr( $s['abandoned_idle_min'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Advanced', 'wafi-connector' ); ?></th>
						<td>
							<label><input type="checkbox" name="wafi[allow_status_writeback]" value="1" <?php checked( $s['allow_status_writeback'] ); ?> /> <?php esc_html_e( 'Let the platform update WooCommerce order status (sync-back poll)', 'wafi-connector' ); ?></label><br />
							<label><input type="checkbox" name="wafi[debug_log]" value="1" <?php checked( $s['debug_log'] ); ?> /> <?php esc_html_e( 'Verbose debug logging (WooCommerce › Status › Logs)', 'wafi-connector' ); ?></label>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="wafi_connector_save" value="1" class="button button-primary"><?php esc_html_e( 'Save changes', 'wafi-connector' ); ?></button>
					<button type="button" id="wafi-test-connection" class="button"><?php esc_html_e( 'Verify connection', 'wafi-connector' ); ?></button>
					<button type="button" id="wafi-sync-now" class="button"><?php esc_html_e( 'Sync now', 'wafi-connector' ); ?></button>
					<span id="wafi-test-result" style="margin-left:8px;"></span>
				</p>
				<p class="description"><?php esc_html_e( '"Verify" checks the credentials + granted scopes. "Sync now" queues your recent orders (in the selected statuses) to push to the platform. Save your changes first.', 'wafi-connector' ); ?></p>
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
			if ( t ) { t.addEventListener( 'click', function () { call( 'wafi_connector_test', <?php echo wp_json_encode( __( 'Verifying…', 'wafi-connector' ) ); ?> ); } ); }
			var s = document.getElementById( 'wafi-sync-now' );
			if ( s ) { s.addEventListener( 'click', function () { call( 'wafi_connector_sync_now', <?php echo wp_json_encode( __( 'Queueing…', 'wafi-connector' ) ); ?> ); } ); }
		} )();
		</script>
		<?php
	}
}
