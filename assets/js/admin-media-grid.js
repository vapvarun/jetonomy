/**
 * Community-uploads filter for the Media Library GRID view.
 *
 * The list view (upload.php?mode=list) gets its "Show/Hide community uploads"
 * dropdown server-side via `restrict_manage_posts`, but that hook never fires on
 * the JavaScript grid view. This script injects the same dropdown into the grid
 * toolbar and persists the choice in a root-path cookie so the admin-ajax
 * `query-attachments` requests (which carry no GET params) still respect it —
 * `Media_Library::show_community()` reads the cookie server-side.
 *
 * @package Jetonomy
 */
( function () {
	'use strict';

	var COOKIE = 'jetonomy_show_community';
	var i18n   = window.jetonomyMediaGrid || {};

	function getCookie( name ) {
		var match = document.cookie.match( '(?:^|; )' + name + '=([^;]*)' );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	}

	function setCookie( name, value ) {
		var expires = new Date();
		expires.setTime( expires.getTime() + 365 * 24 * 60 * 60 * 1000 );
		// Root path so the cookie is sent with admin-ajax query-attachments calls.
		document.cookie = name + '=' + encodeURIComponent( value ) +
			'; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
	}

	function buildFilter() {
		var show = '1' === getCookie( COOKIE );

		var wrap = document.createElement( 'span' );
		wrap.className = 'jetonomy-media-grid-filter';

		var label = document.createElement( 'label' );
		label.className = 'screen-reader-text';
		label.htmlFor = 'jetonomy-community-media-grid';
		label.textContent = i18n.label || 'Community uploads';

		var select = document.createElement( 'select' );
		select.id = 'jetonomy-community-media-grid';
		select.className = 'attachment-filters jetonomy-community-media-grid';
		select.appendChild( new Option( i18n.hide || 'Hide community uploads', '0', ! show, ! show ) );
		select.appendChild( new Option( i18n.show || 'Show community uploads', '1', show, show ) );

		select.addEventListener( 'change', function () {
			setCookie( COOKIE, '1' === select.value ? '1' : '0' );
			// The grid renders via Backbone over admin-ajax; a reload re-runs the
			// query with the new cookie applied — same UX as the list-view filter.
			window.location.reload();
		} );

		wrap.appendChild( label );
		wrap.appendChild( select );
		return wrap;
	}

	function inject() {
		var toolbar = document.querySelector( '.media-toolbar-secondary' );
		if ( ! toolbar ) {
			return false;
		}
		if ( ! toolbar.querySelector( '.jetonomy-media-grid-filter' ) ) {
			toolbar.appendChild( buildFilter() );
		}
		return true;
	}

	function watch() {
		if ( inject() ) {
			return;
		}
		// The grid toolbar mounts asynchronously via Backbone — observe the DOM
		// until it appears, then stop.
		var observer = new MutationObserver( function () {
			if ( inject() ) {
				observer.disconnect();
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		// Safety stop so the observer never lingers.
		window.setTimeout( function () {
			observer.disconnect();
		}, 10000 );
	}

	if ( 'loading' !== document.readyState ) {
		watch();
	} else {
		document.addEventListener( 'DOMContentLoaded', watch );
	}
}() );
