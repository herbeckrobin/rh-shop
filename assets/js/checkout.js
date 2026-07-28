/**
 * Kasse mit Stripe Payment Element. Beim Laden mountet das Payment Element (Zahlarten)
 * und das Address Element (Lieferadresse), gethemet über die Appearance-API. Der
 * "Zahlungspflichtig bestellen"-Button legt die Bestellung an (REST, deferred
 * PaymentIntent) und bestätigt die Zahlung mit confirmPayment (Redirect auf /danke).
 * Stripe.js wird erst hier auf der Kasse geladen (nicht sitewide).
 */
( function () {
	var cfg = window.rhShopCheckout;
	if ( ! cfg || ! cfg.pk ) {
		return;
	}

	var LABELS = {
		ordering: 'Bestellung wird angelegt…',
		paying: 'Zahlung wird verarbeitet…',
		consents: 'Bitte bestätige die markierten Pflichtangaben.',
		email: 'Bitte gib eine gültige E-Mail-Adresse an.',
		error: 'Etwas ist schiefgelaufen. Bitte nochmal versuchen.',
		free: 'kostenlos',
		inclShip: 'inkl. %s Versand',
		inclFree: 'inkl. kostenlosem Versand',
		freeRemain: 'Noch %s bis zum Gratisversand.',
	};

	// Spinner + Text für Lade-Meldungen (LABELS sind statisch, kein User-Input).
	function spin( text ) {
		return '<span class="rhshop-spinner" aria-hidden="true"></span>' + text;
	}

	// Locale explizit aus der Site-Sprache: sonst folgt das Payment Element der
	// Browser-Sprache und rendert z.B. englisch in einer deutschen Kasse.
	function stripeInstance() {
		return window.Stripe( cfg.pk, { locale: cfg.locale || 'auto' } );
	}

	function loadStripe() {
		return new Promise( function ( resolve, reject ) {
			if ( window.Stripe ) {
				resolve( stripeInstance() );
				return;
			}
			var s = document.createElement( 'script' );
			s.src = 'https://js.stripe.com/v3/';
			s.onload = function () {
				window.Stripe ? resolve( stripeInstance() ) : reject( new Error( LABELS.error ) );
			};
			s.onerror = function () {
				reject( new Error( LABELS.error ) );
			};
			document.head.appendChild( s );
		} );
	}

	function initCheckout( root ) {
		var btn = root.querySelector( '[data-rhshop-order]' );
		var msg = root.querySelector( '[data-rhshop-checkout-msg]' );
		var payMount = root.querySelector( '[data-rhshop-payment-element]' );
		var addrMount = root.querySelector( '[data-rhshop-address-element]' );
		if ( ! btn || ! payMount || ! cfg.amount ) {
			return;
		}

		var submitting = false;
		var currentMethod = cfg.shippingMethod || '';
		var currentAmount = cfg.amount;
		var elements = null;

		// Versandmethoden-Auswahl: läuft SOFORT, unabhängig von Stripe. Beim Wechsel holt
		// das JS den neuen Preis vom Quote-Endpoint (Server ist die eine Rechenquelle) und
		// aktualisiert die Anzeige. Nur das Angleichen des Stripe-Betrags wartet konditional
		// auf das geladene Payment Element, damit der Preis sich auch dann ändert, wenn
		// Stripe langsam oder gar nicht lädt.
		function setText( sel, txt ) {
			var el = document.querySelector( sel );
			if ( el ) { el.textContent = txt; }
		}
		function applyQuote( q ) {
			currentMethod = q.shipping_method || currentMethod;
			currentAmount = q.total_cents || currentAmount;
			setText( '[data-rhshop-sum-shipping]', q.shipping_cents > 0 ? q.shipping : LABELS.free );
			setText( '[data-rhshop-sum-tax]', q.tax );
			setText( '[data-rhshop-sum-total]', q.total );
			setText( '[data-rhshop-payline-total]', q.total );
			setText( '[data-rhshop-payline-note]', q.shipping_cents > 0 ? LABELS.inclShip.replace( '%s', q.shipping ) : LABELS.inclFree );
			var free = document.querySelector( '[data-rhshop-sum-freeship]' );
			if ( free ) {
				if ( q.free_shipping_remaining_cents > 0 ) {
					free.textContent = LABELS.freeRemain.replace( '%s', q.free_shipping_remaining );
					free.hidden = false;
				} else {
					free.hidden = true;
				}
			}
			if ( elements && q.total_cents ) { elements.update( { amount: q.total_cents } ); }
		}
		var methodBox = document.querySelector( '[data-rhshop-shipping-methods]' );
		if ( methodBox ) {
			methodBox.addEventListener( 'change', function ( e ) {
				var radio = e.target.closest( '[data-rhshop-shipping-method]' );
				if ( ! radio ) { return; }
				fetch( cfg.restUrl + 'checkout/quote', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { shipping_method: radio.value } ),
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( q ) { if ( q && q.total_cents != null ) { applyQuote( q ); } } )
					.catch( function () {} );
			} );
		}

		loadStripe()
			.then( function ( stripe ) {
				// Betrag mit dem aktuell gewählten Stand mounten (falls schon vor dem
				// Stripe-Load gewechselt wurde), nicht mit dem Default aus cfg.amount.
				elements = stripe.elements( {
					mode: 'payment',
					amount: currentAmount,
					currency: cfg.currency || 'eur',
					appearance: cfg.appearance || {},
				} );
				// Skeleton-Platzhalter entfernen, bevor Stripe seine iframes einhängt.
				payMount.innerHTML = '';
				elements.create( 'payment' ).mount( payMount );
				if ( addrMount ) {
					addrMount.innerHTML = '';
					elements
						.create( 'address', {
							mode: 'shipping',
							allowedCountries: cfg.countries || [ 'DE', 'AT', 'CH' ],
						} )
						.mount( addrMount );
				}

				btn.addEventListener( 'click', function () {
					if ( submitting ) {
						return;
					}
					var email = ( root.querySelector( '[data-rhshop-email]' ) || {} ).value || '';
					var accepts = root.querySelectorAll( '[data-rhshop-accept]' );
					var allChecked = Array.prototype.every.call( accepts, function ( c ) {
						return c.checked;
					} );

					if ( ! allChecked ) {
						msg.textContent = LABELS.consents;
						return;
					}
					if ( ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( email ) ) {
						msg.textContent = LABELS.email;
						return;
					}

					submitting = true;
					btn.disabled = true;
					msg.innerHTML = spin( LABELS.ordering );

					// 1) Stripe-Felder validieren.
					elements
						.submit()
						.then( function ( res ) {
							if ( res.error ) {
								throw new Error( res.error.message || LABELS.error );
							}
							// 2) Bestellung + PaymentIntent anlegen (§312j: verbindliche
							//    Bestellung wird an unserem Button final erzeugt).
							return fetch( cfg.restUrl + 'checkout/session', {
								method: 'POST',
								headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
								credentials: 'same-origin',
								body: JSON.stringify( {
									email: email,
									shipping_method: currentMethod,
									accept_terms: true,
									accept_revocation: true,
									accept_privacy: true,
								} ),
							} );
						} )
						.then( function ( r ) {
							return r.json().then( function ( d ) {
								return { ok: r.ok, d: d };
							} );
						} )
						.then( function ( out ) {
							if ( ! out.ok || ! out.d.client_secret ) {
								throw new Error( out.d && out.d.message ? out.d.message : LABELS.error );
							}
							msg.innerHTML = spin( LABELS.paying );
							// 3) Zahlung bestätigen -> Stripe leitet auf /danke weiter.
							return stripe.confirmPayment( {
								elements: elements,
								clientSecret: out.d.client_secret,
								confirmParams: {
									return_url: cfg.returnUrl,
									payment_method_data: { billing_details: { email: email } },
								},
							} );
						} )
						.then( function ( result ) {
							// Bei Erfolg hat Stripe schon weitergeleitet. Hier landet nur
							// ein Fehler (z.B. abgelehnte Karte).
							if ( result && result.error ) {
								throw new Error( result.error.message || LABELS.error );
							}
						} )
						.catch( function ( e ) {
							submitting = false;
							btn.disabled = false;
							msg.textContent = e.message || LABELS.error;
						} );
				} );
			} )
			.catch( function ( e ) {
				msg.textContent = ( e && e.message ) || LABELS.error;
			} );
	}

	function init() {
		document.querySelectorAll( '[data-rhshop-checkout]' ).forEach( initCheckout );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
