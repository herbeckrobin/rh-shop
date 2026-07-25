/**
 * Warenkorb-Widget: öffnet/schließt den Drawer (Overlay von rechts). Der Warenkorb-Inhalt
 * selbst (Positionen, Mengen, Summe) wird von shop.js gerendert und aktuell gehalten, das
 * hier ist nur die Overlay-Mechanik plus Zugänglichkeit.
 *
 * Isolation: der Drawer wird an den <body> gehängt, damit Nav-/Theme-Layout, Overflow und
 * Stacking ihn nicht beeinflussen. Ohne JS bleibt der Trigger ein Link zur Warenkorb-Seite.
 */
( function () {
	function initWidget( root ) {
		var trigger = root.querySelector( '[data-rhshop-cw-open]' );
		var drawer = root.querySelector( '[data-rhshop-cw-drawer]' );
		if ( ! trigger || ! drawer ) {
			return;
		}

		var openOnAdd = root.getAttribute( 'data-rhshop-cw-open-on-add' ) === '1';
		var panel = drawer.querySelector( '[data-rhshop-cw-panel]' );
		var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		var lastFocus = null;

		// Overlay aus dem Nav-Kontext lösen: an den body hängen (einmalig).
		if ( drawer.parentNode !== document.body ) {
			document.body.appendChild( drawer );
		}

		function focusables() {
			return panel.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
			);
		}

		function onKeydown( e ) {
			if ( e.key === 'Escape' ) {
				close();
				return;
			}
			if ( e.key !== 'Tab' ) {
				return;
			}
			// Simple Fokus-Falle innerhalb des Panels.
			var items = focusables();
			if ( ! items.length ) {
				e.preventDefault();
				panel.focus();
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}

		function open() {
			if ( drawer.classList.contains( 'is-open' ) ) {
				return;
			}
			lastFocus = document.activeElement;
			drawer.hidden = false;
			// Reflow erzwingen, damit die Transition von hidden -> is-open greift.
			void drawer.offsetWidth;
			drawer.classList.add( 'is-open' );
			trigger.setAttribute( 'aria-expanded', 'true' );
			document.documentElement.style.overflow = 'hidden';
			if ( window.lenis && typeof window.lenis.stop === 'function' ) {
				window.lenis.stop();
			}
			document.addEventListener( 'keydown', onKeydown );
			var closeBtn = drawer.querySelector( '[data-rhshop-cw-close-btn]' );
			( closeBtn || panel ).focus();
		}

		function close() {
			if ( ! drawer.classList.contains( 'is-open' ) ) {
				return;
			}
			drawer.classList.remove( 'is-open' );
			trigger.setAttribute( 'aria-expanded', 'false' );
			document.documentElement.style.overflow = '';
			if ( window.lenis && typeof window.lenis.start === 'function' ) {
				window.lenis.start();
			}
			document.removeEventListener( 'keydown', onKeydown );

			var finish = function () {
				drawer.hidden = true;
				panel.removeEventListener( 'transitionend', finish );
			};
			if ( reduce ) {
				finish();
			} else {
				panel.addEventListener( 'transitionend', finish );
			}
			if ( lastFocus && typeof lastFocus.focus === 'function' ) {
				lastFocus.focus();
			}
		}

		trigger.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			open();
		} );

		// Backdrop und Schließen-Buttons tragen data-rhshop-cw-close.
		drawer.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-rhshop-cw-close]' ) ) {
				close();
			}
		} );

		if ( openOnAdd ) {
			document.addEventListener( 'rhshop:cart-added', function () {
				open();
			} );
		}
	}

	function init() {
		document.querySelectorAll( '[data-rhshop-cart-widget]' ).forEach( initWidget );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
