/* Seiten-weite Sub-Tabs auf der Shop-Einstellungsseite. Eigene data-Attribute
   (data-rhshop-subtab/-pane), damit sie nicht mit der modal-gebundenen Core-Mechanik
   oder anderen Modulen kollidieren. Schaltet die Panes per is-active um. */
( function () {
	// js-Klasse erst per Script setzen: ohne JS bleiben alle Panes sichtbar.
	document.querySelectorAll( '.rhshop-settings-tabs' ).forEach( function ( el ) {
		el.classList.add( 'js' );
	} );

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-rhshop-subtab]' );
		if ( ! btn ) {
			return;
		}
		var root = btn.closest( '.rhshop-settings-tabs' ) || document;
		var key = btn.getAttribute( 'data-rhshop-subtab' );
		root.querySelectorAll( '[data-rhshop-subtab]' ).forEach( function ( b ) {
			b.classList.toggle( 'is-active', b === btn );
		} );
		root.querySelectorAll( '[data-rhshop-pane]' ).forEach( function ( p ) {
			p.classList.toggle( 'is-active', p.getAttribute( 'data-rhshop-pane' ) === key );
		} );
		// Elemente, die auf einem bestimmten Tab NICHT gezeigt werden sollen (z.B. der
		// Speichern-Knopf auf dem Status-Tab, wo es nichts zu speichern gibt).
		root.querySelectorAll( '[data-rhshop-hide]' ).forEach( function ( el ) {
			el.classList.toggle( 'rhshop-hidden', el.getAttribute( 'data-rhshop-hide' ) === key );
		} );
	} );
} )();
