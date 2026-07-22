/**
 * Block (Store API) checkout abandoned-cart beacon.
 *
 * WooCommerce Blocks fires no server hook while the shopper fills the checkout
 * form — only at order placement — so we read the contact + address + cart from
 * the `wc/store/cart` data store client-side and POST a snapshot to the plugin
 * once a contact (email or phone) is present. The platform dedupes on a stable
 * per-browser key, so repeated posts update the same cart, never duplicate it.
 *
 * @package ShopifyPulse
 */
( function () {
	'use strict';

	if ( ! window.wp || ! window.wp.data || ! window.SPBeacon || ! window.SPBeacon.url ) {
		return;
	}
	var cfg = window.SPBeacon;
	var STORAGE_KEY = 'sp_cart_key';
	var lastHash = '';
	var timer = null;

	function cartKey() {
		try {
			var k = window.localStorage.getItem( STORAGE_KEY );
			if ( ! k ) {
				k = 'k' + Math.random().toString( 36 ).slice( 2 ) + Date.now().toString( 36 );
				window.localStorage.setItem( STORAGE_KEY, k );
			}
			return k;
		} catch ( e ) {
			return 'k-nostorage';
		}
	}

	function num( v ) {
		var n = Number( v );
		return isFinite( n ) ? n : 0;
	}

	function collect() {
		var sel = window.wp.data.select( 'wc/store/cart' );
		if ( ! sel || ! sel.getCartData || ! sel.getCustomerData ) {
			return null;
		}
		var cart = sel.getCartData();
		var cust = sel.getCustomerData();
		if ( ! cart || ! cust ) {
			return null;
		}
		var b = cust.billingAddress || {};
		var s = cust.shippingAddress || {};
		var email = b.email || cust.email || '';
		var phone = b.phone || s.phone || '';
		if ( ! email && ! phone ) {
			return null; // an incomplete order needs a way to be reached
		}

		var items = ( cart.items || [] ).map( function ( it ) {
			var minor = it.prices && it.prices.currency_minor_unit != null ? it.prices.currency_minor_unit : 2;
			var div = Math.pow( 10, minor );
			return {
				product_id: it.id,
				title: it.name,
				sku: it.sku || null,
				qty: it.quantity,
				price: it.prices ? num( it.prices.price ) / div : 0
			};
		} );
		if ( ! items.length ) {
			return null;
		}

		var totals = cart.totals || {};
		var minorT = totals.currency_minor_unit != null ? totals.currency_minor_unit : 2;
		var subtotal = totals.total_items != null ? num( totals.total_items ) / Math.pow( 10, minorT ) : 0;

		return {
			key: cartKey(),
			email: email,
			phone: phone,
			first_name: b.first_name || s.first_name || '',
			last_name: b.last_name || s.last_name || '',
			address_1: b.address_1 || s.address_1 || '',
			address_2: b.address_2 || s.address_2 || '',
			city: b.city || s.city || '',
			state: b.state || s.state || '',
			postcode: b.postcode || s.postcode || '',
			country: b.country || s.country || '',
			lines: items,
			subtotal: subtotal,
			currency: totals.currency_code || 'BDT',
			furthest_step: ( b.address_1 || s.address_1 ) ? 'address' : 'contact'
		};
	}

	function send() {
		var payload = collect();
		if ( ! payload ) {
			return;
		}
		var body = JSON.stringify( payload );
		if ( body === lastHash ) {
			return; // nothing changed since the last post
		}
		lastHash = body;
		try {
			window.fetch( cfg.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
				body: body,
				keepalive: true
			} ).catch( function () {} );
		} catch ( e ) {}
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( send, cfg.debounce || 4000 );
	}

	// The checkout store updates on every keystroke; debounce coalesces those.
	window.wp.data.subscribe( schedule );
	// Last-chance flush if the tab is being hidden/closed mid-checkout.
	window.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			send();
		}
	} );
} )();
