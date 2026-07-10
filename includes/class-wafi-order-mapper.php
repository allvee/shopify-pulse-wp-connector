<?php
/**
 * Maps a WC_Order into the platform `POST /connect/orders` payload
 * (IngestOrderDto). Lines are pushed FREE-TEXT (title/sku/price, no variantId)
 * so ingestion never touches platform inventory — the platform mirrors Woo.
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wafi_Connector_Order_Mapper {

	/**
	 * @param WC_Order $order
	 * @return array
	 */
	public static function map( WC_Order $order ) {
		$status = $order->get_status(); // no "wc-" prefix

		if ( 'refunded' === $status ) {
			$financial = 'refunded';
		} elseif ( $order->is_paid() || in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$financial = 'paid';
		} else {
			$financial = 'pending';
		}

		$payload = array(
			'externalSource'   => 'woocommerce',
			'externalId'       => (string) $order->get_id(),
			'channel'          => 'manual',
			'sourceName'       => 'woocommerce',
			'currency'         => $order->get_currency(),
			'email'            => $order->get_billing_email() ?: null,
			'phone'            => $order->get_billing_phone() ?: null,
			'financialStatus'  => $financial,
			'fulfillmentStatus' => ( 'completed' === $status ) ? 'fulfilled' : 'unfulfilled',
			'wcStatus'         => $status,
			'paymentGateway'   => substr( (string) ( $order->get_payment_method() ?: 'external' ), 0, 32 ),
			'lineItems'        => self::line_items( $order ),
			'totalTax'         => (float) $order->get_total_tax(),
			'note'             => $order->get_customer_note() ?: null,
		);

		$shipping = self::address( $order, 'shipping' );
		if ( null === $shipping ) {
			$shipping = self::address( $order, 'billing' );
		}
		if ( null !== $shipping ) {
			$payload['shippingAddress'] = $shipping;
		}
		$billing = self::address( $order, 'billing' );
		if ( null !== $billing ) {
			$payload['billingAddress'] = $billing;
		}

		$ship_total = (float) $order->get_shipping_total();
		$ship_title = $order->get_shipping_method();
		if ( $ship_total > 0 || '' !== (string) $ship_title ) {
			$payload['shippingLines'] = array(
				array(
					'title'  => (string) ( $ship_title ? $ship_title : __( 'Shipping', 'wafi-connector' ) ),
					'code'   => 'woocommerce',
					'source' => 'woocommerce',
					'price'  => $ship_total,
				),
			);
		}

		$attribution = self::attribution( $order );
		if ( ! empty( $attribution ) ) {
			$payload['attribution'] = $attribution;
		}

		/**
		 * Filter the mirrored-order payload before it is pushed.
		 *
		 * @param array    $payload
		 * @param WC_Order $order
		 */
		return apply_filters( 'wafi_connector_order_payload', $payload, $order );
	}

	private static function line_items( WC_Order $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$qty      = (int) $item->get_quantity();
			$subtotal = (float) $item->get_subtotal(); // pre-discount line total
			$total    = (float) $item->get_total();     // post-discount line total
			$product  = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$sku      = $product ? $product->get_sku() : '';
			// Keep enough precision that price*qty reconstructs the line
			// subtotal — a 2-dp unit price drifts by up to a cent per line when
			// the subtotal doesn't divide evenly by quantity.
			$unit     = $qty > 0 ? round( $subtotal / $qty, 6 ) : $subtotal;

			$lines[] = array(
				'title'         => $item->get_name(),
				'sku'           => $sku ? $sku : null,
				'quantity'      => max( 1, $qty ),
				'price'         => (float) $unit,
				'totalDiscount' => (float) max( 0, round( $subtotal - $total, 2 ) ),
			);
		}
		// Guarantee at least one line — the platform rejects an empty order.
		if ( empty( $lines ) ) {
			$lines[] = array(
				'title'    => __( 'WooCommerce order', 'wafi-connector' ),
				'quantity' => 1,
				'price'    => (float) $order->get_total(),
			);
		}
		return $lines;
	}

	/**
	 * @param WC_Order $order
	 * @param string   $type shipping|billing
	 * @return array|null null when there is no usable street line.
	 */
	private static function address( WC_Order $order, $type ) {
		$g = function ( $field ) use ( $order, $type ) {
			$method = "get_{$type}_{$field}";
			return is_callable( array( $order, $method ) ) ? (string) $order->{$method}() : '';
		};

		$address1 = $g( 'address_1' );
		if ( '' === trim( $address1 ) ) {
			return null;
		}
		$name = trim( $g( 'first_name' ) . ' ' . $g( 'last_name' ) );

		return array(
			'name'     => $name,
			'company'  => $g( 'company' ),
			'phone'    => $order->get_billing_phone(),
			'email'    => $order->get_billing_email(),
			'address1' => $address1,
			'address2' => $g( 'address_2' ),
			'city'     => $g( 'city' ),
			'province' => $g( 'state' ),
			'country'  => $g( 'country' ),
			'zip'      => $g( 'postcode' ),
		);
	}

	private static function attribution( WC_Order $order ) {
		$map = array(
			'utmSource'   => '_wc_order_attribution_utm_source',
			'utmMedium'   => '_wc_order_attribution_utm_medium',
			'utmCampaign' => '_wc_order_attribution_utm_campaign',
			'utmTerm'     => '_wc_order_attribution_utm_term',
			'utmContent'  => '_wc_order_attribution_utm_content',
			'referrer'    => '_wc_order_attribution_referrer',
			'landingPath' => '_wc_order_attribution_session_entry',
		);
		$out = array();
		foreach ( $map as $key => $meta ) {
			$val = $order->get_meta( $meta );
			if ( '' !== (string) $val ) {
				$out[ $key ] = substr( (string) $val, 0, 1024 );
			}
		}
		return $out;
	}
}
