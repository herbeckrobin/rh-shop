/**
 * Frontend-Logik des Shops, buildless Vanilla-JS.
 * - Buy-Box: Varianten-Auswahl, Preis-Update, In-den-Warenkorb.
 * - Warenkorb: Menge ändern, entfernen, aus dem REST-Zustand neu rendern.
 * Preise und Verfügbarkeit kommen immer vom Server, nie aus dem DOM.
 */
( function () {
	var cfg = window.rhShopConfig;
	if ( ! cfg || ! cfg.restUrl ) {
		return;
	}

	var LABELS = {
		add: 'In den Warenkorb',
		added: 'Im Warenkorb ✓',
		soldOut: 'Ausverkauft',
		lowStock: 'Nur noch {n} verfügbar',
		chooseOptions: 'Bitte Auswahl treffen',
		error: 'Etwas ist schiefgelaufen. Bitte nochmal versuchen.',
	};

	function post( path, body ) {
		return fetch( cfg.restUrl + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body || {} ),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function esc( value ) {
		var d = document.createElement( 'div' );
		d.textContent = value == null ? '' : String( value );
		return d.innerHTML;
	}

	function updateCartCount( state ) {
		document.querySelectorAll( '[data-rhshop-cart-count]' ).forEach( function ( el ) {
			el.textContent = state.count;
			el.hidden = state.count === 0;
		} );
	}

	function emitUpdated( state ) {
		document.dispatchEvent( new CustomEvent( 'rhshop:cart-updated', { detail: state } ) );
	}

	// --- Buy-Box (Einzelprodukt / Produktseite) ---
	function initBuyBox( box ) {
		var variants = [];
		try {
			variants = JSON.parse( box.getAttribute( 'data-rhshop-variants' ) || '[]' );
		} catch ( e ) {
			variants = [];
		}

		var hasVariants = box.getAttribute( 'data-rhshop-has-variants' ) === '1';
		var productId = parseInt( box.getAttribute( 'data-rhshop-product' ), 10 );
		var priceEl = box.querySelector( '[data-rhshop-price]' );
		var gpEl = box.querySelector( '[data-rhshop-grundpreis]' );
		var addBtn = box.querySelector( '[data-rhshop-add]' );
		var msgEl = box.querySelector( '[data-rhshop-msg]' );
		var qtyInput = box.querySelector( '[data-rhshop-qty-input]' );
		var stockEl = box.querySelector( '[data-rhshop-stock]' );
		var selects = box.querySelectorAll( '[data-rhshop-opt]' );
		var lowStock = parseInt( box.getAttribute( 'data-rhshop-low-stock' ), 10 ) || 0;
		var selected = null;

		// Bestandshinweis für eine Variante. Leer bei unbegrenztem Bestand (null),
		// ausverkauft (0, das zeigt der Button) oder über der Schwelle. Spiegelt
		// Render::lowStockText() in PHP.
		function stockText( stock ) {
			if ( stock === null || typeof stock === 'undefined' || stock <= 0 || lowStock <= 0 || stock > lowStock ) {
				return '';
			}
			return LABELS.lowStock.replace( '{n}', stock );
		}

		function axisPresent( axis ) {
			return variants.some( function ( v ) {
				return axis === 1 ? v.o1 !== '' : v.o2 !== '';
			} );
		}

		function selection() {
			var out = { o1: '', o2: '' };
			selects.forEach( function ( s ) {
				if ( s.getAttribute( 'data-rhshop-opt' ) === '1' ) {
					out.o1 = s.value;
				}
				if ( s.getAttribute( 'data-rhshop-opt' ) === '2' ) {
					out.o2 = s.value;
				}
			} );
			return out;
		}

		function resolve() {
			if ( ! hasVariants ) {
				return variants[ 0 ] || null;
			}
			var sel = selection();
			var need1 = axisPresent( 1 );
			var need2 = axisPresent( 2 );
			if ( ( need1 && ! sel.o1 ) || ( need2 && ! sel.o2 ) ) {
				return null;
			}
			return variants.find( function ( v ) {
				return ( ! need1 || v.o1 === sel.o1 ) && ( ! need2 || v.o2 === sel.o2 );
			} ) || null;
		}

		function refresh() {
			selected = resolve();
			if ( selected ) {
				if ( priceEl ) {
					priceEl.textContent = selected.price;
				}
				if ( gpEl ) {
					gpEl.textContent = selected.gp || '';
				}
				addBtn.disabled = ! selected.available;
				addBtn.textContent = selected.available ? LABELS.add : LABELS.soldOut;
				msgEl.textContent = '';
				if ( stockEl ) {
					stockEl.textContent = stockText( selected.stock );
				}
			} else {
				addBtn.disabled = true;
				addBtn.textContent = LABELS.add;
				msgEl.textContent = '';
				if ( stockEl ) {
					stockEl.textContent = '';
				}
			}
		}

		selects.forEach( function ( s ) {
			s.addEventListener( 'change', refresh );
		} );

		box.querySelectorAll( '[data-rhshop-qty]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var step = b.getAttribute( 'data-rhshop-qty' ) === '+' ? 1 : -1;
				qtyInput.value = Math.max( 1, ( parseInt( qtyInput.value, 10 ) || 1 ) + step );
			} );
		} );

		addBtn.addEventListener( 'click', function () {
			if ( ! selected || ! selected.available ) {
				return;
			}
			var qty = Math.max( 1, parseInt( qtyInput.value, 10 ) || 1 );
			addBtn.disabled = true;
			post( 'cart/add', { product_id: productId, variant_id: selected.id, qty: qty } )
				.then( function ( state ) {
					updateCartCount( state );
					emitUpdated( state );
					msgEl.textContent = LABELS.added;
					addBtn.disabled = false;
				} )
				.catch( function () {
					addBtn.disabled = false;
					msgEl.textContent = LABELS.error;
				} );
		} );

		refresh();
	}

	// --- Warenkorb ---
	// Seiten-weit statt pro Root: Positionen (data-rhshop-cart-lines) und Summe
	// (data-rhshop-cart-total) dürfen in getrennten Blöcken liegen. Die Aktualisierung
	// findet sie über die data-Attribute, egal in welchem Block sie stehen.
	function initCart() {
		var linesEl = document.querySelector( '[data-rhshop-cart-lines]' );
		var emptyEl = document.querySelector( '[data-rhshop-cart-empty]' );
		var footEl = document.querySelector( '[data-rhshop-cart-foot]' );
		var totalEl = document.querySelector( '[data-rhshop-cart-total]' );

		if ( ! linesEl ) {
			return;
		}

		function lineHtml( l ) {
			var media = l.thumbnail
				? '<img src="' + esc( l.thumbnail ) + '" alt="" loading="lazy" />'
				: '<span class="rhshop-card__ph" aria-hidden="true"></span>';
			return '<li class="rhshop-cart__line" data-p="' + parseInt( l.product_id, 10 ) + '" data-v="' + esc( l.variant_id ) + '">' +
				'<div class="rhshop-cart__media">' + media + '</div>' +
				'<div class="rhshop-cart__info"><span class="rhshop-cart__title">' + esc( l.title ) + '</span>' +
				'<span class="rhshop-cart__opts">' + esc( l.options ) + '</span>' +
				'<span class="rhshop-cart__unit">' + esc( l.unit_price ) + '</span></div>' +
				'<div class="rhshop-qty"><button type="button" data-rhshop-cart-qty="-">−</button>' +
				'<input type="number" value="' + parseInt( l.qty, 10 ) + '" min="1" max="99" data-rhshop-cart-qty-input inputmode="numeric" />' +
				'<button type="button" data-rhshop-cart-qty="+">+</button></div>' +
				'<span class="rhshop-cart__lt" data-rhshop-line-total>' + esc( l.line_total ) + '</span>' +
				'<button type="button" class="rhshop-cart__remove" data-rhshop-cart-remove aria-label="Entfernen">×</button>' +
				'</li>';
		}

		function render( state ) {
			if ( emptyEl ) {
				emptyEl.hidden = ! state.empty;
			}
			if ( linesEl ) {
				linesEl.hidden = state.empty;
				linesEl.innerHTML = state.empty ? '' : state.lines.map( lineHtml ).join( '' );
			}
			if ( footEl ) {
				footEl.hidden = state.empty;
			}
			if ( totalEl ) {
				totalEl.textContent = state.total;
			}
		}

		function change( line, qty, remove ) {
			var p = parseInt( line.getAttribute( 'data-p' ), 10 );
			var v = line.getAttribute( 'data-v' );
			var path = remove ? 'cart/remove' : 'cart/update';
			var body = remove ? { product_id: p, variant_id: v } : { product_id: p, variant_id: v, qty: qty };
			post( path, body ).then( function ( state ) {
				render( state );
				updateCartCount( state );
				emitUpdated( state );
			} );
		}

		document.addEventListener( 'click', function ( e ) {
			var line = e.target.closest( '.rhshop-cart__line' );
			if ( ! line ) {
				return;
			}
			if ( e.target.closest( '[data-rhshop-cart-remove]' ) ) {
				change( line, 0, true );
				return;
			}
			var qtyBtn = e.target.closest( '[data-rhshop-cart-qty]' );
			if ( qtyBtn ) {
				var input = line.querySelector( '[data-rhshop-cart-qty-input]' );
				var step = qtyBtn.getAttribute( 'data-rhshop-cart-qty' ) === '+' ? 1 : -1;
				var q = Math.max( 0, ( parseInt( input.value, 10 ) || 1 ) + step );
				change( line, q, false );
			}
		} );

		document.addEventListener( 'change', function ( e ) {
			var input = e.target.closest( '[data-rhshop-cart-qty-input]' );
			var line = e.target.closest( '.rhshop-cart__line' );
			if ( input && line ) {
				change( line, Math.max( 0, parseInt( input.value, 10 ) || 0 ), false );
			}
		} );
	}

	function init() {
		document.querySelectorAll( '[data-rhshop-buy]' ).forEach( initBuyBox );
		initCart();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
