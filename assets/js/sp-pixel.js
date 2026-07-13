/**
 * Shopify Pulse Connector — browser analytics. Fires PageView / ViewContent /
 * InitiateCheckout / AddToCart through the same-site AJAX proxy
 * (admin-ajax.php?action=sp_track), which forwards to the platform's public
 * /pixel/events. Purchase is handled server-side, never here.
 */
( function () {
	'use strict';

	if ( typeof window.spPixel === 'undefined' || ! window.spPixel.ajaxUrl ) {
		return;
	}

	var cfg = window.spPixel;

	function uid( name ) {
		return name + '-' + Date.now() + '-' + Math.floor( Math.random() * 1e6 );
	}

	function send( eventName, custom ) {
		var data = new FormData();
		data.append( 'action', 'sp_track' );
		data.append( 'nonce', cfg.nonce );
		data.append( 'eventName', eventName );
		data.append( 'eventId', uid( eventName ) );
		data.append( 'url', cfg.page && cfg.page.url ? cfg.page.url : window.location.href );
		if ( custom ) {
			data.append( 'custom', JSON.stringify( custom ) );
		}
		// Prefer sendBeacon so navigation doesn't cancel the request.
		if ( navigator.sendBeacon ) {
			data.append( '_beacon', '1' );
			navigator.sendBeacon( cfg.ajaxUrl, data );
		} else {
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data, keepalive: true } );
		}
	}

	function pageCustom() {
		var p = cfg.page || {};
		var c = { currency: p.currency || '' };
		if ( p.contentIds && p.contentIds.length ) {
			c.content_ids = p.contentIds;
		}
		if ( p.value ) {
			c.value = p.value;
		}
		return c;
	}

	function init() {
		var type = ( cfg.page && cfg.page.type ) || 'other';

		// PageView on every front-end load.
		send( 'PageView', { currency: cfg.page ? cfg.page.currency : '' } );

		if ( 'product' === type ) {
			send( 'ViewContent', pageCustom() );
		} else if ( 'checkout' === type ) {
			send( 'InitiateCheckout', pageCustom() );
		} else if ( 'search' === type ) {
			send( 'Search', {} );
		}

		// AddToCart — WooCommerce triggers a jQuery 'added_to_cart' event on
		// AJAX add. Fall back to click on any .add_to_cart_button.
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'added_to_cart', function () {
				send( 'AddToCart', pageCustom() );
			} );
		}
		document.addEventListener(
			'click',
			function ( e ) {
				var el = e.target;
				while ( el && el !== document.body ) {
					if ( el.classList && el.classList.contains( 'add_to_cart_button' ) ) {
						// jQuery handler above covers the AJAX path; only fire here
						// when jQuery is unavailable to avoid double-counting.
						if ( ! window.jQuery ) {
							send( 'AddToCart', pageCustom() );
						}
						return;
					}
					el = el.parentNode;
				}
			},
			true
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
