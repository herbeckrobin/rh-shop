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
	};

	function loadStripe() {
		return new Promise( function ( resolve, reject ) {
			if ( window.Stripe ) {
				resolve( window.Stripe( cfg.pk ) );
				return;
			}
			var s = document.createElement( 'script' );
			s.src = 'https://js.stripe.com/v3/';
			s.onload = function () {
				window.Stripe ? resolve( window.Stripe( cfg.pk ) ) : reject( new Error( LABELS.error ) );
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

		loadStripe()
			.then( function ( stripe ) {
				var elements = stripe.elements( {
					mode: 'payment',
					amount: cfg.amount,
					currency: cfg.currency || 'eur',
					appearance: cfg.appearance || {},
				} );
				elements.create( 'payment' ).mount( payMount );
				if ( addrMount ) {
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
					msg.textContent = LABELS.ordering;

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
							msg.textContent = LABELS.paying;
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
