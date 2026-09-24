( function () {
	'use strict';

	var el = window.wp.element.createElement;
	var __ = window.wp.i18n.__;
	var sprintf = window.wp.i18n.sprintf;

	function edit( props ) {
		var height = 'string' === typeof props.attributes.height ? props.attributes.height : '';
		var zoom = 'string' === typeof props.attributes.zoom ? props.attributes.zoom : '';
		var detail = '';

		if ( '' !== height && '' !== zoom ) {
			detail = sprintf( __( 'Height: %1$spx, Zoom: %2$s', 'low-dealer-locator' ), height, zoom );
		} else if ( '' !== height ) {
			detail = sprintf( __( 'Height: %spx', 'low-dealer-locator' ), height );
		} else if ( '' !== zoom ) {
			detail = sprintf( __( 'Zoom: %s', 'low-dealer-locator' ), zoom );
		}

		return el(
			window.wp.element.Fragment,
			null,
			el(
				window.wp.blockEditor.InspectorControls,
				null,
				el(
					window.wp.components.PanelBody,
					{ title: __( 'Map settings', 'low-dealer-locator' ) },
					el( window.wp.components.TextControl, {
						label: __( 'Map height (px)', 'low-dealer-locator' ),
						help: __( 'Leave blank to use the Locator settings. 200 to 1200.', 'low-dealer-locator' ),
						type: 'number',
						value: height,
						onChange: function ( value ) {
							props.setAttributes( { height: value } );
						},
					} ),
					el( window.wp.components.TextControl, {
						label: __( 'Default zoom', 'low-dealer-locator' ),
						help: __( 'Leave blank to use the Locator settings. 1 to 18.', 'low-dealer-locator' ),
						type: 'number',
						value: zoom,
						onChange: function ( value ) {
							props.setAttributes( { zoom: value } );
						},
					} )
				)
			),
			el(
				'div',
				window.wp.blockEditor.useBlockProps(),
				el( 'h3', null, __( 'Dealer Locator', 'low-dealer-locator' ) ),
				el( 'p', null, __( 'The map and search box appear on the published page.', 'low-dealer-locator' ) ),
				'' !== detail ? el( 'p', null, detail ) : null
			)
		);
	}

	window.wp.blocks.registerBlockType( 'low-dealer-locator/locator', {
		edit: edit,
		save: function () {
			return null;
		},
	} );
}() );
