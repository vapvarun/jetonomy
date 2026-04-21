( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var registerBlockType   = blocks.registerBlockType;
	var createElement       = element.createElement;
	var Fragment            = element.Fragment;
	var useBlockProps       = blockEditor.useBlockProps;
	var InspectorControls   = blockEditor.InspectorControls;
	var PanelBody           = components.PanelBody;
	var SelectControl       = components.SelectControl;
	var TextControl         = components.TextControl;
	var __                  = i18n.__;

	registerBlockType( 'jetonomy/compose-topic', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var mode          = attributes.mode || 'picker';
			var spaceId       = attributes.spaceId || 0;
			var types         = attributes.types || 'topic,question,idea';

			var inspector = createElement( InspectorControls, {},
				createElement( PanelBody, { title: __( 'Compose Topic', 'jetonomy' ), initialOpen: true },
					createElement( SelectControl, {
						label: __( 'Mode', 'jetonomy' ),
						value: mode,
						options: [
							{ label: __( 'Space picker (user chooses)', 'jetonomy' ), value: 'picker' },
							{ label: __( 'Fixed space', 'jetonomy' ), value: 'fixed' },
						],
						onChange: function ( next ) {
							setAttributes( { mode: next } );
						},
					} ),
					'fixed' === mode ? createElement( TextControl, {
						label: __( 'Space ID', 'jetonomy' ),
						type: 'number',
						value: spaceId || '',
						onChange: function ( next ) {
							var parsed = parseInt( next, 10 );
							setAttributes( { spaceId: isNaN( parsed ) ? 0 : parsed } );
						},
						help: __( 'Numeric ID of the space to post into. Invalid IDs fall back to picker at render time.', 'jetonomy' ),
					} ) : null,
					createElement( TextControl, {
						label: __( 'Allowed types', 'jetonomy' ),
						value: types,
						onChange: function ( next ) {
							setAttributes( { types: next } );
						},
						help: __( 'Comma-separated: topic,question,idea', 'jetonomy' ),
					} )
				)
			);

			var preview = createElement( 'div',
				useBlockProps( { className: 'jt-compose-topic-preview' } ),
				createElement( 'div', { className: 'jt-compose-topic-preview-frame' },
					createElement( 'p', { className: 'jt-compose-topic-preview-badge' },
						'fixed' === mode
							? __( 'Compose Topic: fixed space', 'jetonomy' ) + ( spaceId ? ' #' + spaceId : '' )
							: __( 'Compose Topic: space picker', 'jetonomy' )
					),
					createElement( 'div', { className: 'jt-compose-topic-preview-mock' },
						createElement( 'div', { className: 'jt-compose-topic-preview-field' } ),
						createElement( 'div', { className: 'jt-compose-topic-preview-field jt-compose-topic-preview-field-body' } ),
						createElement( 'div', { className: 'jt-compose-topic-preview-button' },
							__( 'Post topic', 'jetonomy' )
						)
					)
				)
			);

			return createElement( Fragment, {}, inspector, preview );
		},
		save: function () {
			// Server-rendered — save returns null so the editor stores only attributes.
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
