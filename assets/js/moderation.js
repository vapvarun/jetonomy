/**
 * Moderation queue resolver — shared by per-space and admin queues.
 *
 * Contract:
 *   Each flag card has  data-flag-id + data-resolve-endpoint (prefix).
 *   Each resolve button has  .jt-mod-resolve + data-flag-id + data-resolution.
 *   Endpoint shape:  {prefix}{flag_id}/resolve
 *   Body:            { status: 'valid' | 'dismissed' }
 *
 * On success the card is removed. On failure we revert the disabled state
 * and flash an inline error so moderators never wonder whether it worked.
 */
(function () {
	'use strict';

	var data = window.jetonomyData || {};
	if ( ! data.restNonce ) {
		return;
	}

	function findCard( btn ) {
		return btn.closest ? btn.closest( '.jt-mod-flag' ) : null;
	}

	function showError( card, message ) {
		var existing = card.querySelector( '.jt-mod-flag-error' );
		if ( existing ) {
			existing.remove();
		}
		var p = document.createElement( 'p' );
		p.className = 'jt-mod-flag-error';
		p.setAttribute( 'role', 'alert' );
		p.textContent = message;
		card.appendChild( p );
	}

	function removeCard( card ) {
		var container = card.parentNode;
		card.remove();
		if ( container && ! container.querySelector( '.jt-mod-flag' ) ) {
			var wrapper = document.createElement( 'div' );
			wrapper.className = 'jt-empty';
			var msg = document.createElement( 'div' );
			msg.className = 'jt-empty-text';
			msg.textContent = ( data.i18n && data.i18n.queueClean ) || 'Queue cleared.';
			wrapper.appendChild( msg );
			container.parentNode.replaceChild( wrapper, container );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest ? event.target.closest( '.jt-mod-resolve' ) : null;
		if ( ! btn ) {
			return;
		}
		event.preventDefault();

		var card = findCard( btn );
		if ( ! card ) {
			return;
		}

		var flagId     = btn.getAttribute( 'data-flag-id' );
		var resolution = btn.getAttribute( 'data-resolution' );
		var endpoint   = card.getAttribute( 'data-resolve-endpoint' );

		if ( ! flagId || ! resolution || ! endpoint ) {
			showError( card, ( data.i18n && data.i18n.resolveFailed ) || 'Could not resolve. Please refresh.' );
			return;
		}

		// Disable all action buttons in this card while the request is in flight.
		var buttons = card.querySelectorAll( 'button' );
		buttons.forEach( function ( b ) { b.disabled = true; } );

		// restFetch is path-based (it owns the restBase prefix), so the
		// inherited `endpoint` -- which is an absolute REST URL pinned by the
		// server template -- has to be trimmed back to the path segment
		// before handing it off. Falls back to the legacy fetch path when
		// restFetch isn't loaded so the queue keeps working on any future
		// page that ships moderation.js without jetonomy-rest.js.
		var resolvePath = endpoint + flagId + '/resolve';
		var base        = ( data && data.restBase ) ? String( data.restBase ).replace( /\/+$/, '' ) : '';
		if ( base && resolvePath.indexOf( base ) === 0 ) {
			resolvePath = resolvePath.slice( base.length );
		}

		var onSuccess = function () { removeCard( card ); };
		var onFailure = function () {
			buttons.forEach( function ( b ) { b.disabled = false; } );
			showError( card, ( data.i18n && data.i18n.resolveFailed ) || 'Could not resolve flag. Please try again.' );
		};

		if ( window.jetonomyRest && typeof window.jetonomyRest.restFetch === 'function' ) {
			window.jetonomyRest.restFetch( resolvePath, {
				method: 'POST',
				body: { status: resolution }
			} ).then( function ( res ) {
				if ( res.ok ) { onSuccess(); } else { onFailure(); }
			} );
		} else {
			fetch( endpoint + flagId + '/resolve', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   data.restNonce
				},
				body: JSON.stringify( { status: resolution } )
			} )
				.then( function ( res ) { if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); } return res.json(); } )
				.then( onSuccess )
				.catch( onFailure );
		}
	} );
} )();
