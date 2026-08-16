/* Elemente, die auf einem bestimmten Reiter nicht gezeigt werden sollen.
   Konkret der Speichern-Knopf auf dem Status-Reiter, wo es nichts zu speichern
   gibt. Das Umschalten der Reiter selbst macht das Core-Skript, hier stand
   dafuer frueher eine Kopie mit eigenen data-Attributen. */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-rhbp-subtab]' );
		if ( ! btn ) {
			return;
		}
		var key = btn.getAttribute( 'data-rhbp-subtab' );
		document.querySelectorAll( '[data-rhshop-hide]' ).forEach( function ( el ) {
			el.classList.toggle( 'rhshop-hidden', el.getAttribute( 'data-rhshop-hide' ) === key );
		} );
	} );
} )();
