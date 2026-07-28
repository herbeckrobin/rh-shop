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
		optSoldOut: 'ausverkauft',
		optLeft: 'nur noch {n}',
		capped: 'Nur noch {n} verfügbar, die Menge wurde angepasst.',
		chooseOptions: 'Bitte Auswahl treffen',
		error: 'Etwas ist schiefgelaufen. Bitte nochmal versuchen.',
	};

	var NET_ERROR = 'Verbindung fehlgeschlagen. Bitte nochmal versuchen.';

	// Eine Request-Schicht für alle Cart-Aktionen: prüft response.ok, liest bei einem
	// Fehler die WP_Error-Meldung ({code, message}) für die Anzeige, wirft sonst einen
	// freundlichen Netzwerkfehler. Kein stilles Weiterverarbeiten eines 4xx/5xx als State.
	function request( path, body ) {
		return fetch( cfg.restUrl + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body || {} ),
		} ).then(
			function ( r ) {
				return r
					.json()
					.catch( function () {
						return {};
					} )
					.then( function ( d ) {
						if ( ! r.ok ) {
							throw new Error( ( d && d.message ) || NET_ERROR );
						}
						return d;
					} );
			},
			function () {
				// fetch selbst gescheitert (offline, DNS, abgebrochen).
				throw new Error( NET_ERROR );
			}
		);
	}

	// Einheitlicher Ladezustand: Element sperren + Spinner (.is-pending) während das
	// Promise läuft, danach zurücksetzen. Gibt das Promise durch (Fehler propagiert).
	function withPending( el, promise ) {
		if ( ! el ) {
			return promise;
		}
		var wasDisabled = el.disabled;
		el.disabled = true;
		el.classList.add( 'is-pending' );
		var settle = function () {
			el.disabled = wasDisabled;
			el.classList.remove( 'is-pending' );
		};
		return promise.then(
			function ( v ) {
				settle();
				return v;
			},
			function ( e ) {
				settle();
				throw e;
			}
		);
	}

	// Einheitlicher Fehler-Slot: Meldung setzen (role="alert" am Element) oder leeren.
	function showError( el, message ) {
		if ( el ) {
			el.textContent = message || '';
		}
	}

	// Escaping für Einbettung in innerHTML, inklusive Attribut-Kontext: textContent
	// neutralisiert &, <, >; die zwei replaces zusätzlich " und ', damit ein Wert auch
	// zwischen Attribut-Anführungszeichen (data-v="...", src="...") nicht ausbrechen kann.
	function esc( value ) {
		var d = document.createElement( 'div' );
		d.textContent = value == null ? '' : String( value );
		return d.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
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

	// Eigenes Signal fürs "gerade hinzugefügt" (nur beim In-den-Warenkorb-Legen), damit
	// das Nav-Widget-Overlay nur dann aufgeht, nicht bei jeder Mengenänderung.
	function emitAdded( state ) {
		document.dispatchEvent( new CustomEvent( 'rhshop:cart-added', { detail: state } ) );
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

		// Kaufbare Höchstmenge der Variante (Bestand); Infinity = unbegrenzt. Die eine
		// Deckel-Wahrheit, gleich wie Server (Cart::clampQty) und Warenkorb.
		function maxFor( v ) {
			return v && v.stock !== null && typeof v.stock !== 'undefined' ? Math.max( 0, v.stock ) : Infinity;
		}

		// Auswahl-Optionen mit Bestand beschriften: ausverkaufte deaktivieren, knappe
		// markieren. Läuft über dieselben Varianten-Daten wie alles andere. Bei zwei
		// Achsen richtet sich die Beschriftung nach der bereits getroffenen Gegen-Wahl.
		function annotateOptions() {
			selects.forEach( function ( s ) {
				var axis = s.getAttribute( 'data-rhshop-opt' );
				var otherAxis = axis === '1' ? '2' : '1';
				var otherVal = '';
				selects.forEach( function ( o ) {
					if ( o.getAttribute( 'data-rhshop-opt' ) === otherAxis ) {
						otherVal = o.value;
					}
				} );

				Array.prototype.forEach.call( s.options, function ( opt ) {
					if ( opt.value === '' ) {
						return;
					}
					if ( ! opt.hasAttribute( 'data-base' ) ) {
						opt.setAttribute( 'data-base', opt.textContent );
					}
					var base = opt.getAttribute( 'data-base' );
					var matches = variants.filter( function ( v ) {
						var mineOk = ( axis === '1' ? v.o1 : v.o2 ) === opt.value;
						var otherOk = ! otherVal || ( otherAxis === '1' ? v.o1 : v.o2 ) === otherVal;
						return mineOk && otherOk;
					} );

					var suffix = '';
					var disable = false;
					if ( matches.length ) {
						var available = matches.filter( function ( v ) { return v.available; } );
						if ( ! available.length ) {
							disable = true;
							suffix = ' (' + LABELS.optSoldOut + ')';
						} else if ( available.length === 1 && maxFor( available[ 0 ] ) <= lowStock && lowStock > 0 ) {
							// Eindeutige Variante hinter dieser Option -> exakter Bestand.
							suffix = ' (' + LABELS.optLeft.replace( '{n}', maxFor( available[ 0 ] ) ) + ')';
						}
					}
					opt.textContent = base + suffix;
					opt.disabled = disable;
				} );

				// Wurde die aktuell gewählte Option gerade deaktiviert, Auswahl zurücksetzen.
				if ( s.selectedOptions[ 0 ] && s.selectedOptions[ 0 ].disabled ) {
					s.value = '';
				}
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

		// Mengenfeld auf den Bestand der gewählten Variante deckeln. Gibt true zurück,
		// wenn dabei nach unten korrigiert wurde (für die Warnung).
		function capQty() {
			var cap = Math.min( 99, selected ? maxFor( selected ) : 99 );
			var val = Math.max( 1, parseInt( qtyInput.value, 10 ) || 1 );
			qtyInput.setAttribute( 'max', isFinite( cap ) ? cap : 99 );
			if ( val > cap ) {
				qtyInput.value = Math.max( 1, cap );
				return true;
			}
			qtyInput.value = val;
			return false;
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
				capQty();
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
			s.addEventListener( 'change', function () {
				annotateOptions();
				refresh();
			} );
		} );

		box.querySelectorAll( '[data-rhshop-qty]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var step = b.getAttribute( 'data-rhshop-qty' ) === '+' ? 1 : -1;
				var cap = Math.min( 99, selected ? maxFor( selected ) : 99 );
				var next = ( parseInt( qtyInput.value, 10 ) || 1 ) + step;
				if ( next > cap ) {
					msgEl.textContent = LABELS.capped.replace( '{n}', cap );
					next = cap;
				} else if ( step > 0 || next >= 1 ) {
					msgEl.textContent = '';
				}
				qtyInput.value = Math.max( 1, next );
			} );
		} );

		qtyInput.addEventListener( 'change', function () {
			if ( capQty() ) {
				msgEl.textContent = LABELS.capped.replace( '{n}', Math.min( 99, selected ? maxFor( selected ) : 99 ) );
			}
		} );

		addBtn.addEventListener( 'click', function () {
			if ( ! selected || ! selected.available ) {
				return;
			}
			var qty = Math.max( 1, parseInt( qtyInput.value, 10 ) || 1 );
			showError( msgEl, '' );
			withPending( addBtn, request( 'cart/add', { product_id: productId, variant_id: selected.id, qty: qty } ) )
				.then( function ( state ) {
					// Alle Ansichten (auch das Nav-Widget-Overlay) synchron ziehen.
					applyCartState( state );
					emitUpdated( state );
					emitAdded( state );
					// Hat der Server wegen Bestand gedeckelt, seine Warnung zeigen,
					// sonst die Erfolgsmeldung.
					msgEl.textContent = state.notice || LABELS.added;
				} )
				.catch( function ( e ) {
					showError( msgEl, ( e && e.message ) || LABELS.error );
				} );
		} );

		annotateOptions();
		refresh();
	}

	// --- Warenkorb ---
	// Seiten-weit UND mehr-Ansichten: die Warenkorb-Positionen können in mehreren
	// Ansichten gleichzeitig stehen (die Warenkorb-Seite und das Nav-Widget-Overlay).
	// Der Renderer aktualisiert darum ALLE data-rhshop-cart-*-Container, nicht nur den
	// ersten. Eine Cart-State-Quelle, viele Ansichten, immer synchron.
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
			'<input type="number" value="' + parseInt( l.qty, 10 ) + '" min="1" max="' + ( l.max != null ? Math.min( 99, l.max ) : 99 ) + '" data-rhshop-cart-qty-input inputmode="numeric" />' +
			'<button type="button" data-rhshop-cart-qty="+">+</button></div>' +
			'<span class="rhshop-cart__lt" data-rhshop-line-total>' + esc( l.line_total ) + '</span>' +
			'<button type="button" class="rhshop-cart__remove" data-rhshop-cart-remove aria-label="Entfernen">×</button>' +
			'</li>';
	}

	// Alle Warenkorb-Ansichten auf der Seite aus einem State aktualisieren.
	function renderCartViews( state ) {
		document.querySelectorAll( '[data-rhshop-cart-empty]' ).forEach( function ( el ) {
			el.hidden = ! state.empty;
		} );
		document.querySelectorAll( '[data-rhshop-cart-lines]' ).forEach( function ( el ) {
			el.hidden = state.empty;
			el.innerHTML = state.empty ? '' : state.lines.map( lineHtml ).join( '' );
		} );
		document.querySelectorAll( '[data-rhshop-cart-foot]' ).forEach( function ( el ) {
			el.hidden = state.empty;
		} );
		document.querySelectorAll( '[data-rhshop-cart-total]' ).forEach( function ( el ) {
			el.textContent = state.total;
		} );
		document.querySelectorAll( '[data-rhshop-cart-notice]' ).forEach( function ( el ) {
			// Bestands-Warnung des Servers (Menge gedeckelt) zeigen, sonst leeren.
			el.textContent = state.notice || '';
		} );
	}

	// Ein State -> alle Ansichten + alle Zähler-Badges. Zentral, damit Buy-Box,
	// Warenkorb-Seite und Widget nie auseinanderlaufen.
	function applyCartState( state ) {
		renderCartViews( state );
		updateCartCount( state );
	}

	function initCart() {
		// Klick-Delegation am document: greift für JEDE Warenkorb-Ansicht (Seite wie
		// Widget-Overlay), auch für später eingehängte (das Overlay wandert per JS an
		// den body). Darum kein Early-Return, wenn beim Init noch keine Zeile da ist.
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

		function change( line, qty, remove ) {
			var p = parseInt( line.getAttribute( 'data-p' ), 10 );
			var v = line.getAttribute( 'data-v' );
			var path = remove ? 'cart/remove' : 'cart/update';
			var body = remove ? { product_id: p, variant_id: v } : { product_id: p, variant_id: v, qty: qty };
			// Zeile als "läuft" markieren; bei Erfolg wird sie eh neu gerendert.
			line.classList.add( 'is-pending' );
			request( path, body )
				.then( function ( state ) {
					applyCartState( state );
					emitUpdated( state );
				} )
				.catch( function ( e ) {
					// Kein stiller Fehlschlag mehr: Zeile freigeben, Fehler in allen
					// Warenkorb-Notice-Slots (Seite und Widget) zeigen.
					line.classList.remove( 'is-pending' );
					document.querySelectorAll( '[data-rhshop-cart-notice]' ).forEach( function ( el ) {
						el.textContent = ( e && e.message ) || NET_ERROR;
					} );
				} );
		}
	}

	// --- Produkt-Galerie (Detailseite) ---
	// Thumb-Klick tauscht das Hauptbild, Klick aufs Hauptbild öffnet den Zoom.
	// Ohne JS sind die Thumbs Links auf die Originaldatei (Progressive Enhancement).
	function initGallery( gallery ) {
		var main = gallery.querySelector( '[data-rhshop-gallery-main]' );
		var zoomBtn = gallery.querySelector( '[data-rhshop-gallery-zoom]' );
		var thumbs = gallery.querySelectorAll( '[data-rhshop-gallery-thumb]' );
		var title = gallery.getAttribute( 'data-rhshop-gallery-title' ) || '';
		var fullUrl = thumbs.length ? thumbs[ 0 ].getAttribute( 'href' ) : '';

		thumbs.forEach( function ( thumb ) {
			thumb.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( main ) {
					main.src = thumb.getAttribute( 'data-rhshop-gallery-thumb' );
					main.removeAttribute( 'srcset' );
					main.removeAttribute( 'sizes' );
				}
				fullUrl = thumb.getAttribute( 'href' );
				thumbs.forEach( function ( t ) {
					t.classList.toggle( 'is-active', t === thumb );
					if ( t === thumb ) {
						t.setAttribute( 'aria-current', 'true' );
					} else {
						t.removeAttribute( 'aria-current' );
					}
				} );
			} );
		} );

		if ( ! zoomBtn || ! main ) {
			return;
		}

		zoomBtn.addEventListener( 'click', function () {
			var overlay = document.createElement( 'div' );
			overlay.className = 'rhshop-lightbox';
			overlay.setAttribute( 'role', 'dialog' );
			overlay.setAttribute( 'aria-modal', 'true' );
			overlay.setAttribute( 'aria-label', title );
			overlay.innerHTML = '<button type="button" class="rhshop-lightbox__close" aria-label="Schließen">×</button>' +
				'<img src="' + esc( fullUrl || main.src ) + '" alt="' + esc( title ) + '">';
			document.body.appendChild( overlay );
			document.documentElement.style.overflow = 'hidden';

			function close() {
				overlay.remove();
				document.documentElement.style.overflow = '';
				document.removeEventListener( 'keydown', onKey );
				zoomBtn.focus();
			}
			function onKey( e ) {
				if ( e.key === 'Escape' ) {
					close();
				}
			}
			overlay.addEventListener( 'click', close );
			document.addEventListener( 'keydown', onKey );
			overlay.querySelector( '.rhshop-lightbox__close' ).focus();
		} );
	}

	// --- Grid-Controls (Kategorie-Pills) ---
	// Rein client-seitig über die data-Attribute der Karten; die Controls sind bis
	// hierher hidden, ohne JS zeigt das Raster einfach alles.
	function initGridControls( controls ) {
		var block = controls.closest( '.rhshop-grid-block' ) || controls.parentElement;
		var grid = block ? block.querySelector( '[data-rhshop-grid]' ) : null;
		var empty = block ? block.querySelector( '[data-rhshop-grid-empty]' ) : null;
		if ( ! grid ) {
			return;
		}

		var pills = controls.querySelectorAll( '[data-rhshop-pill]' );
		var activeCat = '';

		function apply() {
			var visible = 0;
			grid.querySelectorAll( '.rhshop-card' ).forEach( function ( card ) {
				var cats = ( card.getAttribute( 'data-rhshop-cats' ) || '' ).split( ' ' );
				var show = ! activeCat || cats.indexOf( activeCat ) !== -1;
				card.hidden = ! show;
				if ( show ) {
					visible++;
				}
			} );
			if ( empty ) {
				empty.hidden = visible > 0;
			}
		}

		pills.forEach( function ( pill ) {
			pill.addEventListener( 'click', function () {
				activeCat = pill.getAttribute( 'data-rhshop-pill' ) || '';
				pills.forEach( function ( p ) {
					p.setAttribute( 'aria-pressed', p === pill ? 'true' : 'false' );
				} );
				apply();
			} );
		} );

		controls.hidden = false;
	}

	// --- Quick-Add auf Grid-Karten (Produkte ohne Varianten) ---
	// Delegation am document: greift auch für Karten, die JS später einhängt
	// (z.B. die Empfehlungen im leeren Warenkorb). Erfolg zeigt der aufgehende
	// Drawer (rhshop:cart-added), Fehler eine kurze Markierung am Button.
	function initQuickAdd() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-rhshop-quick-add]' );
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			var productId = parseInt( btn.getAttribute( 'data-rhshop-quick-add' ), 10 );
			btn.classList.remove( 'is-error' );
			withPending( btn, request( 'cart/add', { product_id: productId, variant_id: 'default', qty: 1 } ) )
				.then( function ( state ) {
					applyCartState( state );
					emitUpdated( state );
					emitAdded( state );
				} )
				.catch( function ( err ) {
					btn.classList.add( 'is-error' );
					btn.setAttribute( 'title', ( err && err.message ) || NET_ERROR );
				} );
		} );
	}

	function init() {
		document.querySelectorAll( '[data-rhshop-buy]' ).forEach( initBuyBox );
		document.querySelectorAll( '[data-rhshop-gallery]' ).forEach( initGallery );
		document.querySelectorAll( '[data-rhshop-grid-controls]' ).forEach( initGridControls );
		initQuickAdd();
		initCart();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
