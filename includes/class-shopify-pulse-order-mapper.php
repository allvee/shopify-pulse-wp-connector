<?php
/**
 * Maps a WC_Order into the platform `POST /connect/orders` payload
 * (IngestOrderDto). Lines are pushed FREE-TEXT (title/sku/price, no variantId)
 * so ingestion never touches platform inventory — the platform mirrors Woo.
 *
 * @package ShopifyPulse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Order_Mapper {

	/**
	 * @param WC_Order $order
	 * @param bool     $is_backfill When true (Sync-now / past orders) mirror the
	 *                 WooCommerce status verbatim; when false (a live order) a
	 *                 COD order still in "processing" is reported as unpaid,
	 *                 since cash-on-delivery isn't collected until delivery.
	 * @return array
	 */
	public static function map( WC_Order $order, $is_backfill = false ) {
		$status = $order->get_status(); // no "wc-" prefix
		$is_cod = 'cod' === $order->get_payment_method();

		if ( 'refunded' === $status ) {
			$financial = 'refunded';
		} elseif ( ! $is_backfill && $is_cod && 'processing' === $status ) {
			// Live COD order in processing: payment is collected on delivery, so
			// it's pending, not paid. Backfilled/past orders are mirrored as-is.
			$financial = 'pending';
		} elseif ( $order->is_paid() || in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$financial = 'paid';
		} else {
			$financial = 'pending';
		}

		// Product lines + order fees. Positive fees (COD fee, gift wrap) become
		// extra line items; negative fees (gift card, store credit, smart-coupon)
		// become an order-level discount — so the mirrored total still
		// reconstructs on the platform without an OrderFee model.
		$lines        = self::line_items( $order );
		$fee_discount = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			$amt = round( (float) $fee->get_total(), 2 ); // ex-tax; can be negative
			if ( $amt < 0 ) {
				$fee_discount += -$amt;
			} elseif ( $amt > 0 ) {
				$lines[] = array(
					'title'    => $fee->get_name() ? $fee->get_name() : __( 'Fee', 'shopify-pulse-connector' ),
					'quantity' => 1,
					'price'    => $amt,
				);
			}
		}
		// Guarantee at least one line. Tax + shipping travel in their own fields
		// and the negative-fee discount is applied separately, so this residual
		// carries only the remaining amount (gross of that discount).
		if ( empty( $lines ) ) {
			$residual = (float) $order->get_total() - (float) $order->get_total_tax() - (float) $order->get_shipping_total() + $fee_discount;
			$lines[]  = array(
				'title'    => __( 'WooCommerce order', 'shopify-pulse-connector' ),
				'quantity' => 1,
				'price'    => (float) max( 0, round( $residual, 2 ) ),
			);
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
			'lineItems'        => $lines,
			'totalTax'         => (float) $order->get_total_tax(),
			// Authoritative WooCommerce aggregates. The platform re-derives the
			// total from the lines above; it uses orderTotal purely as a checksum
			// and raises a reconciliation alert when the two disagree (a dropped
			// fee, a platform-only discount, tax drift), never overriding it.
			'orderTotal'         => (float) $order->get_total(),
			'orderSubtotal'      => (float) $order->get_subtotal(),
			'orderDiscountTotal' => (float) $order->get_total_discount(),
			'note'             => $order->get_customer_note() ?: null,
		);

		// Negative-fee discount (gift card / store credit) as an order-level
		// manual discount — the connector suppresses platform auto-discounts, so
		// this is the only discount stacked on the mirror.
		if ( $fee_discount > 0 ) {
			$payload['discount'] = array( 'amount' => round( $fee_discount, 2 ), 'type' => 'amount' );
		}
		// Cumulative refunded amount → the platform books the delta and mirrors a
		// partial refund as partially_refunded (full as refunded).
		$refunded = (float) $order->get_total_refunded();
		if ( $refunded > 0 ) {
			$payload['refundedAmount'] = round( $refunded, 2 );
		}

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

		$shipping_lines = self::shipping_lines( $order );
		if ( ! empty( $shipping_lines ) ) {
			$payload['shippingLines'] = $shipping_lines;
		}

		$blob        = Shopify_Pulse_Attribution::get( $order );
		$attribution = self::attribution( $order, $blob );
		if ( ! empty( $attribution ) ) {
			$payload['attribution'] = $attribution;
		}
		// Rich attribution (first + last touch, traffic source, browser time,
		// device, visit count) that doesn't fit the flat UTM columns.
		if ( ! empty( $blob ) ) {
			$payload['attributionExtra'] = $blob;
		}

		/**
		 * Filter the mirrored-order payload before it is pushed.
		 *
		 * @param array    $payload
		 * @param WC_Order $order
		 */
		return apply_filters( 'shopify_pulse_order_payload', $payload, $order );
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
		// The "at least one line" guarantee lives in map(), after fees are folded
		// in (an itemless order may still carry fees) — return product lines only.
		return $lines;
	}

	/**
	 * One platform shipping line per WooCommerce shipping method, carrying the
	 * method's identity so the platform can map it to a shipping rate/zone (or
	 * reconcile later) instead of a hardcoded label. `code` encodes the WC
	 * method + instance (e.g. "flat_rate:3"), which the platform connector can
	 * resolve to a ShippingRate; unmatched, it stays a faithful mirror line.
	 * `price` is the ex-tax method total (shipping tax stays in totalTax), so
	 * the platform's sum(shippingLines.price) still equals get_shipping_total().
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	private static function shipping_lines( WC_Order $order ) {
		$map   = self::shipping_map();
		$lines = array();
		foreach ( $order->get_shipping_methods() as $item ) {
			/** @var WC_Order_Item_Shipping $item */
			$method_id   = is_callable( array( $item, 'get_method_id' ) ) ? (string) $item->get_method_id() : '';
			$instance_id = is_callable( array( $item, 'get_instance_id' ) ) ? (string) $item->get_instance_id() : '';
			$title       = $item->get_name() ? $item->get_name() : __( 'Shipping', 'shopify-pulse-connector' );

			$code = '' !== $method_id
				? $method_id . ( '' !== $instance_id ? ':' . $instance_id : '' )
				: 'woocommerce';

			$line = array(
				'title'  => (string) $title,
				'code'   => substr( $code, 0, 64 ),
				'source' => 'woocommerce',
				'price'  => (float) $item->get_total(), // ex-tax
			);
			// If the operator mapped this WC method to a platform shipping rate,
			// tag it so the platform links the delivery charge to that rate
			// (else the platform raises an unmapped-shipping reconcile alert).
			$rate_id = isset( $map[ $code ] ) ? (int) $map[ $code ] : 0;
			if ( $rate_id > 0 ) {
				$line['shippingRateId'] = $rate_id;
			}

			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * The operator's WooCommerce-method → platform-shipping-rate map, keyed by
	 * "<method_id>:<instance_id>" (the shipping line `code`). Stored in the
	 * connector settings option.
	 *
	 * @return array<string,int>
	 */
	private static function shipping_map() {
		$opt = get_option( SHOPIFY_PULSE_OPTION, array() );
		return ( is_array( $opt ) && isset( $opt['shipping_map'] ) && is_array( $opt['shipping_map'] ) )
			? $opt['shipping_map']
			: array();
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

	/**
	 * Flat UTM/referrer attribution for the platform's AttributionDto columns.
	 * Prefers the tracker's LAST-touch cookie; falls back per-field to
	 * WooCommerce's own order-attribution meta.
	 *
	 * @param WC_Order $order
	 * @param array    $blob   the rich attribution blob (may be empty)
	 * @return array
	 */
	private static function attribution( WC_Order $order, $blob = array() ) {
		$out  = array();
		$last = ( isset( $blob['last_touch'] ) && is_array( $blob['last_touch'] ) ) ? $blob['last_touch'] : array();
		$cap  = function ( $key, $val ) {
			$limit = ( 0 === strpos( $key, 'utm' ) ) ? 128 : 1024;
			return substr( (string) $val, 0, $limit );
		};

		$from_blob = array(
			'utmSource'   => 'utm_source',
			'utmMedium'   => 'utm_medium',
			'utmCampaign' => 'utm_campaign',
			'utmTerm'     => 'utm_term',
			'utmContent'  => 'utm_content',
			'referrer'    => 'referrer',
			'landingPath' => 'landing_path',
		);
		foreach ( $from_blob as $dto => $bk ) {
			if ( ! empty( $last[ $bk ] ) ) {
				$out[ $dto ] = $cap( $dto, $last[ $bk ] );
			}
		}

		$from_wc = array(
			'utmSource'   => '_wc_order_attribution_utm_source',
			'utmMedium'   => '_wc_order_attribution_utm_medium',
			'utmCampaign' => '_wc_order_attribution_utm_campaign',
			'utmTerm'     => '_wc_order_attribution_utm_term',
			'utmContent'  => '_wc_order_attribution_utm_content',
			'referrer'    => '_wc_order_attribution_referrer',
			'landingPath' => '_wc_order_attribution_session_entry',
		);
		foreach ( $from_wc as $dto => $meta ) {
			if ( empty( $out[ $dto ] ) ) {
				$val = $order->get_meta( $meta );
				if ( '' !== (string) $val ) {
					$out[ $dto ] = $cap( $dto, $val );
				}
			}
		}
		return $out;
	}
}
