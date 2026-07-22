<?php
/**
 * "Abandoned carts" admin screen: a submenu under Shopify Pulse that turns the
 * local capture table ({@see Shopify_Pulse_Abandoned_Sync::table_name()}) into
 * an operator worklist — headline analytics, a recovery funnel, and a filtered
 * table of every captured cart with a per-row (and bulk) Resync action.
 *
 * Data source is the LOCAL table only — no platform round-trip to render — so
 * the page is instant and works even while the connection is paused. Resync
 * re-pushes to POST /connect/abandoned, which upserts on (sid, fingerprint);
 * the fingerprint is derived from the stable WC session key, so resyncing can
 * never duplicate a cart on the platform.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Abandoned_Admin {

	const CAPABILITY  = 'manage_woocommerce';
	const PAGE_SLUG   = 'shopify-pulse-abandoned';
	const PARENT_SLUG = 'shopify-pulse-connector';
	const NONCE       = 'shopify_pulse_abandoned';
	const PER_PAGE    = 100;

	/** @var Shopify_Pulse_Settings */
	private $settings;
	/** @var Shopify_Pulse_Abandoned_Sync */
	private $abandoned;
	/** @var Shopify_Pulse_Logger */
	private $logger;

	public function __construct( Shopify_Pulse_Settings $settings, Shopify_Pulse_Abandoned_Sync $abandoned, Shopify_Pulse_Logger $logger ) {
		$this->settings  = $settings;
		$this->abandoned = $abandoned;
		$this->logger    = $logger;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_shopify_pulse_abandoned_resync', array( $this, 'ajax_resync' ) );
	}

	public function add_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Abandoned carts', 'shopify-pulse-connector' ),
			__( 'Abandoned carts', 'shopify-pulse-connector' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/** True once the capture table exists (i.e. the plugin has activated once). */
	private function table_ready() {
		global $wpdb;
		$t = Shopify_Pulse_Abandoned_Sync::table_name();
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Headline analytics from cheap aggregates over the local table.
	 *
	 * @return array<string,mixed>
	 */
	private function stats() {
		global $wpdb;
		$t = Shopify_Pulse_Abandoned_Sync::table_name();
		$reachable = "( ( email IS NOT NULL AND email <> '' ) OR ( phone IS NOT NULL AND phone <> '' ) )";

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			"SELECT
				COUNT(*) AS total,
				SUM(CASE WHEN converted = 1 THEN 1 ELSE 0 END) AS recovered,
				SUM(CASE WHEN converted = 0 AND synced = 1 THEN 1 ELSE 0 END) AS pushed,
				SUM(CASE WHEN converted = 0 AND synced = 0 AND {$reachable} THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN converted = 0 AND NOT {$reachable} THEN 1 ELSE 0 END) AS unreachable,
				SUM(CASE WHEN converted = 0 AND {$reachable} THEN 1 ELSE 0 END) AS reachable_open,
				SUM(CASE WHEN converted = 0 THEN subtotal ELSE 0 END) AS open_value,
				SUM(CASE WHEN converted = 1 THEN subtotal ELSE 0 END) AS recovered_value
			FROM {$t}",
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : array();

		$funnel = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT COALESCE(NULLIF(furthest_step, ''), 'unknown') AS step, COUNT(*) AS n
			 FROM {$t} WHERE converted = 0 GROUP BY step ORDER BY n DESC",
			ARRAY_A
		);

		$total       = (int) ( $row['total'] ?? 0 );
		$recovered   = (int) ( $row['recovered'] ?? 0 );
		$open        = $total - $recovered;
		$open_val    = (float) ( $row['open_value'] ?? 0 );
		$reach_open  = (int) ( $row['reachable_open'] ?? 0 );

		return array(
			'total'           => $total,
			'open'            => $open,
			'recovered'       => $recovered,
			'pushed'          => (int) ( $row['pushed'] ?? 0 ),
			'pending'         => (int) ( $row['pending'] ?? 0 ),
			'unreachable'     => (int) ( $row['unreachable'] ?? 0 ),
			'reachable_open'  => $reach_open,
			'open_value'      => $open_val,
			'recovered_value' => (float) ( $row['recovered_value'] ?? 0 ),
			'avg_open'        => $open > 0 ? $open_val / $open : 0,
			'recovery_rate'   => $total > 0 ? $recovered / $total : 0,
			'funnel'          => is_array( $funnel ) ? $funnel : array(),
		);
	}

	/**
	 * Fetch a page of rows honouring the status filter.
	 *
	 * @param string $filter all|open|pending|recovered|unreachable
	 * @return array
	 */
	private function rows( $filter ) {
		global $wpdb;
		$t         = Shopify_Pulse_Abandoned_Sync::table_name();
		$reachable = "( ( email IS NOT NULL AND email <> '' ) OR ( phone IS NOT NULL AND phone <> '' ) )";
		switch ( $filter ) {
			case 'recovered':
				$where = 'converted = 1';
				break;
			case 'pending':
				$where = "converted = 0 AND synced = 0 AND {$reachable}";
				break;
			case 'unreachable':
				$where = "converted = 0 AND NOT {$reachable}";
				break;
			case 'open':
				$where = 'converted = 0';
				break;
			case 'all':
			default:
				$where = '1=1';
				break;
		}
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY updated_at DESC LIMIT %d", self::PER_PAGE )
		);
	}

	/** Currency-format a number using the store's WooCommerce currency symbol. */
	private function money( $n, $currency = '' ) {
		$symbol = '';
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = html_entity_decode( get_woocommerce_currency_symbol( $currency ? $currency : null ) );
		}
		return $symbol . number_format_i18n( (float) $n, 0 );
	}

	/** Classify a row into a status label + tone for the badge. */
	private function row_status( $row ) {
		$reachable = ( ! empty( $row->email ) || ! empty( $row->phone ) );
		if ( (int) $row->converted === 1 ) {
			return array( 'recovered', __( 'Recovered', 'shopify-pulse-connector' ), 'ok' );
		}
		if ( ! $reachable ) {
			return array( 'unreachable', __( 'Unreachable', 'shopify-pulse-connector' ), 'muted' );
		}
		if ( (int) $row->synced === 1 ) {
			return array( 'pushed', __( 'Pushed', 'shopify-pulse-connector' ), 'info' );
		}
		return array( 'pending', __( 'Pending push', 'shopify-pulse-connector' ), 'warn' );
	}

	public function ajax_resync() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shopify-pulse-connector' ) ), 403 );
		}
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'shopify-pulse-connector' ) ) );
		}
		if ( ! $this->settings->get( 'enable_abandoned' ) ) {
			wp_send_json_error( array( 'message' => __( 'Abandoned-cart sync is turned off in settings.', 'shopify-pulse-connector' ) ) );
		}

		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'one';
		if ( 'all' === $scope ) {
			$sent = $this->abandoned->resync_pending( self::PER_PAGE );
			wp_send_json_success(
				array(
					'scope'   => 'all',
					'sent'    => $sent,
					/* translators: %d: number of carts */
					'message' => sprintf( _n( 'Resynced %d cart.', 'Resynced %d carts.', $sent, 'shopify-pulse-connector' ), $sent ),
				)
			);
		}

		$key = isset( $_POST['session_key'] ) ? sanitize_text_field( wp_unslash( $_POST['session_key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing cart reference.', 'shopify-pulse-connector' ) ) );
		}
		$ok = $this->abandoned->resync( $key );
		if ( $ok ) {
			wp_send_json_success( array( 'scope' => 'one', 'message' => __( 'Resynced', 'shopify-pulse-connector' ) ) );
		}
		wp_send_json_error( array( 'message' => __( 'Could not resync — no contact captured, or the API rejected it. Check the logs.', 'shopify-pulse-connector' ) ) );
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$filter  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification
		$allowed = array( 'all', 'open', 'pending', 'recovered', 'unreachable' );
		if ( ! in_array( $filter, $allowed, true ) ) {
			$filter = 'open';
		}

		if ( ! $this->table_ready() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Abandoned carts', 'shopify-pulse-connector' ) . '</h1>';
			echo '<p>' . esc_html__( 'No capture table yet. Enable "Abandoned carts" in Shopify Pulse settings, then re-save to create it.', 'shopify-pulse-connector' ) . '</p></div>';
			return;
		}

		$k        = $this->stats();
		$rows     = $this->rows( $filter );
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BDT';
		$active   = $this->settings->is_active() && $this->settings->get( 'enable_abandoned' );
		$max_step = 0;
		foreach ( $k['funnel'] as $f ) {
			$max_step = max( $max_step, (int) $f['n'] );
		}
		?>
		<div class="wrap sp-ab">
			<style>
				.sp-ab{--pri:#2271b1;--ok:#00844a;--warn:#996800;--err:#b32d2e;--info:#1d6ad4;--bd:#dcdcde;--muted:#646970}
				.sp-ab .sp-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:12px 0 4px}
				.sp-ab h1{margin:0;font-size:22px}
				.sp-ab .sp-sub{color:var(--muted);font-size:13px;margin:2px 0 0}
				.sp-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin:16px 0 18px}
				.sp-kpi{background:#fff;border:1px solid var(--bd);border-left:3px solid var(--pri);border-radius:8px;padding:12px 14px}
				.sp-kpi.ok{border-left-color:var(--ok)}.sp-kpi.warn{border-left-color:var(--warn)}.sp-kpi.muted{border-left-color:var(--muted)}.sp-kpi.info{border-left-color:var(--info)}
				.sp-kpi__label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
				.sp-kpi__num{font-size:24px;font-weight:700;line-height:1.15;margin-top:6px;font-variant-numeric:tabular-nums;color:#1d2327}
				.sp-kpi__sub{font-size:12px;color:var(--muted);margin-top:2px}
				.sp-panel{background:#fff;border:1px solid var(--bd);border-radius:8px;margin:0 0 18px}
				.sp-panel__head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border-bottom:1px solid var(--bd);font-weight:600}
				.sp-panel__body{padding:14px}
				.sp-funnel{display:flex;flex-direction:column;gap:8px}
				.sp-funnel__row{display:grid;grid-template-columns:120px 1fr 48px;align-items:center;gap:10px;font-size:13px}
				.sp-funnel__bar{height:10px;border-radius:999px;background:linear-gradient(90deg,#2271b1,#4a9fe0);min-width:3px}
				.sp-funnel__track{background:#f0f0f1;border-radius:999px;overflow:hidden}
				.sp-filters{display:flex;gap:6px;flex-wrap:wrap}
				.sp-filters a{text-decoration:none;font-size:13px;padding:4px 10px;border:1px solid var(--bd);border-radius:999px;color:#1d2327;background:#fff}
				.sp-filters a.on{background:var(--pri);border-color:var(--pri);color:#fff}
				.sp-tbl{width:100%;border-collapse:collapse;background:#fff}
				.sp-tbl th,.sp-tbl td{text-align:left;padding:10px 12px;border-bottom:1px solid #f0f0f1;font-size:13px;vertical-align:top}
				.sp-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);background:#fbfbfc}
				.sp-tbl tr:last-child td{border-bottom:0}
				.sp-badge{display:inline-flex;align-items:center;gap:6px;font-weight:600;padding:3px 9px;border-radius:999px;font-size:12px}
				.sp-badge::before{content:'';width:7px;height:7px;border-radius:50%;background:currentColor}
				.sp-badge.ok{background:#edfaef;color:var(--ok)}.sp-badge.warn{background:#fcf5e6;color:var(--warn)}.sp-badge.err{background:#fcebea;color:var(--err)}.sp-badge.info{background:#e9f2fd;color:var(--info)}.sp-badge.muted{background:#f0f0f1;color:var(--muted)}
				.sp-cust{font-weight:600}
				.sp-contact{color:var(--muted);font-size:12px;margin-top:2px;line-height:1.5}
				.sp-mono{font-variant-numeric:tabular-nums}
				.sp-empty{padding:40px 16px;text-align:center;color:var(--muted)}
				.sp-ab .sp-msg{font-size:12px}
				@media(max-width:900px){.sp-funnel__row{grid-template-columns:90px 1fr 40px}.sp-tbl thead{display:none}.sp-tbl,.sp-tbl tbody,.sp-tbl tr,.sp-tbl td{display:block;width:100%}.sp-tbl tr{border-bottom:1px solid var(--bd);padding:6px 0}.sp-tbl td{border:0;padding:4px 12px}}
			</style>

			<div class="sp-top">
				<div>
					<h1><?php esc_html_e( 'Abandoned carts', 'shopify-pulse-connector' ); ?></h1>
					<p class="sp-sub"><?php esc_html_e( 'Captured on this WooCommerce store and mirrored to Shopify Pulse for recovery. Resync re-pushes without creating duplicates.', 'shopify-pulse-connector' ); ?></p>
				</div>
				<div>
					<button type="button" id="sp-resync-all" class="button button-primary" <?php disabled( ! $active || $k['pending'] < 1 ); ?>>
						<?php
						/* translators: %d: number of carts awaiting push */
						echo esc_html( sprintf( __( 'Resync all pending (%d)', 'shopify-pulse-connector' ), $k['pending'] ) );
						?>
					</button>
					<span id="sp-resync-msg" class="sp-msg" style="margin-left:8px;"></span>
				</div>
			</div>

			<?php if ( ! $active ) : ?>
				<div class="notice notice-warning inline" style="margin:8px 0;"><p><?php esc_html_e( 'Abandoned-cart sync is paused or disabled. New carts aren’t captured and Resync is off until you enable it in Shopify Pulse settings.', 'shopify-pulse-connector' ); ?></p></div>
			<?php endif; ?>

			<div class="sp-kpis">
				<div class="sp-kpi">
					<div class="sp-kpi__label"><?php esc_html_e( 'Total captured', 'shopify-pulse-connector' ); ?></div>
					<div class="sp-kpi__num"><?php echo esc_html( number_format_i18n( $k['total'] ) ); ?></div>
				</div>
				<div class="sp-kpi warn">
					<div class="sp-kpi__label"><?php esc_html_e( 'Open carts', 'shopify-pulse-connector' ); ?></div>
					<div class="sp-kpi__num"><?php echo esc_html( number_format_i18n( $k['open'] ) ); ?></div>
					<div class="sp-kpi__sub"><?php echo esc_html( sprintf( __( '%s reachable', 'shopify-pulse-connector' ), number_format_i18n( $k['reachable_open'] ) ) ); ?></div>
				</div>
				<div class="sp-kpi info">
					<div class="sp-kpi__label"><?php esc_html_e( 'Pushed', 'shopify-pulse-connector' ); ?></div>
					<div class="sp-kpi__num"><?php echo esc_html( number_format_i18n( $k['pushed'] ) ); ?></div>
					<div class="sp-kpi__sub"><?php echo esc_html( sprintf( __( '%s pending', 'shopify-pulse-connector' ), number_format_i18n( $k['pending'] ) ) ); ?></div>
				</div>
				<div class="sp-kpi ok">
					<div class="sp-kpi__label"><?php esc_html_e( 'Recovered', 'shopify-pulse-connector' ); ?></div>
					<div class="sp-kpi__num"><?php echo esc_html( number_format_i18n( $k['recovered'] ) ); ?></div>
					<div class="sp-kpi__sub"><?php echo esc_html( sprintf( __( '%s rate', 'shopify-pulse-connector' ), number_format_i18n( $k['recovery_rate'] * 100, 1 ) . '%' ) ); ?></div>
				</div>
				<div class="sp-kpi">
					<div class="sp-kpi__label"><?php esc_html_e( 'Open value', 'shopify-pulse-connector' ); ?></div>
					<div class="sp-kpi__num sp-mono"><?php echo esc_html( $this->money( $k['open_value'], $currency ) ); ?></div>
					<div class="sp-kpi__sub"><?php echo esc_html( sprintf( __( 'avg %s', 'shopify-pulse-connector' ), $this->money( $k['avg_open'], $currency ) ) ); ?></div>
				</div>
				<div class="sp-kpi ok">
					<div class="sp-kpi__label"><?php esc_html_e( 'Recovered value', 'shopify-pulse-connector' ); ?></div>
					<div class="sp-kpi__num sp-mono"><?php echo esc_html( $this->money( $k['recovered_value'], $currency ) ); ?></div>
				</div>
			</div>

			<?php if ( ! empty( $k['funnel'] ) ) : ?>
			<div class="sp-panel">
				<div class="sp-panel__head"><?php esc_html_e( 'Where open carts drop off', 'shopify-pulse-connector' ); ?></div>
				<div class="sp-panel__body">
					<div class="sp-funnel">
						<?php foreach ( $k['funnel'] as $f ) : $n = (int) $f['n']; $pct = $max_step > 0 ? round( $n / $max_step * 100 ) : 0; ?>
							<div class="sp-funnel__row">
								<span><?php echo esc_html( ucfirst( (string) $f['step'] ) ); ?></span>
								<span class="sp-funnel__track"><span class="sp-funnel__bar" style="width:<?php echo esc_attr( max( 3, $pct ) ); ?>%"></span></span>
								<span class="sp-mono" style="text-align:right;"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<div class="sp-panel">
				<div class="sp-panel__head">
					<span><?php esc_html_e( 'Carts', 'shopify-pulse-connector' ); ?></span>
					<span class="sp-filters">
						<?php
						$labels = array(
							'open'        => __( 'Open', 'shopify-pulse-connector' ),
							'pending'     => __( 'Pending', 'shopify-pulse-connector' ),
							'recovered'   => __( 'Recovered', 'shopify-pulse-connector' ),
							'unreachable' => __( 'Unreachable', 'shopify-pulse-connector' ),
							'all'         => __( 'All', 'shopify-pulse-connector' ),
						);
						foreach ( $labels as $key => $label ) :
							$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'status' => $key ), admin_url( 'admin.php' ) );
							?>
							<a class="<?php echo $filter === $key ? 'on' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</span>
				</div>
				<div class="sp-panel__body" style="padding:0;">
					<?php if ( empty( $rows ) ) : ?>
						<div class="sp-empty"><?php esc_html_e( 'No carts match this filter.', 'shopify-pulse-connector' ); ?></div>
					<?php else : ?>
					<table class="sp-tbl">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Customer', 'shopify-pulse-connector' ); ?></th>
								<th><?php esc_html_e( 'Address', 'shopify-pulse-connector' ); ?></th>
								<th><?php esc_html_e( 'Cart', 'shopify-pulse-connector' ); ?></th>
								<th><?php esc_html_e( 'Value', 'shopify-pulse-connector' ); ?></th>
								<th><?php esc_html_e( 'Step', 'shopify-pulse-connector' ); ?></th>
								<th><?php esc_html_e( 'Status', 'shopify-pulse-connector' ); ?></th>
								<th><?php esc_html_e( 'Updated', 'shopify-pulse-connector' ); ?></th>
								<th style="text-align:right;"><?php esc_html_e( 'Action', 'shopify-pulse-connector' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $rows as $row ) :
								$lines = json_decode( (string) $row->cart_json, true );
								$lines = is_array( $lines ) ? $lines : array();
								$count = 0;
								foreach ( $lines as $l ) {
									$count += isset( $l['qty'] ) ? (int) $l['qty'] : 1;
								}
								$first = ! empty( $lines[0]['title'] ) ? (string) $lines[0]['title'] : '';
								$addr  = json_decode( (string) $row->address_json, true );
								$addr  = is_array( $addr ) ? $addr : array();
								$addr_bits = array_filter( array(
									isset( $addr['address1'] ) ? $addr['address1'] : '',
									isset( $addr['city'] ) ? $addr['city'] : '',
									isset( $addr['province'] ) ? $addr['province'] : '',
								) );
								list( $status_key, $status_label, $status_tone ) = $this->row_status( $row );
								$can_resync = ( 'recovered' !== $status_key && 'unreachable' !== $status_key );
								?>
								<tr data-key="<?php echo esc_attr( $row->session_key ); ?>">
									<td>
										<div class="sp-cust"><?php echo esc_html( $row->customer_name ? $row->customer_name : __( 'Anonymous', 'shopify-pulse-connector' ) ); ?></div>
										<div class="sp-contact">
											<?php if ( $row->phone ) : ?><span>📞 <?php echo esc_html( $row->phone ); ?></span><br /><?php endif; ?>
											<?php if ( $row->email ) : ?><span>✉ <?php echo esc_html( $row->email ); ?></span><?php endif; ?>
											<?php if ( ! $row->phone && ! $row->email ) : ?><span><?php esc_html_e( 'no contact', 'shopify-pulse-connector' ); ?></span><?php endif; ?>
										</div>
									</td>
									<td class="sp-contact" style="color:#1d2327;">
										<?php echo $addr_bits ? esc_html( implode( ', ', $addr_bits ) ) : '<span style="color:#646970;">—</span>'; ?>
									</td>
									<td>
										<div class="sp-mono"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $count, 'shopify-pulse-connector' ), $count ) ); ?></div>
										<?php if ( $first ) : ?><div class="sp-contact"><?php echo esc_html( wp_html_excerpt( $first, 42, '…' ) ); ?></div><?php endif; ?>
									</td>
									<td class="sp-mono"><?php echo esc_html( $this->money( $row->subtotal, $row->currency ? $row->currency : $currency ) ); ?></td>
									<td><?php echo esc_html( $row->furthest_step ? ucfirst( (string) $row->furthest_step ) : '—' ); ?></td>
									<td><span class="sp-badge <?php echo esc_attr( $status_tone ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
									<td class="sp-contact" style="color:#1d2327;"><?php echo esc_html( $row->updated_at ? human_time_diff( strtotime( $row->updated_at . ' UTC' ) ) . ' ' . __( 'ago', 'shopify-pulse-connector' ) : '—' ); ?></td>
									<td style="text-align:right;">
										<?php if ( $can_resync ) : ?>
											<button type="button" class="button sp-resync-one" data-key="<?php echo esc_attr( $row->session_key ); ?>" <?php disabled( ! $active ); ?>><?php esc_html_e( 'Resync', 'shopify-pulse-connector' ); ?></button>
										<?php else : ?>
											<span class="sp-contact">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<script>
		( function () {
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var syncing = <?php echo wp_json_encode( __( 'Resyncing…', 'shopify-pulse-connector' ) ); ?>;
			var failed  = <?php echo wp_json_encode( __( 'Failed', 'shopify-pulse-connector' ) ); ?>;
			function post( body ) {
				return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
			}
			Array.prototype.forEach.call( document.querySelectorAll( '.sp-resync-one' ), function ( btn ) {
				btn.addEventListener( 'click', function () {
					var original = btn.textContent;
					btn.disabled = true; btn.textContent = syncing;
					var data = new FormData();
					data.append( 'action', 'shopify_pulse_abandoned_resync' );
					data.append( 'nonce', nonce );
					data.append( 'scope', 'one' );
					data.append( 'session_key', btn.getAttribute( 'data-key' ) );
					post( data ).then( function ( j ) {
						if ( j && j.success ) {
							btn.textContent = '✓ ' + ( j.data && j.data.message ? j.data.message : 'Done' );
							btn.classList.add( 'button-primary' );
						} else {
							btn.disabled = false; btn.textContent = original;
							alert( ( j && j.data && j.data.message ) ? j.data.message : failed );
						}
					} ).catch( function () { btn.disabled = false; btn.textContent = original; alert( failed ); } );
				} );
			} );
			var all = document.getElementById( 'sp-resync-all' );
			var msg = document.getElementById( 'sp-resync-msg' );
			if ( all ) {
				all.addEventListener( 'click', function () {
					all.disabled = true; msg.textContent = syncing; msg.style.color = '#555';
					var data = new FormData();
					data.append( 'action', 'shopify_pulse_abandoned_resync' );
					data.append( 'nonce', nonce );
					data.append( 'scope', 'all' );
					post( data ).then( function ( j ) {
						msg.textContent = ( j && j.data && j.data.message ) ? j.data.message : failed;
						msg.style.color = ( j && j.success ) ? '#146c43' : '#b32d2e';
						if ( j && j.success ) { setTimeout( function () { location.reload(); }, 900 ); }
					} ).catch( function () { all.disabled = false; msg.textContent = failed; msg.style.color = '#b32d2e'; } );
				} );
			}
		} )();
		</script>
		<?php
	}
}
