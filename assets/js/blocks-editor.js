/**
 * Jetonomy block editor registrations.
 *
 * Server-rendered blocks need a JS-side `registerBlockType()` to appear in
 * the inserter — without it, `register_block_type()` registers the type with
 * the REST API but the block editor never learns about it. (Discovered 2026-04-28
 * via support ticket: only Compose Topic showed up in the inserter because it
 * was the only block with an editor_script.)
 *
 * One file, one registerBlockType per block. Each `edit` returns a static
 * preview card (the real render lives server-side in class-blocks.php), so
 * dropping any of these blocks in the editor is fast and safe — no live REST.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var registerBlockType = blocks.registerBlockType;
	var createElement     = element.createElement;
	var Fragment          = element.Fragment;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody         = components.PanelBody;
	var TextControl       = components.TextControl;
	var ToggleControl     = components.ToggleControl;
	var SelectControl     = components.SelectControl;
	var __                = i18n.__;

	function previewCard( label, hint, props ) {
		return createElement( 'div',
			useBlockProps( { className: 'jt-block-preview' } ),
			createElement( 'div', { className: 'jt-block-preview-frame' },
				createElement( 'p', { className: 'jt-block-preview-label' }, label ),
				hint ? createElement( 'p', { className: 'jt-block-preview-hint' }, hint ) : null
			)
		);
	}

	// Opt-in flags silence WP 6.7+ deprecation warnings and adopt the new
	// component defaults that ship as the only option in WP 7.0/7.1.
	var modernSizing = { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true };

	function numberControl( label, value, onChange, help ) {
		return createElement( TextControl, Object.assign( {}, modernSizing, {
			label: label,
			type: 'number',
			value: value || '',
			onChange: function ( next ) {
				var parsed = parseInt( next, 10 );
				onChange( isNaN( parsed ) ? 0 : parsed );
			},
			help: help || null,
		} ) );
	}

	function textControl( label, value, onChange, help ) {
		return createElement( TextControl, Object.assign( {}, modernSizing, {
			label: label,
			value: value || '',
			onChange: onChange,
			help: help || null,
		} ) );
	}

	function selectControl( label, value, options, onChange ) {
		return createElement( SelectControl, Object.assign( {}, modernSizing, {
			label: label,
			value: value,
			options: options,
			onChange: onChange,
		} ) );
	}

	function toggleControl( label, checked, onChange, help ) {
		return createElement( ToggleControl, Object.assign( {}, modernSizing, {
			label: label,
			checked: !! checked,
			onChange: onChange,
			help: help || null,
		} ) );
	}

	/* Forum Feed */
	registerBlockType( 'jetonomy/forum-feed', {
		edit: function ( props ) {
			var a    = props.attributes;
			var set  = props.setAttributes;
			var sort = a.sort || 'latest';
			var hint = a.spaceId
				? __( 'Filtered to space #', 'jetonomy' ) + a.spaceId
				: __( 'Latest topics from all spaces', 'jetonomy' );
			return createElement( Fragment, {},
				createElement( InspectorControls, {},
					createElement( PanelBody, { title: __( 'Forum Feed', 'jetonomy' ), initialOpen: true },
						numberControl( __( 'Count', 'jetonomy' ), a.count, function ( v ) { set( { count: v } ); } ),
						numberControl( __( 'Space ID (0 = all)', 'jetonomy' ), a.spaceId, function ( v ) { set( { spaceId: v } ); } ),
						selectControl(
							__( 'Sort', 'jetonomy' ),
							sort,
							[
								{ label: __( 'Latest', 'jetonomy' ), value: 'latest' },
								{ label: __( 'Top voted', 'jetonomy' ), value: 'top' },
							],
							function ( next ) { set( { sort: next } ); }
						),
						toggleControl( __( 'Show header', 'jetonomy' ), a.showHeader, function ( v ) { set( { showHeader: v } ); } ),
						a.showHeader ? textControl( __( 'Header title (optional)', 'jetonomy' ), a.title, function ( v ) { set( { title: v } ); } ) : null
					)
				),
				previewCard( __( 'Jetonomy · Forum Feed', 'jetonomy' ), hint )
			);
		},
		save: function () { return null; },
	} );

	/* Trending */
	registerBlockType( 'jetonomy/trending', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;
			var hint = ( a.window || 7 ) + ' ' + __( 'day window', 'jetonomy' );
			return createElement( Fragment, {},
				createElement( InspectorControls, {},
					createElement( PanelBody, { title: __( 'Trending', 'jetonomy' ), initialOpen: true },
						numberControl( __( 'Count', 'jetonomy' ), a.count, function ( v ) { set( { count: v } ); } ),
						numberControl( __( 'Space ID (0 = all)', 'jetonomy' ), a.spaceId, function ( v ) { set( { spaceId: v } ); } ),
						numberControl( __( 'Window (days)', 'jetonomy' ), a.window, function ( v ) { set( { window: v } ); } ),
						toggleControl( __( 'Show header', 'jetonomy' ), a.showHeader, function ( v ) { set( { showHeader: v } ); } ),
						a.showHeader ? textControl( __( 'Header title (optional)', 'jetonomy' ), a.title, function ( v ) { set( { title: v } ); } ) : null
					)
				),
				previewCard( __( 'Jetonomy · Trending Topics', 'jetonomy' ), hint )
			);
		},
		save: function () { return null; },
	} );

	/* Space List */
	registerBlockType( 'jetonomy/space-list', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;
			return createElement( Fragment, {},
				createElement( InspectorControls, {},
					createElement( PanelBody, { title: __( 'Space List', 'jetonomy' ), initialOpen: true },
						numberControl( __( 'Count', 'jetonomy' ), a.count, function ( v ) { set( { count: v } ); } ),
						numberControl( __( 'Category ID (0 = all)', 'jetonomy' ), a.categoryId, function ( v ) { set( { categoryId: v } ); } )
					)
				),
				previewCard(
					__( 'Jetonomy · Space List', 'jetonomy' ),
					a.categoryId
						? __( 'Filtered to category #', 'jetonomy' ) + a.categoryId
						: __( 'All public spaces', 'jetonomy' )
				)
			);
		},
		save: function () { return null; },
	} );

	/* Leaderboard */
	registerBlockType( 'jetonomy/leaderboard', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;
			return createElement( Fragment, {},
				createElement( InspectorControls, {},
					createElement( PanelBody, { title: __( 'Leaderboard', 'jetonomy' ), initialOpen: true },
						numberControl( __( 'Count', 'jetonomy' ), a.count, function ( v ) { set( { count: v } ); } )
					)
				),
				previewCard( __( 'Jetonomy · Leaderboard', 'jetonomy' ), __( 'Top members by reputation', 'jetonomy' ) )
			);
		},
		save: function () { return null; },
	} );

	/* Navigation */
	registerBlockType( 'jetonomy/navigation', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;
			return createElement( Fragment, {},
				createElement( InspectorControls, {},
					createElement( PanelBody, { title: __( 'Navigation', 'jetonomy' ), initialOpen: true },
						toggleControl( __( 'Show category headings', 'jetonomy' ), a.showCategoryHeadings, function ( v ) { set( { showCategoryHeadings: v } ); } ),
						toggleControl( __( 'Collapsible sections', 'jetonomy' ), a.collapsible, function ( v ) { set( { collapsible: v } ); } ),
						toggleControl( __( 'Show post counts', 'jetonomy' ), a.showPostCount, function ( v ) { set( { showPostCount: v } ); } ),
						toggleControl( __( 'Hide empty categories', 'jetonomy' ), a.hideEmptyCategories, function ( v ) { set( { hideEmptyCategories: v } ); } ),
						textControl( __( 'Title (optional)', 'jetonomy' ), a.title, function ( v ) { set( { title: v } ); } )
					)
				),
				previewCard( __( 'Jetonomy · Navigation', 'jetonomy' ), __( 'Categories → spaces tree, permission-aware', 'jetonomy' ) )
			);
		},
		save: function () { return null; },
	} );

	/* User Panel */
	registerBlockType( 'jetonomy/user-panel', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;
			return createElement( Fragment, {},
				createElement( InspectorControls, {},
					createElement( PanelBody, { title: __( 'User Panel', 'jetonomy' ), initialOpen: true },
						textControl(
							__( 'Title (optional)', 'jetonomy' ),
							a.title,
							function ( v ) { set( { title: v } ); },
							__( 'Defaults to "Hi, {display name}".', 'jetonomy' )
						)
					)
				),
				previewCard( __( 'Jetonomy · User Panel', 'jetonomy' ), __( 'Logged-in profile card. Empty for guests.', 'jetonomy' ) )
			);
		},
		save: function () { return null; },
	} );

	/* Login */
	registerBlockType( 'jetonomy/login', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;
			return createElement( Fragment, {},
				createElement( InspectorControls, {},
					createElement( PanelBody, { title: __( 'Login', 'jetonomy' ), initialOpen: true },
						textControl( __( 'Title (optional)', 'jetonomy' ), a.title, function ( v ) { set( { title: v } ); } ),
						toggleControl(
							__( 'Show register tab', 'jetonomy' ),
							a.showRegister,
							function ( v ) { set( { showRegister: v } ); },
							__( 'Tab only appears if site allows new registrations.', 'jetonomy' )
						)
					)
				),
				previewCard( __( 'Jetonomy · Login', 'jetonomy' ), __( 'Quick login + register. Empty for signed-in viewers.', 'jetonomy' ) )
			);
		},
		save: function () { return null; },
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
