/**
 * Space-members role dropdown handler.
 *
 * Only rendered for space admins. When they change a member's role via the
 * <select class="jt-member-role-select">, we:
 *
 *  1. Confirm the change inline (1.4.0 G4) so a slip of the mouse can't
 *     accidentally demote the wrong person.
 *  2. PATCH /jetonomy/v1/spaces/{id}/members/{user_id} with the new role.
 *  3. On 400 with a `jetonomy_*` error code, render the server's i18n
 *     message in the row's .jt-member-role-error slot — including the
 *     two integrity-guard codes shipped in G4 commit 1:
 *       - jetonomy_cannot_self_demote
 *       - jetonomy_last_admin_required
 *  4. On any failure, revert the dropdown to its previous value via
 *     `data-prev-role`.
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
		if ( ! row ) {
			return;
		}
		var existing = row.querySelector( '.jt-member-role-error' );
		if ( existing ) {
			existing.remove();
		}
		var p = document.createElement( 'p' );
		p.className = 'jt-member-role-error';
		p.setAttribute( 'role', 'alert' );
		p.textContent = message;
		row.appendChild( p );
	}

	function clearError( select ) {
		var row = select.closest( '.jt-member-item' );
		var existing = row && row.querySelector( '.jt-member-role-error' );
		if ( existing ) {
			existing.remove();
		}
	}

	function memberName( select ) {
		var row = select.closest( '.jt-member-item' );
		var nameEl = row && row.querySelector( '.jt-member-name, .jt-member-display-name, [data-member-name]' );
		return nameEl ? nameEl.textContent.trim() : '';
	}

	/**
	 * Inline confirmation. Uses the shared modal toolkit from
	 * assets/js/jetonomy-modals.js (`window.jetonomyConfirm`) — every
	 * customer-facing confirm in the plugin goes through that single
	 * implementation so the UX stays consistent. Returns Promise<boolean>.
	 */
	function confirmRoleChange( prev, next, name ) {
		var prevLabel = labels[ prev ] || prev;
		var nextLabel = labels[ next ] || next;
		var template  = ( data.i18n && data.i18n.confirmRoleChange )
			|| 'Change %name% from %from% to %to%?';
		var body = template
			.replace( '%name%', name || 'this member' )
			.replace( '%from%', prevLabel )
			.replace( '%to%',   nextLabel );
		var title  = ( data.i18n && data.i18n.confirmRoleChangeTitle ) || 'Change role';
		var ok     = ( data.i18n && data.i18n.confirmLabel ) || 'Change role';
		var cancel = ( data.i18n && data.i18n.cancelLabel )  || 'Cancel';
		if ( typeof window.jetonomyConfirm !== 'function' ) {
			// Defensive fallback — should never fire because jetonomy-modals
			// is a hard dependency of this script's enqueue. If it does,
			// we proceed without confirmation rather than block the action.
			return Promise.resolve( true );
		}
		return window.jetonomyConfirm( body, {
			title:        title,
			confirmLabel: ok,
			cancelLabel:  cancel,
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		var select = event.target;
		if ( ! select.classList || ! select.classList.contains( 'jt-member-role-select' ) ) {
			return;
		}

		var spaceId = select.getAttribute( 'data-space-id' );
		var userId  = select.getAttribute( 'data-user-id' );
		var role    = select.value;
		var prev    = select.getAttribute( 'data-prev-role' ) || '';

		if ( ! spaceId || ! userId || ! role ) {
			return;
		}

		// No-op edits never reach the server.
		if ( prev === role ) {
			return;
		}

		// G4: confirm via inline modal before submitting. Returns a Promise
		// (the modal is async) — chain off it instead of synchronous if().
		// If the admin cancels, restore the dropdown to the previous value
		// and bail out without firing PATCH.
		confirmRoleChange( prev, role, memberName( select ) ).then( function ( confirmed ) {
			if ( ! confirmed ) {
				select.value = prev;
				return;
			}

			clearError( select );
			select.disabled = true;

			window.jetonomyRest.restFetch( '/spaces/' + spaceId + '/members/' + userId, {
				method: 'PATCH',
				body: { role: role }
			} ).then( function ( payload ) {
				if ( ! payload.ok ) {
					var msg = ( payload.data && payload.data.message )
						|| ( ( data.i18n && data.i18n.roleUpdateFailed ) || 'Could not update role. Please try again.' );
					select.value = prev;
					select.disabled = false;
					showError( select, msg );
					return;
				}
				var row = select.closest( '.jt-member-item' );
				if ( row ) {
					setBadge( row, role );
				}
				select.setAttribute( 'data-prev-role', role );
				select.disabled = false;
				clearError( select );
			} );
		} );
	} );

	// 1.4.0 C.3 — Ban button. Confirms via the shared modal toolkit (no
	// native confirm), POSTs space_ban to /moderation/ban, and visually
	// disables the member row on success.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '.jt-member-ban-btn' );
		if ( ! btn ) {
			return;
		}
		var spaceId = btn.getAttribute( 'data-space-id' );
		var userId  = btn.getAttribute( 'data-user-id' );
		var name    = btn.getAttribute( 'data-user-name' );
		if ( ! spaceId || ! userId ) {
			return;
		}

		// Per the no-browser-alerts rule, we never fall back to
		// window.confirm here. window.jetonomyConfirm is a hard JS
		// dependency — if it's absent at runtime, treat the absence as
		// "cancel" rather than surface a native dialog on a destructive
		// action.
		if ( typeof window.jetonomyConfirm !== 'function' ) {
			return;
		}
		var i18n        = ( data.i18n ) || {};
		var banFmt      = i18n.banConfirmFormat || 'Ban %s from this space? They will lose access to its posts and replies until you lift the ban.';
		var banBody     = banFmt.replace( '%s', name || '' );
		var promptResult = window.jetonomyConfirm(
			banBody,
			{
				title:        i18n.banMemberTitle || 'Ban member',
				confirmLabel: i18n.banLabel || 'Ban',
				danger:       true
			}
		);

		promptResult.then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			btn.disabled = true;
			window.jetonomyRest.restFetch( '/moderation/ban', {
				method: 'POST',
				body: {
					user_id: parseInt( userId, 10 ),
					space_id: parseInt( spaceId, 10 ),
					type: 'space_ban'
				}
			} ).then( function ( res ) {
				if ( ! res.ok ) {
					btn.disabled = false;
					var msg = ( res.data && res.data.message ) || ( ( data.i18n && data.i18n.banFailed ) || 'Ban failed. Please try again.' );
					showError( btn, msg );
					return;
				}
				var row = btn.closest( '.jt-member-item' );
				if ( row ) {
					row.style.opacity = '0.5';
					var note = document.createElement( 'span' );
					note.className = 'jt-member-banned-note';
					note.textContent = ( data.i18n && data.i18n.memberBanned ) || 'Banned';
					row.appendChild( note );
				}
				btn.remove();
			} );
		} );
	} );
} )();
