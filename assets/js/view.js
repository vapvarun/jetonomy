/**
 * Jetonomy Interactivity API Store
 * Handles voting, sorting, load-more, and notifications polling
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Custom modal helpers — replace browser alert/confirm/prompt with styled modals.
 */
function jetonomyConfirm( message ) {
	return new Promise( ( resolve ) => {
		const overlay = document.createElement( 'div' );
		overlay.className = 'jt-modal-overlay';
		const box = document.createElement( 'div' );
		box.className = 'jt-modal-box';
		const msg = document.createElement( 'p' );
		msg.className = 'jt-modal-msg';
		msg.textContent = message;
		box.appendChild( msg );
		const actions = document.createElement( 'div' );
		actions.className = 'jt-modal-actions';
		const cancelBtn = document.createElement( 'button' );
		cancelBtn.className = 'jt-btn jt-btn-ghost';
		cancelBtn.textContent = 'Cancel';
		cancelBtn.addEventListener( 'click', () => { overlay.remove(); resolve( false ); } );
		const okBtn = document.createElement( 'button' );
		okBtn.className = 'jt-btn jt-btn-fill';
		okBtn.textContent = 'Confirm';
		okBtn.addEventListener( 'click', () => { overlay.remove(); resolve( true ); } );
		actions.appendChild( cancelBtn );
		actions.appendChild( okBtn );
		box.appendChild( actions );
		overlay.appendChild( box );
		overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) { overlay.remove(); resolve( false ); } } );
		document.body.appendChild( overlay );
		okBtn.focus();
	} );
}

function jetonomyPrompt( message, placeholder ) {
	return new Promise( ( resolve ) => {
		const overlay = document.createElement( 'div' );
		overlay.className = 'jt-modal-overlay';
		const box = document.createElement( 'div' );
		box.className = 'jt-modal-box';
		const msg = document.createElement( 'p' );
		msg.className = 'jt-modal-msg';
		msg.textContent = message;
		box.appendChild( msg );
		const input = document.createElement( 'textarea' );
		input.className = 'jt-modal-input jt-input';
		input.placeholder = placeholder || '';
		input.rows = 3;
		box.appendChild( input );
		const actions = document.createElement( 'div' );
		actions.className = 'jt-modal-actions';
		const cancelBtn = document.createElement( 'button' );
		cancelBtn.className = 'jt-btn jt-btn-ghost';
		cancelBtn.textContent = 'Cancel';
		cancelBtn.addEventListener( 'click', () => { overlay.remove(); resolve( null ); } );
		const okBtn = document.createElement( 'button' );
		okBtn.className = 'jt-btn jt-btn-fill';
		okBtn.textContent = 'Submit';
		okBtn.addEventListener( 'click', () => { overlay.remove(); resolve( input.value.trim() ); } );
		actions.appendChild( cancelBtn );
		actions.appendChild( okBtn );
		box.appendChild( actions );
		overlay.appendChild( box );
		overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) { overlay.remove(); resolve( null ); } } );
		document.body.appendChild( overlay );
		input.focus();
	} );
}


/**
 * Space picker modal — shows a dropdown of available spaces, excluding the current one.
 * Fetches from GET /jetonomy/v1/spaces. Resolves with the selected space ID (string) or null.
 *
 * @param {string} title          Modal heading text.
 * @param {string|number} excludeSpaceId  The space to exclude from the list.
 * @return {Promise<string|null>}
 */
function jetonomySpacePicker( title, excludeSpaceId ) {
	return new Promise( ( resolve ) => {
		const overlay = document.createElement( 'div' );
		overlay.className = 'jt-modal-overlay';
		const box = document.createElement( 'div' );
		box.className = 'jt-modal-box';
		const msg = document.createElement( 'p' );
		msg.className = 'jt-modal-msg';
		msg.textContent = title;
		box.appendChild( msg );
		const select = document.createElement( 'select' );
		select.className = 'jt-modal-input jt-input';
		const loadingOpt = document.createElement( 'option' );
		loadingOpt.textContent = 'Loading spaces…';
		loadingOpt.disabled = true;
		loadingOpt.selected = true;
		select.appendChild( loadingOpt );
		box.appendChild( select );
		const actions = document.createElement( 'div' );
		actions.className = 'jt-modal-actions';
		const cancelBtn = document.createElement( 'button' );
		cancelBtn.className = 'jt-btn jt-btn-ghost';
		cancelBtn.textContent = 'Cancel';
		cancelBtn.addEventListener( 'click', () => { overlay.remove(); resolve( null ); } );
		const okBtn = document.createElement( 'button' );
		okBtn.className = 'jt-btn jt-btn-fill';
		okBtn.textContent = 'Move';
		okBtn.disabled = true;
		okBtn.addEventListener( 'click', () => { overlay.remove(); resolve( select.value || null ); } );
		actions.appendChild( cancelBtn );
		actions.appendChild( okBtn );
		box.appendChild( actions );
		overlay.appendChild( box );
		overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) { overlay.remove(); resolve( null ); } } );
		document.body.appendChild( overlay );

		// Derive API base: prefer the interactive element's data attribute, fall back to wpApiSettings.
		const apiBase = document.querySelector( '[data-wp-interactive="jetonomy"]' )?.dataset?.apiBase
			|| ( window.wpApiSettings?.root ? window.wpApiSettings.root.replace( /\/$/, '' ) + '/jetonomy/v1' : '/wp-json/jetonomy/v1' );

		fetch( apiBase + '/spaces', { credentials: 'same-origin' } )
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				select.innerHTML = '';
				const defaultOpt = document.createElement( 'option' );
				defaultOpt.textContent = 'Select a space…';
				defaultOpt.value = '';
				defaultOpt.disabled = true;
				defaultOpt.selected = true;
				select.appendChild( defaultOpt );
				// GET /spaces returns { data: [...] } via paginated_response.
				const spaces = Array.isArray( data.data ) ? data.data : ( Array.isArray( data ) ? data : [] );
				spaces.forEach( ( s ) => {
					if ( String( s.id ) === String( excludeSpaceId ) ) return;
					const opt = document.createElement( 'option' );
					opt.value = s.id;
					opt.textContent = s.title;
					select.appendChild( opt );
				} );
				if ( select.options.length <= 1 ) {
					// Only the placeholder — no other spaces available.
					const noneOpt = document.createElement( 'option' );
					noneOpt.textContent = 'No other spaces available';
					noneOpt.disabled = true;
					select.appendChild( noneOpt );
				} else {
					select.addEventListener( 'change', () => { okBtn.disabled = ! select.value; } );
				}
			} )
			.catch( () => {
				select.innerHTML = '<option disabled>Failed to load spaces</option>';
			} );
	} );
}

/**
 * Post picker modal for merge — search and select a target topic.
 */
function jetonomyPostPicker( title, excludePostId, spaceId ) {
	return new Promise( ( resolve ) => {
		const overlay = document.createElement( 'div' );
		overlay.className = 'jt-modal-overlay';
		const box = document.createElement( 'div' );
		box.className = 'jt-modal-box';
		const msg = document.createElement( 'p' );
		msg.className = 'jt-modal-msg';
		msg.textContent = title;
		box.appendChild( msg );

		const searchInput = document.createElement( 'input' );
		searchInput.type = 'text';
		searchInput.className = 'jt-modal-input jt-input';
		searchInput.placeholder = 'Search for a topic...';
		box.appendChild( searchInput );

		const resultsList = document.createElement( 'div' );
		resultsList.className = 'jt-modal-results';
		resultsList.style.cssText = 'max-height:200px;overflow-y:auto;margin:8px 0;';
		box.appendChild( resultsList );

		let selectedId = null;
		const actionsDiv = document.createElement( 'div' );
		actionsDiv.className = 'jt-modal-actions';
		const cancelBtn = document.createElement( 'button' );
		cancelBtn.className = 'jt-btn jt-btn-ghost';
		cancelBtn.textContent = 'Cancel';
		cancelBtn.addEventListener( 'click', () => { overlay.remove(); resolve( null ); } );
		const okBtn = document.createElement( 'button' );
		okBtn.className = 'jt-btn jt-btn-fill';
		okBtn.textContent = 'Merge';
		okBtn.disabled = true;
		okBtn.addEventListener( 'click', () => { overlay.remove(); resolve( selectedId ); } );
		actionsDiv.appendChild( cancelBtn );
		actionsDiv.appendChild( okBtn );
		box.appendChild( actionsDiv );
		overlay.appendChild( box );
		overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) { overlay.remove(); resolve( null ); } } );
		document.body.appendChild( overlay );
		searchInput.focus();

		const apiBase = document.querySelector( '[data-wp-interactive="jetonomy"]' )?.dataset?.apiBase
			|| ( window.wpApiSettings?.root ? window.wpApiSettings.root.replace( /\/$/, '' ) + '/jetonomy/v1' : '/wp-json/jetonomy/v1' );

		let debounce = null;
		searchInput.addEventListener( 'input', () => {
			clearTimeout( debounce );
			debounce = setTimeout( () => {
				const q = searchInput.value.trim();
				if ( q.length < 2 ) { while ( resultsList.firstChild ) resultsList.removeChild( resultsList.firstChild ); return; }
				fetch( `${ apiBase }/search?q=${ encodeURIComponent( q ) }&type=post`, { credentials: 'same-origin' } )
					.then( r => r.json() )
					.then( data => {
						while ( resultsList.firstChild ) resultsList.removeChild( resultsList.firstChild );
						const posts = data.data || data.results || data;
						if ( ! Array.isArray( posts ) || posts.length === 0 ) {
							const empty = document.createElement( 'div' );
							empty.style.cssText = 'padding:8px;color:var(--jt-text-secondary);';
							empty.textContent = 'No topics found';
							resultsList.appendChild( empty );
							return;
						}
						posts.forEach( p => {
							if ( String( p.id ) === String( excludePostId ) ) return;
							const item = document.createElement( 'div' );
							item.style.cssText = 'padding:8px 10px;cursor:pointer;border-radius:var(--jt-radius-sm,4px);';
							item.textContent = p.title;
							item.addEventListener( 'mouseenter', () => { item.style.background = 'var(--jt-bg-hover)'; } );
							item.addEventListener( 'mouseleave', () => { item.style.background = selectedId === String( p.id ) ? 'var(--jt-accent-light)' : ''; } );
							item.addEventListener( 'click', () => {
								resultsList.querySelectorAll( 'div' ).forEach( d => { d.style.background = ''; } );
								item.style.background = 'var(--jt-accent-light)';
								selectedId = String( p.id );
								okBtn.disabled = false;
							} );
							resultsList.appendChild( item );
						} );
					} )
					.catch( () => {
						while ( resultsList.firstChild ) resultsList.removeChild( resultsList.firstChild );
						const errDiv = document.createElement( 'div' );
						errDiv.style.cssText = 'padding:8px;color:var(--jt-danger);';
						errDiv.textContent = 'Search failed';
						resultsList.appendChild( errDiv );
					} );
			}, 300 );
		} );
	} );
}

/**
 * Build reply HTML for client-side rendering (used by loadGapReplies and loadMoreReplies).
 */
function jetonomyBuildReplyHtml( reply ) {
    const author = reply.author_name || 'Anonymous';
    const initials = author.substring( 0, 2 ).toUpperCase();
    const avatarUrl = reply.author_avatar || '';
    const timeAgo = reply.time_ago || '';
    const isAccepted = reply.is_accepted ? ' accepted' : '';
    const trustLevel = reply.trust_level || 0;
    const profileUrl = reply.profile_url || '#';

    const avatarHtml = avatarUrl
        ? `<img src="${ avatarUrl }" alt="${ author }" class="jt-avatar jt-avatar-sm" width="28" height="28" loading="lazy">`
        : `<span class="jt-avatar jt-avatar-sm">${ initials }</span>`;

    return `
        <div class="jt-reply${ isAccepted }" data-wp-interactive="jetonomy">
            <div class="jt-reply-head">
                <a href="${ profileUrl }" class="jt-user-link">${ avatarHtml } <span class="jt-user-name">${ author }</span></a>
                <span class="jt-tl" data-jt-tl="${ trustLevel }">${ trustLevel }</span>
                <span class="jt-reply-time">${ timeAgo }</span>
                ${ reply.is_accepted ? '<span class="jt-accepted-tag">&#10003; Accepted</span>' : '' }
            </div>
            <div class="jt-reply-body">${ reply.content }</div>
            <div class="jt-reply-foot">
                <button class="jt-act" data-wp-on--click="actions.voteReplyUp" data-reply-id="${ reply.id }">&#9650; <span class="n">${ reply.vote_score || 0 }</span></button>
                <button class="jt-act" data-wp-on--click="actions.voteReplyDown" data-reply-id="${ reply.id }">&#9660;</button>
                <button class="jt-act jt-reply-to-btn" data-wp-on--click="actions.setReplyTo" data-reply-id="${ reply.id }" data-reply-author="${ author }">Reply</button>
                <button class="jt-act" data-wp-on--click="actions.quoteReply" data-reply-id="${ reply.id }" data-reply-author="${ author }">Quote</button>
            </div>
        </div>`;
}

const { state, actions } = store( 'jetonomy', {
    state: {
        // Post vote scores (populated from server state)
        postScores: {},
        // Reply vote scores
        replyScores: {},
        // Current sort
        currentSort: 'latest',
        // Loading states
        isLoading: false,
        // Notification count
        unreadCount: 0,
        // Composer visibility
        composerVisible: false,
        composerReplyTo: null,
        // Threaded reply-to tracking
        replyToId: null,
        replyToAuthor: '',
        // Form submission state
        isSubmitting: false,
        submitLabel: 'Post Topic',
        // Publish mode dropdown open/closed
        publishMenuOpen: false,
        // Nonce for API calls
        get nonce() {
            return state._nonce || '';
        },
    },

    actions: {
        // ── Voting ──
        *voteUp( event ) {
            event.stopPropagation();
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            // Optimistic update. Delta depends on the user's existing vote,
            // not a naïve +1: flipping from a prior downvote is +2 (remove -1,
            // add +1); clicking the same up button again toggles off (-1);
            // no prior vote is a straight +1. Without this, a user flipping
            // down → up saw an intermediate 'wrong' score for a beat before
            // the server reply corrected it.
            const current = state.postScores[ postId ] || 0;
            const downSibling = el.ref.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteDown"], [data-wp-on--click="actions.voteDown"]' );
            let delta = 1;
            if ( el.ref.classList.contains( 'voted' ) ) {
                delta = -1;
            } else if ( downSibling?.classList.contains( 'voted' ) ) {
                delta = 2;
            }
            state.postScores[ postId ] = current + delta;

            // Visual feedback — vote pop
            const scoreEl = el.ref.querySelector( '.n' );
            if ( scoreEl ) {
                scoreEl.style.transform = 'scale(1.3)';
                setTimeout( () => { scoreEl.style.transform = 'scale(1)'; }, 200 );
            }

            try {
                const response = yield fetch(
                    `${ state.apiBase }/posts/${ postId }/vote`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state.nonce,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify( { value: 1 } ),
                    }
                );
                if ( response.ok ) {
                    const data = yield response.json();
                    if ( data.score !== undefined ) {
                        state.postScores[ postId ] = data.score;
                    }
                    // Toggle voted state on the button.
                    if ( data.action === 'removed' ) {
                        el.ref.classList.remove( 'voted' );
                    } else {
                        el.ref.classList.add( 'voted' );
                        // Remove voted from the sibling down button.
                        const sibling = el.ref.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteDown"], [data-wp-on--click="actions.voteDown"]' );
                        if ( sibling ) sibling.classList.remove( 'voted' );
                    }
                    if ( window.bnToast && !window._jetonomyVoteToasted ) { window.bnToast( state.i18n?.voteRecorded || 'Vote recorded' ); window._jetonomyVoteToasted = true; setTimeout( () => { window._jetonomyVoteToasted = false; }, 2000 ); }
                } else {
                    // Rollback on error
                    state.postScores[ postId ] = current;
                    const err = yield response.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || 'Vote failed.', 'error' );
                }
            } catch {
                state.postScores[ postId ] = current;
                if ( window.bnToast ) window.bnToast( 'Network error. Please try again.', 'error' );
            }
        },

        *voteDown( event ) {
            event.stopPropagation();
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            // Optimistic delta mirrors voteUp: flipping up → down is -2,
            // clicking the same down again toggles off (+1), no prior vote
            // is a straight -1. See voteUp comment for the full rationale.
            const current = state.postScores[ postId ] || 0;
            const upSibling = el.ref.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteUp"], [data-wp-on--click="actions.voteUp"]' );
            let delta = -1;
            if ( el.ref.classList.contains( 'voted' ) ) {
                delta = 1;
            } else if ( upSibling?.classList.contains( 'voted' ) ) {
                delta = -2;
            }
            state.postScores[ postId ] = current + delta;

            // Visual feedback — vote pop
            const scoreEl = el.ref.querySelector( '.n' );
            if ( scoreEl ) {
                scoreEl.style.transform = 'scale(1.3)';
                setTimeout( () => { scoreEl.style.transform = 'scale(1)'; }, 200 );
            }

            try {
                const response = yield fetch(
                    `${ state.apiBase }/posts/${ postId }/vote`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state.nonce,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify( { value: -1 } ),
                    }
                );
                if ( response.ok ) {
                    const data = yield response.json();
                    if ( data.score !== undefined ) {
                        state.postScores[ postId ] = data.score;
                    }
                    // Toggle voted state on the button.
                    if ( data.action === 'removed' ) {
                        el.ref.classList.remove( 'voted' );
                    } else {
                        el.ref.classList.add( 'voted' );
                        // Remove voted from the sibling up button.
                        const sibling = el.ref.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteUp"], [data-wp-on--click="actions.voteUp"]' );
                        if ( sibling ) sibling.classList.remove( 'voted' );
                    }
                    if ( window.bnToast && !window._jetonomyVoteToasted ) { window.bnToast( state.i18n?.voteRecorded || 'Vote recorded' ); window._jetonomyVoteToasted = true; setTimeout( () => { window._jetonomyVoteToasted = false; }, 2000 ); }
                } else {
                    state.postScores[ postId ] = current;
                    const err = yield response.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || 'Vote failed.', 'error' );
                }
            } catch {
                state.postScores[ postId ] = current;
                if ( window.bnToast ) window.bnToast( 'Network error. Please try again.', 'error' );
            }
        },

        *voteReplyUp( event ) {
            event.stopPropagation();
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            const scoreEl = el.ref.querySelector( '.n' );
            const current = parseInt( scoreEl?.textContent || '0', 10 );

            // Delta accounts for a previous vote: same-button click toggles
            // off (-1); flipping from a prior downvote is +2; first vote is +1.
            const downSibling = el.ref.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteReplyDown"], [data-wp-on--click="actions.voteReplyDown"]' );
            let delta = 1;
            if ( el.ref.classList.contains( 'voted' ) ) {
                delta = -1;
            } else if ( downSibling?.classList.contains( 'voted' ) ) {
                delta = 2;
            }
            state.replyScores[ replyId ] = current + delta;
            if ( scoreEl ) {
                scoreEl.textContent = current + delta;
                scoreEl.style.transform = 'scale(1.3)';
                setTimeout( () => { scoreEl.style.transform = 'scale(1)'; }, 200 );
            }

            try {
                const response = yield fetch(
                    `${ state.apiBase }/replies/${ replyId }/vote`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state.nonce,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify( { value: 1 } ),
                    }
                );
                if ( response.ok ) {
                    const data = yield response.json();
                    if ( data.score !== undefined ) {
                        state.replyScores[ replyId ] = data.score;
                        if ( scoreEl ) scoreEl.textContent = data.score;
                    }
                    // Toggle voted state on the button.
                    if ( data.action === 'removed' ) {
                        el.ref.classList.remove( 'voted' );
                    } else {
                        el.ref.classList.add( 'voted' );
                        const sibling = el.ref.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteReplyDown"], [data-wp-on--click="actions.voteReplyDown"]' );
                        if ( sibling ) sibling.classList.remove( 'voted' );
                    }
                } else {
                    state.replyScores[ replyId ] = current;
                    if ( scoreEl ) scoreEl.textContent = current;
                    const err = yield response.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || 'Vote failed.', 'error' );
                }
            } catch {
                state.replyScores[ replyId ] = current;
                if ( scoreEl ) scoreEl.textContent = current;
            }
        },

        *voteReplyDown( event ) {
            event.stopPropagation();
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            const scoreEl = el.ref.querySelector( '.n' );
            const current = parseInt( scoreEl?.textContent || '0', 10 );

            // Delta mirrors voteReplyUp: flipping up → down is -2, same-button
            // toggle-off is +1, first vote is -1.
            const upSibling = el.ref.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteReplyUp"], [data-wp-on--click="actions.voteReplyUp"]' );
            let delta = -1;
            if ( el.ref.classList.contains( 'voted' ) ) {
                delta = 1;
            } else if ( upSibling?.classList.contains( 'voted' ) ) {
                delta = -2;
            }
            state.replyScores[ replyId ] = current + delta;
            if ( scoreEl ) {
                scoreEl.textContent = current + delta;
                scoreEl.style.transform = 'scale(1.3)';
                setTimeout( () => { scoreEl.style.transform = 'scale(1)'; }, 200 );
            }

            try {
                const response = yield fetch(
                    `${ state.apiBase }/replies/${ replyId }/vote`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state.nonce,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify( { value: -1 } ),
                    }
                );
                if ( response.ok ) {
                    const data = yield response.json();
                    if ( data.score !== undefined ) {
                        state.replyScores[ replyId ] = data.score;
                        if ( scoreEl ) scoreEl.textContent = data.score;
                    }
                    // Toggle voted state on the button.
                    if ( data.action === 'removed' ) {
                        el.ref.classList.remove( 'voted' );
                    } else {
                        el.ref.classList.add( 'voted' );
                        const sibling = el.ref.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteReplyUp"], [data-wp-on--click="actions.voteReplyUp"]' );
                        if ( sibling ) sibling.classList.remove( 'voted' );
                    }
                } else {
                    state.replyScores[ replyId ] = current;
                    if ( scoreEl ) scoreEl.textContent = current;
                    const err = yield response.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || 'Vote failed.', 'error' );
                }
            } catch {
                state.replyScores[ replyId ] = current;
                if ( scoreEl ) scoreEl.textContent = current;
            }
        },

        // ── Inline reply edit ──
        editReply( event ) {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            const replyCard = el.ref.closest( '.jt-reply' );
            if ( ! replyCard ) return;
            if ( replyCard.querySelector( '.jt-reply-editor' ) ) return;

            const bodyEl = replyCard.querySelector( '.jt-reply-body' );
            if ( ! bodyEl ) return;

            // [Basecamp 9808714691 analog — same root cause as editPost]
            // Old implementation used bodyEl.innerText into a <textarea>, so
            // images, links, and inline formatting were stripped every time
            // a user edited a reply. Now we use a contenteditable div seeded
            // with bodyEl.innerHTML, and submit innerHTML on save. Server-
            // side wp_kses_post (Replies_Controller::update_item) still
            // polices which tags survive.
            bodyEl.style.display = 'none';

            const editor = document.createElement( 'div' );
            editor.className = 'jt-reply-editor';
            editor.style.cssText = 'margin:8px 0';

            const editable = document.createElement( 'div' );
            editable.className = 'jt-reply-editor-body jt-input';
            editable.contentEditable = 'true';
            editable.setAttribute( 'role', 'textbox' );
            editable.setAttribute( 'aria-multiline', 'true' );
            editable.setAttribute( 'aria-label', state.i18n?.editReply || 'Edit reply' );
            editable.style.cssText = 'width:100%;min-height:4rem;padding:0.5rem 0.75rem;box-sizing:border-box;';
            editable.innerHTML = bodyEl.innerHTML.trim();

            const btnRow = document.createElement( 'div' );
            btnRow.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;margin-top:8px';

            const cancelBtn = document.createElement( 'button' );
            cancelBtn.className = 'jt-btn jt-btn-ghost';
            cancelBtn.textContent = state.i18n?.cancel || 'Cancel';
            cancelBtn.type = 'button';

            const saveBtn = document.createElement( 'button' );
            saveBtn.className = 'jt-btn jt-btn-fill';
            saveBtn.textContent = state.i18n?.save || 'Save';
            saveBtn.type = 'button';

            btnRow.append( cancelBtn, saveBtn );
            editor.append( editable, btnRow );
            bodyEl.after( editor );
            editable.focus();
            // Caret to end of content (matches editPost behaviour).
            const range = document.createRange();
            range.selectNodeContents( editable );
            range.collapse( false );
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange( range );

            cancelBtn.addEventListener( 'click', () => {
                editor.remove();
                bodyEl.style.display = '';
            } );

            saveBtn.addEventListener( 'click', async () => {
                const content     = editable.innerHTML.trim();
                const plainCheck  = ( editable.textContent || '' ).trim();
                const hasMediaTag = /<(?:img|video|audio|iframe|embed)\b/i.test( content );
                if ( ! plainCheck && ! hasMediaTag ) return;

                saveBtn.disabled = true;
                saveBtn.textContent = state.i18n?.saving || 'Saving...';

                try {
                    const res = await fetch( `${ state.apiBase }/replies/${ replyId }`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state._nonce || state.nonce,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify( { content } ),
                    } );

                    if ( res.ok ) {
                        // Reload so the display filter (wpautop) renders paragraphs.
                        window.location.reload();
                    } else {
                        const err = await res.json().catch( () => ( {} ) );
                        if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedSave || 'Failed to save.' );
                        saveBtn.disabled = false;
                        saveBtn.textContent = state.i18n?.save || 'Save';
                    }
                } catch {
                    if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
                    saveBtn.disabled = false;
                    saveBtn.textContent = state.i18n?.save || 'Save';
                }
            } );
        },

        // ── Follow post ──
        *followPost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            const isFollowing = el.ref.dataset.following === '1';
            if ( ! postId ) return;

            try {
                if ( isFollowing ) {
                    const res = yield fetch( `${ state.apiBase }/subscriptions?object_type=post&object_id=${ postId }`, {
                        headers: { 'X-WP-Nonce': state._nonce || state.nonce },
                        credentials: 'same-origin',
                    } );
                    if ( res.ok ) {
                        const data = yield res.json();
                        const subs = data.data || [];
                        if ( subs.length > 0 ) {
                            yield fetch( `${ state.apiBase }/subscriptions/${ subs[0].id }`, {
                                method: 'DELETE',
                                headers: { 'X-WP-Nonce': state._nonce || state.nonce },
                                credentials: 'same-origin',
                            } );
                        }
                    }
                    el.ref.dataset.following = '0';
                    el.ref.textContent = state.i18n?.follow || 'Follow';
                    el.ref.classList.remove( 'jt-btn-fill', 'jt-following' );
                    el.ref.classList.add( 'jt-btn-ghost' );
                } else {
                    const res = yield fetch( `${ state.apiBase }/subscriptions`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state._nonce || state.nonce },
                        credentials: 'same-origin',
                        body: JSON.stringify( { object_type: 'post', object_id: parseInt( postId ), via: 'both' } ),
                    } );
                    if ( res.ok ) {
                        el.ref.dataset.following = '1';
                        el.ref.textContent = state.i18n?.following || 'Following';
                        el.ref.classList.remove( 'jt-btn-ghost' );
                        el.ref.classList.add( 'jt-btn-fill', 'jt-following' );
                    }
                }
            } catch { /* non-critical */ }
        },

        // ── Follow space ──
        *followSpace( event ) {
            const el = getElement();
            const spaceId = el.ref.dataset.spaceId;
            const isFollowing = el.ref.dataset.following === '1';
            if ( ! spaceId ) return;

            try {
                if ( isFollowing ) {
                    const res = yield fetch( `${ state.apiBase }/subscriptions?object_type=space&object_id=${ spaceId }`, {
                        headers: { 'X-WP-Nonce': state._nonce || state.nonce },
                        credentials: 'same-origin',
                    } );
                    if ( res.ok ) {
                        const data = yield res.json();
                        const subs = data.data || [];
                        if ( subs.length > 0 ) {
                            yield fetch( `${ state.apiBase }/subscriptions/${ subs[0].id }`, {
                                method: 'DELETE',
                                headers: { 'X-WP-Nonce': state._nonce || state.nonce },
                                credentials: 'same-origin',
                            } );
                        }
                    }
                    el.ref.dataset.following = '0';
                    el.ref.textContent = state.i18n?.follow || 'Follow';
                    el.ref.classList.remove( 'jt-btn-fill', 'jt-following' );
                    el.ref.classList.add( 'jt-btn-ghost' );
                    if ( window.bnToast ) window.bnToast( state.i18n?.unfollowedSpace || 'Unfollowed space' );
                } else {
                    const res = yield fetch( `${ state.apiBase }/subscriptions`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state._nonce || state.nonce },
                        credentials: 'same-origin',
                        body: JSON.stringify( { object_type: 'space', object_id: parseInt( spaceId ), via: 'both' } ),
                    } );
                    if ( res.ok ) {
                        el.ref.dataset.following = '1';
                        el.ref.textContent = state.i18n?.following || 'Following';
                        el.ref.classList.remove( 'jt-btn-ghost' );
                        el.ref.classList.add( 'jt-btn-fill', 'jt-following' );
                        if ( window.bnToast ) window.bnToast( state.i18n?.followingSpace || 'Following space' );
                    }
                }
            } catch { /* non-critical */ }
        },

        // ── Share post ──
        //
        // [Basecamp 9808920407] — Previously the dropdown was inserted as a
        // SIBLING of the share button via `el.ref.after()` and positioned with
        // CSS `bottom: 100%; left: 0`. Two compounding problems:
        //   1. `position: absolute` anchors to the nearest positioned ancestor,
        //      not a sibling — so the dropdown anchored to `.jt-post-foot` (the
        //      shared parent), which is wider than the button and at the bottom
        //      of the post card. With `bottom: 100%` the dropdown rendered
        //      ~180px ABOVE that container, which for posts near the viewport
        //      top put it off-screen at `top: -184.57px`. User saw "nothing".
        //   2. The `.jt-more-dropdown` sibling pattern was fixed the same way
        //      in 1.3.6 (Basecamp 9803818273 — see jetonomy.css `.jt-more-
        //      dropdown` comment). Share was a missed sibling.
        // Fix: append the dropdown to document.body with `position: fixed` and
        // viewport-computed coordinates. Layout of `.jt-post-foot` can no
        // longer affect anchoring. Right-aligned to the button's right edge,
        // clamped to the viewport left edge so it never overflows horizontally.
        sharePost() {
            const el = getElement();
            const url = el.ref.dataset.postUrl;
            const title = el.ref.dataset.postTitle;
            if ( ! url ) return;

            // Toggle: if a dropdown opened by this button is already on the
            // page, close it instead of opening a second one.
            const existing = document.querySelector( '.jt-share-dropdown[data-jt-owner="' + el.ref.id + '"]' );
            if ( existing ) { existing.remove(); return; }
            // Also clear any stale dropdown from a previous button (e.g. the
            // user clicked a different post's share before closing the last).
            document.querySelectorAll( '.jt-share-dropdown' ).forEach( ( n ) => n.remove() );

            const dropdown = document.createElement( 'div' );
            dropdown.className = 'jt-share-dropdown';
            if ( ! el.ref.id ) {
                el.ref.id = 'jt-share-btn-' + Math.random().toString( 36 ).slice( 2, 9 );
            }
            dropdown.dataset.jtOwner = el.ref.id;

            const encodedUrl = encodeURIComponent( url );
            const encodedTitle = encodeURIComponent( title || '' );

            // Lucide SVG icons (MIT). Mirror the files at
            // assets/icons/{link,twitter-x,facebook,linkedin}.svg so the PHP
            // side can render the same glyphs via jetonomy_echo_icon().
            // Paths use stroke="currentColor" so they inherit the dropdown
            // item's text color (default + hover).
            const LUCIDE = {
                link:     '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
                x:        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4l16 16"/><path d="M20 4L4 20"/></svg>',
                facebook: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
                linkedin: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect width="4" height="12" x="2" y="9"/><circle cx="4" cy="4" r="2"/></svg>',
            };

            // Copy-link handler. navigator.clipboard.writeText returns a
            // Promise that rejects when the browser blocks the write (HTTP
            // pages, iframe permissions, permission-policy deny, or an older
            // browser that exposes the API behind a flag). Without handling
            // the rejection the user sees nothing happen and assumes the
            // button is broken. Fall back to document.execCommand('copy')
            // via a hidden textarea for older engines, and surface a toast
            // in either outcome.
            const copyLink = async () => {
                const okMsg = state.i18n?.linkCopied || 'Link copied';
                const failMsg = state.i18n?.linkCopyFailed || 'Could not copy the link. Copy it from the address bar.';
                try {
                    if ( navigator.clipboard && navigator.clipboard.writeText ) {
                        await navigator.clipboard.writeText( url );
                        if ( window.bnToast ) window.bnToast( okMsg );
                        return;
                    }
                    // Legacy fallback: hidden textarea + execCommand.
                    const ta = document.createElement( 'textarea' );
                    ta.value = url;
                    ta.setAttribute( 'readonly', '' );
                    ta.style.position = 'absolute';
                    ta.style.left = '-9999px';
                    document.body.appendChild( ta );
                    ta.select();
                    const ok = document.execCommand && document.execCommand( 'copy' );
                    document.body.removeChild( ta );
                    if ( window.bnToast ) window.bnToast( ok ? okMsg : failMsg, ok ? undefined : 'error' );
                } catch ( _err ) {
                    if ( window.bnToast ) window.bnToast( failMsg, 'error' );
                }
            };

            const items = [
                { label: state.i18n?.copyLink || 'Copy link', icon: LUCIDE.link,     action: () => { copyLink(); dropdown.remove(); } },
                { label: 'Twitter / X',                        icon: LUCIDE.x,        href: `https://twitter.com/intent/tweet?url=${ encodedUrl }&text=${ encodedTitle }` },
                { label: 'Facebook',                           icon: LUCIDE.facebook, href: `https://www.facebook.com/sharer/sharer.php?u=${ encodedUrl }` },
                { label: 'LinkedIn',                           icon: LUCIDE.linkedin, href: `https://www.linkedin.com/sharing/share-offsite/?url=${ encodedUrl }` },
            ];

            // Parse a trusted Lucide SVG string (static constants above) into
            // a real SVGElement node. DOMParser avoids any innerHTML
            // assignment, which keeps the security linter + reviewers happy.
            const parseSvg = ( svgString ) => {
                const doc = new DOMParser().parseFromString( svgString, 'image/svg+xml' );
                return doc.documentElement;
            };

            items.forEach( item => {
                const btn = document.createElement( 'button' );
                btn.className = 'jt-share-item';
                btn.type = 'button';

                const iconSlot = document.createElement( 'span' );
                iconSlot.className = 'jt-share-item-icon';
                iconSlot.appendChild( parseSvg( item.icon ) );
                btn.appendChild( iconSlot );

                const labelNode = document.createElement( 'span' );
                labelNode.className = 'jt-share-item-label';
                labelNode.textContent = item.label;
                btn.appendChild( labelNode );

                if ( item.href ) {
                    btn.addEventListener( 'click', () => { window.open( item.href, '_blank', 'width=600,height=400' ); dropdown.remove(); } );
                } else if ( item.action ) {
                    btn.addEventListener( 'click', item.action );
                }
                dropdown.appendChild( btn );
            } );

            // Position using viewport coordinates — `fixed` so no ancestor
            // layout can displace us. Append to body so z-index stacking is
            // predictable (no accidental clipping by `overflow: hidden` in a
            // card). Apply `position: fixed` BEFORE insertion so the dropdown
            // shrinks to content width (display:block default would take full
            // body width and blow up offsetWidth).
            dropdown.style.position = 'fixed';
            dropdown.style.top = '-9999px';
            dropdown.style.left = '-9999px';
            document.body.appendChild( dropdown );
            const rect = el.ref.getBoundingClientRect();
            const dropWidth = dropdown.offsetWidth || 176;
            const dropHeight = dropdown.offsetHeight || 180;
            const gap = 4;
            let top = rect.bottom + gap;
            let left = rect.right - dropWidth;
            if ( left < 8 ) left = 8;                                        // clamp to left viewport edge
            if ( left + dropWidth > window.innerWidth - 8 ) {                 // clamp to right viewport edge
                left = window.innerWidth - dropWidth - 8;
            }
            // If there's not enough room below, flip above the button.
            if ( top + dropHeight > window.innerHeight - 8 && rect.top > dropHeight + gap + 8 ) {
                top = rect.top - dropHeight - gap;
            }
            dropdown.style.top = top + 'px';
            dropdown.style.left = left + 'px';

            // Close on scroll or resize. The dropdown uses position: fixed so
            // scrolling the page would otherwise let the dropdown visibly
            // detach from its post (reproduced: 200px scroll moves the button
            // up, dropdown stays in place, gap grows to 204px). Matching the
            // pattern on Twitter / Reddit / GitHub: dismiss on scroll rather
            // than reposition-tracking. Click-outside still applies.
            const cleanup = () => {
                dropdown.remove();
                document.removeEventListener( 'click', closeHandler );
                window.removeEventListener( 'scroll', scrollHandler, true );
                window.removeEventListener( 'resize', scrollHandler );
            };
            const closeHandler = ( e ) => {
                if ( ! dropdown.contains( e.target ) && e.target !== el.ref && ! el.ref.contains( e.target ) ) {
                    cleanup();
                }
            };
            const scrollHandler = () => { cleanup(); };
            setTimeout( () => {
                document.addEventListener( 'click', closeHandler );
                window.addEventListener( 'scroll', scrollHandler, { passive: true, capture: true } );
                window.addEventListener( 'resize', scrollHandler, { passive: true } );
            }, 0 );
        },

        // ── Toggle bookmark ──
        *toggleBookmark( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/bookmarks`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state._nonce || state.nonce },
                    credentials: 'same-origin',
                    body: JSON.stringify( { post_id: parseInt( postId ) } ),
                } );
                if ( res.ok ) {
                    const data = yield res.json();
                    el.ref.dataset.bookmarked = data.bookmarked ? '1' : '0';
                    el.ref.classList.toggle( 'bookmarked', data.bookmarked );
                    el.ref.title = data.bookmarked ? ( state.i18n?.removeBookmark || 'Remove bookmark' ) : ( state.i18n?.bookmark || 'Bookmark' );
                    if ( window.bnToast ) window.bnToast( data.bookmarked ? ( state.i18n?.bookmarked || 'Bookmarked' ) : ( state.i18n?.bookmarkRemoved || 'Bookmark removed' ) );
                }
            } catch { /* non-critical */ }
        },

        // ── Flag / report post ──
        *flagPost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            const reason = yield jetonomyPrompt( state.i18n?.reportPrompt || 'Why are you reporting this post?', state.i18n?.reportPlaceholder || 'Describe the issue...' );
            if ( reason === null ) return; // Cancelled

            try {
                const res = yield fetch( `${ state.apiBase }/flags`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state._nonce || state.nonce },
                    credentials: 'same-origin',
                    body: JSON.stringify( { object_type: 'post', object_id: parseInt( postId ), reason: 'other', description: reason } ),
                } );
                if ( res.ok ) {
                    if ( window.bnToast ) window.bnToast( 'Reported \u2014 thank you' );
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedReport || 'Failed to submit report.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Flag / report reply ──
        *flagReply( event ) {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            const reason = yield jetonomyPrompt( state.i18n?.reportReplyPrompt || 'Why are you reporting this reply?', state.i18n?.reportPlaceholder || 'Describe the issue...' );
            if ( reason === null ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/flags`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state._nonce || state.nonce },
                    credentials: 'same-origin',
                    body: JSON.stringify( { object_type: 'reply', object_id: parseInt( replyId ), reason: 'other', description: reason } ),
                } );
                if ( res.ok ) {
                    if ( window.bnToast ) window.bnToast( 'Reported \u2014 thank you' );
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedReport || 'Failed to submit report.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Flag / report user ──
        *flagUser( event ) {
            const el = getElement();
            const userId = el.ref.dataset.userId;
            if ( ! userId ) return;

            const reason = yield jetonomyPrompt( state.i18n?.reportUserPrompt || 'Why are you reporting this user?', state.i18n?.reportUserPlaceholder || 'Describe the issue...' );
            if ( reason === null ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/flags`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state._nonce || state.nonce },
                    credentials: 'same-origin',
                    body: JSON.stringify( { object_type: 'user', object_id: parseInt( userId ), reason: 'other', description: reason } ),
                } );
                if ( res.ok ) {
                    if ( window.bnToast ) window.bnToast( 'Reported \u2014 thank you' );
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedReport || 'Failed to submit report.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Toggle "more" dropdown menu ──
        toggleMoreMenu( event ) {
            event.stopPropagation();
            const el = getElement();
            const menu = el.ref.closest( '.jt-more-menu' );
            if ( ! menu ) return;

            const dropdown = menu.querySelector( '.jt-more-dropdown' );
            if ( ! dropdown ) return;

            const isHidden = dropdown.hidden;
            dropdown.hidden = ! isHidden;

            if ( ! isHidden ) return;

            const closeHandler = ( e ) => {
                if ( ! menu.contains( e.target ) ) {
                    dropdown.hidden = true;
                    document.removeEventListener( 'click', closeHandler );
                }
            };
            setTimeout( () => document.addEventListener( 'click', closeHandler ), 0 );
        },

        // ── Inline post (topic) edit ──
        editPost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            const article = el.ref.closest( 'article' ) || el.ref.closest( '.jt-post' );
            if ( ! article ) return;
            if ( article.querySelector( '.jt-post-editor' ) ) return;

            const bodyEl = article.querySelector( '.jt-post-body' );
            if ( ! bodyEl ) return;

            // [Basecamp 9808714691 part 2] Previously this editor used a
            // <textarea> seeded with bodyEl.innerText.trim() — which strips
            // <img> tags and all HTML. On save the plain text was PATCHed
            // back and the server's wp_kses_post pass had no image markup to
            // preserve, so uploaded images were silently dropped from every
            // edited post.
            //
            // Fix: render the editor as a contenteditable <div> seeded with
            // bodyEl.innerHTML. The server already kses'd this markup before
            // we rendered it, so re-using it as editor source is safe. On
            // save we send editor.innerHTML so images, links, and inline
            // formatting round-trip intact. Text-only edits still work —
            // the user just types and the image stays visible in place.
            bodyEl.style.display = 'none';

            const editor = document.createElement( 'div' );
            editor.className = 'jt-post-editor';
            editor.style.cssText = 'margin:8px 0';

            const editable = document.createElement( 'div' );
            editable.className = 'jt-post-editor-body jt-input';
            editable.contentEditable = 'true';
            editable.setAttribute( 'role', 'textbox' );
            editable.setAttribute( 'aria-multiline', 'true' );
            editable.setAttribute( 'aria-label', state.i18n?.editPost || 'Edit post' );
            editable.style.cssText = 'width:100%;min-height:6rem;padding:0.5rem 0.75rem;box-sizing:border-box;';
            // Preserve the original HTML (including any <img>, <a>, <strong>,
            // etc.). This is the whole fix — innerHTML instead of innerText.
            editable.innerHTML = bodyEl.innerHTML.trim();

            const btnRow = document.createElement( 'div' );
            btnRow.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;margin-top:8px';

            const cancelBtn = document.createElement( 'button' );
            cancelBtn.className = 'jt-btn jt-btn-ghost';
            cancelBtn.textContent = state.i18n?.cancel || 'Cancel';
            cancelBtn.type = 'button';

            const saveBtn = document.createElement( 'button' );
            saveBtn.className = 'jt-btn jt-btn-fill';
            saveBtn.textContent = state.i18n?.save || 'Save';
            saveBtn.type = 'button';

            btnRow.append( cancelBtn, saveBtn );
            editor.append( editable, btnRow );
            bodyEl.after( editor );
            // Move caret to end of existing content so the user can continue
            // typing without losing position — UX parity with textarea focus.
            editable.focus();
            const range = document.createRange();
            range.selectNodeContents( editable );
            range.collapse( false );
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange( range );

            cancelBtn.addEventListener( 'click', () => {
                editor.remove();
                bodyEl.style.display = '';
            } );

            saveBtn.addEventListener( 'click', async () => {
                // Use innerHTML for the payload — the server wp_kses_post's
                // it on receive so anything disallowed is stripped there.
                // Validate with textContent so an "all whitespace / empty
                // tags" editor doesn't trip us into submitting nothing.
                const content     = editable.innerHTML.trim();
                const plainCheck  = ( editable.textContent || '' ).trim();
                const hasMediaTag = /<(?:img|video|audio|iframe|embed)\b/i.test( content );
                if ( ! plainCheck && ! hasMediaTag ) return;

                saveBtn.disabled = true;
                saveBtn.textContent = state.i18n?.saving || 'Saving...';

                try {
                    const res = await fetch( `${ state.apiBase }/posts/${ postId }`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state._nonce || state.nonce,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify( { content } ),
                    } );

                    if ( res.ok ) {
                        window.location.reload();
                    } else {
                        const err = await res.json().catch( () => ( {} ) );
                        if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedSave || 'Failed to save.' );
                        saveBtn.disabled = false;
                        saveBtn.textContent = state.i18n?.save || 'Save';
                    }
                } catch {
                    if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
                    saveBtn.disabled = false;
                    saveBtn.textContent = state.i18n?.save || 'Save';
                }
            } );
        },

        // ── Pin / Unpin post ──
        *pinPost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/posts/${ postId }/pin`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': state._nonce || state.nonce,
                    },
                    credentials: 'same-origin',
                } );

                if ( res.ok ) {
                    const data = yield res.json();
                    // Reload page to reflect pinned state in UI
                    if ( window.bnToast ) {
                        window.bnToast( data.is_sticky ? ( state.i18n?.postPinned || 'Post pinned' ) : ( state.i18n?.postUnpinned || 'Post unpinned' ) );
                    }
                    setTimeout( () => window.location.reload(), 600 );
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) {
                        window.bnToast( err.message || state.i18n?.failedPin || 'Failed to toggle pin.' );
                    }
                }
            } catch {
                if ( window.bnToast ) {
                    window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
                }
            }
        },
        // ── Toggle private visibility ──
        *togglePrivate( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            const isPrivate = el.ref.dataset.private === '1';
            if ( ! postId ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/posts/${ postId }`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': state._nonce || state.nonce,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify( { is_private: ! isPrivate } ),
                } );

                if ( res.ok ) {
                    const data = yield res.json();
                    if ( window.bnToast ) {
                        window.bnToast( data.is_private ? ( state.i18n?.madePrivate || 'Topic is now private' ) : ( state.i18n?.madePublic || 'Topic is now public' ) );
                    }
                    setTimeout( () => window.location.reload(), 600 );
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) {
                        window.bnToast( err.message || state.i18n?.failedTogglePrivate || 'Failed to change visibility.' );
                    }
                }
            } catch {
                if ( window.bnToast ) {
                    window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
                }
            }
        },
        // ── Move post (topic) to another space ──
        *movePost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            const currentSpaceId = el.ref.dataset.spaceId;
            if ( ! postId ) return;

            const spaceId = yield jetonomySpacePicker(
                state.i18n?.moveTopicTitle || 'Move topic to another space',
                currentSpaceId
            );
            if ( ! spaceId ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/posts/${ postId }/move`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': state._nonce || state.nonce,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify( { target_space_id: parseInt( spaceId, 10 ) } ),
                } );

                if ( res.ok ) {
                    const data = yield res.json();
                    if ( window.bnToast ) {
                        window.bnToast( state.i18n?.topicMoved || 'Topic moved successfully.' );
                    }
                    const base = state.communityBase || '/community';
                    setTimeout( () => {
                        window.location.href = `${ base }/s/${ data.space_slug }/t/${ data.slug }/`;
                    }, 600 );
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) {
                        window.bnToast( err.message || state.i18n?.moveFailed || 'Failed to move topic.' );
                    }
                }
            } catch {
                if ( window.bnToast ) {
                    window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
                }
            }
        },

        // ── Merge post (topic) into another ──
        *mergePost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            const spaceId = el.ref.dataset.spaceId;
            if ( ! postId ) return;

            // Prompt for target post ID via search
            const targetId = yield jetonomyPostPicker( state.i18n?.mergeTopicTitle || 'Merge into another topic', postId, spaceId );
            if ( ! targetId ) return;

            if ( ! ( yield jetonomyConfirm( state.i18n?.confirmMerge || 'Merge this topic into the selected one? All replies will be moved and this topic will be deleted.' ) ) ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/posts/${ postId }/merge`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': state._nonce || state.nonce,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify( { target_post_id: parseInt( targetId, 10 ) } ),
                } );

                if ( res.ok ) {
                    const data = yield res.json();
                    if ( window.bnToast ) {
                        window.bnToast( state.i18n?.topicMerged || 'Topics merged successfully.' );
                    }
                    const base = state.communityBase || '/community';
                    setTimeout( () => {
                        window.location.href = `${ base }/s/${ data.space_slug }/t/${ data.slug }/`;
                    }, 600 );
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) {
                        window.bnToast( err.message || state.i18n?.mergeFailed || 'Failed to merge topics.' );
                    }
                }
            } catch {
                if ( window.bnToast ) {
                    window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
                }
            }
        },

        // ── Split reply to new topic ──
        *splitReply( event ) {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            const spaceId = el.ref.dataset.spaceId;
            if ( ! replyId ) return;

            const title = yield jetonomyPrompt( state.i18n?.splitReplyTitle || 'Enter a title for the new topic:', '' );
            if ( ! title ) return;

            try {
                const body = { title };
                if ( spaceId ) {
                    body.space_id = parseInt( spaceId, 10 );
                }

                const res = yield fetch( `${ state.apiBase }/replies/${ replyId }/split`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': state._nonce || state.nonce,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify( body ),
                } );

                if ( res.ok ) {
                    const data = yield res.json();
                    if ( window.bnToast ) {
                        window.bnToast( state.i18n?.replySplit || 'Reply split into new topic.' );
                    }
                    const base = state.communityBase || '/community';
                    setTimeout( () => {
                        window.location.href = `${ base }/s/${ data.space_slug }/t/${ data.slug }/`;
                    }, 600 );
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) {
                        window.bnToast( err.message || state.i18n?.splitFailed || 'Failed to split reply.' );
                    }
                }
            } catch {
                if ( window.bnToast ) {
                    window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
                }
            }
        },

        // ── Delete post (topic) ──
        *deletePost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            const spaceSlug = el.ref.dataset.spaceSlug;
            if ( ! postId ) return;

            if ( ! ( yield jetonomyConfirm( state.i18n?.confirmDeletePost || 'Are you sure you want to delete this topic?' ) ) ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/posts/${ postId }`, {
                    method: 'DELETE',
                    headers: {
                        'X-WP-Nonce': state._nonce || state.nonce,
                    },
                    credentials: 'same-origin',
                } );

                if ( res.ok ) {
                    // Redirect to space listing
                    const base = state.communityBase || '/community';
                    window.location.href = spaceSlug ? `${ base }/s/${ spaceSlug }/` : `${ base }/`;
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedDelete || 'Failed to delete.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Delete reply ──
        *deleteReply( event ) {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            if ( ! ( yield jetonomyConfirm( state.i18n?.confirmDeleteReply || 'Are you sure you want to delete this reply?' ) ) ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/replies/${ replyId }`, {
                    method: 'DELETE',
                    headers: {
                        'X-WP-Nonce': state._nonce || state.nonce,
                    },
                    credentials: 'same-origin',
                } );

                if ( res.ok ) {
                    const replyEl = el.ref.closest( '.jt-reply' );
                    if ( replyEl ) {
                        replyEl.style.opacity = '0.3';
                        replyEl.style.pointerEvents = 'none';
                        setTimeout( () => replyEl.remove(), 300 );
                    }
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedDelete || 'Failed to delete.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Accept reply as best answer (Q&A) ──
        *acceptReply( event ) {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            try {
                const res = yield fetch( `${ state.apiBase }/replies/${ replyId }/accept`, {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': state._nonce || state.nonce },
                    credentials: 'same-origin',
                } );

                if ( res.ok ) {
                    if ( window.bnToast ) window.bnToast( state.i18n?.accepted || 'Accepted' );
                    setTimeout( () => window.location.reload(), 600 );
                } else {
                    const err = yield res.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || 'Failed to accept.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error.' );
            }
        },

        // ── Toggle collapsible thread ──
        toggleThread() {
            const ctx = getContext();
            ctx.collapsed = ! ctx.collapsed;
        },

        // ── Sort ──
        changeSort( event ) {
            const sort = event.target.dataset.sort;
            if ( ! sort || sort === state.currentSort ) return;

            state.currentSort = sort;
            // Reload page with new sort param
            const url = new URL( window.location );
            url.searchParams.set( 'sort', sort );
            window.location = url.toString();
        },

        // ── Composer ──
        showReplyComposer( event ) {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            state.composerVisible = true;
            state.composerReplyTo = replyId || null;
            // Scroll to composer
            const composer = document.getElementById( 'jt-composer' );
            if ( composer ) {
                composer.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                const input = composer.querySelector( '[contenteditable]' );
                if ( input ) input.focus();
            }
        },

        cancelReplyComposer() {
            state.composerVisible = false;
            state.composerReplyTo = null;
            state.replyToId = null;
            state.replyToAuthor = '';

            // Remove the reply-to indicator.
            const composer = document.getElementById( 'jt-composer' );
            if ( composer ) {
                const existing = composer.querySelector( '.jt-replying-to' );
                if ( existing ) existing.remove();
            }
        },

        // ── Set reply-to parent (threaded replies) ──
        setReplyTo() {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            const authorName = el.ref.dataset.replyAuthor;
            state.replyToId = replyId ? parseInt( replyId, 10 ) : null;
            state.replyToAuthor = authorName || '';
            state.composerReplyTo = replyId || null;

            // Show "Replying to X" indicator in composer.
            const composer = document.getElementById( 'jt-composer' );
            if ( composer ) {
                // Remove existing indicator.
                const existing = composer.querySelector( '.jt-replying-to' );
                if ( existing ) existing.remove();

                if ( replyId ) {
                    const indicator = document.createElement( 'div' );
                    indicator.className = 'jt-replying-to';

                    const textNode = document.createTextNode( ( state.i18n?.replyingTo || 'Replying to' ) + ' ' );
                    const strong = document.createElement( 'strong' );
                    strong.textContent = authorName || 'reply';
                    const cancelBtn = document.createElement( 'button' );
                    cancelBtn.className = 'jt-replying-to-cancel';
                    cancelBtn.setAttribute( 'aria-label', state.i18n?.cancelReply || 'Cancel reply' );
                    cancelBtn.textContent = '\u2715';
                    cancelBtn.addEventListener( 'click', () => {
                        indicator.remove();
                        state.replyToId = null;
                        state.replyToAuthor = '';
                        state.composerReplyTo = null;
                    } );

                    indicator.appendChild( textNode );
                    indicator.appendChild( strong );
                    indicator.appendChild( cancelBtn );
                    composer.prepend( indicator );
                }

                const input = composer.querySelector( '[contenteditable]' );
                if ( input ) {
                    input.focus();
                    composer.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                }
            }
        },

        // ── Quote reply into composer ──
        quoteReply() {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            const authorName = el.ref.dataset.replyAuthor || 'Someone';

            // Find the reply body text.
            const replyEl = el.ref.closest( '.jt-reply' );
            if ( ! replyEl ) return;
            const bodyEl = replyEl.querySelector( '.jt-reply-body' );
            if ( ! bodyEl ) return;

            // Get plain text, trimmed to ~300 chars.
            let text = ( bodyEl.textContent || '' ).trim();
            if ( text.length > 300 ) {
                text = text.substring( 0, 297 ) + '...';
            }

            // Build blockquote HTML.
            const cite = document.createElement( 'cite' );
            cite.textContent = authorName;
            const blockquote = document.createElement( 'blockquote' );
            blockquote.className = 'jt-quote';
            blockquote.appendChild( cite );
            const p = document.createElement( 'p' );
            p.textContent = text;
            blockquote.appendChild( p );

            // Insert into composer.
            const composer = document.getElementById( 'jt-composer' );
            if ( ! composer ) return;
            const input = composer.querySelector( '[contenteditable]' );
            if ( ! input ) return;

            // Prepend the blockquote + a blank line for the user's reply.
            input.focus();
            input.insertBefore( blockquote, input.firstChild );
            const br = document.createElement( 'br' );
            if ( blockquote.nextSibling ) {
                input.insertBefore( br, blockquote.nextSibling );
            } else {
                input.appendChild( br );
            }

            // Also set reply-to parent for threading.
            state.replyToId = replyId ? parseInt( replyId, 10 ) : null;
            state.replyToAuthor = authorName;
            state.composerReplyTo = replyId || null;

            composer.scrollIntoView( { behavior: 'smooth', block: 'center' } );

            // Place cursor after the blockquote.
            const sel = window.getSelection();
            const range = document.createRange();
            range.setStartAfter( br );
            range.collapse( true );
            sel.removeAllRanges();
            sel.addRange( range );
        },

        // ── Load gap replies (in-between) ──
        *loadGapReplies() {
            const ctx = getContext();
            if ( ctx.loading ) return;
            ctx.loading = true;

            try {
                const response = yield fetch(
                    `${ state.apiBase }/posts/${ ctx.postId }/replies?sort=oldest&limit=${ ctx.gapCount }&offset=${ ctx.gapStart }`,
                    { headers: { 'X-WP-Nonce': state.nonce }, credentials: 'same-origin' }
                );

                if ( ! response.ok ) return;
                const result = yield response.json();
                const replies = result.data || result;

                if ( ! replies.length ) return;

                // Find the gap element and insert replies before it.
                const elRef = getElement();
                const gap = elRef.ref.closest( '.jt-load-gap' );
                if ( ! gap ) return;

                for ( const reply of replies ) {
                    const html = jetonomyBuildReplyHtml( reply );
                    gap.insertAdjacentHTML( 'beforebegin', html );
                }

                // Remove the gap loader.
                gap.remove();
            } catch {
                // silent
            } finally {
                ctx.loading = false;
            }
        },

        // ── Editor input tracking ──
        onEditorInput() {
            // Placeholder for editor input handling (e.g. auto-save, char count).
            // The contenteditable body dispatches this on every keystroke.
        },

        // ── Moderation ──
        *dismissFlag( event ) {
            const el = getElement();
            const flagId = el.ref.dataset.flagId;
            const flagAction = el.ref.dataset.action; // 'approved' or 'dismissed'
            if ( ! flagId ) return;

            // Map template values to API-expected enum: 'valid' or 'dismissed'.
            const apiStatus = flagAction === 'approved' ? 'valid' : 'dismissed';

            try {
                const response = yield fetch(
                    `${ state.apiBase }/moderation/flags/${ flagId }/resolve`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state._nonce || state.nonce,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify( { status: apiStatus } ),
                    }
                );
                if ( response.ok ) {
                    const card = el.ref.closest( '.jt-mod-flag' );
                    if ( card ) card.remove();
                    if ( window.bnToast ) window.bnToast( apiStatus === 'valid' ? 'Content removed' : 'Flag dismissed' );
                } else {
                    if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Failed' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Reply submission ──
        *submitReply() {
            const el = getElement();
            const postId = el.ref.dataset.postId || state.currentPostId;
            const replyTo = el.ref.dataset.replyTo || state.composerReplyTo || null;

            // Find the closest editor container (the .jt-editor wrapper)
            const editorWrap = el.ref.closest( '.jt-editor' );
            const body = editorWrap?.querySelector( '[contenteditable]' )
                || document.getElementById( `jt-composer-${ postId }` );
            if ( ! body || ! body.innerHTML.trim() ) return;

            const ctx = getContext();
            ctx.submitting = true;

            // Only include parent_id when it resolves to a positive integer.
            // Passing null/undefined/"null" would fail REST schema validation.
            const rawParentId = state.replyToId || ( replyTo ? parseInt( replyTo, 10 ) : null );
            const parentId    = rawParentId && Number.isInteger( rawParentId ) && rawParentId > 0
                ? rawParentId
                : null;

            // Get CAPTCHA token if a provider is active.
            let captchaToken = '';
            if ( window.jetonomyCaptcha ) {
                if ( window.jetonomyCaptcha.provider === 'recaptcha_v3' && window.grecaptcha ) {
                    captchaToken = yield new Promise( ( r ) => window.grecaptcha.execute( window.jetonomyCaptcha.siteKey, { action: 'submit' } ).then( r ) );
                } else if ( window.jetonomyCaptcha.provider === 'turnstile' ) {
                    const tsInput = document.querySelector( '[name="cf-turnstile-response"]' );
                    captchaToken = tsInput ? ( tsInput.value || '' ) : '';
                }
            }

            try {
                const response = yield fetch(
                    `${ state.apiBase }/posts/${ postId }/replies`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state.nonce,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify( {
                            content: body.innerHTML,
                            ...( parentId && { parent_id: parentId } ),
                            ...( captchaToken && { captcha_token: captchaToken } ),
                        } ),
                    }
                );

                if ( response.ok ) {
                    window.location.reload();
                } else {
                    const err = yield response.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedSave || 'Failed to post reply.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            } finally {
                ctx.submitting = false;
            }
        },

        // ── Publish mode menu ──
        togglePublishMenu() {
            state.publishMenuOpen = ! state.publishMenuOpen;
        },

        selectPublishNow() {
            const ctx = getContext();
            ctx.postStatus    = 'publish';
            ctx.showScheduler = false;
            state.publishMenuOpen = false;
            state.submitLabel = state.i18n?.postTopic || 'Post Topic';
        },

        selectSaveDraft() {
            const ctx = getContext();
            ctx.postStatus    = 'draft';
            ctx.showScheduler = false;
            state.publishMenuOpen = false;
            state.submitLabel = state.i18n?.saveDraft || 'Save Draft';
        },

        selectSchedule() {
            const ctx = getContext();
            ctx.postStatus    = 'draft';
            ctx.showScheduler = true;
            state.publishMenuOpen = false;
            state.submitLabel = state.i18n?.schedule || 'Schedule';
        },

        // ── New post submission ──
        *submitNewPost( event ) {
            event.preventDefault();
            const ctx = getContext();
            state.isSubmitting = true;
            state.submitLabel = state.i18n?.posting || 'Posting...';
            // Clear any prior inline error surfaced from a previous attempt.
            state.submitError = '';

            // Close publish menu if open.
            state.publishMenuOpen = false;

            const form     = getElement().ref;
            const title    = form.querySelector('[name="title"]')?.value?.trim();
            const bodyEl   = form.querySelector('[contenteditable]');
            // innerHTML is what we send to the server (preserves Markdown,
            // inline formatting, embeds). textContent is what we validate
            // against, because an "interacted-with-then-cleared" contentedi-
            // table commonly leaves <br> or &nbsp; in innerHTML which are
            // truthy — that false-negative was the root cause of Basecamp
            // 9808714691 ("no validation message is shown for empty content").
            const content      = bodyEl?.innerHTML?.trim() || '';
            const contentPlain = ( bodyEl?.textContent || '' ).trim();
            const tags     = form.querySelector('[name="tags"]')?.value?.trim();

            const resetForRetry = () => {
                state.isSubmitting = false;
                state.submitLabel  = state.i18n?.postTopic || 'Post Topic';
            };

            if ( ! title ) {
                state.submitError = state.i18n?.titleRequired || 'Please enter a title for your topic.';
                resetForRetry();
                return;
            }
            if ( ! contentPlain ) {
                state.submitError = state.i18n?.bodyRequired || 'Please add some details before posting.';
                resetForRetry();
                return;
            }

            // Determine post status and optional scheduled date. The scheduler
            // uses two separate native inputs (type=date + type=time) instead
            // of a single `datetime-local` because Firefox's native
            // datetime-local picker exposes only the date portion — see
            // Basecamp #9788118420. Combine them into an ISO-like local
            // datetime string here so the REST payload stays unchanged.
            const postStatus   = ctx.postStatus || 'publish';
            let publishedAt = '';
            if ( ctx.showScheduler ) {
                const dateVal = form.querySelector('[name="published_date"]')?.value?.trim() || '';
                const timeVal = form.querySelector('[name="published_time"]')?.value?.trim() || '';
                if ( dateVal && timeVal ) {
                    // time input omits seconds when step >= 60; normalise to HH:MM:SS.
                    const normalisedTime = /^\d{2}:\d{2}$/.test( timeVal ) ? timeVal + ':00' : timeVal;
                    publishedAt = dateVal + 'T' + normalisedTime;
                } else if ( dateVal ) {
                    publishedAt = dateVal + 'T00:00:00';
                }
            }

            // Validate scheduler: a scheduled post needs a future date.
            if ( 'draft' === postStatus && ctx.showScheduler && ! publishedAt ) {
                state.isSubmitting = false;
                state.submitLabel = state.i18n?.schedule || 'Schedule';
                if ( window.bnToast ) window.bnToast( state.i18n?.scheduleDateRequired || 'Please choose a publish date and time.' );
                return;
            }

            // Get CAPTCHA token if a provider is active.
            let captchaToken = '';
            if ( window.jetonomyCaptcha ) {
                if ( window.jetonomyCaptcha.provider === 'recaptcha_v3' && window.grecaptcha ) {
                    captchaToken = yield new Promise( ( r ) => window.grecaptcha.execute( window.jetonomyCaptcha.siteKey, { action: 'submit' } ).then( r ) );
                } else if ( window.jetonomyCaptcha.provider === 'turnstile' ) {
                    const tsInput = document.querySelector( '[name="cf-turnstile-response"]' );
                    captchaToken = tsInput ? ( tsInput.value || '' ) : '';
                }
            }

            try {
                const response = yield fetch(
                    `${ state.apiBase }/spaces/${ ctx.spaceId }/posts`,
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state.nonce },
                        credentials: 'same-origin',
                        body: JSON.stringify( {
                            title,
                            content,
                            type: ctx.postType,
                            tags: tags ? tags.split( ',' ).map( t => t.trim() ) : [],
                            status: postStatus,
                            prefix: form.querySelector('[name="prefix"]')?.value || '',
                            is_private: !! form.querySelector('[name="is_private"]')?.checked,
                            ...( publishedAt && { published_at: publishedAt } ),
                            ...( captchaToken && { captcha_token: captchaToken } ),
                        } ),
                    }
                );
                if ( ! response.ok ) {
                    const err = yield response.json().catch( () => ( {} ) );
                    const errMsg = err.message || state.i18n?.failedSave || 'Failed to create post.';
                    // Surface inline above the composer — does not rely on
                    // window.bnToast, which is only present when BuddyNext is
                    // active. Without this, a REST 403 / 400 on this endpoint
                    // left the composer with no feedback at all.
                    state.submitError = errMsg;
                    if ( window.bnToast ) window.bnToast( errMsg );
                    return;
                }
                const data = yield response.json();
                if ( data.id || data.data?.id ) {
                    const status = data.status || data.data?.status || 'publish';
                    if ( 'draft' === status ) {
                        state.submitLabel = state.i18n?.saveDraft || 'Save Draft';
                        state.isSubmitting = false;
                        if ( window.bnToast ) window.bnToast( state.i18n?.draftSaved || 'Draft saved. You can find it in your profile under Drafts.' );
                        return;
                    }
                    if ( 'pending' === status || 'spam' === status ) {
                        state.submitLabel = state.i18n?.postTopic || 'Post Topic';
                        state.isSubmitting = false;
                        if ( window.bnToast ) window.bnToast( state.i18n?.pendingNotice || 'Your post is awaiting moderation and will appear once approved.' );
                        return;
                    }
                    const slug = data.slug || data.data?.slug || '';
                    window.location.href = `${ state.communityBase }/s/${ ctx.spaceSlug }/t/${ slug }/`;
                }
            } catch {
                const errMsg = state.i18n?.networkError || 'Network error. Please try again.';
                state.submitError = errMsg;
                if ( window.bnToast ) window.bnToast( errMsg );
            } finally {
                state.isSubmitting = false;
                state.submitLabel = state.i18n?.postTopic || 'Post Topic';
            }
        },

        // ── Profile save ──
        *saveProfile( event ) {
            event.preventDefault();
            const ctx = getContext();
            state.isSubmitting = true;

            const form = getElement().ref;
            const displayName = form.querySelector('[name="display_name"]')?.value;
            const bio = form.querySelector('[name="bio"]')?.value;

            // Collect notification preferences from toggle checkboxes.
            const notifPrefs = {};
            form.querySelectorAll( '[name^="notification_preferences"]' ).forEach( input => {
                const match = input.name.match( /\[(\w+)\]\[(\w+)\]/ );
                if ( match ) {
                    const [ , type, channel ] = match;
                    if ( ! notifPrefs[ type ] ) notifPrefs[ type ] = {};
                    notifPrefs[ type ][ channel ] = input.checked;
                }
            } );

            const payload = { display_name: displayName, bio };
            if ( Object.keys( notifPrefs ).length > 0 ) {
                payload.notification_preferences = notifPrefs;
            }

            try {
                const response = yield fetch(
                    `${ state.apiBase }/users/me`,
                    {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state.nonce },
                        credentials: 'same-origin',
                        body: JSON.stringify( payload ),
                    }
                );
                if ( response.ok ) {
                    window.location.href = ctx.profileUrl;
                } else {
                    const err = yield response.json().catch( () => ( {} ) );
                    if ( window.bnToast ) window.bnToast( err.message || 'Failed to save profile.', 'error' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.', 'error' );
            } finally {
                state.isSubmitting = false;
            }
        },

        // ── Load More Replies (no page reload) ──
        *loadMoreReplies() {
            const ctx = getContext();
            if ( ctx.loading || ! ctx.hasMore ) return;

            ctx.loading = true;

            try {
                const response = yield fetch(
                    `${ state.apiBase }/posts/${ ctx.postId }/replies?sort=${ ctx.sort }&limit=20&offset=${ ctx.loadedCount }`,
                    {
                        headers: { 'X-WP-Nonce': state.nonce },
                        credentials: 'same-origin',
                    }
                );

                if ( ! response.ok ) return;
                const result = yield response.json();
                const replies = result.data || result;

                if ( ! replies.length ) {
                    ctx.hasMore = false;
                    return;
                }

                // Render replies and append to container
                const container = document.getElementById( 'jt-replies-container' );
                if ( ! container ) return;

                for ( const reply of replies ) {
                    container.insertAdjacentHTML( 'beforeend', jetonomyBuildReplyHtml( reply ) );
                }

                ctx.loadedCount += replies.length;
                ctx.lastReplyId = replies[ replies.length - 1 ].id;

                // Update remaining count on button
                const remaining = ctx.totalReplies - ctx.loadedCount;
                if ( remaining <= 0 ) {
                    ctx.hasMore = false;
                }
            } catch {
                // silent
            } finally {
                ctx.loading = false;
            }
        },

        // ── Notification polling ──
        *pollNotifications() {
            if ( ! state.nonce ) return;

            try {
                const response = yield fetch(
                    `${ state.apiBase }/notifications/unread-count`,
                    {
                        headers: { 'X-WP-Nonce': state.nonce },
                        credentials: 'same-origin',
                    }
                );
                if ( response.ok ) {
                    const data = yield response.json();
                    state.unreadCount = data.count || 0;
                }
            } catch {
                // Silent fail for polling
            }
        },

        // ── Compose Topic embed (1.3.7) ──
        // Inline topic composer usable on any WordPress page — fixed-space or
        // member picker. Shares submit plumbing with submitNewPost but works
        // against local context only (no form element / CAPTCHA / scheduler).
        composeTopicSelectSpace( e ) {
            const ctx = getContext();
            ctx.spaceId = parseInt( e.target.value, 10 ) || 0;
            ctx.error   = '';
        },
        composeTopicTitleInput( e ) {
            const ctx = getContext();
            ctx.title = e.target.value;
            if ( ctx.error ) ctx.error = '';
        },
        composeTopicBodyInput( e ) {
            const ctx = getContext();
            ctx.body = e.target.value;
            if ( ctx.error ) ctx.error = '';
        },
        *composeTopicSubmit() {
            const ctx = getContext();
            ctx.error = '';

            if ( ! ctx.spaceId ) {
                ctx.error = state.i18n?.chooseSpace || 'Choose a space first.';
                return;
            }
            if ( ! ctx.title || ! ctx.title.trim() ) {
                ctx.error = state.i18n?.titleRequired || 'Title is required.';
                return;
            }

            ctx.submitting = true;
            try {
                const res = yield fetch(
                    `${ state.apiBase }/spaces/${ ctx.spaceId }/posts`,
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state.nonce,
                        },
                        body: JSON.stringify( {
                            title:   ctx.title.trim(),
                            content: ( ctx.body || '' ).trim(),
                            type:    ctx.postType || 'topic',
                        } ),
                    }
                );

                if ( ! res.ok ) {
                    const err = yield res.json().catch( () => ( {} ) );
                    ctx.error = ( err && err.message )
                        ? err.message
                        : ( state.i18n?.couldNotCreate || 'Could not create the topic.' );
                    ctx.submitting = false;
                    return;
                }

                const data      = yield res.json();
                const slug      = data.slug || data.data?.slug || '';
                const spaceSlug = data.space_slug || data.data?.space_slug || '';
                if ( slug && spaceSlug && state.communityBase ) {
                    window.location.href = `${ state.communityBase }/s/${ spaceSlug }/t/${ slug }/`;
                    return;
                }
                // Fallback: hard reload so the user sees their new topic
                // wherever the embed hosts page redirects them.
                window.location.reload();
            } catch {
                ctx.error = state.i18n?.networkError || 'Network error — try again.';
                ctx.submitting = false;
            }
        },
    },

    callbacks: {
        // Start polling on init
        startPolling() {
            // Poll every 30 seconds
            setInterval( () => {
                actions.pollNotifications();
            }, 30000 );
        },

        // Auto-trigger gap loading when the user scrolls to it (infinite scroll).
        // Gated on a real scroll event so a trigger that is already inside the
        // initial viewport (e.g. when posts_per_page=1 on a short space) does
        // not auto-fire on page load. The Load More button stays clickable either
        // way, so users who want more without scrolling can still request it.
        initInfiniteScroll() {
            const gaps = document.querySelectorAll( '.jt-load-gap' );
            if ( ! gaps.length ) return;

            let userHasScrolled = false;
            const markScrolled = () => {
                userHasScrolled = true;
                window.removeEventListener( 'scroll', markScrolled );
            };
            window.addEventListener( 'scroll', markScrolled, { passive: true } );

            const observer = new IntersectionObserver( ( entries ) => {
                if ( ! userHasScrolled ) return;
                entries.forEach( ( entry ) => {
                    if ( entry.isIntersecting ) {
                        const btn = entry.target.querySelector( '.jt-load-gap-btn' );
                        if ( btn && ! btn.disabled ) {
                            btn.click();
                            observer.unobserve( entry.target );
                        }
                    }
                } );
            }, { rootMargin: '200px' } );

            gaps.forEach( ( gap ) => observer.observe( gap ) );
        },

        // On mobile, the profile tabs row is horizontally scrollable to fit
        // all five tabs. When the viewer lands on a sub-page whose tab sits
        // off-screen (e.g. /drafts/ at 390px), they can't see the active
        // underline without scrolling the tab row manually. Scroll the
        // active tab into view once on load so the indicator is visible.
        initProfileTabsActive() {
            const container = document.querySelector( '.jt-profile-tabs' );
            if ( ! container ) return;
            if ( container.scrollWidth <= container.clientWidth ) return;
            const active = container.querySelector( '.jt-profile-tab.active' );
            if ( ! active ) return;
            active.scrollIntoView( { inline: 'nearest', block: 'nearest', behavior: 'instant' } );
        },

        // Poll for new replies and show a sticky banner
        initReplyPolling() {
            const repliesSection = document.getElementById( 'jt-replies-container' );
            if ( ! repliesSection || ! state.currentPostId ) return;

            let lastCheck = Date.now();

            setInterval( async () => {
                try {
                    const since = new Date( lastCheck ).toISOString();
                    const response = await fetch(
                        `${ state.apiBase }/updates?scope=post&id=${ state.currentPostId }&since=${ encodeURIComponent( since ) }`,
                        { headers: { 'X-WP-Nonce': state.nonce }, credentials: 'same-origin' }
                    );
                    if ( ! response.ok ) return;
                    const data = await response.json();
                    const newReplies = ( data.data || [] ).length;

                    if ( newReplies > 0 ) {
                        let banner = document.querySelector( '.jt-new-replies-banner' );
                        if ( ! banner ) {
                            banner = document.createElement( 'div' );
                            banner.className = 'jt-new-replies-banner';
                            banner.addEventListener( 'click', () => {
                                window.location.reload();
                            } );
                            repliesSection.parentElement.appendChild( banner );
                        }
                        banner.textContent = `${ newReplies } new ${ newReplies === 1 ? 'reply' : 'replies' } — click to refresh`;
                    }

                    lastCheck = Date.now();
                } catch {
                    // silent
                }
            }, 15000 );
        },
    },
} );

/* ── Link Preview Cards ──
   Renders a LinkedIn / Twitter / Facebook style rich card beneath any standalone
   link inside .jt-post-body / .jt-reply-body. Response shape comes from
   GET /jetonomy/v1/link-preview (Jetonomy\Services\Links\Preview_Data) so the
   same endpoint drives the native mobile app. */
( function() {
    const DATA     = window.jetonomyData || {};
    const API_BASE = DATA.restBase || '/wp-json/jetonomy/v1';
    const NONCE    = DATA.restNonce || '';

    const MAX_PER_CONTAINER = 3;
    const DESC_MAX          = 200;
    // Embed HTML comes from the REST response, which is kses-sanitised on the
    // server (see OEmbed_Provider::sanitize_embed — iframe/blockquote/p/a/br
    // only, no script, no on* attrs). We further restrict to this tag allowlist
    // client-side as a belt-and-braces measure before attaching to the DOM.
    const EMBED_ALLOWED_TAGS = new Set( [ 'IFRAME', 'BLOCKQUOTE', 'P', 'A', 'BR' ] );

    function shouldPreview( a ) {
        if ( ! a.parentElement || a.parentElement.tagName !== 'P' ) return false;
        if ( a.parentElement.children.length !== 1 )                return false;
        if ( a.classList.contains( 'jt-mention' ) )                 return false;
        if ( a.classList.contains( 'jt-tag-link' ) )                return false;
        if ( a.closest( '.jt-link-preview' ) )                      return false;
        const href = a.getAttribute( 'href' ) || '';
        if ( ! /^https?:\/\//i.test( href ) )                       return false;
        const text = ( a.textContent || '' ).trim();
        return /^https?:\/\//i.test( text );
    }

    function fetchPreview( href ) {
        const headers = { 'Accept': 'application/json' };
        if ( NONCE ) headers[ 'X-WP-Nonce' ] = NONCE;
        return fetch( API_BASE + '/link-preview?url=' + encodeURIComponent( href ), {
            headers,
            credentials: 'same-origin',
        } ).then( r => r.ok ? r.json() : null );
    }

    function el( tag, cls, text ) {
        const node = document.createElement( tag );
        if ( cls ) node.className = cls;
        if ( text !== undefined && text !== null && text !== '' ) node.textContent = text;
        return node;
    }

    function renderCard( data, href ) {
        const card = el( 'a', 'jt-link-preview' );
        card.href = href;
        card.target = '_blank';
        card.rel = 'noopener noreferrer';
        card.setAttribute( 'data-provider', data.provider || 'generic' );

        if ( data.image ) {
            const media = el( 'div', 'jt-link-preview-media' );
            const img = new Image();
            img.src = data.image;
            img.alt = data.image_alt || '';
            img.loading = 'lazy';
            img.decoding = 'async';
            img.className = 'jt-link-preview-img';
            img.onerror = function() { media.remove(); card.classList.add( 'jt-link-preview--no-media' ); };
            media.appendChild( img );
            card.appendChild( media );
        } else {
            card.classList.add( 'jt-link-preview--no-media' );
        }

        const body = el( 'div', 'jt-link-preview-body' );

        const meta = el( 'div', 'jt-link-preview-meta' );
        if ( data.favicon ) {
            const fav = new Image();
            fav.src = data.favicon;
            fav.alt = '';
            fav.loading = 'lazy';
            fav.className = 'jt-link-preview-favicon';
            fav.onerror = function() { fav.remove(); };
            meta.appendChild( fav );
        }
        meta.appendChild( el( 'span', 'jt-link-preview-domain', data.site_name || data.domain ) );
        body.appendChild( meta );

        if ( data.title ) {
            body.appendChild( el( 'strong', 'jt-link-preview-title', data.title ) );
        }
        if ( data.description ) {
            const desc = data.description.length > DESC_MAX
                ? data.description.slice( 0, DESC_MAX ).trim() + '…'
                : data.description;
            body.appendChild( el( 'p', 'jt-link-preview-desc', desc ) );
        }
        if ( data.author || data.published_at ) {
            const foot = el( 'div', 'jt-link-preview-foot' );
            if ( data.author ) foot.appendChild( el( 'span', 'jt-link-preview-author', data.author ) );
            if ( data.published_at ) {
                const d = new Date( data.published_at );
                if ( ! isNaN( d.getTime() ) ) {
                    foot.appendChild( el( 'time', 'jt-link-preview-date', d.toLocaleDateString() ) );
                }
            }
            body.appendChild( foot );
        }

        card.appendChild( body );
        return card;
    }

    function renderEmbed( data ) {
        // Parse the server-sanitised embed HTML into an isolated DOMParser
        // document, then copy over only allowlisted elements. This defends
        // against the hypothetical case where the server allowlist was loosened
        // without updating the client.
        const wrap = document.createElement( 'div' );
        wrap.className = 'jt-link-embed';
        wrap.setAttribute( 'data-provider', data.provider || 'generic' );

        const parsed = new DOMParser().parseFromString( data.embed_html || '', 'text/html' );
        parsed.body.querySelectorAll( '*' ).forEach( function( node ) {
            if ( ! EMBED_ALLOWED_TAGS.has( node.tagName ) ) node.remove();
        } );
        Array.from( parsed.body.childNodes ).forEach( function( node ) {
            wrap.appendChild( node );
        } );
        return wrap;
    }

    // Mirror of jetonomy_maybe_enqueue_embed_scripts() for the client-side
    // insertion path — when we inject a TikTok/Instagram/Twitter blockquote
    // after page load, we still need the provider hydration script to run.
    // Each provider is loaded once per document; rescan calls are safe to
    // repeat and cheap after the first.
    const EMBED_SCRIPT_SOURCES = {
        tiktok:    'https://www.tiktok.com/embed.js',
        instagram: 'https://www.instagram.com/embed.js',
        twitter:   'https://platform.twitter.com/widgets.js',
        facebook:  'https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v19.0',
    };
    const loadedEmbedScripts = new Set();

    function ensureEmbedScript( provider ) {
        if ( ! EMBED_SCRIPT_SOURCES[ provider ] ) return Promise.resolve();
        if ( loadedEmbedScripts.has( provider ) ) return Promise.resolve();
        loadedEmbedScripts.add( provider );
        return new Promise( function( resolve ) {
            const s = document.createElement( 'script' );
            s.async = true;
            s.src = EMBED_SCRIPT_SOURCES[ provider ];
            s.onload = s.onerror = resolve;
            document.body.appendChild( s );
        } );
    }

    function rescanEmbed( provider ) {
        if ( provider === 'instagram' && window.instgrm && window.instgrm.Embeds ) {
            window.instgrm.Embeds.process();
        } else if ( provider === 'twitter' && window.twttr && window.twttr.widgets ) {
            window.twttr.widgets.load();
        } else if ( provider === 'facebook' && window.FB && window.FB.XFBML ) {
            window.FB.XFBML.parse();
        }
        // TikTok's embed.js auto-rescans; nothing to call.
    }

    document.addEventListener( 'DOMContentLoaded', function() {
        document.querySelectorAll( '.jt-post-body, .jt-reply-body' ).forEach( function( body ) {
            const candidates = Array.from( body.querySelectorAll( 'p > a' ) )
                .filter( shouldPreview )
                .slice( 0, MAX_PER_CONTAINER );
            candidates.forEach( function( a ) {
                const href = a.getAttribute( 'href' );
                fetchPreview( href ).then( function( data ) {
                    if ( ! data ) return;
                    if ( data.embed_html ) {
                        a.parentElement.insertAdjacentElement( 'afterend', renderEmbed( data ) );
                        ensureEmbedScript( data.provider ).then( function() {
                            rescanEmbed( data.provider );
                        } );
                        return;
                    }
                    if ( ! data.title && ! data.description && ! data.image ) return;
                    a.parentElement.insertAdjacentElement( 'afterend', renderCard( data, href ) );
                } ).catch( function() { /* preview is best-effort */ } );
            } );
        } );

        // ── Similar Topics — debounced FULLTEXT search on new-post title ──
        const titleInput = document.getElementById( 'jt-post-title' );
        const similarPanel = document.getElementById( 'jt-similar-topics' );
        const similarResults = document.getElementById( 'jt-similar-results' );
        const allSpacesCheck = document.getElementById( 'jt-similar-all-spaces' );

        if ( titleInput && similarPanel && similarResults ) {
            let debounceTimer = null;
            const spaceId = titleInput.dataset.spaceId;
            const SIMILAR_API = API_BASE;
            // Derive community base from breadcrumb or form context.
            const formEl = document.getElementById( 'jt-new-post-form' );
            const ctxData = formEl ? JSON.parse( formEl.dataset.wpContext || '{}' ) : {};
            const spaceSlug = ctxData.spaceSlug || '';
            const SIMILAR_COMMUNITY = ( document.querySelector( '.jt-crumb a' )?.getAttribute( 'href' ) || '/community/' ).replace( /\/$/, '' );

            function buildSimilarItem( p ) {
                const spaceName = p.space_title || p.space_slug || '';
                const replies = p.reply_count || 0;
                const href = SIMILAR_COMMUNITY + '/s/' + ( p.space_slug || '' ) + '/t/' + ( p.slug || p.post_slug || '' ) + '/';

                const a = document.createElement( 'a' );
                a.href = href;
                a.className = 'jt-similar-item';
                a.target = '_blank';
                a.rel = 'noopener';

                const titleSpan = document.createElement( 'span' );
                titleSpan.className = 'jt-similar-title';
                titleSpan.textContent = p.title || '';
                a.appendChild( titleSpan );

                const metaSpan = document.createElement( 'span' );
                metaSpan.className = 'jt-similar-meta';
                metaSpan.textContent = ( spaceName ? spaceName + ' \u00b7 ' : '' ) + replies + ' ' + ( replies === 1 ? 'reply' : 'replies' );
                a.appendChild( metaSpan );

                return a;
            }

            function searchSimilar() {
                const q = titleInput.value.trim();
                if ( q.length < 4 ) {
                    similarPanel.hidden = true;
                    similarResults.textContent = '';
                    return;
                }

                const useAllSpaces = allSpacesCheck && allSpacesCheck.checked;
                let url = SIMILAR_API + '/search?q=' + encodeURIComponent( q ) + '&type=post';
                if ( ! useAllSpaces && spaceId ) {
                    url += '&space_id=' + spaceId;
                }

                fetch( url, { credentials: 'same-origin' } )
                    .then( function( r ) { return r.json(); } )
                    .then( function( res ) {
                        const posts = res.data || [];
                        similarResults.textContent = '';

                        if ( ! posts.length ) {
                            similarPanel.hidden = true;
                            return;
                        }

                        posts.slice( 0, 5 ).forEach( function( p ) {
                            similarResults.appendChild( buildSimilarItem( p ) );
                        } );
                        similarPanel.hidden = false;
                    } )
                    .catch( function() {} );
            }

            titleInput.addEventListener( 'input', function() {
                clearTimeout( debounceTimer );
                debounceTimer = setTimeout( searchSimilar, 400 );
            } );

            if ( allSpacesCheck ) {
                allSpacesCheck.addEventListener( 'change', searchSimilar );
            }
        }
    } );
} )();
