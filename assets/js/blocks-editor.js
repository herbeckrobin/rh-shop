/**
 * Editor-Registrierung der Shop-Blocks. Buildless über die window.wp.*-Globals.
 * Die Block-Metadaten (Attribute) kommen aus den lokalisierten block.json-Objekten,
 * damit sie nicht doppelt gepflegt werden. Dynamische Blocks: save gibt null zurück,
 * die Vorschau rendert der Server (ServerSideRender).
 */
( function ( wp, data ) {
	if ( ! wp || ! wp.blocks || ! data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var getBlockType = wp.blocks.getBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;

	function preview( block, attributes ) {
		return el( ServerSideRender, { block: block, attributes: attributes } );
	}

	function editGrid( props ) {
		var a = props.attributes;
		var categories = [ { value: '', label: __( 'Alle Kategorien', 'rh-shop' ) } ].concat( data.categories || [] );

		return el(
			Fragment,
			{},
			el(
				InspectorControls,
				{},
				el(
					c.PanelBody,
					{ title: __( 'Raster', 'rh-shop' ) },
					el( c.RangeControl, {
						label: __( 'Spalten', 'rh-shop' ),
						min: 1,
						max: 6,
						value: a.columns,
						onChange: function ( v ) {
							props.setAttributes( { columns: v || 3 } );
						},
					} ),
					el( c.RangeControl, {
						label: __( 'Max. Produkte', 'rh-shop' ),
						min: 1,
						max: 48,
						value: a.limit,
						onChange: function ( v ) {
							props.setAttributes( { limit: v || 12 } );
						},
					} ),
					el( c.SelectControl, {
						label: __( 'Kategorie', 'rh-shop' ),
						value: a.category,
						options: categories,
						onChange: function ( v ) {
							props.setAttributes( { category: v } );
						},
					} )
				)
			),
			el( 'div', useBlockProps(), preview( 'rh-shop/product-grid', a ) )
		);
	}

	function editSingle( props ) {
		var a = props.attributes;
		var products = [ { value: 0, label: __( 'Produkt wählen…', 'rh-shop' ) } ].concat( data.products || [] );

		return el(
			Fragment,
			{},
			el(
				InspectorControls,
				{},
				el(
					c.PanelBody,
					{ title: __( 'Produkt', 'rh-shop' ) },
					el( c.SelectControl, {
						label: __( 'Produkt', 'rh-shop' ),
						value: a.productId,
						options: products,
						onChange: function ( v ) {
							props.setAttributes( { productId: parseInt( v, 10 ) || 0 } );
						},
					} )
				)
			),
			el(
				'div',
				useBlockProps(),
				a.productId
					? preview( 'rh-shop/product-single', a )
					: el( c.Placeholder, {
						icon: 'products',
						label: __( 'Einzelprodukt', 'rh-shop' ),
						instructions: __( 'Wähle rechts in den Block-Einstellungen ein Produkt aus.', 'rh-shop' ),
					} )
			)
		);
	}

	/**
	 * Für die Blocks, die im Frontend automatisch gefüllt werden (Warenkorb, Kasse,
	 * Widerruf, Kauf-Box): eine echte Server-Vorschau mit Musterdaten (?rhshop_preview),
	 * damit man sich das Ergebnis vorstellen kann, plus eine Info-Notice, die erklärt,
	 * dass es eine Beispielansicht ist und hier nichts einzustellen ist.
	 */
	function previewWithNote( block, note ) {
		return function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el( c.Notice, { status: 'info', isDismissible: false, className: 'rhshop-editor-note' }, note ),
				el( ServerSideRender, {
					block: block,
					attributes: props.attributes,
					urlQueryArgs: { rhshop_preview: 1 },
				} )
			);
		};
	}

	// Icon-Pfade (gespiegelt zu CartWidget::iconSvg in PHP) für die Editor-Vorschau.
	var CW_ICONS = {
		bag: [
			el( 'path', { key: 'a', d: 'M6 8h12l-1 12H7L6 8Z' } ),
			el( 'path', { key: 'b', d: 'M9 8V6a3 3 0 0 1 6 0v2' } ),
		],
		cart: [
			el( 'circle', { key: 'a', cx: 9, cy: 20, r: 1.4 } ),
			el( 'circle', { key: 'b', cx: 18, cy: 20, r: 1.4 } ),
			el( 'path', { key: 'c', d: 'M3 4h2l2.4 12.2A1.5 1.5 0 0 0 8.9 17.4H18a1.5 1.5 0 0 0 1.5-1.2L21 8H6' } ),
		],
		basket: [
			el( 'path', { key: 'a', d: 'M5 9h14l-1.3 10.2A1.5 1.5 0 0 1 16.2 20.5H7.8a1.5 1.5 0 0 1-1.5-1.3L5 9Z' } ),
			el( 'path', { key: 'b', d: 'M9 9 12 4l3 5' } ),
		],
	};

	function cwSvg( name ) {
		return el(
			'svg',
			{
				viewBox: '0 0 24 24',
				width: '1.35em',
				height: '1.35em',
				fill: 'none',
				stroke: 'currentColor',
				strokeWidth: 1.6,
				strokeLinecap: 'round',
				strokeLinejoin: 'round',
				'aria-hidden': true,
			},
			CW_ICONS[ name ] || CW_ICONS.bag
		);
	}

	/**
	 * Warenkorb-Widget: Trigger-Vorschau im Canvas (Icon/Wort/Badge nach Einstellung)
	 * plus die Einstellungen im Inspector. Kein ServerSideRender, weil der Block im
	 * Frontend ein Overlay an den <body> hängt, das im Editor nichts zu suchen hat.
	 */
	function editCartWidget( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var showIcon = a.display !== 'word';
		var showWord = a.display !== 'icon';

		var trigger = el(
			'span',
			{ className: 'rhshop-cw__trigger', style: { display: 'inline-flex', alignItems: 'center', gap: '0.4em' } },
			showIcon ? el( 'span', { className: 'rhshop-cw__icon' }, cwSvg( a.icon ) ) : null,
			showWord ? el( 'span', { className: 'rhshop-cw__label' }, a.label || __( 'Warenkorb', 'rh-shop' ) ) : null,
			a.showBadge ? el( 'span', { className: 'rhshop-cw__badge' }, a.hideZero ? '2' : '0' ) : null
		);

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					c.PanelBody,
					{ title: __( 'Warenkorb-Widget', 'rh-shop' ), initialOpen: true },
					el( c.SelectControl, {
						label: __( 'Anzeige', 'rh-shop' ),
						value: a.display,
						options: [
							{ label: __( 'Nur Symbol', 'rh-shop' ), value: 'icon' },
							{ label: __( 'Nur Wort', 'rh-shop' ), value: 'word' },
							{ label: __( 'Symbol und Wort', 'rh-shop' ), value: 'both' },
						],
						onChange: function ( v ) {
							set( { display: v } );
						},
					} ),
					showIcon
						? el( c.SelectControl, {
								label: __( 'Symbol', 'rh-shop' ),
								value: a.icon,
								options: [
									{ label: __( 'Tasche', 'rh-shop' ), value: 'bag' },
									{ label: __( 'Einkaufswagen', 'rh-shop' ), value: 'cart' },
									{ label: __( 'Korb', 'rh-shop' ), value: 'basket' },
								],
								onChange: function ( v ) {
									set( { icon: v } );
								},
						  } )
						: null,
					showWord
						? el( c.TextControl, {
								label: __( 'Wort', 'rh-shop' ),
								value: a.label,
								onChange: function ( v ) {
									set( { label: v } );
								},
						  } )
						: null,
					el( c.ToggleControl, {
						label: __( 'Anzahl-Badge zeigen', 'rh-shop' ),
						checked: a.showBadge,
						onChange: function ( v ) {
							set( { showBadge: v } );
						},
					} ),
					a.showBadge
						? el( c.ToggleControl, {
								label: __( 'Badge bei 0 verstecken', 'rh-shop' ),
								checked: a.hideZero,
								onChange: function ( v ) {
									set( { hideZero: v } );
								},
						  } )
						: null,
					el( c.ToggleControl, {
						label: __( 'Beim In-den-Warenkorb-Legen öffnen', 'rh-shop' ),
						checked: a.openOnAdd,
						onChange: function ( v ) {
							set( { openOnAdd: v } );
						},
					} )
				)
			),
			el( 'div', useBlockProps( { className: 'rhshop-cw' } ), trigger )
		);
	}

	var edits = {
		'rh-shop/product-grid': editGrid,
		'rh-shop/product-single': editSingle,
		'rh-shop/cart-widget': editCartWidget,
		'rh-shop/buy-box': previewWithNote(
			'rh-shop/buy-box',
			__( 'Beispielansicht mit einem deiner Produkte. Im Frontend zeigt der Block das Produkt der jeweiligen Detailseite.', 'rh-shop' )
		),
		'rh-shop/cart-items': previewWithNote(
			'rh-shop/cart-items',
			__( 'Beispielansicht der Warenkorb-Positionen. Im Frontend die Artikel des Besuchers.', 'rh-shop' )
		),
		'rh-shop/cart-summary': previewWithNote(
			'rh-shop/cart-summary',
			__( 'Beispielansicht der Warenkorb-Summe mit Zur-Kasse-Button.', 'rh-shop' )
		),
		'rh-shop/checkout-summary': previewWithNote(
			'rh-shop/checkout-summary',
			__( 'Beispielansicht der Bestellübersicht. Im Frontend die echten Artikel des Besuchers.', 'rh-shop' )
		),
		'rh-shop/checkout-form': previewWithNote(
			'rh-shop/checkout-form',
			__( 'Beispielansicht des Kassen-Formulars mit Gesamtpreis, Pflichtangaben und Stripe-Zahlung.', 'rh-shop' )
		),
		'rh-shop/widerruf': previewWithNote(
			'rh-shop/widerruf',
			__( 'So sieht das Widerrufs-Formular für den Kunden aus. Hier musst du nichts einstellen.', 'rh-shop' )
		),
	};

	( data.meta || [] ).forEach( function ( meta ) {
		if ( ! meta || ! meta.name || getBlockType( meta.name ) ) {
			return;
		}
		registerBlockType( meta, {
			edit: edits[ meta.name ] || function () {
				return null;
			},
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp, window.rhShopBlocks );
