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
	 * Instruktiver Platzhalter für die Blocks, die im Frontend automatisch gefüllt
	 * werden (Warenkorb, Kasse, Widerruf, Kauf-Box). Statt eines kargen Textkastens
	 * die native Placeholder-Komponente: Icon, Titel und ein Satz, was der Block tut
	 * und dass hier nichts einzustellen ist.
	 */
	function placeholder( icon, label, instructions ) {
		return function () {
			return el(
				'div',
				useBlockProps(),
				el( c.Placeholder, { icon: icon, label: label, instructions: instructions } )
			);
		};
	}

	var edits = {
		'rh-shop/product-grid': editGrid,
		'rh-shop/product-single': editSingle,
		'rh-shop/buy-box': placeholder(
			'cart',
			__( 'Kauf-Box', 'rh-shop' ),
			__( 'Zeigt Preis, Varianten-Auswahl und den In-den-Warenkorb-Button des Produkts. Gehört ins Produkt-Template und wird im Frontend automatisch gefüllt, hier ist nichts einzustellen.', 'rh-shop' )
		),
		'rh-shop/cart': placeholder(
			'cart',
			__( 'Warenkorb', 'rh-shop' ),
			__( 'Diese Seite zeigt im Frontend automatisch den Warenkorb des Besuchers. Hier musst du nichts einstellen, die Vorschau siehst du erst im Frontend.', 'rh-shop' )
		),
		'rh-shop/checkout': placeholder(
			'money-alt',
			__( 'Kasse', 'rh-shop' ),
			__( 'Diese Seite zeigt im Frontend automatisch die Bestellübersicht, die Pflichtangaben und die Stripe-Zahlung. Hier musst du nichts einstellen.', 'rh-shop' )
		),
		'rh-shop/widerruf': placeholder(
			'edit-page',
			__( 'Vertrag widerrufen', 'rh-shop' ),
			__( 'Zeigt im Frontend das Widerrufs-Formular nach §356a (Name, Bestellnummer, E-Mail). Hier musst du nichts einstellen.', 'rh-shop' )
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
