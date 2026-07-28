/**
 * Produkt-Such-Overlay (Block rh-shop/search), buildless Vanilla-JS.
 * Öffnet das Overlay (an den <body> gehängt, Isolation vom Nav-Layout), sucht mit
 * Debounce gegen rhshop/v1/search und rendert die Treffer. Escape, Backdrop und
 * der Schließen-Button schließen; der Fokus geht zurück auf den Trigger.
 */
( function () {
	var cfg = window.rhShopConfig;
	if ( ! cfg || ! cfg.restUrl ) {
		return;
	}

	var LABELS = {
		min: 'Mindestens 2 Zeichen eingeben.',
		none: 'Keine Produkte gefunden.',
		many: '{n} Treffer',
		one: '1 Treffer',
		soldOut: 'Ausverkauft',
		error: 'Suche gerade nicht möglich. Bitte nochmal versuchen.',
	};

	function esc( value ) {
		var d = document.createElement( 'div' );
		d.textContent = value == null ? '' : String( value );
		return d.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function resultHtml( r ) {
		var media = r.thumb
			? '<img src="' + esc( r.thumb ) + '" alt="" loading="lazy">'
			: '<span class="rhshop-card__ph" aria-hidden="true"></span>';
		var badge = r.soldOut ? '<span class="rhshop-search__soldout">' + LABELS.soldOut + '</span>' : '';
		return '<li><a class="rhshop-search__result" href="' + esc( r.url ) + '">' +
			'<span class="rhshop-search__result-media">' + media + '</span>' +
			'<span class="rhshop-search__result-info"><span class="rhshop-search__result-title">' + esc( r.title ) + '</span>' +
			'<span class="rhshop-search__result-price">' + esc( r.price ) + '</span></span>' +
			badge +
			'</a></li>';
	}

	function initSearch( root ) {
		var trigger = root.querySelector( '[data-rhshop-search-open]' );
		var overlay = root.querySelector( '[data-rhshop-search-overlay]' );
		if ( ! trigger || ! overlay ) {
			return;
		}

		// Overlay an den body: unabhängig von Nav-Stacking-Kontexten und overflow.
		document.body.appendChild( overlay );

		var input = overlay.querySelector( '[data-rhshop-search-input]' );
		var results = overlay.querySelector( '[data-rhshop-search-results]' );
		var status = overlay.querySelector( '[data-rhshop-search-status]' );
		var timer = null;
		var controller = null;

		function open() {
			overlay.hidden = false;
			trigger.setAttribute( 'aria-expanded', 'true' );
			document.documentElement.style.overflow = 'hidden';
			window.requestAnimationFrame( function () {
				overlay.classList.add( 'is-open' );
				input.focus();
			} );
			document.addEventListener( 'keydown', onKey );
		}

		function close() {
			overlay.classList.remove( 'is-open' );
			overlay.hidden = true;
			trigger.setAttribute( 'aria-expanded', 'false' );
			document.documentElement.style.overflow = '';
			document.removeEventListener( 'keydown', onKey );
			trigger.focus();
		}

		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				close();
			}
		}

		function render( list ) {
			results.innerHTML = list.map( resultHtml ).join( '' );
			if ( ! list.length ) {
				status.textContent = LABELS.none;
			} else {
				status.textContent = list.length === 1 ? LABELS.one : LABELS.many.replace( '{n}', list.length );
			}
		}

		function query( term ) {
			if ( controller ) {
				controller.abort();
			}
			controller = new AbortController();
			fetch( cfg.restUrl + 'search?q=' + encodeURIComponent( term ), {
				credentials: 'same-origin',
				signal: controller.signal,
			} )
				.then( function ( r ) {
					return r.json().then( function ( d ) {
						if ( ! r.ok ) {
							throw new Error( ( d && d.message ) || LABELS.error );
						}
						return d;
					} );
				} )
				.then( function ( d ) {
					render( d.results || [] );
				} )
				.catch( function ( e ) {
					if ( e && e.name === 'AbortError' ) {
						return;
					}
					results.innerHTML = '';
					status.textContent = ( e && e.message ) || LABELS.error;
				} );
		}

		trigger.addEventListener( 'click', open );
		overlay.querySelectorAll( '[data-rhshop-search-close]' ).forEach( function ( el ) {
			el.addEventListener( 'click', close );
		} );

		input.addEventListener( 'input', function () {
			var term = input.value.trim();
			window.clearTimeout( timer );
			if ( term.length < 2 ) {
				results.innerHTML = '';
				status.textContent = term.length ? LABELS.min : '';
				return;
			}
			timer = window.setTimeout( function () {
				query( term );
			}, 250 );
		} );
	}

	function init() {
		document.querySelectorAll( '[data-rhshop-search]' ).forEach( initSearch );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
