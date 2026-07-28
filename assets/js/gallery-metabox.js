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

	/**
	 * Schreibt den Zustand der Kacheln in die versteckten Felder: die erste Kachel
	 * ist das Hauptbild, alle weiteren sind die zusätzlichen Bilder. Setzt zugleich
	 * das Hauptbild-Badge und den Leer-Hinweis.
	 */
	function syncInput() {
		var b = box();
		if ( ! b ) {
			return;
		}
		var items = Array.prototype.slice.call( b.querySelectorAll( '[data-rhshop-gallery-item]' ) );
		var rest = [];

		items.forEach( function ( li, index ) {
			li.classList.toggle( 'is-featured', index === 0 );
			if ( index > 0 ) {
				rest.push( li.getAttribute( 'data-rhshop-gallery-item' ) );
			}
		} );

		b.querySelector( '[data-rhshop-gallery-ids]' ).value = rest.join( ',' );

		// Hauptbild nur melden, wenn es sich vom gespeicherten unterscheidet.
		var featuredField = b.querySelector( '[data-rhshop-gallery-featured]' );
		if ( featuredField ) {
			var first = items.length ? items[ 0 ].getAttribute( 'data-rhshop-gallery-item' ) : '';
			featuredField.value = first && first !== featuredField.getAttribute( 'data-initial' ) ? first : '';
		}

		var empty = b.querySelector( '[data-rhshop-gallery-empty]' );
		if ( empty ) {
			empty.hidden = items.length > 0;
		}
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

		var badge = document.createElement( 'span' );
		badge.className = 'rhshop-gallery-box__badge';
		badge.textContent = L.featuredBadge || 'Hauptbild';

		var tools = document.createElement( 'span' );
		tools.className = 'rhshop-gallery-box__tools';
		tools.innerHTML = ( L.iconStar || '' ) && '';
		[
			[ 'data-rhshop-gallery-feature', L.feature || 'Als Hauptbild festlegen', L.iconStar ],
			[ 'data-rhshop-gallery-replace', L.replace || 'Bild ersetzen', L.iconReplace ],
			[ 'data-rhshop-gallery-remove', L.remove || 'Bild entfernen', null ],
		].forEach( function ( def ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.setAttribute( def[ 0 ], '' );
			btn.setAttribute( 'aria-label', def[ 1 ] );
			btn.title = def[ 1 ];
			if ( def[ 2 ] ) {
				btn.innerHTML = def[ 2 ];
			} else {
				btn.textContent = '×';
			}
			tools.appendChild( btn );
		} );

		li.appendChild( img );
		li.appendChild( badge );
		li.appendChild( tools );
		list.appendChild( li );
	}

	function thumbOf( data ) {
		return data.sizes && data.sizes.medium ? data.sizes.medium.url : ( data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url );
	}

	/**
	 * Medienauswahl. Ohne Ziel werden Bilder hinzugefügt, mit Ziel wird genau diese
	 * Kachel ersetzt (eigener Frame, weil Mehrfachauswahl hier keinen Sinn ergibt).
	 */
	function openFrame( target ) {
		if ( ! window.wp || ! window.wp.media ) {
			window.alert( L.mediaMissing || 'Die Medienauswahl konnte nicht geladen werden. Bitte lade die Seite neu.' );
			return;
		}

		if ( target ) {
			var single = window.wp.media( {
				title: L.replace || 'Bild ersetzen',
				button: { text: L.button || 'Übernehmen' },
				library: { type: 'image' },
				multiple: false,
			} );
			single.on( 'select', function () {
				var data = single.state().get( 'selection' ).first().toJSON();
				var id = String( data.id );

				// Steckt das gewählte Bild schon in einer anderen Kachel, würde es
				// doppelt in der Liste stehen. Die andere Kachel fliegt dann raus.
				var dupe = target.parentElement.querySelector( '[data-rhshop-gallery-item="' + id + '"]' );
				if ( dupe && dupe !== target ) {
					dupe.remove();
				}

				target.setAttribute( 'data-rhshop-gallery-item', id );
				target.title = data.title || '';
				target.querySelector( 'img' ).src = thumbOf( data );
				syncInput();
			} );
			single.open();
			return;
		}

		if ( ! frame ) {
			frame = window.wp.media( {
				title: L.title || 'Bilder wählen',
				button: { text: L.button || 'Übernehmen' },
				library: { type: 'image' },
				multiple: 'add',
			} );
			frame.on( 'select', function () {
				frame.state().get( 'selection' ).forEach( function ( att ) {
					var data = att.toJSON();
					addItem( String( data.id ), thumbOf( data ) );
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
			return;
		}

		// Als Hauptbild: die Kachel wandert an die erste Stelle.
		var feature = e.target.closest( '[data-rhshop-gallery-feature]' );
		if ( feature ) {
			e.preventDefault();
			var li = feature.closest( '[data-rhshop-gallery-item]' );
			li.parentElement.insertBefore( li, li.parentElement.firstElementChild );
			syncInput();
			return;
		}

		// Ersetzen: Medienauswahl für genau diese Kachel.
		var replace = e.target.closest( '[data-rhshop-gallery-replace]' );
		if ( replace ) {
			e.preventDefault();
			openFrame( replace.closest( '[data-rhshop-gallery-item]' ) );
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
