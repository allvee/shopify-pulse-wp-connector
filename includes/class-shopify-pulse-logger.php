<?php
/**
 * Thin wrapper over WC_Logger so all connector output lands in one source
 * ("shopify-pulse-connector") under WooCommerce › Status › Logs.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Logger {

	/** @var Shopify_Pulse_Settings */
	private $settings;

	/** @var WC_Logger_Interface|null */
	private $wc_logger = null;

	public function __construct( Shopify_Pulse_Settings $settings ) {
		$this->settings = $settings;
	}

	private function logger() {
		if ( null === $this->wc_logger && function_exists( 'wc_get_logger' ) ) {
			$this->wc_logger = wc_get_logger();
		}
		return $this->wc_logger;
	}

	/**
	 * @param string $message
	 * @param string $level   emergency|alert|critical|error|warning|notice|info|debug
	 */
	public function log( $message, $level = 'info' ) {
		// Debug lines are suppressed unless the operator enables debug logging.
		if ( 'debug' === $level && ! $this->settings->get( 'debug_log' ) ) {
			return;
		}
		$logger = $this->logger();
		if ( ! $logger ) {
			return;
		}
		if ( ! is_scalar( $message ) ) {
			$message = wp_json_encode( $message );
		}
		$logger->log( $level, (string) $message, array( 'source' => 'shopify-pulse-connector' ) );
	}

	public function error( $message ) {
		$this->log( $message, 'error' );
	}

	public function debug( $message ) {
		$this->log( $message, 'debug' );
	}
}
