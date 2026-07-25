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

	var edits = {
		'rh-shop/product-grid': editGrid,
		'rh-shop/product-single': editSingle,
		'rh-shop/buy-box': previewWithNote(
			'rh-shop/buy-box',
			__( 'Beispielansicht mit einem deiner Produkte. Im Frontend zeigt der Block das Produkt der jeweiligen Detailseite.', 'rh-shop' )
		),
		'rh-shop/cart': previewWithNote(
			'rh-shop/cart',
			__( 'Beispielansicht. Im Frontend zeigt der Warenkorb die Artikel des Besuchers.', 'rh-shop' )
		),
		'rh-shop/checkout': previewWithNote(
			'rh-shop/checkout',
			__( 'Beispielansicht der Kasse. Im Frontend mit den echten Artikeln des Besuchers und der Stripe-Zahlung.', 'rh-shop' )
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
