/* ================================================================
   PCIO VIS Products — delivery-admin.js
   Delivery orders page (/vis/delivery) — status tracking only.
   Delivery methods are managed in /vis/shop -> Delivery Methods tab.
   ================================================================ */

( function () {
'use strict';

const CFG      = window.PCIO_VIS_Products_DELIVERY || {};
const T        = CFG.i18n     || {};
const REST     = ( CFG.rest   || '' ).replace( /\/$/, '' );
const NONCE    = CFG.nonce    || '';
const CURRENCY = CFG.currency || 'DKK';

// ── State ─────────────────────────────────────────────────────
let allOrders      = [];
let toastTimer     = null;
let filterStatus   = '';
let filterMethodId = 0;

// ── Bootstrap ─────────────────────────────────────────────────
document.addEventListener( 'DOMContentLoaded', () => {
buildShell();
loadOrders();
} );

// ── Shell ─────────────────────────────────────────────────────

function buildShell() {
const app = document.getElementById( 'pcio-me-app' );
if ( ! app ) { return; }

app.innerHTML =
'<div class="me-table-card">' +
'<div class="me-toolbar">' +
'<div class="me-toolbar-actions" style="gap:.5rem">' +
'<select id="me-order-filter-status" class="me-form-input" style="min-width:140px">' +
'<option value="">'          + escHtml( T.filterAll )       + '</option>' +
'<option value="pending">'   + escHtml( T.statusPending )   + '</option>' +
'<option value="delivered">' + escHtml( T.statusDelivered ) + '</option>' +
'</select>' +
'<select id="me-order-filter-method" class="me-form-input" style="min-width:160px">' +
'<option value="0">' + escHtml( T.filterMethod ) + '</option>' +
'</select>' +
'<div class="me-search-box">' +
'<input type="search" id="me-order-search" placeholder="' + escAttr( T.search ) + '" autocomplete="off">' +
'</div>' +
'</div>' +
'</div>' +
'<div class="me-table-wrap">' +
'<table id="me-orders-table"><thead><tr>' +
'<th>' + escHtml( T.colOrder )    + '</th>' +
'<th>' + escHtml( T.colCustomer ) + '</th>' +
'<th>' + escHtml( T.colProduct )  + '</th>' +
'<th>' + escHtml( T.colMethod )   + '</th>' +
'<th>' + escHtml( T.colDate )     + '</th>' +
'<th>' + escHtml( T.colStatus )   + '</th>' +
'<th></th>' +
'</tr></thead>' +
'<tbody id="me-orders-tbody"></tbody>' +
'</table>' +
'</div>' +
'<div class="me-empty-state me-hidden" id="me-orders-empty">' +
'<p id="me-orders-empty-msg">' + escHtml( T.noOrders ) + '</p>' +
'</div>' +
'</div>' +

'<div class="me-toast me-hidden" id="me-delivery-toast" role="status" aria-live="polite"></div>';

document.getElementById( 'me-order-filter-status' )?.addEventListener( 'change', e => {
filterStatus = e.target.value;
renderOrders();
} );
document.getElementById( 'me-order-filter-method' )?.addEventListener( 'change', e => {
filterMethodId = parseInt( e.target.value || '0', 10 );
renderOrders();
} );
document.getElementById( 'me-order-search' )?.addEventListener( 'input', renderOrders );

document.addEventListener( 'click', () => {
	document.querySelectorAll( '.mep-addr-popup' ).forEach( p => p.classList.add( 'me-hidden' ) );
} );
}

// ── Data ──────────────────────────────────────────────────────

async function loadOrders() {
try {
const data = await apiFetch( REST + '/delivery-orders' );
allOrders = Array.isArray( data ) ? data : [];
populateMethodFilter();
renderOrders();
} catch ( err ) {
const msg = document.getElementById( 'me-orders-empty-msg' );
const el  = document.getElementById( 'me-orders-empty' );
if ( msg ) { msg.textContent = err.message; }
el?.classList.remove( 'me-hidden' );
}
}

function populateMethodFilter() {
const sel = document.getElementById( 'me-order-filter-method' );
if ( ! sel ) { return; }
const seen = new Map();
allOrders.forEach( o => {
if ( o.delivery_method_id && ! seen.has( o.delivery_method_id ) ) {
seen.set( o.delivery_method_id, o.delivery_method_name );
}
} );
seen.forEach( ( name, id ) => {
const opt = document.createElement( 'option' );
opt.value       = String( id );
opt.textContent = name;
sel.appendChild( opt );
} );
}

// ── Render ────────────────────────────────────────────────────

function renderOrders() {
const tbody = document.getElementById( 'me-orders-tbody' );
const empty = document.getElementById( 'me-orders-empty' );
if ( ! tbody ) { return; }

const q = ( document.getElementById( 'me-order-search' )?.value || '' ).toLowerCase().trim();

const rows = allOrders.filter( o => {
if ( filterStatus   && o.delivery_status    !== filterStatus    ) { return false; }
if ( filterMethodId && o.delivery_method_id !== filterMethodId  ) { return false; }
if ( q ) {
const hay = ( o.order_no + ' ' + o.customer_name + ' ' + o.email + ' ' + o.summary + ' ' + o.delivery_method_name ).toLowerCase();
if ( ! hay.includes( q ) ) { return false; }
}
return true;
} );

if ( ! rows.length ) {
tbody.innerHTML = '';
empty?.classList.remove( 'me-hidden' );
return;
}
empty?.classList.add( 'me-hidden' );

tbody.innerHTML = rows.map( o => {
const isDelivered = o.delivery_status === 'delivered';
const badge = isDelivered
? '<span class="mep-badge mep-badge-delivered">' + escHtml( T.statusDelivered ) + '</span>'
: '<span class="mep-badge mep-badge-pending">'   + escHtml( T.statusPending )   + '</span>';
const btnLabel = isDelivered ? T.markPending : T.markDelivered;
const btnClass = isDelivered ? 'me-btn-secondary' : 'me-btn-primary';
return '<tr>' +
'<td><code>' + escHtml( o.order_no ) + '</code></td>' +
buildAddressCell( o ) +
'<td>' + escHtml( o.summary ) + '</td>' +
'<td>' + escHtml( o.delivery_method_name ) + '</td>' +
'<td>' + escHtml( o.created.substring( 0, 10 ) ) + '</td>' +
'<td>' + badge + '</td>' +
'<td><button class="me-btn ' + btnClass + ' me-btn-sm mep-toggle-delivery"' +
    ' data-id="' + o.id + '" data-status="' + ( isDelivered ? 'pending' : 'delivered' ) + '">' +
escHtml( btnLabel ) +
'</button></td>' +
'</tr>';
} ).join( '' );

tbody.querySelectorAll( '.mep-toggle-delivery' ).forEach( btn => {
btn.addEventListener( 'click', () => toggleDelivery( +btn.dataset.id, btn.dataset.status ) );
} );

tbody.querySelectorAll( '.mep-cust-addr-btn' ).forEach( btn => {
btn.addEventListener( 'click', e => {
	e.stopPropagation();
	const popup = document.getElementById( 'mep-addr-' + btn.dataset.id );
	if ( ! popup ) { return; }
	const wasHidden = popup.classList.contains( 'me-hidden' );
	document.querySelectorAll( '.mep-addr-popup' ).forEach( p => p.classList.add( 'me-hidden' ) );
	if ( wasHidden ) { popup.classList.remove( 'me-hidden' ); }
} );
} );
}

async function toggleDelivery( orderId, newStatus ) {
try {
const updated = await apiFetch( REST + '/delivery-orders/' + orderId, 'PATCH', { delivery_status: newStatus } );
const idx = allOrders.findIndex( o => o.id === orderId );
if ( idx >= 0 ) { allOrders[ idx ] = updated; }
renderOrders();
} catch ( err ) {
toast( err.message, 'error' );
}
}

// ── Utilities ─────────────────────────────────────────────────

async function apiFetch( url, method = 'GET', body = null ) {
const opts = { method, headers: { 'X-WP-Nonce': NONCE } };
if ( body ) { opts.headers[ 'Content-Type' ] = 'application/json'; opts.body = JSON.stringify( body ); }
const res  = await fetch( url, opts );
const json = await res.json().catch( () => ( {} ) );
if ( ! res.ok ) { throw new Error( json.message || 'HTTP ' + res.status ); }
return json;
}

function escHtml( s ) {
return String( s === null || s === undefined ? '' : s ).replace( /[&<>"']/g,
function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } );
}
function escAttr( s ) { return escHtml( s ); }

function toast( msg, type ) {
const el = document.getElementById( 'me-delivery-toast' );
if ( ! el ) { return; }
el.textContent = msg;
el.className   = 'me-toast ' + ( type || 'info' );
clearTimeout( toastTimer );
toastTimer = setTimeout( () => el.classList.add( 'me-hidden' ), 3500 );
}

// ── Address popup ─────────────────────────────────────────────

function buildAddressCell( o ) {
const hasContact = !! ( o.member_address || o.member_phone );
let cell = '<td class="mep-cust-cell"><span class="mep-cust-name">'
	+ escHtml( o.customer_name || o.email ) + '</span>';
if ( hasContact ) {
	cell += ' <button class="mep-cust-addr-btn" type="button" data-id="' + o.id
		+ '" aria-label="' + escAttr( T.colAddress || 'Address' ) + '">\u24d8</button>'
		+ '<div class="mep-addr-popup me-hidden" id="mep-addr-' + o.id + '">'
		+ '<div class="mep-addr-email">' + escHtml( o.email ) + '</div>';
	if ( o.member_phone ) {
		cell += '<div class="mep-addr-phone">' + escHtml( o.member_phone ) + '</div>';
	}
	if ( o.member_address ) {
		cell += '<div class="mep-addr-text">'
			+ escHtml( o.member_address ).replace( /\n/g, '<br>' )
			+ '</div>';
	}
	cell += '</div>';
}
cell += '</td>';
return cell;
}
}() );