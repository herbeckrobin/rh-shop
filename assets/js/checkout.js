/**
 * Kasse. Der "Zahlungspflichtig bestellen"-Button legt die Bestellung an (REST) und
 * mountet danach die embedded Stripe-Zahl-UI auf DIESER Seite (kein Redirect).
 * Stripe.js wird erst hier, auf der Kasse, nachgeladen (nicht sitewide).
 */
( function () {
	var cfg = window.rhShopCheckout;
	if ( ! cfg || ! cfg.pk ) {
		return;
	}

	var LABELS = {
		ordering: 'Bestellung wird angelegt…',
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
		var form = root.querySelector( '[data-rhshop-checkout-form]' );
		var mount = root.querySelector( '[data-rhshop-stripe-mount]' );
		if ( ! btn || ! mount ) {
			return;
		}
		var mounted = false;

		btn.addEventListener( 'click', function () {
			if ( mounted ) {
				return;
			}
			var email = ( root.querySelector( '[data-rhshop-email]' ) || {} ).value || '';
			var name = ( root.querySelector( '[data-rhshop-name]' ) || {} ).value || '';
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

			btn.disabled = true;
			msg.textContent = LABELS.ordering;

			fetch( cfg.restUrl + 'checkout/session', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( {
					email: email,
					name: name,
					accept_terms: true,
					accept_revocation: true,
					accept_privacy: true,
				} ),
			} )
				.then( function ( r ) {
					return r.json().then( function ( d ) {
						return { ok: r.ok, d: d };
					} );
				} )
				.then( function ( res ) {
					if ( ! res.ok || ! res.d.client_secret ) {
						throw new Error( res.d && res.d.message ? res.d.message : LABELS.error );
					}
					if ( form ) {
						form.setAttribute( 'hidden', '' );
					}
					mount.removeAttribute( 'hidden' );
					mounted = true;
					return loadStripe()
						.then( function ( stripe ) {
							return stripe.initEmbeddedCheckout( { clientSecret: res.d.client_secret } );
						} )
						.then( function ( checkout ) {
							checkout.mount( mount );
						} );
				} )
				.catch( function ( e ) {
					btn.disabled = false;
					mounted = false;
					if ( form ) {
						form.removeAttribute( 'hidden' );
					}
					mount.setAttribute( 'hidden', '' );
					msg.textContent = e.message || LABELS.error;
				} );
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
