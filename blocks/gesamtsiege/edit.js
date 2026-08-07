( function ( wp ) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType( 'lsg-bestenliste/gesamtsiege', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Standardfilter', 'lsg-bestenliste' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Standard-Jahr (0 = aktuelles Jahr)', 'lsg-bestenliste' ),
							type: 'number',
							value: attributes.defaultYear,
							onChange: function ( value ) {
								setAttributes( { defaultYear: parseInt( value, 10 ) || 0 } );
							}
						} )
					)
				),
				el( ServerSideRender, {
					block: 'lsg-bestenliste/gesamtsiege',
					attributes: attributes
				} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
