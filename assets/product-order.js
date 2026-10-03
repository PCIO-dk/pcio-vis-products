/* ================================================================
   PCIO VIS Products — product-order.js
   Single-product order shortcode [pcio_me_product_order].
   Renders product card + delivery method picker + order form.
   ================================================================ */

( function () {
	'use strict';

	document.querySelectorAll( '.pcio-mep-product-order' ).forEach( el => {
		initProductOrder( el );
	} );

	function initProductOrder( root ) {
		const CFG      = window.PCIO_VIS_Products_ORDER || {};
		const T        = CFG.i18n     || {};
		const REST     = ( CFG.rest   || '' ).replace( /\/$/, '' );
		const NONCE    = CFG.checkoutNonce || '';
		const CURRENCY = CFG.currency || 'DKK';
		const PRODUCT  = CFG.product  || null;
		const MEMBER   = CFG.member   || {};

		if ( ! PRODUCT ) { root.innerHTML = '<p>' + esc( T.unavailable || 'Product not available.' ) + '</p>'; return; }

		let selectedMethodId    = 0;
		let selectedMethodPrice = 0;
		const MAX_QTY           = Math.max( 1, parseInt( CFG.maxQty || '10', 10 ) );
		let currentQty          = 1;

		// ── Load delivery methods then render ─────────────────────
		fetch( REST + '/delivery-methods', {
			headers: { 'X-WP-Nonce': CFG.restNonce || '' },
		} )
			.then( r => r.json() )
			.then( methods => {
				if ( ! Array.isArray( methods ) ) { methods = []; }
				render( methods );
			} )
			.catch( () => render( [] ) );

		function render( methods ) {
			const hasImage  = !! PRODUCT.image_url;
			const basePrice = PRODUCT.price_cents;

			function updateTotal() {
				const qtyEl = document.getElementById( 'mep-po-qty' );
				if ( qtyEl ) {
					currentQty  = Math.max( 1, Math.min( MAX_QTY, parseInt( qtyEl.value || '1', 10 ) ) );
					qtyEl.value = currentQty;
				}
				const total = document.getElementById( 'mep-po-total' );
				if ( total ) {
					total.textContent = formatMoney( basePrice * currentQty + selectedMethodPrice, CURRENCY );
				}
			}

			root.innerHTML =
				'<div class="mep-po-card">' +
					( hasImage
						? `<img class="mep-po-img" src="${ escAttr( PRODUCT.image_url ) }" alt="${ escAttr( PRODUCT.title ) }">`
						: '' ) +
					'<div class="mep-po-body">' +
						`<h2 class="mep-po-title">${ esc( PRODUCT.title ) }</h2>` +
						( PRODUCT.description
							? `<div class="mep-po-desc">${ PRODUCT.description }</div>`
							: '' ) +
						`<div class="mep-po-price" id="mep-po-total">${ formatMoney( basePrice, CURRENCY ) }</div>` +

						( methods.length
							? '<fieldset class="mep-po-methods">' +
								`<legend class="mep-po-methods-legend">${ esc( T.deliveryMethod ) }</legend>` +
								methods.map( m => {
									const extra = m.price_cents > 0
										? ' (+' + formatMoney( m.price_cents, CURRENCY ) + ')'
										: '';
									return `<label class="mep-po-method-label">
										<input type="radio" name="delivery_method" value="${ m.id }" data-price="${ m.price_cents }">
										<span class="mep-po-method-name">${ esc( m.name ) }${ extra }</span>
										${ m.description ? '<span class="mep-po-method-desc">' + esc( m.description ) + '</span>' : '' }
									</label>`;
								} ).join( '' ) +
							'</fieldset>'
							: '' ) +

						'<form class="mep-po-form" id="mep-po-form" novalidate>' +
							'<div class="mep-po-row">' +
								`<label class="mep-po-label" for="mep-po-name">${ esc( T.yourName ) } <span class="req">*</span></label>` +
								`<input class="mep-po-input" type="text" id="mep-po-name" name="name" required maxlength="200" value="${ escAttr( MEMBER.name || '' ) }">` +
							'</div>' +
							'<div class="mep-po-row">' +
								`<label class="mep-po-label" for="mep-po-email">${ esc( T.yourEmail ) } <span class="req">*</span></label>` +
								`<input class="mep-po-input" type="email" id="mep-po-email" name="email" required maxlength="190" value="${ escAttr( MEMBER.email || '' ) }">` +
							'</div>' +
							'<div class="mep-po-row">' +
								`<label class="mep-po-label" for="mep-po-qty">${ esc( T.qty || 'Quantity' ) } <span class="req">*</span></label>` +
								`<input class="mep-po-input" type="number" id="mep-po-qty" name="qty" min="1" max="${ MAX_QTY }" value="1" style="width:80px">` +
							'</div>' +
							'<p class="mep-po-error me-hidden" id="mep-po-error"></p>' +
							`<button type="submit" class="mep-po-submit" id="mep-po-submit">${ esc( T.order ) }</button>` +
						'</form>' +
					'</div>' +
				'</div>';

			// Delivery method selection — update total
			root.querySelectorAll( 'input[name="delivery_method"]' ).forEach( radio => {
				radio.addEventListener( 'change', () => {
					selectedMethodId    = parseInt( radio.value, 10 );
					selectedMethodPrice = parseInt( radio.dataset.price || '0', 10 );
					updateTotal();
				} );
			} );

			// Auto-select first method
			const first = root.querySelector( 'input[name="delivery_method"]' );
			if ( first ) { first.checked = true; first.dispatchEvent( new Event( 'change' ) ); }
			else { updateTotal(); }

			document.getElementById( 'mep-po-qty' )?.addEventListener( 'input', updateTotal );

			// Form submit
			document.getElementById( 'mep-po-form' )?.addEventListener( 'submit', async e => {
				e.preventDefault();
				const name  = document.getElementById( 'mep-po-name' )?.value.trim() || '';
				const email = document.getElementById( 'mep-po-email' )?.value.trim() || '';
				const errEl = document.getElementById( 'mep-po-error' );

				if ( ! name ) {
					showError( errEl, T.needName || '' );
					document.getElementById( 'mep-po-name' )?.focus();
					return;
				}
				if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
					showError( errEl, T.needEmail || '' );
					document.getElementById( 'mep-po-email' )?.focus();
					return;
				}
				if ( methods.length && ! selectedMethodId ) {
					showError( errEl, T.needMethod || '' );
					return;
				}

				hideError( errEl );
				const btn = document.getElementById( 'mep-po-submit' );
				if ( btn ) { btn.disabled = true; btn.textContent = T.processing || '…'; }

				try {
					const qty     = currentQty;
					const payload = {
						nonce:    NONCE,
						name,
						email,
						items:    [ { product_id: PRODUCT.id, qty } ],
					};
					if ( selectedMethodId ) {
						payload.delivery_method_id = selectedMethodId;
					}

					const res = await fetch( REST + '/checkout', {
						method:  'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce':   CFG.restNonce || '',
						},
						body: JSON.stringify( payload ),
					} );
					const data = await res.json().catch( () => ( {} ) );
					if ( ! res.ok ) {
						throw new Error( data.message || 'HTTP ' + res.status );
					}
					window.location.href = data.redirect;
				} catch ( err ) {
					showError( errEl, err.message );
					if ( btn ) { btn.disabled = false; btn.textContent = T.order || ''; }
				}
			} );
		}

		function formatMoney( cents, currency ) {
			return ( cents / 100 ).toFixed( 2 ).replace( '.', ',' ) + '\u00a0' + currency;
		}
		function showError( el, msg ) { if ( el ) { el.textContent = msg; el.classList.remove( 'me-hidden' ); } }
		function hideError( el )      { if ( el ) { el.classList.add( 'me-hidden' ); el.textContent = ''; } }
		function esc( s )     { return String( s ?? '' ).replace( /[&<>"']/g, c => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ] ); }
		function escAttr( s ) { return esc( s ); }
	}
}() );
