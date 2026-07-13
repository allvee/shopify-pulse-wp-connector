<?php
/**
 * SEO redirects + robots (platform → WooCommerce).
 *
 * Pulls platform-managed URL redirects and the robots disallow block, then
 * applies them on the storefront: 301/302 on matching paths via
 * `template_redirect`, and appended `Disallow:` lines via the `robots_txt`
 * filter. The platform is the source of truth for SEO redirects; the plugin is
 * the enforcement point on WordPress (no third-party redirect plugin needed).
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Seo_Sync {

	const REDIRECTS_OPTION = 'wafi_redirects';
	const ROBOTS_OPTION    = 'wafi_robots_disallow';
	const CURSOR_OPTION    = 'wafi_redirect_cursor';

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
		if ( ! $this->settings->get( 'enable_catalog_sync' ) ) {
			return;
		}
		// Enforce on the storefront from the cached rules (cheap, no API call).
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );
		add_filter( 'robots_txt', array( $this, 'filter_robots' ), 20, 2 );

		$dir = $this->settings->get( 'catalog_sync_dir', 'push' );
		if ( 'pull' === $dir || 'both' === $dir ) {
			add_action( SHOPIFY_PULSE_CATALOG_PULL_CRON, array( $this, 'pull' ) );
		}
		if ( 'push' === $dir || 'both' === $dir ) {
			// Push WordPress-authored redirects (from the active redirect plugin)
			// up to the platform, on the same tick as the pull.
			add_action( SHOPIFY_PULSE_CATALOG_PULL_CRON, array( $this, 'push_redirects' ) );
		}
	}

	/** Cron: refresh the redirect + robots caches from the platform. */
	public function pull() {
		$dir = $this->settings->get( 'catalog_sync_dir', 'push' );
		if ( 'pull' !== $dir && 'both' !== $dir ) {
			return;
		}

		$cursor = get_option( self::CURSOR_OPTION, '' );
		$res    = $this->api->get( '/connect/redirects?limit=500' . ( $cursor ? '&updatedSince=' . rawurlencode( $cursor ) : '' ) );
		if ( ! is_wp_error( $res ) ) {
			$stored = get_option( self::REDIRECTS_OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			$rows = isset( $res['redirects'] ) && is_array( $res['redirects'] ) ? $res['redirects'] : array();
			$max  = $cursor;
			foreach ( $rows as $r ) {
				if ( empty( $r['path'] ) ) {
					continue;
				}
				$stored[ $r['path'] ] = array(
					'target' => isset( $r['target'] ) ? $r['target'] : '',
					'code'   => isset( $r['code'] ) ? (int) $r['code'] : 301,
					'active' => ! empty( $r['isActive'] ),
				);
				if ( ! empty( $r['updatedAt'] ) && $r['updatedAt'] > $max ) {
					$max = $r['updatedAt'];
				}
			}
			update_option( self::REDIRECTS_OPTION, $stored, false );
			if ( $max && $max !== $cursor ) {
				update_option( self::CURSOR_OPTION, $max, false );
			}
		} else {
			$this->logger->error( 'Redirect pull failed: ' . $res->get_error_message() );
		}

		$rb = $this->api->get( '/connect/robots' );
		if ( ! is_wp_error( $rb ) ) {
			update_option( self::ROBOTS_OPTION, isset( $rb['disallow'] ) ? (string) $rb['disallow'] : '', false );
		}
	}

	/** Front-end: 301/302 a matching path to its target. */
	public function maybe_redirect() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$redirects = get_option( self::REDIRECTS_OPTION, array() );
		if ( empty( $redirects ) || ! is_array( $redirects ) ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$hit  = null;
		foreach ( array( $path, untrailingslashit( $path ), trailingslashit( $path ) ) as $candidate ) {
			if ( isset( $redirects[ $candidate ] ) ) {
				$hit = $redirects[ $candidate ];
				break;
			}
		}
		if ( $hit && ! empty( $hit['active'] ) && ! empty( $hit['target'] ) ) {
			$code = in_array( (int) $hit['code'], array( 301, 302 ), true ) ? (int) $hit['code'] : 301;
			wp_safe_redirect( $hit['target'], $code );
			exit;
		}
	}

	/** Append the platform disallow rules to robots.txt. */
	public function filter_robots( $output, $public ) {
		$dis = (string) get_option( self::ROBOTS_OPTION, '' );
		if ( '' === trim( $dis ) ) {
			return $output;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', $dis ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && 0 === strpos( $line, '/' ) ) {
				$output .= 'Disallow: ' . $line . "\n";
			}
		}
		return $output;
	}

	// ── Push (WooCommerce → platform) ───────────────────────────────────────

	const PUSH_HASHES_OPTION = 'wafi_redirect_push_hashes';

	/**
	 * Cron: read WordPress-authored redirects from whichever SEO/redirect plugin
	 * is active and push changed ones to the platform. Hash-gated per path so
	 * unchanged rules never re-send. Platform-managed redirects (applied via
	 * template_redirect, never written into a WP plugin) aren't read here, so
	 * there is no ping-pong.
	 */
	public function push_redirects() {
		$dir = $this->settings->get( 'catalog_sync_dir', 'push' );
		if ( 'push' !== $dir && 'both' !== $dir ) {
			return;
		}
		$rules = $this->read_wp_redirects();
		if ( empty( $rules ) ) {
			return;
		}
		$hashes = get_option( self::PUSH_HASHES_OPTION, array() );
		if ( ! is_array( $hashes ) ) {
			$hashes = array();
		}
		$changed = false;
		foreach ( $rules as $r ) {
			$payload = array(
				'path'     => $r['path'],
				'target'   => $r['target'],
				'code'     => $r['code'],
				'isActive' => $r['isActive'],
			);
			$hash = md5( (string) wp_json_encode( $payload ) );
			if ( isset( $hashes[ $r['path'] ] ) && $hashes[ $r['path'] ] === $hash ) {
				continue;
			}
			$res = $this->api->post( '/connect/redirects', $payload );
			if ( is_wp_error( $res ) ) {
				$this->logger->error( 'Redirect push failed for ' . $r['path'] . ': ' . $res->get_error_message() );
				continue;
			}
			$hashes[ $r['path'] ] = $hash;
			$changed              = true;
		}
		if ( $changed ) {
			update_option( self::PUSH_HASHES_OPTION, $hashes, false );
		}
	}

	/**
	 * Normalize redirects from the active redirect plugin. Returns
	 * [ [path, target, code, isActive], … ] with only exact-path (non-regex)
	 * rules — those map cleanly to the platform's (sid, path) redirect.
	 *
	 * @return array
	 */
	private function read_wp_redirects() {
		global $wpdb;
		$out = array();

		// Rank Math — table {prefix}rank_math_redirections.
		$rm = $wpdb->prefix . 'rank_math_redirections';
		if ( $rm === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rm ) ) ) { // phpcs:ignore WordPress.DB
			$rows = $wpdb->get_results( "SELECT sources, url_to, header_code, status FROM {$rm} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $row ) {
				$sources = maybe_unserialize( $row->sources );
				foreach ( (array) $sources as $src ) {
					$comparison = isset( $src['comparison'] ) ? $src['comparison'] : 'exact';
					$pattern    = isset( $src['pattern'] ) ? $src['pattern'] : '';
					if ( 'exact' !== $comparison || '' === $pattern ) {
						continue;
					}
					$out[] = $this->norm_redirect( $pattern, $row->url_to, (int) $row->header_code, true );
				}
			}
			return $this->dedupe_redirects( $out );
		}

		// Redirection plugin — table {prefix}redirection_items.
		$ri = $wpdb->prefix . 'redirection_items';
		if ( $ri === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ri ) ) ) { // phpcs:ignore WordPress.DB
			$rows = $wpdb->get_results( "SELECT url, action_data, action_code, match_type, action_type, status FROM {$ri} WHERE status = 'enabled' AND action_type = 'url' AND match_type = 'url'" ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $row ) {
				$target = is_string( $row->action_data ) ? $row->action_data : '';
				if ( '' === $target ) {
					continue;
				}
				$out[] = $this->norm_redirect( $row->url, $target, (int) $row->action_code, true );
			}
			return $this->dedupe_redirects( $out );
		}

		// Yoast Premium — option 'wpseo-premium-redirects-base'.
		$yoast = get_option( 'wpseo-premium-redirects-base' );
		if ( is_array( $yoast ) ) {
			foreach ( $yoast as $origin => $rule ) {
				$target = isset( $rule['url'] ) ? $rule['url'] : '';
				$type   = isset( $rule['type'] ) ? (int) $rule['type'] : 301;
				if ( '' === $target ) {
					continue;
				}
				$out[] = $this->norm_redirect( $origin, $target, $type, true );
			}
			return $this->dedupe_redirects( $out );
		}

		return array();
	}

	private function norm_redirect( $path, $target, $code, $active ) {
		$path = '/' . ltrim( (string) $path, '/' );
		return array(
			'path'     => substr( $path, 0, 1024 ),
			'target'   => substr( (string) $target, 0, 1024 ),
			'code'     => in_array( (int) $code, array( 301, 302 ), true ) ? (int) $code : 301,
			'isActive' => (bool) $active,
		);
	}

	private function dedupe_redirects( $rules ) {
		$by_path = array();
		foreach ( $rules as $r ) {
			if ( '' !== $r['path'] && '' !== $r['target'] ) {
				$by_path[ $r['path'] ] = $r; // last wins per path
			}
		}
		return array_values( $by_path );
	}
}
