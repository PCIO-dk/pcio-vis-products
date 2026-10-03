/**
 * Public storefront for [pcio_me_shop group=''].
 *
 * Renders a product grid (image, title, price, "See details"), a details modal
 * (title, image, description, qty, "Add to cart"), and a cart with checkout.
 * Checkout posts the cart to the REST API which returns a Stripe redirect URL.
 *
 * Also wires the subscription renewal button on /shop/renew.
 */
( function () {
	'use strict';

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'class' ) { node.className = attrs[ k ]; }
			else if ( k === 'text' ) { node.textContent = attrs[ k ]; }
			else if ( k === 'html' ) { node.innerHTML = attrs[ k ]; }
			else if ( k.indexOf( 'on' ) === 0 && typeof attrs[ k ] === 'function' ) {
				node.addEventListener( k.slice( 2 ), attrs[ k ] );
			} else if ( attrs[ k ] !== null && attrs[ k ] !== undefined ) {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c == null ) { return; }
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function Shop( root, cfg ) {
		var T = cfg.i18n || {};
		var REST = ( cfg.rest || '' ).replace( /\/$/, '' );
		var cart = []; // [{ id, title, price_cents, price, qty }]

		function api( path, method, body ) {
			return fetch( REST + path, {
				method: method || 'GET',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
				credentials: 'same-origin',
				body: body ? JSON.stringify( body ) : undefined
			} ).then( function ( r ) {
				return r.json().then( function ( d ) {
					if ( ! r.ok ) { throw new Error( ( d && d.message ) || ( T.genericErr || 'Error' ) ); }
					return d;
				} );
			} );
		}

		var grid = el( 'div', { class: 'mep-grid' } );
		var cartBtn = el( 'button', { class: 'mep-cart-btn', onclick: openCart } );
		var bar = el( 'div', { class: 'mep-shop-bar' }, [ cartBtn ] );
		root.appendChild( bar );
		root.appendChild( grid );
		updateCartButton();

		function updateCartButton() {
			var count = cart.reduce( function ( n, i ) { return n + i.qty; }, 0 );
			var label = count === 1
				? ( T.itemsOne || '%d item' ).replace( '%d', count )
				: ( T.itemsMany || '%d items' ).replace( '%d', count );
			cartBtn.textContent = '🛒 ' + ( T.cart || 'Cart' ) + ' (' + label + ')';
			cartBtn.disabled = count === 0;
		}

		function load() {
			grid.innerHTML = '<p class="mep-loading">…</p>';
			api( '/catalog?group=' + encodeURIComponent( cfg.group || '' ) ).then( function ( data ) {
				render( data.products || [] );
			} ).catch( function ( e ) {
				grid.innerHTML = '';
				grid.appendChild( el( 'p', { class: 'mep-error', text: e.message } ) );
			} );
		}

		function render( products ) {
			grid.innerHTML = '';
			if ( ! products.length ) {
				grid.appendChild( el( 'p', { class: 'mep-empty', text: T.noProducts || 'No products available.' } ) );
				return;
			}
			products.forEach( function ( p ) {
				var media = p.image_url
					? el( 'div', { class: 'mep-card-img' }, [ el( 'img', { src: p.image_url, alt: p.title, loading: 'lazy' } ) ] )
					: el( 'div', { class: 'mep-card-img mep-card-img-empty' } );

				var card = el( 'div', { class: 'mep-card' }, [
					media,
					el( 'div', { class: 'mep-card-body' }, [
						el( 'h3', { class: 'mep-card-title', text: p.title } ),
						el( 'div', { class: 'mep-card-price', text: p.price_cents > 0 ? p.price : ( T.free || 'Free' ) } ),
						el( 'button', { class: 'mep-btn mep-btn-secondary', text: T.seeDetails || 'See details', onclick: function () { openDetails( p.id ); } } )
					] )
				] );
				grid.appendChild( card );
			} );
		}

		// ── Details modal ─────────────────────────────────────────

		function openDetails( id ) {
			api( '/catalog/' + id ).then( function ( data ) {
				var p = data.product;
				var qty = el( 'input', { type: 'number', min: '1', step: '1', value: '1', class: 'mep-qty' } );

				var body = el( 'div', { class: 'mep-detail' }, [
					p.image_url ? el( 'div', { class: 'mep-detail-img' }, [ el( 'img', { src: p.image_url, alt: p.title } ) ] ) : null,
					el( 'div', { class: 'mep-detail-info' }, [
						el( 'div', { class: 'mep-detail-price', text: p.price_cents > 0 ? p.price : ( T.free || 'Free' ) } ),
						el( 'div', { class: 'mep-detail-desc', html: p.description || '' } ),
						el( 'div', { class: 'mep-detail-buy' }, [
							el( 'label', { class: 'mep-qty-label' }, [ ( T.qty || 'Qty' ) + ' ', qty ] ),
							el( 'button', {
								class: 'mep-btn mep-btn-primary', text: T.addToCart || 'Add to cart',
								onclick: function () {
									addToCart( p, Math.max( 1, parseInt( qty.value, 10 ) || 1 ) );
									close();
								}
							} )
						] )
					] )
				] );

				var overlay, close;
				var modal = el( 'div', { class: 'mep-modal' }, [
					el( 'div', { class: 'mep-modal-head' }, [
						el( 'h2', { class: 'mep-modal-title', text: p.title } ),
						el( 'button', { class: 'mep-modal-close', text: '×', onclick: function () { close(); } } )
					] ),
					el( 'div', { class: 'mep-modal-body' }, [ body ] )
				] );
				overlay = el( 'div', { class: 'mep-overlay' }, [ modal ] );
				close = function () { if ( overlay.parentNode ) { document.body.removeChild( overlay ); } };
				overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay ) { close(); } } );
				document.body.appendChild( overlay );
			} ).catch( function ( e ) { window.alert( e.message ); } );
		}

		function addToCart( p, qty ) {
			var existing = cart.filter( function ( i ) { return i.id === p.id; } )[ 0 ];
			if ( existing ) { existing.qty += qty; }
			else { cart.push( { id: p.id, title: p.title, price_cents: p.price_cents, price: p.price, qty: qty } ); }
			updateCartButton();
		}

		// ── Cart / checkout ───────────────────────────────────────

		function openCart() {
			var overlay, close;
			var listWrap = el( 'div', { class: 'mep-cart-list' } );
			var totalEl = el( 'div', { class: 'mep-cart-total' } );
			var nameInput = el( 'input', { type: 'text', class: 'mep-input', placeholder: T.name || 'Your name' } );
			var emailInput = el( 'input', { type: 'email', class: 'mep-input', placeholder: T.email || 'Your email' } );
			var err = el( 'p', { class: 'mep-error', hidden: 'hidden' } );

			function money( cents ) {
				return ( ( cents || 0 ) / 100 ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ' ' + ( cfg.currency || '' );
			}

			function paint() {
				listWrap.innerHTML = '';
				if ( ! cart.length ) {
					listWrap.appendChild( el( 'p', { class: 'mep-empty', text: T.empty || 'Your cart is empty.' } ) );
					totalEl.textContent = '';
					return;
				}
				var total = 0;
				cart.forEach( function ( item, idx ) {
					total += item.price_cents * item.qty;
					var q = el( 'input', { type: 'number', min: '1', value: String( item.qty ), class: 'mep-qty mep-qty-sm' } );
					q.addEventListener( 'input', function () {
						item.qty = Math.max( 1, parseInt( q.value, 10 ) || 1 );
						updateCartButton();
						paint();
					} );
					listWrap.appendChild( el( 'div', { class: 'mep-cart-row' }, [
						el( 'span', { class: 'mep-cart-name', text: item.title } ),
						q,
						el( 'span', { class: 'mep-cart-line', text: item.price_cents > 0 ? money( item.price_cents * item.qty ) : ( T.free || 'Free' ) } ),
						el( 'button', { class: 'mep-cart-remove', text: '×', title: T.remove || 'Remove', onclick: function () { cart.splice( idx, 1 ); updateCartButton(); paint(); } } )
					] ) );
				} );
				totalEl.textContent = ( T.total || 'Total' ) + ': ' + money( total );
			}
			paint();

			var payBtn = el( 'button', { class: 'mep-btn mep-btn-primary', text: T.checkout || 'Checkout' } );
			payBtn.addEventListener( 'click', function () {
				err.hidden = true;
				if ( ! nameInput.value.trim() ) { err.textContent = T.needName || 'Please enter your name.'; err.hidden = false; return; }
				if ( ! /.+@.+\..+/.test( emailInput.value ) ) { err.textContent = T.needEmail || 'Please enter a valid email address.'; err.hidden = false; return; }
				if ( ! cart.length ) { return; }

				payBtn.disabled = true;
				payBtn.textContent = T.processing || 'Processing…';
				api( '/checkout', 'POST', {
					nonce: cfg.nonce,
					name: nameInput.value.trim(),
					email: emailInput.value.trim(),
					items: cart.map( function ( i ) { return { product_id: i.id, qty: i.qty }; } )
				} ).then( function ( res ) {
					if ( res.redirect ) { window.location.href = res.redirect; }
				} ).catch( function ( e ) {
					err.textContent = e.message || ( T.genericErr || 'Error' );
					err.hidden = false;
					payBtn.disabled = false;
					payBtn.textContent = T.checkout || 'Checkout';
				} );
			} );

			var modal = el( 'div', { class: 'mep-modal' }, [
				el( 'div', { class: 'mep-modal-head' }, [
					el( 'h2', { class: 'mep-modal-title', text: T.cart || 'Cart' } ),
					el( 'button', { class: 'mep-modal-close', text: '×', onclick: function () { close(); } } )
				] ),
				el( 'div', { class: 'mep-modal-body' }, [
					listWrap, totalEl,
					el( 'div', { class: 'mep-checkout-fields' }, [ nameInput, emailInput ] ),
					err
				] ),
				el( 'div', { class: 'mep-modal-foot' }, [ payBtn ] )
			] );
			overlay = el( 'div', { class: 'mep-overlay' }, [ modal ] );
			close = function () { if ( overlay.parentNode ) { document.body.removeChild( overlay ); } };
			overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay ) { close(); } } );
			document.body.appendChild( overlay );
		}

		load();
	}

	// ── Renewal page button ───────────────────────────────────────

	function initRenew() {
		var cfg = window.PCIO_VIS_Products_RENEW;
		var btn = document.getElementById( 'pcio-mep-renew-btn' );
		if ( ! cfg || ! btn ) { return; }
		var T = cfg.i18n || {};
		var REST = ( cfg.rest || '' ).replace( /\/$/, '' );
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			var original = btn.textContent;
			btn.textContent = T.processing || 'Processing…';
			fetch( REST + '/renew', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
				credentials: 'same-origin',
				body: JSON.stringify( { nonce: cfg.nonce, token: cfg.token } )
			} ).then( function ( r ) { return r.json().then( function ( d ) { if ( ! r.ok ) { throw new Error( d.message || '' ); } return d; } ); } )
				.then( function ( res ) { if ( res.redirect ) { window.location.href = res.redirect; } } )
				.catch( function ( e ) { window.alert( e.message || ( T.genericErr || 'Error' ) ); btn.disabled = false; btn.textContent = original; } );
		} );
	}

	function init() {
		var roots = document.querySelectorAll( '.pcio-me-shop' );
		Array.prototype.forEach.call( roots, function ( root ) {
			if ( root.getAttribute( 'data-init' ) ) { return; }
			root.setAttribute( 'data-init', '1' );
			var cfg = window.PCIO_VIS_Products_SHOP || {};
			cfg = Object.assign( {}, cfg, { group: root.getAttribute( 'data-group' ) || cfg.group } );
			Shop( root, cfg );
		} );
		initRenew();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
