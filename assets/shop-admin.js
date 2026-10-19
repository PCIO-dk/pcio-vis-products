/* ================================================================
   PCIO Member Event — Products: shop-admin.js
   Shop management page (/vis/shop). Two tabs (Products / Subscriptions)
   rendered one at a time into #pcio-me-app, reusing the core list shell
   (table#me-table, me-head-row / me-filter-row, sortable headers) so the
   styling matches every other Vis list.
   ================================================================ */

( function () {
	'use strict';

	var CFG      = window.PCIO_VIS_Products_ADMIN || {};
	var T        = CFG.i18n || {};
	var REST     = ( CFG.rest || '' ).replace( /\/$/, '' );
	var CURRENCY = CFG.currency || '';
	var CAPS     = CFG.caps || { products: true, subscriptions: true };
	var MEMBERS_URL = CFG.membersUrl || '';

	var ICON_EDIT = '<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
	var ICON_DEL  = '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';
	var ICON_EMPTY = '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';

	var PERIOD_OPTIONS = [
		{ value: 'floating',  label: function() { return T.periodFloating  || '1 — Floating (1 year from purchase)'; } },
		{ value: 'fixed',     label: function() { return T.periodFixed     || '2 — Fixed (expires on next annual start date)'; } },
		{ value: 'fixed_cut', label: function() { return T.periodFixedCut  || '3 — Fixed with cutting date'; } },
	];

	// ── State ─────────────────────────────────────────────────────
	var activeTab = 'products';
	var rows      = [];      // current tab's rows
	var sortCol   = '';
	var sortDir   = 'asc';
	var editId    = null;
	var deleteId  = null;

	// ── Helpers ───────────────────────────────────────────────────
	function api( path, method, body ) {
		return fetch( REST + path, {
			method: method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
			return r.json().then( function ( d ) {
				if ( ! r.ok ) { throw new Error( ( d && d.message ) || 'Error' ); }
				return d;
			} );
		} );
	}

	function escHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function escAttr( s ) { return escHtml( s ); }

	function money( cents ) {
		return ( ( cents || 0 ) / 100 ).toLocaleString( undefined, {
			minimumFractionDigits: 2, maximumFractionDigits: 2
		} ) + ' ' + CURRENCY;
	}

	function yesNo( v ) { return v ? ( T.yes || 'Yes' ) : ( T.no || 'No' ); }

	// Link to the member directory pre-filtered to this subscription's member.
	function memberUrl( r ) {
		var q = encodeURIComponent( String( r.member_number || r.member_id || '' ) );
		return MEMBERS_URL + ( MEMBERS_URL.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'q=' + q;
	}

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text ).catch( function () {} );
		}
		// Fallback for non-secure (http) contexts where the Clipboard API is unavailable.
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		document.body.removeChild( ta );
		return Promise.resolve();
	}

	// ── Column definitions per tab ────────────────────────────────
	function columnsFor( tab ) {
		if ( tab === 'products' ) {
			return [
				{ key: 'id',           label: 'ID',                              sortable: true,  filter: false,
				  render: function ( r ) { return '<span class="mep-muted" style="font-family:monospace">' + escHtml( String( r.id ) ) + '</span>'; } },
				{ key: 'group_name',      label: T.group || 'Group',            sortable: true,  filter: true },
				{ key: 'product_name',    label: T.productName || 'Product name', sortable: true, filter: true },
				{ key: 'title',           label: T.title || 'Title',            sortable: true,  filter: true },
				{ key: 'image_id',        label: T.image || 'Image',            sortable: false, filter: false,
				  render: function ( r ) { return r.image_url ? '<img class="mep-thumb" src="' + escAttr( r.image_url ) + '" alt="">' : '<span class="mep-muted">&ndash;</span>'; } },
				{ key: 'price_cents',     label: T.price || 'Price',            sortable: true,  filter: false,
				  text: function ( r ) { return String( r.price_cents ); },
				  render: function ( r ) { return escHtml( money( r.price_cents ) ); } },
				{ key: 'is_subscription', label: T.isSubscription || 'Subscription', sortable: true, filter: false,
				  text: function ( r ) { return yesNo( r.is_subscription ); },
				  render: function ( r ) { return escHtml( yesNo( r.is_subscription ) ); } },
				{ key: 'is_membership',   label: T.isMembership || 'Membership', sortable: true, filter: false,
				  text: function ( r ) { return yesNo( r.is_membership ); },
				  render: function ( r ) { return escHtml( yesNo( r.is_membership ) ); } },
				{ key: 'active',          label: T.active || 'Active',          sortable: true,  filter: false,
				  text: function ( r ) { return yesNo( r.active ); },
				  render: function ( r ) { return escHtml( yesNo( r.active ) ); } }
			];
		}
		if ( tab === 'delivery-methods' ) {
			return [
				{ key: 'name',        label: T.dmName  || 'Name',        sortable: true,  filter: true },
				{ key: 'description', label: T.dmDesc  || 'Description', sortable: false, filter: false },
				{ key: 'price_cents', label: T.price   || 'Price',       sortable: true,  filter: false,
				  text:   function ( r ) { return String( r.price_cents ); },
				  render: function ( r ) { return r.price_cents > 0 ? escHtml( money( r.price_cents ) ) : '<span class="mep-muted">0</span>'; } },
				{ key: 'sort',        label: T.dmSort  || 'Sort',        sortable: true,  filter: false },
				{ key: 'active',      label: T.active  || 'Active',      sortable: true,  filter: false,
				  text:   function ( r ) { return yesNo( r.active ); },
				  render: function ( r ) { return escHtml( yesNo( r.active ) ); } }
			];
		}
		
		return [
			{ key: 'member_number', label: T.memberNo || 'Member #',       sortable: true, filter: true,
			  cellStyle: 'width:1%;white-space:nowrap',
			  text: function ( r ) { return String( r.member_number || '' ); },
			  render: function ( r ) {
			      var num = String( r.member_number || '' );
			      return num
			          ? '<span style="font-family:monospace">' + escHtml( num ) + '</span>'
			          : '<span class="mep-muted">&ndash;</span>';
			  } },
			{ key: 'product_name',   label: T.productName || 'Product name', sortable: true, filter: true,
			  render: function ( r ) {
			      var name = String( r.product_name || '' );
			      if ( ! name ) { return '<span class="mep-muted">&ndash;</span>'; }
			      // Jump to the Products tab filtered to this product (shop managers only).
			      return CAPS.products
			          ? '<a class="mep-link" href="#" data-product-filter="' + escAttr( name ) + '">' + escHtml( name ) + '</a>'
			          : escHtml( name );
			  } },
			{ key: 'license_token',  label: T.licenseKey || 'Licence key',   sortable: true, filter: true,
			  render: function ( r ) {
			      var tok = String( r.license_token || '' );
			      if ( ! tok ) { return '<span class="mep-muted">&ndash;</span>'; }
			      // Truncate in JS (not just CSS) so it stays short even if a CSS
			      // optimisation cache serves a stale stylesheet.
			      var shortTok = tok.length > 10 ? tok.slice( 0, 10 ) + '\u2026' : tok;
			      return '<code class="mep-code mep-copy" style="cursor:pointer" data-copy="' + escAttr( tok ) +
			          '" title="' + escAttr( ( T.clickToCopy || 'Click to copy' ) + ': ' + tok ) + '">' +
			          escHtml( shortTok ) + '</code>';
			  } },
			{ key: 'customer_name',  label: T.customer || 'Customer',        sortable: true, filter: true,
			  render: function ( r ) {
			      var name = String( r.customer_name || '' );
			      // Member link when attached (and viewable); otherwise mailto the buyer.
			      if ( r.member_id && MEMBERS_URL && CAPS.members ) {
			          return '<a class="mep-link" href="' + escAttr( memberUrl( r ) ) + '">' + escHtml( name || ( '#' + r.member_number ) ) + '</a>';
			      }
			      if ( r.customer_email ) {
			          return '<a class="mep-link" href="mailto:' + escAttr( r.customer_email ) + '">' + escHtml( name || r.customer_email ) + '</a>';
			      }
			      return name ? escHtml( name ) : '<span class="mep-muted">&ndash;</span>';
			  } },
			{ key: 'customer_email', label: T.email || 'Email',              sortable: true, filter: true },
			{ key: 'expire_date',    label: T.expires || 'Expires',          sortable: true, filter: true },
			{ key: 'status',         label: T.status || 'Status',            sortable: true, filter: true,
			  cellStyle: 'width:1%;white-space:nowrap;text-align:center',
			  render: function ( r ) {
			      var labels = {
			          active:    T.statusActive    || 'Active',
			          expired:   T.statusExpired   || 'Expired',
			          refunded:  T.statusRefunded  || 'Refunded',
			          cancelled: T.statusCancelled || 'Cancelled'
			      };
			      var colors = { active: '#00a32a', expired: '#b91c1c', refunded: '#b45309', cancelled: '#64748b' };
			      var st = String( r.status || '' );
			      return '<span style="font-weight:600;color:' + ( colors[ st ] || '#64748b' ) + '">' +
			          escHtml( labels[ st ] || st ) + '</span>';
			  } }
		];
	}

	function canEditTab() {
		if ( activeTab === 'subscriptions' ) { return !! CAPS.subscriptions; }
		return ( activeTab === 'products' || activeTab === 'delivery-methods' ) && !! CAPS.products;
	}

	function defaultSort( tab ) {
		if ( tab === 'delivery-methods' ) { return { col: 'sort', dir: 'asc' }; }
		return tab === 'products'
			? { col: 'product_name', dir: 'asc' }
			: { col: 'expire_date', dir: 'desc' };
	}

	// ── Shell ─────────────────────────────────────────────────────
	function buildShell() {
		var app = document.getElementById( 'pcio-me-app' );
		if ( ! app ) { return; }

		var newBtnLabel = activeTab === 'delivery-methods'
			? ( T.newMethod || '+ New method' )
			: activeTab === 'subscriptions'
			? ( T.newSubscription || '+ New subscription' )
			: ( T.newProduct || '+ New product' );
		var newBtn = canEditTab()
			? '<button class="me-btn me-btn-primary" id="mep-btn-new">' + escHtml( newBtnLabel ) + '</button>'
			: '';

		app.innerHTML =
			'<div class="me-table-card">' +
				'<div class="me-toolbar">' +
					'<span class="me-toolbar-info" id="mep-visible-count"></span>' +
					'<div class="me-toolbar-actions">' +
						'<div class="me-search-box">' +
							'<input type="search" id="mep-global-search" placeholder="' + escAttr( T.search || 'Search…' ) + '" autocomplete="off">' +
						'</div>' + newBtn +
					'</div>' +
				'</div>' +
				'<div class="me-table-wrap" style="overflow-x:auto">' +
					'<table id="me-table">' +
						'<thead>' +
							'<tr class="me-head-row" id="mep-head-row"></tr>' +
							'<tr class="me-filter-row" id="mep-filter-row"></tr>' +
						'</thead>' +
						'<tbody id="mep-tbody"></tbody>' +
					'</table>' +
				'</div>' +
				'<div class="me-empty-state me-hidden" id="mep-empty">' + ICON_EMPTY +
					'<p id="mep-empty-msg"></p>' +
				'</div>' +
			'</div>';

		document.getElementById( 'mep-global-search' ).addEventListener( 'input', renderTable );
		var nb = document.getElementById( 'mep-btn-new' );
		if ( nb ) { nb.addEventListener( 'click', function () { openModal( null ); } ); }

		buildHeadRow();
	}

	function buildHeadRow() {
		var headRow   = document.getElementById( 'mep-head-row' );
		var filterRow = document.getElementById( 'mep-filter-row' );
		var cols      = columnsFor( activeTab );

		cols.forEach( function ( col ) {
			var th = document.createElement( 'th' );
			th.dataset.col = col.key;
			if ( col.sortable ) {
				th.classList.add( 'sortable' );
				th.innerHTML = escHtml( col.label ) + '<span class="sort-icon" aria-hidden="true"></span>';
				th.addEventListener( 'click', function () { toggleSort( col.key ); } );
			} else {
				th.textContent = col.label;
			}
			if ( col.cellStyle ) { th.style.cssText = col.cellStyle; }
			headRow.appendChild( th );

			var td = document.createElement( 'td' );
			if ( col.cellStyle ) { td.style.cssText = col.cellStyle; }
			if ( col.filter ) {
				var input = document.createElement( 'input' );
				input.type = 'text';
				input.classList.add( 'me-filter-input' );
				input.dataset.col = col.key;
				input.placeholder = ( T.filterWord || 'Filter' ) + ' ' + String( col.label ).toLowerCase() + '\u2026';
				input.autocomplete = 'off';
				input.addEventListener( 'input', renderTable );
				td.appendChild( input );
			}
			filterRow.appendChild( td );
		} );

		if ( canEditTab() ) {
			// Pin the actions column to the right so CRUD stays visible while the
			// wide table scrolls. Inline styles so a stale CSS cache can't drop it.
			var actTh = document.createElement( 'th' );
			actTh.style.cssText = 'position:sticky;right:0;background:#334155;z-index:3';
			headRow.appendChild( actTh );
			var actFilterTd = document.createElement( 'td' );
			actFilterTd.style.cssText = 'position:sticky;right:0;background:#f1f5f9;z-index:2';
			filterRow.appendChild( actFilterTd );
		}

		updateSortClasses();
	}

	function updateSortClasses() {
		var ths = document.querySelectorAll( '#mep-head-row th[data-col]' );
		Array.prototype.forEach.call( ths, function ( th ) {
			th.classList.remove( 'sort-asc', 'sort-desc' );
			if ( th.dataset.col === sortCol ) {
				th.classList.add( sortDir === 'asc' ? 'sort-asc' : 'sort-desc' );
			}
		} );
	}

	function toggleSort( col ) {
		sortDir = sortCol === col ? ( sortDir === 'asc' ? 'desc' : 'asc' ) : 'asc';
		sortCol = col;
		updateSortClasses();
		renderTable();
	}

	// ── Data ──────────────────────────────────────────────────────
	function load() {
		var path = activeTab === 'products' ? '/products'
		         : activeTab === 'delivery-methods' ? '/delivery-methods'
		         : '/subscriptions';
		var key  = activeTab === 'products' ? 'products'
		         : activeTab === 'delivery-methods' ? null
		         : 'subscriptions';
		api( path ).then( function ( data ) {
			rows = key ? ( ( data && data[ key ] ) || [] ) : ( Array.isArray( data ) ? data : [] );
			renderTable();
		} ).catch( function ( e ) {
			rows = [];
			renderTable();
			var msg = document.getElementById( 'mep-empty-msg' );
			var empty = document.getElementById( 'mep-empty' );
			if ( msg && empty ) { msg.textContent = e.message; empty.classList.remove( 'me-hidden' ); }
		} );
	}

	// ── Render ────────────────────────────────────────────────────
	function colText( col, row ) {
		if ( col.text ) { return col.text( row ); }
		return String( row[ col.key ] == null ? '' : row[ col.key ] );
	}

	function renderTable() {
		var tbody = document.getElementById( 'mep-tbody' );
		var empty = document.getElementById( 'mep-empty' );
		var emptyMsg = document.getElementById( 'mep-empty-msg' );
		var vis   = document.getElementById( 'mep-visible-count' );
		if ( ! tbody ) { return; }

		var cols = columnsFor( activeTab );
		var q = ( document.getElementById( 'mep-global-search' ) || {} ).value || '';
		q = q.toLowerCase().trim();

		var colFilters = {};
		var fInputs = document.querySelectorAll( '.me-filter-input' );
		Array.prototype.forEach.call( fInputs, function ( input ) {
			var v = input.value.trim().toLowerCase();
			if ( v ) { colFilters[ input.dataset.col ] = v; }
		} );

		var filtered = rows.filter( function ( row ) {
			if ( q ) {
				var hay = cols.map( function ( c ) { return colText( c, row ); } ).join( ' ' ).toLowerCase();
				if ( hay.indexOf( q ) === -1 ) { return false; }
			}
			for ( var col in colFilters ) {
				if ( ! Object.prototype.hasOwnProperty.call( colFilters, col ) ) { continue; }
				var c = cols.filter( function ( cc ) { return cc.key === col; } )[ 0 ];
				var cell = ( c ? colText( c, row ) : String( row[ col ] || '' ) ).toLowerCase();
				if ( cell.indexOf( colFilters[ col ] ) === -1 ) { return false; }
			}
			return true;
		} );

		if ( sortCol ) {
			var sc = cols.filter( function ( c ) { return c.key === sortCol; } )[ 0 ];
			filtered = filtered.slice().sort( function ( a, b ) {
				var av = sc ? colText( sc, a ) : String( a[ sortCol ] || '' );
				var bv = sc ? colText( sc, b ) : String( b[ sortCol ] || '' );
				var cmp = av.localeCompare( bv, undefined, { numeric: true, sensitivity: 'base' } );
				return sortDir === 'asc' ? cmp : -cmp;
			} );
		}

		if ( vis ) {
			if ( rows.length === 0 ) {
				vis.textContent = '';
			} else {
				var label = activeTab === 'products'  ? ( T.products || 'Products' )
			          : activeTab === 'delivery-methods' ? ( T.deliveryMethods || 'Delivery methods' )
			          : ( T.subscriptions || 'Subscriptions' );
				vis.textContent = filtered.length < rows.length
					? ( ( T.showing || 'Showing' ) + ' ' + filtered.length + ' ' + ( T.of || 'of' ) + ' ' + rows.length )
					: rows.length + ' ' + label;
			}
		}

		if ( filtered.length === 0 ) {
			tbody.innerHTML = '';
			if ( emptyMsg ) {
				emptyMsg.textContent = rows.length === 0
					? ( activeTab === 'products'          ? ( T.noProducts || 'No products yet.' )
					  : activeTab === 'delivery-methods'  ? ( T.noDMethods || 'No delivery methods yet.' )
					  : ( T.noSubs || 'No subscriptions yet.' ) )
					: ( T.emptyFiltered || 'No matches.' );
			}
			if ( empty ) { empty.classList.remove( 'me-hidden' ); }
			return;
		}
		if ( empty ) { empty.classList.add( 'me-hidden' ); }

		tbody.innerHTML = filtered.map( function ( row ) {
			var tds = cols.map( function ( col ) {
				return '<td' + ( col.cellStyle ? ' style="' + col.cellStyle + '"' : '' ) + '>' +
					( col.render ? col.render( row ) : escHtml( colText( col, row ) ) ) + '</td>';
			} ).join( '' );

			var actions = '';
			if ( canEditTab() ) {
				actions = '<td style="position:sticky;right:0;background:#fff;z-index:1;box-shadow:-6px 0 6px -6px rgba(0,0,0,.18)"><div class="me-row-actions">' +
					'<button class="me-icon-btn edit" data-edit="' + row.id + '" title="' + escAttr( T.edit || 'Edit' ) + '">' + ICON_EDIT + '</button>' +
					'<button class="me-icon-btn delete" data-del="' + row.id + '" title="' + escAttr( T.delete || 'Delete' ) + '">' + ICON_DEL + '</button>' +
					'</div></td>';
			}
			return '<tr>' + tds + actions + '</tr>';
		} ).join( '' );

		if ( canEditTab() ) {
			Array.prototype.forEach.call( tbody.querySelectorAll( '[data-edit]' ), function ( b ) {
				b.addEventListener( 'click', function () { openModal( parseInt( b.dataset.edit, 10 ) ); } );
			} );
			Array.prototype.forEach.call( tbody.querySelectorAll( '[data-del]' ), function ( b ) {
				b.addEventListener( 'click', function () { confirmDelete( parseInt( b.dataset.del, 10 ) ); } );
			} );
		}

		// Product links jump to the Products tab pre-filtered to that product.
		Array.prototype.forEach.call( tbody.querySelectorAll( '[data-product-filter]' ), function ( a ) {
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var name = a.getAttribute( 'data-product-filter' );
				activate( 'products' );
				var s = document.getElementById( 'mep-global-search' );
				if ( s ) { s.value = name; }
			} );
		} );

		// Licence keys copy their full value to the clipboard on click.
		Array.prototype.forEach.call( tbody.querySelectorAll( '[data-copy]' ), function ( el ) {
			el.addEventListener( 'click', function () {
				copyText( el.getAttribute( 'data-copy' ) );
				el.classList.add( 'mep-copied' );
				setTimeout( function () { el.classList.remove( 'mep-copied' ); }, 900 );
			} );
		} );
	}

	// ── Delete ────────────────────────────────────────────────────
	function confirmDelete( id ) {
		if ( ! window.confirm( T.confirmDelete || 'Delete this item?' ) ) { return; }
		var path = activeTab === 'delivery-methods' ? '/delivery-methods/' + id
		         : activeTab === 'subscriptions'    ? '/subscriptions/' + id
		         : '/products/' + id;
		api( path, 'DELETE' ).then( load );
	}

	// ── Product modal ─────────────────────────────────────────────
	function openModal( id ) {
		if ( activeTab === 'delivery-methods' ) {
			openDeliveryMethodModal( id );
			return;
		}
		if ( activeTab === 'subscriptions' ) {
			openSubscriptionModal( id );
			return;
		}
		editId = id;
		var product = id ? rows.filter( function ( r ) { return r.id === id; } )[ 0 ] : null;
		var data = product || {
			group_name: '', product_name: '', title: '', description: '',
			price_cents: 0, image_id: 0, image_url: '', is_subscription: 0, is_membership: 0, subscription_period: 'floating', active: 1
		};

		var overlay = document.createElement( 'div' );
		overlay.className = 'me-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.innerHTML =
			'<div class="me-modal">' +
				'<div class="me-modal-header">' +
					'<h2 class="me-modal-title">' + escHtml( id ? ( T.editProduct || 'Edit product' ) : ( T.createProduct || 'New product' ) ) + '</h2>' +
					'<button class="me-modal-close" data-close aria-label="' + escAttr( T.cancel || 'Cancel' ) + '">&times;</button>' +
				'</div>' +
				'<div class="me-modal-body">' +
					'<form id="mep-form" novalidate>' +
						'<div class="me-form-row">' +
							grp( 'f-group', T.group || 'Group', '<input class="me-form-input" id="f-group" type="text" value="' + escAttr( data.group_name ) + '">' ) +
							grp( 'f-name', ( T.productName || 'Product name' ) + ' *', '<input class="me-form-input" id="f-name" type="text" value="' + escAttr( data.product_name ) + '">', T.nameUnique ) +
						'</div>' +
						'<div class="me-form-row full">' +
							grp( 'f-title', ( T.title || 'Title' ) + ' *', '<input class="me-form-input" id="f-title" type="text" value="' + escAttr( data.title ) + '">' ) +
						'</div>' +
						'<div class="me-form-row full">' +
							grp( 'f-desc', T.description || 'Description', '<textarea class="me-form-input" id="f-desc" rows="5">' + escHtml( data.description ) + '</textarea>' ) +
						'</div>' +
						'<div class="me-form-row">' +
							grp( 'f-price', T.price || 'Price', '<input class="me-form-input" id="f-price" type="number" step="0.01" min="0" value="' + escAttr( ( ( data.price_cents || 0 ) / 100 ).toFixed( 2 ) ) + '">', T.priceHint ) +
							grp( '', T.image || 'Image',
								'<div class="me-media-picker">' +
									'<span class="mep-img-preview" id="mep-img-preview">' + ( data.image_url ? '<img src="' + escAttr( data.image_url ) + '" alt="">' : '<span class="mep-muted">&ndash;</span>' ) + '</span>' +
									'<button type="button" class="me-btn me-btn-secondary" id="mep-img-pick">' + escHtml( T.chooseImage || 'Choose image' ) + '</button>' +
									'<button type="button" class="me-btn me-btn-secondary" id="mep-img-clear">' + escHtml( T.removeImage || 'Remove' ) + '</button>' +
								'</div>' ) +
						'</div>' +
						'<div class="me-form-row full">' +
							'<div class="me-form-group mep-checks">' +
								check( 'f-sub', T.isSubscription || 'Subscription', data.is_subscription ) +
								check( 'f-mem', T.isMembership || 'Membership', data.is_membership ) +
								check( 'f-active', T.active || 'Active', data.active ) +
							'</div>' +
						'</div>' +					'<div id="mep-period-row" class="me-form-row full">' +
						grp( '', T.subPeriod || 'Subscription period',
							PERIOD_OPTIONS.map( function ( opt ) {
								var sel = ( data.subscription_period || 'floating' ) === opt.value;
								return '<label class="mep-check" style="display:block;margin-bottom:4px">' +
									'<input type="radio" name="f-period" value="' + escAttr( opt.value ) + '"' + ( sel ? ' checked' : '' ) + '> ' +
									escHtml( opt.label() ) +
									'</label>';
							} ).join( '' )
						) +
					'</div>' +
					'<div id="mep-mem-rule-note" class="me-form-row full" style="display:none">' +
						'<p class="description" style="color:#6366f1;font-style:italic">' +
							escHtml( T.memRuleApplied || 'Membership rule applied automatically — see Vis → Membership Rules.' ) +
						'</p>' +
					'</div>' +						'<p class="me-field-error" id="mep-form-error"></p>' +
					'</form>' +
				'</div>' +
				'<div class="me-modal-footer">' +
					'<button class="me-btn me-btn-secondary" data-close>' + escHtml( T.cancel || 'Cancel' ) + '</button>' +
					'<button class="me-btn me-btn-primary" id="mep-save">' + escHtml( T.save || 'Save' ) + '</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild( overlay );

		var imgState = { id: data.image_id, url: data.image_url };
		function paintImg() {
			document.getElementById( 'mep-img-preview' ).innerHTML = imgState.url
				? '<img src="' + escAttr( imgState.url ) + '" alt="">'
				: '<span class="mep-muted">&ndash;</span>';
		}

		// ── Subscription / membership / period wiring ─────────────
		function syncSubPeriod() {
			var isMem = document.getElementById( 'f-mem' ).checked;
			var isSub = document.getElementById( 'f-sub' ).checked;
			var subCb = document.getElementById( 'f-sub' );

			// Membership always implies subscription; grey the checkbox out.
			if ( isMem ) {
				subCb.checked  = true;
				subCb.disabled = true;
			} else {
				subCb.disabled = false;
			}

			var periodRow   = document.getElementById( 'mep-period-row' );
			var memRuleNote = document.getElementById( 'mep-mem-rule-note' );
			if ( isMem ) {
				periodRow.style.display   = 'none';
				memRuleNote.style.display = '';
			} else if ( isSub ) {
				periodRow.style.display   = '';
				memRuleNote.style.display = 'none';
			} else {
				periodRow.style.display   = 'none';
				memRuleNote.style.display = 'none';
			}
		}
		document.getElementById( 'f-mem' ).addEventListener( 'change', syncSubPeriod );
		document.getElementById( 'f-sub' ).addEventListener( 'change', syncSubPeriod );
		syncSubPeriod();

		function close( saved ) {
			if ( ! PCIOVisModal.close( overlay, saved ) ) { return; }
			if ( overlay.parentNode ) { document.body.removeChild( overlay ); }
		}
		PCIOVisModal.open( overlay, close, function () { return imgState.id; } );
		document.getElementById( 'f-group' ).focus();
		Array.prototype.forEach.call( overlay.querySelectorAll( '[data-close]' ), function ( b ) { b.addEventListener( 'click', close ); } );

		document.getElementById( 'mep-img-pick' ).addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) { return; }
			var frame = window.wp.media( { multiple: false, library: { type: 'image' } } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				imgState.id = att.id;
				imgState.url = ( att.sizes && att.sizes.large ? att.sizes.large.url : att.url );
				paintImg();
			} );
			frame.open();
		} );
		document.getElementById( 'mep-img-clear' ).addEventListener( 'click', function () { imgState.id = 0; imgState.url = ''; paintImg(); } );

		document.getElementById( 'mep-save' ).addEventListener( 'click', function () {
			var err = document.getElementById( 'mep-form-error' );
			err.textContent = '';
			var payload = {
				group_name: document.getElementById( 'f-group' ).value,
				product_name: document.getElementById( 'f-name' ).value,
				title: document.getElementById( 'f-title' ).value,
				description: document.getElementById( 'f-desc' ).value,
				price_cents: Math.round( parseFloat( document.getElementById( 'f-price' ).value || '0' ) * 100 ),
				image_id: imgState.id,
				is_subscription: document.getElementById( 'f-sub' ).checked ? 1 : 0,
				is_membership: document.getElementById( 'f-mem' ).checked ? 1 : 0,
				subscription_period: ( function () {
					var r = document.querySelector( 'input[name="f-period"]:checked' );
					return r ? r.value : 'floating';
				} )(),
				active: document.getElementById( 'f-active' ).checked ? 1 : 0
			};
			var saveBtn = document.getElementById( 'mep-save' );
			saveBtn.disabled = true;
			var p = id ? api( '/products/' + id, 'PUT', payload ) : api( '/products', 'POST', payload );
			p.then( function () { close( true ); load(); } )
				.catch( function ( e ) { err.textContent = e.message || ( T.saveFailed || 'Save failed.' ); saveBtn.disabled = false; } );
		} );
	}

	// ── Subscription modal ────────────────────────────────────────
	var productCache = null;
	function ensureProducts() {
		if ( productCache ) { return Promise.resolve( productCache ); }
		return api( '/products' ).then( function ( d ) {
			productCache = ( d && d.products ) || [];
			return productCache;
		} ).catch( function () { productCache = []; return productCache; } );
	}

	function openSubscriptionModal( id ) {
		editId = id;
		var sub  = id ? rows.filter( function ( r ) { return r.id === id; } )[ 0 ] : null;
		var data = sub || {
			product_name: '', member_number: '', customer_name: '',
			customer_email: '', expire_date: '', status: 'active'
		};

		ensureProducts().then( function ( prods ) {
			var membershipFirst = prods.slice().sort( function ( a, b ) {
				return ( b.is_membership ? 1 : 0 ) - ( a.is_membership ? 1 : 0 );
			} );
			var dlist = membershipFirst.map( function ( p ) {
				var lbl = p.is_membership ? ' (' + ( T.membershipTag || 'membership' ) + ')' : '';
				return '<option value="' + escAttr( p.product_name ) + '">' + escHtml( ( p.title || p.product_name ) + lbl ) + '</option>';
			} ).join( '' );

			var statusOpts = [
				[ 'active',    T.statusActive    || 'Active' ],
				[ 'expired',   T.statusExpired   || 'Expired' ],
				[ 'cancelled', T.statusCancelled || 'Cancelled' ]
			].map( function ( o ) {
				return '<option value="' + o[0] + '"' + ( ( data.status || 'active' ) === o[0] ? ' selected' : '' ) + '>' + escHtml( o[1] ) + '</option>';
			} ).join( '' );

			var overlay = document.createElement( 'div' );
			overlay.className = 'me-overlay';
			overlay.setAttribute( 'role', 'dialog' );
			overlay.setAttribute( 'aria-modal', 'true' );
			overlay.innerHTML =
				'<div class="me-modal">' +
					'<div class="me-modal-header">' +
						'<h2 class="me-modal-title">' + escHtml( id ? ( T.editSubscription || 'Edit subscription' ) : ( T.createSubscription || 'New subscription' ) ) + '</h2>' +
						'<button class="me-modal-close" data-close aria-label="' + escAttr( T.cancel || 'Cancel' ) + '">&times;</button>' +
					'</div>' +
					'<div class="me-modal-body">' +
						'<form id="mep-sub-form" novalidate>' +
							'<div class="me-form-row full">' +
								grp( 'fs-product', T.product || 'Product',
									'<input class="me-form-input" id="fs-product" type="text" list="mep-sub-products" value="' + escAttr( data.product_name ) + '">' +
									'<datalist id="mep-sub-products">' + dlist + '</datalist>',
									T.membershipProductHint ) +
							'</div>' +
							'<div class="me-form-row">' +
								grp( 'fs-member', T.memberNumber || 'Member number',
									'<input class="me-form-input" id="fs-member" type="text" value="' + escAttr( data.member_number || '' ) + '">',
									T.memberNumberHint ) +
								grp( 'fs-status', T.status || 'Status',
									'<select class="me-form-input" id="fs-status">' + statusOpts + '</select>' ) +
							'</div>' +
							'<div class="me-form-row">' +
								grp( 'fs-name', T.customer || 'Customer',
									'<input class="me-form-input" id="fs-name" type="text" value="' + escAttr( data.customer_name || '' ) + '">' ) +
								grp( 'fs-email', T.email || 'Email',
									'<input class="me-form-input" id="fs-email" type="email" value="' + escAttr( data.customer_email || '' ) + '">' ) +
							'</div>' +
							'<div class="me-form-row">' +
								grp( 'fs-expire', T.expires || 'Expires',
									'<input class="me-form-input" id="fs-expire" type="date" value="' + escAttr( ( data.expire_date || '' ).substring( 0, 10 ) ) + '">' ) +
							'</div>' +
							'<p class="me-field-error" id="mep-sub-error"></p>' +
						'</form>' +
					'</div>' +
					'<div class="me-modal-footer">' +
						'<button class="me-btn me-btn-secondary" data-close>' + escHtml( T.cancel || 'Cancel' ) + '</button>' +
						'<button class="me-btn me-btn-primary" id="mep-sub-save">' + escHtml( T.save || 'Save' ) + '</button>' +
					'</div>' +
				'</div>';
			document.body.appendChild( overlay );

			function close( saved ) {
				if ( ! PCIOVisModal.close( overlay, saved ) ) { return; }
				if ( overlay.parentNode ) { document.body.removeChild( overlay ); }
			}
			PCIOVisModal.open( overlay, close );
			document.getElementById( 'fs-product' ).focus();
			Array.prototype.forEach.call( overlay.querySelectorAll( '[data-close]' ), function ( b ) { b.addEventListener( 'click', close ); } );

			document.getElementById( 'mep-sub-save' ).addEventListener( 'click', function () {
				var err = document.getElementById( 'mep-sub-error' );
				err.textContent = '';
				var payload = {
					product_name:   document.getElementById( 'fs-product' ).value,
					member_number:  document.getElementById( 'fs-member' ).value,
					customer_name:  document.getElementById( 'fs-name' ).value,
					customer_email: document.getElementById( 'fs-email' ).value,
					expire_date:    document.getElementById( 'fs-expire' ).value,
					status:         document.getElementById( 'fs-status' ).value
				};
				var saveBtn = document.getElementById( 'mep-sub-save' );
				saveBtn.disabled = true;
				var p = id ? api( '/subscriptions/' + id, 'PUT', payload ) : api( '/subscriptions', 'POST', payload );
				p.then( function () { close( true ); load(); } )
					.catch( function ( e ) { err.textContent = e.message || ( T.saveFailed || 'Save failed.' ); saveBtn.disabled = false; } );
			} );
		} );
	}

	function grp( id, label, control, hint ) {
		return '<div class="me-form-group">' +
			( label ? '<label class="me-form-label"' + ( id ? ' for="' + id + '"' : '' ) + '>' + escHtml( label ) + '</label>' : '' ) +
			control +
			( hint ? '<span class="me-form-hint">' + escHtml( hint ) + '</span>' : '' ) +
			'</div>';
	}

	function check( id, label, val ) {
		return '<label class="mep-check"><input type="checkbox" id="' + id + '"' + ( val ? ' checked' : '' ) + '> ' + escHtml( label ) + '</label>';
	}

	// ── Delivery method modal ─────────────────────────────────────
	function openDeliveryMethodModal( id ) {
		editId = id;
		var method = id ? rows.filter( function ( r ) { return r.id === id; } )[ 0 ] : null;
		var data   = method || { name: '', description: '', price_cents: 0, sort: 0, active: 1 };

		var overlay = document.createElement( 'div' );
		overlay.className = 'me-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.innerHTML =
			'<div class="me-modal">' +
				'<div class="me-modal-header">' +
					'<h2 class="me-modal-title">' + escHtml( id ? ( T.editMethod || 'Edit method' ) : ( T.newMethod || 'New method' ) ) + '</h2>' +
					'<button class="me-modal-close" data-close>&times;</button>' +
				'</div>' +
				'<div class="me-modal-body">' +
					'<form id="mep-form" novalidate>' +
						'<div class="me-form-row full">' +
							grp( 'f-dm-name', ( T.dmName || 'Name' ) + ' *',
								'<input class="me-form-input" id="f-dm-name" type="text" maxlength="200" placeholder="' + escAttr( T.dmNamePh || '' ) + '" value="' + escAttr( data.name ) + '">' ) +
						'</div>' +
						'<div class="me-form-row full">' +
							grp( 'f-dm-desc', T.dmDesc || 'Description',
								'<input class="me-form-input" id="f-dm-desc" type="text" maxlength="500" placeholder="' + escAttr( T.dmDescPh || '' ) + '" value="' + escAttr( data.description ) + '">' ) +
						'</div>' +
						'<div class="me-form-row">' +
							grp( 'f-dm-price', T.price || 'Extra price',
								'<input class="me-form-input" id="f-dm-price" type="number" step="0.01" min="0" value="' + escAttr( ( ( data.price_cents || 0 ) / 100 ).toFixed( 2 ) ) + '">',
								T.priceHint ) +
							grp( 'f-dm-sort', T.dmSort || 'Sort',
								'<input class="me-form-input" id="f-dm-sort" type="number" step="1" min="0" value="' + escAttr( String( data.sort || 0 ) ) + '">' ) +
						'</div>' +
						'<div class="me-form-row full"><div class="me-form-group mep-checks">' +
							check( 'f-dm-active', T.active || 'Active', data.active ) +
						'</div></div>' +
						'<p class="me-field-error" id="mep-form-error"></p>' +
					'</form>' +
				'</div>' +
				'<div class="me-modal-footer">' +
					'<button class="me-btn me-btn-secondary" data-close>' + escHtml( T.cancel || 'Cancel' ) + '</button>' +
					'<button class="me-btn me-btn-primary" id="mep-save">' + escHtml( T.save || 'Save' ) + '</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild( overlay );

		function close( saved ) {
			if ( ! PCIOVisModal.close( overlay, saved ) ) { return; }
			if ( overlay.parentNode ) { document.body.removeChild( overlay ); }
		}
		PCIOVisModal.open( overlay, close );
		document.getElementById( 'f-dm-name' ).focus();
		Array.prototype.forEach.call( overlay.querySelectorAll( '[data-close]' ), function ( b ) {
			b.addEventListener( 'click', close );
		} );

		document.getElementById( 'mep-save' ).addEventListener( 'click', function () {
			var err  = document.getElementById( 'mep-form-error' );
			err.textContent = '';
			var name = document.getElementById( 'f-dm-name' ).value.trim();
			if ( ! name ) { document.getElementById( 'f-dm-name' ).focus(); return; }
			var payload = {
				name:        name,
				description: document.getElementById( 'f-dm-desc' ).value.trim(),
				price_cents: Math.round( parseFloat( document.getElementById( 'f-dm-price' ).value || '0' ) * 100 ),
				sort:        parseInt( document.getElementById( 'f-dm-sort' ).value || '0', 10 ),
				active:      document.getElementById( 'f-dm-active' ).checked ? 1 : 0,
			};
			var saveBtn = document.getElementById( 'mep-save' );
			saveBtn.disabled = true;
			var p = id
				? api( '/delivery-methods/' + id, 'PUT',  payload )
				: api( '/delivery-methods',        'POST', payload );
			p.then( function () { close( true ); load(); } )
				.catch( function ( e ) { err.textContent = e.message || ( T.saveFailed || 'Save failed.' ); saveBtn.disabled = false; } );
		} );
	}

	// ── Tabs ──────────────────────────────────────────────────────
	function activate( tab ) {
		activeTab = tab;
		if ( window.location.hash !== '#' + tab ) window.history.replaceState( null, '', '#' + tab );
		var ds = defaultSort( tab );
		sortCol = ds.col;
		sortDir = ds.dir;
		rows = [];

		var tabs = document.querySelectorAll( '.mep-tab' );
		Array.prototype.forEach.call( tabs, function ( t ) {
			t.classList.toggle( 'is-active', t.getAttribute( 'data-tab' ) === tab );
		} );

		buildShell();
		load();
	}

	function init() {
		var tabs = document.querySelectorAll( '.mep-tab' );
		Array.prototype.forEach.call( tabs, function ( t ) {
			t.addEventListener( 'click', function () { activate( t.getAttribute( 'data-tab' ) ); } );
		} );
		// Land on the first tab the user actually has (finance-only users have
		// no Products tab, so this becomes Subscriptions).
		var selectHashTab = function () {
			var requested = window.location.hash.slice( 1 );
			var target = Array.prototype.find.call( tabs, function ( tab ) { return tab.getAttribute( 'data-tab' ) === requested; } );
			activate( target ? requested : ( tabs.length ? tabs[0].getAttribute( 'data-tab' ) : 'subscriptions' ) );
		};
		selectHashTab();
		window.addEventListener( 'hashchange', selectHashTab );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
