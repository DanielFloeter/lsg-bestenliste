( function ( wp ) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	var DISTANCE_OPTIONS = [
		{ label: 'Alle', value: 'alle' },
		{ label: '5 km', value: '5km' },
		{ label: '10 km', value: '10km' },
		{ label: '15 km', value: '15km' },
		{ label: '20 km', value: '20km' },
		{ label: 'Halbmarathon', value: 'HM' },
		{ label: '25 km', value: '25km' },
		{ label: 'Marathon', value: 'Marathon' },
		{ label: '50 km', value: '50km' },
		{ label: '100 km', value: '100km' },
		{ label: '6 Stunden', value: '6h' },
		{ label: '12 Stunden', value: '12h' },
		{ label: '24 Stunden', value: '24h' }
	];

	registerBlockType( 'lsg-bestenliste/ewige-bestenliste', {
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
						el( SelectControl, {
							label: __( 'Geschlecht', 'lsg-bestenliste' ),
							value: attributes.defaultGender,
							options: [
								{ label: 'Alle', value: 'alle' },
								{ label: 'Männer', value: 'm' },
								{ label: 'Frauen', value: 'f' }
							],
							onChange: function ( value ) {
								setAttributes( { defaultGender: value } );
							}
						} ),
						el( TextControl, {
							label: __( 'Altersklasse (z.B. 45, hk oder "alle")', 'lsg-bestenliste' ),
							value: attributes.defaultAk,
							onChange: function ( value ) {
								setAttributes( { defaultAk: value || 'alle' } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Distanz', 'lsg-bestenliste' ),
							value: attributes.defaultDistance,
							options: DISTANCE_OPTIONS,
							onChange: function ( value ) {
								setAttributes( { defaultDistance: value } );
							}
						} )
					)
				),
				el( ServerSideRender, {
					block: 'lsg-bestenliste/ewige-bestenliste',
					attributes: attributes
				} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
