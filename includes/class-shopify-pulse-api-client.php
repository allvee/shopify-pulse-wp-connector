<?php
/**
 * HTTP client for the Shopify Pulse platform. Owns the OAuth client_credentials token
 * lifecycle (mint, cache, refresh, single retry on 401) and attaches the
 * required X-Store-Sid tenant header to every call.
 *
 * Auth model (see platform runbook woocommerce-connector.md):
 *   - Mint a `wat_` bearer via POST {base}/api/v1/oauth/token (1h TTL, no
 *     refresh token). Cached in a transient until ~60s before expiry.
 *   - Every request carries `Authorization: Bearer wat_…` + `X-Store-Sid`.
 *   - The public pixel endpoint needs only `X-Store-Sid` (public_post()).
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Api_Client {

	const TIMEOUT = 20;

	/** @var Shopify_Pulse_Settings */
	private $settings;

	/** @var Shopify_Pulse_Logger */
	private $logger;

	public function __construct( Shopify_Pulse_Settings $settings, Shopify_Pulse_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/** Admin API host (OAuth + /connect/*). */
	private function admin_base() {
		return $this->settings->get_api_base() . '/api/v1';
	}

	/**
	 * Storefront API host (/pixel/*, /fraud/*). These live on the client-api
	 * service — a different host than the admin API. Falls back to the admin
	 * base for single-host deployments where the operator left it blank.
	 */
	private function storefront_base() {
		$sf = $this->settings->get_storefront_base();
		return ( '' !== $sf ? $sf : $this->settings->get_api_base() ) . '/api/v1';
	}

	/**
	 * Return a valid access token, minting one if the cache is empty/expired.
	 *
	 * @param bool $force Bypass the cache (used after a 401).
	 * @return string|WP_Error
	 */
	public function get_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( SHOPIFY_PULSE_TOKEN_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		if ( ! $this->settings->is_configured() ) {
			return new WP_Error( 'sp_not_configured', __( 'Connector is not configured (API base, SID, client id/secret required).', 'shopify-pulse-connector' ) );
		}

		$response = wp_remote_post(
			$this->admin_base() . '/oauth/token',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'X-Store-Sid'  => $this->settings->get_sid(),
				),
				'body'    => wp_json_encode(
					array(
						'grant_type'    => 'client_credentials',
						'client_id'     => $this->settings->get( 'client_id' ),
						'client_secret' => $this->settings->get( 'client_secret' ),
						'scope'         => 'orders.read orders.write',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Token request failed: ' . $response->get_error_message() );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			$msg = isset( $body['message'] ) ? ( is_array( $body['message'] ) ? implode( '; ', $body['message'] ) : $body['message'] ) : 'HTTP ' . $code;
			$this->logger->error( 'Token mint rejected: ' . $msg );
			return new WP_Error( 'sp_token_failed', $msg );
		}

		$token   = (string) $body['access_token'];
		$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		// Refresh a minute early so an in-flight request never races expiry.
		set_transient( SHOPIFY_PULSE_TOKEN_TRANSIENT, $token, max( 60, $expires - 60 ) );
		$this->logger->debug( 'Minted access token (expires_in=' . $expires . ')' );
		return $token;
	}

	/**
	 * Core sender. `$auth` attaches the bearer token (and retries once after
	 * re-minting on a 401); unauthenticated calls send only X-Store-Sid.
	 *
	 * @param string     $base   host base (admin or storefront) incl /api/v1
	 * @param string     $method GET|POST|PATCH|DELETE
	 * @param string     $path
	 * @param array|null $body
	 * @param bool       $auth
	 * @param bool       $retry  internal — false on the retry pass
	 * @return array|WP_Error
	 */
	private function send( $base, $method, $path, $body, $auth, $retry = true ) {
		if ( '' === $this->settings->get_sid() ) {
			return new WP_Error( 'sp_not_configured', __( 'Missing Store SID.', 'shopify-pulse-connector' ) );
		}
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'X-Store-Sid'  => $this->settings->get_sid(),
		);
		if ( $auth ) {
			$token = $this->get_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $base . $path, $args );
		if ( is_wp_error( $response ) ) {
			$this->logger->error( $method . ' ' . $path . ' transport error: ' . $response->get_error_message() );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code && $auth && $retry ) {
			// Token expired or app re-enabled — mint fresh and retry once.
			delete_transient( SHOPIFY_PULSE_TOKEN_TRANSIENT );
			$this->get_token( true );
			return $this->send( $base, $method, $path, $body, $auth, false );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = ( is_array( $decoded ) && isset( $decoded['message'] ) )
				? ( is_array( $decoded['message'] ) ? implode( '; ', $decoded['message'] ) : $decoded['message'] )
				: 'HTTP ' . $code;
			$this->logger->error( $method . ' ' . $path . ' -> ' . $msg );
			return new WP_Error( 'sp_http_' . $code, $msg, array( 'status' => $code, 'body' => $decoded ) );
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Authenticated request against the ADMIN host (/connect/*). */
	public function request( $method, $path, $body = null ) {
		return $this->send( $this->admin_base(), $method, $path, $body, true );
	}

	public function get( $path ) {
		return $this->request( 'GET', $path, null );
	}

	public function post( $path, $body ) {
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * POST against the STOREFRONT host (/pixel/*, /fraud/*). Pixel ingest is
	 * public (`$auth = false`, X-Store-Sid only); fraud screening needs the
	 * OAuth token (`$auth = true`).
	 *
	 * @return array|WP_Error
	 */
	public function storefront_post( $path, $body, $auth = false ) {
		return $this->send( $this->storefront_base(), 'POST', $path, $body, $auth );
	}

	/** Back-compat alias: public (unauthenticated) storefront POST. */
	public function public_post( $path, $body ) {
		return $this->storefront_post( $path, $body, false );
	}
}
