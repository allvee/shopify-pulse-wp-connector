/**
 * Checkout guard modal. When the classic checkout is blocked by the plugin's
 * fraud / courier screen, WooCommerce shows a plain notice — this replaces it
 * with a modern, clear popup that explains why and offers the store's contact
 * (call / WhatsApp) so a genuine shopper can still complete the purchase.
 *
 * On the `checkout_error` event we ask the server whether the failure was one
 * of OUR blocks (stashed in the WC session during validation); only then do we
 * show the modal. Everything degrades gracefully — the underlying notice still
 * renders if JS is off.
 *
 * @package ShopifyPulse
 */
( function ( $ ) {
	'use strict';
	if ( ! window.SPGuard || ! window.SPGuard.ajaxUrl ) {
		return;
	}
	var G = window.SPGuard;
	var styled = false;

	function esc( s ) {
		return $( '<div>' ).text( s == null ? '' : String( s ) ).html();
	}

	function injectStyle() {
		if ( styled ) {
			return;
		}
		styled = true;
		var css =
			'.spg-bg{position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px}' +
			'.spg{position:relative;background:#fff;border-radius:16px;max-width:420px;width:100%;padding:26px 24px 22px;box-shadow:0 24px 70px rgba(0,0,0,.35);text-align:center;font-family:inherit}' +
			'.spg-x{position:absolute;top:10px;right:14px;border:0;background:none;font-size:24px;line-height:1;color:#94a3b8;cursor:pointer}' +
			'.spg-ic{width:56px;height:56px;margin:2px auto 12px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;color:#dc2626}' +
			'.spg-ic svg{width:30px;height:30px}' +
			'.spg-msg{margin:0 0 10px;font-size:16px;line-height:1.55;color:#0f172a;font-weight:600}' +
			'.spg p{margin:0 0 10px;font-size:14px;line-height:1.5;color:#475569}' +
			'.spg-help{margin-top:14px;font-weight:600;color:#0f172a}' +
			'.spg-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:6px}' +
			'.spg-btn{display:inline-flex;align-items:center;gap:7px;padding:11px 16px;border-radius:10px;font-weight:600;font-size:14px;text-decoration:none;min-height:44px;box-sizing:border-box}' +
			'.spg-call{background:#eff6ff;color:#1d4ed8}' +
			'.spg-wa{background:#22c55e;color:#fff}' +
			'.spg-btn:focus-visible{outline:2px solid #1d4ed8;outline-offset:2px}' +
			'@media(prefers-reduced-motion:no-preference){.spg{animation:spgIn .18s ease-out}@keyframes spgIn{from{transform:translateY(8px) scale(.98);opacity:0}to{transform:none;opacity:1}}}';
		$( '<style id="spg-style">' ).text( css ).appendTo( 'head' );
	}

	var ALERT_SVG =
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
		'<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

	function showModal( message ) {
		injectStyle();
		$( '.spg-bg' ).remove();
		var telDigits = ( G.phone || '' ).replace( /[^0-9+]/g, '' );
		var tel = telDigits ? 'tel:' + telDigits : '';
		var wa = G.whatsapp ? 'https://wa.me/' + G.whatsapp : '';
		var buttons = '';
		if ( tel ) {
			buttons += '<a class="spg-btn spg-call" href="' + tel + '">' + esc( G.i18n.callBtn ) + ' ' + esc( G.phone ) + '</a>';
		}
		if ( wa ) {
			buttons += '<a class="spg-btn spg-wa" href="' + wa + '" target="_blank" rel="noopener">' + esc( G.i18n.waBtn ) + '</a>';
		}
		var contactBlock = buttons ? '<p class="spg-help">' + esc( G.i18n.help ) + '</p><div class="spg-btns">' + buttons + '</div>' : '';
		var $m = $(
			'<div class="spg-bg"><div class="spg" role="dialog" aria-modal="true" aria-label="' + esc( G.i18n.title ) + '">' +
			'<button class="spg-x" aria-label="' + esc( G.i18n.close ) + '">×</button>' +
			'<div class="spg-ic">' + ALERT_SVG + '</div>' +
			'<p class="spg-msg">' + esc( message ) + '</p>' +
			contactBlock +
			'</div></div>'
		);
		$( 'body' ).append( $m );
		function close() { $m.remove(); $( document ).off( 'keydown.spg' ); }
		$m.on( 'click', function ( e ) { if ( e.target === $m[0] ) { close(); } } );
		$m.find( '.spg-x' ).on( 'click', close ).trigger( 'focus' );
		$( document ).on( 'keydown.spg', function ( e ) { if ( e.key === 'Escape' ) { close(); } } );
	}

	// WooCommerce fires this on the body after a failed AJAX checkout.
	$( document.body ).on( 'checkout_error', function () {
		$.post( G.ajaxUrl, { action: 'shopify_pulse_guard', nonce: G.nonce } ).done( function ( res ) {
			if ( res && res.success && res.data && res.data.message ) {
				showModal( res.data.message );
			}
		} );
	} );
} )( jQuery );
