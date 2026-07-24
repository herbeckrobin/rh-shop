/**
 * Widerrufs-Bestätigung (§356a). Der zweite Klick ("Widerruf bestätigen") sendet die
 * drei Pflichtangaben an die REST-API, danach zeigt die Seite die Eingangsbestätigung.
 */
( function () {
	var cfg = window.rhShopConfig;
	if ( ! cfg || ! cfg.restUrl ) {
		return;
	}

	var L = {
		incomplete: 'Bitte Name und Bestellnummer angeben.',
		email: 'Bitte eine gültige E-Mail-Adresse angeben.',
		error: 'Etwas ist schiefgelaufen. Bitte per E-Mail widerrufen.',
	};

	function init( root ) {
		var btn = root.querySelector( '[data-rhshop-widerruf-submit]' );
		if ( ! btn ) {
			return;
		}
		var msg = root.querySelector( '[data-rhshop-w-msg]' );
		var form = root.querySelector( '[data-rhshop-widerruf-form]' );
		var success = root.querySelector( '[data-rhshop-w-success]' );

		function v( sel ) {
			var el = root.querySelector( sel );
			return el ? el.value.trim() : '';
		}

		btn.addEventListener( 'click', function () {
			var name = v( '[data-rhshop-w-name]' );
			var order = v( '[data-rhshop-w-order]' );
			var email = v( '[data-rhshop-w-email]' );
			var reason = v( '[data-rhshop-w-reason]' );

			if ( ! name || ! order ) {
				msg.textContent = L.incomplete;
				return;
			}
			if ( ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( email ) ) {
				msg.textContent = L.email;
				return;
			}

			btn.disabled = true;
			msg.textContent = '';

			fetch( cfg.restUrl + 'widerruf', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { name: name, order_number: order, email: email, reason: reason } ),
			} )
				.then( function ( r ) {
					return r.json().then( function ( d ) {
						return { ok: r.ok, d: d };
					} );
				} )
				.then( function ( res ) {
					if ( ! res.ok || ! res.d.confirmed ) {
						throw new Error( res.d && res.d.message ? res.d.message : L.error );
					}
					if ( form ) {
						form.setAttribute( 'hidden', '' );
					}
					if ( success ) {
						success.removeAttribute( 'hidden' );
					}
				} )
				.catch( function ( e ) {
					btn.disabled = false;
					msg.textContent = e.message || L.error;
				} );
		} );
	}

	function boot() {
		document.querySelectorAll( '[data-rhshop-widerruf]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
