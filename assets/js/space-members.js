/**
 * Space-members role dropdown handler.
 *
 * Only rendered for space admins. When they change a member's role via the
 * <select class="jt-member-role-select">, we PATCH the existing REST
 * endpoint (spaces/:id/members/:user_id) and update the in-page badge
 * without a reload. Reverts on failure.
 */
(function () {
	'use strict';

	var data = window.jetonomyData || {};
	if ( ! data.restNonce || ! data.restBase ) {
		return;
	}

	var labels = ( data.i18n && data.i18n.roleLabels ) || {
		member:    'Member',
		moderator: 'Moderator',
		admin:     'Admin'
	};

	function setBadge( row, role ) {
		var badge = row.querySelector( '.jt-member-badge' );
		if ( 'moderator' === role || 'admin' === role ) {
			if ( ! badge ) {
				badge = document.createElement( 'span' );
				badge.className = 'jt-badge-accent jt-member-badge';
				var anchor = row.querySelector( '.jt-member-role-select' );
				if ( anchor ) {
					anchor.parentNode.insertBefore( badge, anchor );
				} else {
					row.appendChild( badge );
				}
			}
			badge.textContent = labels[ role ] || role;
		} else if ( badge ) {
			badge.remove();
		}
	}

	function showError( select, message ) {
		var row = select.closest( '.jt-member-item' );
		var existing = row && row.querySelector( '.jt-member-role-error' );
		if ( existing ) {
			existing.remove();
		}
		if ( ! row ) {
			return;
		}
		var p = document.createElement( 'p' );
		p.className = 'jt-member-role-error';
		p.setAttribute( 'role', 'alert' );
		p.textContent = message;
		row.appendChild( p );
	}

	document.addEventListener( 'change', function ( event ) {
		var select = event.target;
		if ( ! select.classList || ! select.classList.contains( 'jt-member-role-select' ) ) {
			return;
		}

		var spaceId = select.getAttribute( 'data-space-id' );
		var userId  = select.getAttribute( 'data-user-id' );
		var role    = select.value;
		var prev    = select.getAttribute( 'data-prev-role' ) || select.value;

		if ( ! spaceId || ! userId || ! role ) {
			return;
		}

		select.disabled = true;

		fetch( data.restBase + '/spaces/' + spaceId + '/members/' + userId, {
			method: 'PATCH',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   data.restNonce
			},
			body: JSON.stringify( { role: role } )
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'HTTP ' + res.status );
				}
				return res.json();
			} )
			.then( function () {
				var row = select.closest( '.jt-member-item' );
				if ( row ) {
					setBadge( row, role );
				}
				select.setAttribute( 'data-prev-role', role );
				select.disabled = false;
				var existing = row && row.querySelector( '.jt-member-role-error' );
				if ( existing ) {
					existing.remove();
				}
			} )
			.catch( function () {
				select.value = prev;
				select.disabled = false;
				showError( select, ( data.i18n && data.i18n.roleUpdateFailed ) || 'Could not update role. Please try again.' );
			} );
	} );
} )();
