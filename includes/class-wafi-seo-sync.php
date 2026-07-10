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

class Wafi_Connector_Seo_Sync {

	const REDIRECTS_OPTION = 'wafi_redirects';
	const ROBOTS_OPTION    = 'wafi_robots_disallow';
	const CURSOR_OPTION    = 'wafi_redirect_cursor';

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
		// Enforce on the storefront from the cached rules (cheap, no API call).
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );
		add_filter( 'robots_txt', array( $this, 'filter_robots' ), 20, 2 );

		$dir = $this->settings->get( 'catalog_sync_dir', 'push' );
		if ( 'pull' === $dir || 'both' === $dir ) {
			add_action( WAFI_CONNECTOR_CATALOG_PULL_CRON, array( $this, 'pull' ) );
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
}
