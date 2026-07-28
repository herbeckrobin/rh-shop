/**
 * Bildergalerie-Meta-Box im Produkt-Editor, buildless Vanilla-JS.
 *
 * Bewusst mit Event-Delegation am document statt direkt gebundener Listener:
 * die Meta-Box wird im Block-Editor per AJAX nachgeladen, und wp.media steht
 * beim ersten Skript-Durchlauf noch nicht bereit (gemessen: Box da bei 278ms,
 * wp.media erst bei 286ms). Ein Guard auf wp.media beim Initialisieren würde
 * still abbrechen und den Button für immer tot lassen. Darum wird wp.media
 * erst im Klick-Handler angefasst, wo es garantiert geladen ist.
 */
( function () {
	var L = window.rhShopGallery || {};
	var frame = null;

	function box() {
		return document.querySelector( '[data-rhshop-gallery-box]' );
	}

	function syncInput() {
		var b = box();
		if ( ! b ) {
			return;
		}
		var ids = [];
		b.querySelectorAll( '[data-rhshop-gallery-item]' ).forEach( function ( li ) {
			ids.push( li.getAttribute( 'data-rhshop-gallery-item' ) );
		} );
		b.querySelector( '[data-rhshop-gallery-ids]' ).value = ids.join( ',' );
	}

	function addItem( id, thumbUrl ) {
		var b = box();
		var list = b && b.querySelector( '[data-rhshop-gallery-list]' );
		if ( ! list || list.querySelector( '[data-rhshop-gallery-item="' + id + '"]' ) ) {
			return;
		}
		var li = document.createElement( 'li' );
		li.setAttribute( 'data-rhshop-gallery-item', id );
		li.setAttribute( 'draggable', 'true' );

		var img = document.createElement( 'img' );
		img.src = thumbUrl;
		img.alt = '';

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.setAttribute( 'data-rhshop-gallery-remove', '' );
		btn.setAttribute( 'aria-label', L.remove || 'Bild entfernen' );
		btn.textContent = '×';

		li.appendChild( img );
		li.appendChild( btn );
		list.appendChild( li );
	}

	function openFrame() {
		if ( ! window.wp || ! window.wp.media ) {
			window.alert( L.mediaMissing || 'Die Medienauswahl konnte nicht geladen werden. Bitte die Seite neu laden.' );
			return;
		}

		if ( ! frame ) {
			frame = window.wp.media( {
				title: L.title || 'Galerie-Bilder wählen',
				button: { text: L.button || 'Übernehmen' },
				library: { type: 'image' },
				multiple: 'add',
			} );
			frame.on( 'select', function () {
				frame.state().get( 'selection' ).forEach( function ( att ) {
					var data = att.toJSON();
					var thumb = data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url;
					addItem( String( data.id ), thumb );
				} );
				syncInput();
			} );
		}
		frame.open();
	}

	// Eine Delegation am document deckt Öffnen und Entfernen ab, auch für DOM,
	// das der Block-Editor erst später einhängt.
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-rhshop-gallery-add]' ) ) {
			e.preventDefault();
			openFrame();
			return;
		}
		var remove = e.target.closest( '[data-rhshop-gallery-remove]' );
		if ( remove ) {
			e.preventDefault();
			remove.closest( '[data-rhshop-gallery-item]' ).remove();
			syncInput();
		}
	} );

	// Sortierung per Drag and Drop (nativ, reicht für kleine Galerien).
	var dragged = null;
	document.addEventListener( 'dragstart', function ( e ) {
		var item = e.target.closest ? e.target.closest( '[data-rhshop-gallery-item]' ) : null;
		if ( item ) {
			dragged = item;
		}
	} );
	document.addEventListener( 'dragover', function ( e ) {
		if ( ! dragged ) {
			return;
		}
		var over = e.target.closest ? e.target.closest( '[data-rhshop-gallery-item]' ) : null;
		if ( ! over || over === dragged || over.parentElement !== dragged.parentElement ) {
			return;
		}
		e.preventDefault();
		var rect = over.getBoundingClientRect();
		var before = ( e.clientX - rect.left ) < rect.width / 2;
		over.parentElement.insertBefore( dragged, before ? over : over.nextSibling );
	} );
	document.addEventListener( 'drop', function ( e ) {
		if ( dragged ) {
			e.preventDefault();
			syncInput();
			dragged = null;
		}
	} );
	document.addEventListener( 'dragend', function () {
		if ( dragged ) {
			syncInput();
			dragged = null;
		}
	} );
} )();
