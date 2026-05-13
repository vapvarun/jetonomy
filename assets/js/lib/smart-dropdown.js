/**
 * Jetonomy Smart Dropdown
 *
 * Lightweight, framework-free positioner for trigger/panel pairs.
 * Handles flip-on-overflow (vertical), shift-on-overflow (horizontal),
 * RTL placement swap, outside-click + Escape close, and "group" exclusivity
 * (opening one in a group closes any other in the same group).
 *
 *   const dd = window.jetonomySmartDropdown( trigger, panel, {
 *     placement:      'bottom-end',  // bottom-start | bottom-end | top-start | top-end
 *     offset:         6,
 *     closeOnOutside: true,
 *     closeOnEscape:  true,
 *     group:          null,          // string — same group closes prior on open
 *     flipPadding:    8,
 *     onOpen:         null,
 *     onClose:        null,
 *   } );
 *   dd.open(); dd.close(); dd.toggle(); dd.destroy(); dd.isOpen;
 *
 * Foundation for WS3-A; callsites migrate in WS3-B.
 */
( function ( window, document ) {
	'use strict';

	var groupMap = new Map();

	function isRtl() {
		try {
			return getComputedStyle( document.documentElement ).direction === 'rtl';
		} catch ( _e ) {
			return false;
		}
	}

	function clamp( v, lo, hi ) {
		if ( hi < lo ) return lo;
		return v < lo ? lo : v > hi ? hi : v;
	}

	function smartDropdown( triggerEl, panelEl, opts ) {
		opts = opts || {};
		var placement = opts.placement || 'bottom-end';
		var offset = typeof opts.offset === 'number' ? opts.offset : 6;
		var flipPadding = typeof opts.flipPadding === 'number' ? opts.flipPadding : 8;
		var closeOnOutside = opts.closeOnOutside !== false;
		var closeOnEscape = opts.closeOnEscape !== false;
		var group = opts.group || null;
		var onOpen = typeof opts.onOpen === 'function' ? opts.onOpen : null;
		var onClose = typeof opts.onClose === 'function' ? opts.onClose : null;

		var open = false;
		var rafId = 0;
		var destroyed = false;

		function reposition() {
			if ( ! open || ! triggerEl || ! panelEl ) return;
			// Show invisibly so we can measure.
			panelEl.style.position = 'fixed';
			panelEl.style.display = 'block';
			panelEl.style.visibility = 'hidden';
			panelEl.style.top = '0px';
			panelEl.style.left = '0px';

			var tr = triggerEl.getBoundingClientRect();
			var pr = panelEl.getBoundingClientRect();
			var pw = pr.width;
			var ph = pr.height;

			// Parse placement: vertical-horizontal.
			var parts = placement.split( '-' );
			var vertical = parts[ 0 ] === 'top' ? 'top' : 'bottom';
			var horizontal = parts[ 1 ] === 'start' ? 'start' : 'end';
			if ( isRtl() ) {
				horizontal = horizontal === 'start' ? 'end' : 'start';
			}

			// Initial position.
			var top = vertical === 'bottom' ? tr.bottom + offset : tr.top - ph - offset;
			var left = horizontal === 'end' ? tr.right - pw : tr.left;

			// Flip vertical.
			if ( vertical === 'bottom' && top + ph > window.innerHeight - flipPadding ) {
				top = tr.top - ph - offset;
			}
			if ( top < flipPadding ) {
				// Force bottom if forced off-screen at top.
				top = tr.bottom + offset;
			}

			// Shift horizontal.
			left = clamp( left, flipPadding, window.innerWidth - pw - flipPadding );

			panelEl.style.top = Math.round( top ) + 'px';
			panelEl.style.left = Math.round( left ) + 'px';
			panelEl.style.visibility = '';
		}

		function rafReposition() {
			if ( rafId ) return;
			rafId = window.requestAnimationFrame( function () {
				rafId = 0;
				reposition();
			} );
		}

		function maybeCloseOutside( e ) {
			if ( ! closeOnOutside || ! open ) return;
			var target = e.target;
			if ( ! target ) return;
			if ( triggerEl && triggerEl.contains( target ) ) return;
			if ( panelEl && panelEl.contains( target ) ) return;
			doClose();
		}

		function escHandler( e ) {
			if ( ! closeOnEscape || ! open ) return;
			if ( e.key === 'Escape' || e.keyCode === 27 ) {
				doClose();
			}
		}

		function attach() {
			window.addEventListener( 'resize', rafReposition, { passive: true } );
			window.addEventListener( 'scroll', rafReposition, { capture: true, passive: true } );
			document.addEventListener( 'click', maybeCloseOutside, true );
			document.addEventListener( 'keydown', escHandler );
		}

		function detach() {
			window.removeEventListener( 'resize', rafReposition, { passive: true } );
			window.removeEventListener( 'scroll', rafReposition, { capture: true, passive: true } );
			document.removeEventListener( 'click', maybeCloseOutside, true );
			document.removeEventListener( 'keydown', escHandler );
		}

		function doOpen() {
			if ( destroyed || open || ! triggerEl || ! panelEl ) return;
			if ( group ) {
				var prev = groupMap.get( group );
				if ( prev && prev !== instance ) prev.close();
			}
			open = true;
			reposition();
			attach();
			if ( group ) groupMap.set( group, instance );
			if ( onOpen ) {
				try { onOpen(); } catch ( _e ) {}
			}
		}

		function doClose() {
			if ( ! open ) return;
			open = false;
			if ( panelEl ) panelEl.style.display = 'none';
			detach();
			if ( group && groupMap.get( group ) === instance ) groupMap.delete( group );
			if ( onClose ) {
				try { onClose(); } catch ( _e ) {}
			}
		}

		function toggle() {
			if ( open ) doClose(); else doOpen();
		}

		function destroy() {
			doClose();
			destroyed = true;
			triggerEl = null;
			panelEl = null;
		}

		var instance = {
			open: doOpen,
			close: doClose,
			toggle: toggle,
			destroy: destroy,
			get isOpen() { return open; },
		};
		return instance;
	}

	window.jetonomySmartDropdown = smartDropdown;
} )( window, document );
