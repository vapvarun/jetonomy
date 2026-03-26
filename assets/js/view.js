/**
 * Jetonomy Interactivity API Store
 * Handles voting, sorting, load-more, and notifications polling
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Custom modal helpers — replace browser alert/confirm/prompt with styled modals.
 */
function jtConfirm( message ) {
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

function jtPrompt( message, placeholder ) {
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
 * Build reply HTML for client-side rendering (used by loadGapReplies and loadMoreReplies).
 */
function buildReplyHtml( reply ) {
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

            // Optimistic update
            const current = state.postScores[ postId ] || 0;
            state.postScores[ postId ] = current + 1;

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
                        body: JSON.stringify( { value: 1 } ),
                    }
                );
                if ( response.ok ) {
                    const data = yield response.json();
                    if ( data.score !== undefined ) {
                        state.postScores[ postId ] = data.score;
                    }
                    if ( window.bnToast && !window._jtVoteToasted ) { window.bnToast( state.i18n?.voteRecorded || 'Vote recorded' ); window._jtVoteToasted = true; setTimeout( () => { window._jtVoteToasted = false; }, 2000 ); }
                } else {
                    // Rollback on error
                    state.postScores[ postId ] = current;
                }
            } catch {
                state.postScores[ postId ] = current;
            }
        },

        *voteDown( event ) {
            event.stopPropagation();
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            const current = state.postScores[ postId ] || 0;
            state.postScores[ postId ] = current - 1;

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
                        body: JSON.stringify( { value: -1 } ),
                    }
                );
                if ( response.ok ) {
                    const data = yield response.json();
                    if ( data.score !== undefined ) {
                        state.postScores[ postId ] = data.score;
                    }
                    if ( window.bnToast && !window._jtVoteToasted ) { window.bnToast( state.i18n?.voteRecorded || 'Vote recorded' ); window._jtVoteToasted = true; setTimeout( () => { window._jtVoteToasted = false; }, 2000 ); }
                } else {
                    state.postScores[ postId ] = current;
                }
            } catch {
                state.postScores[ postId ] = current;
            }
        },

        *voteReplyUp( event ) {
            event.stopPropagation();
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            const scoreEl = el.ref.querySelector( '.n' );
            const current = parseInt( scoreEl?.textContent || '0', 10 );

            state.replyScores[ replyId ] = current + 1;
            if ( scoreEl ) {
                scoreEl.textContent = current + 1;
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
                        body: JSON.stringify( { value: 1 } ),
                    }
                );
                if ( response.ok ) {
                    const data = yield response.json();
                    if ( data.score !== undefined ) {
                        state.replyScores[ replyId ] = data.score;
                        if ( scoreEl ) scoreEl.textContent = data.score;
                    }
                } else {
                    state.replyScores[ replyId ] = current;
                    if ( scoreEl ) scoreEl.textContent = current;
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

            state.replyScores[ replyId ] = current - 1;
            if ( scoreEl ) {
                scoreEl.textContent = current - 1;
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
                        body: JSON.stringify( { value: -1 } ),
                    }
                );
                if ( response.ok ) {
                    const data = yield response.json();
                    if ( data.score !== undefined ) {
                        state.replyScores[ replyId ] = data.score;
                        if ( scoreEl ) scoreEl.textContent = data.score;
                    }
                } else {
                    state.replyScores[ replyId ] = current;
                    if ( scoreEl ) scoreEl.textContent = current;
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

            const plainText = bodyEl.textContent.trim();
            bodyEl.style.display = 'none';

            const editor = document.createElement( 'div' );
            editor.className = 'jt-reply-editor';
            editor.style.cssText = 'margin:8px 0';

            const textarea = document.createElement( 'textarea' );
            textarea.className = 'jt-input';
            textarea.value = plainText;
            textarea.rows = 4;
            textarea.style.cssText = 'width:100%;resize:vertical';

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
            editor.append( textarea, btnRow );
            bodyEl.after( editor );
            textarea.focus();

            cancelBtn.addEventListener( 'click', () => {
                editor.remove();
                bodyEl.style.display = '';
            } );

            saveBtn.addEventListener( 'click', async () => {
                const content = textarea.value.trim();
                if ( ! content ) return;

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
                        // Show edited plain text; server-rendered version loads on reload.
                        bodyEl.textContent = content;
                        editor.remove();
                        bodyEl.style.display = '';
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
                        const subs = data.items || data;
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
                        const subs = data.items || data;
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
        sharePost() {
            const el = getElement();
            const url = el.ref.dataset.postUrl;
            const title = el.ref.dataset.postTitle;
            if ( ! url ) return;

            let dropdown = el.ref.parentElement.querySelector( '.jt-share-dropdown' );
            if ( dropdown ) { dropdown.remove(); return; }

            dropdown = document.createElement( 'div' );
            dropdown.className = 'jt-share-dropdown';

            const encodedUrl = encodeURIComponent( url );
            const encodedTitle = encodeURIComponent( title || '' );

            const items = [
                { label: state.i18n?.copyLink || 'Copy link', icon: '\u{1F517}', action: () => { navigator.clipboard.writeText( url ); if ( window.bnToast ) window.bnToast( state.i18n?.linkCopied || 'Link copied' ); dropdown.remove(); } },
                { label: 'Twitter / X', icon: '\u{1D54F}', href: `https://twitter.com/intent/tweet?url=${ encodedUrl }&text=${ encodedTitle }` },
                { label: 'Facebook', icon: 'f', href: `https://www.facebook.com/sharer/sharer.php?u=${ encodedUrl }` },
                { label: 'LinkedIn', icon: 'in', href: `https://www.linkedin.com/sharing/share-offsite/?url=${ encodedUrl }` },
            ];

            items.forEach( item => {
                const btn = document.createElement( 'button' );
                btn.className = 'jt-share-item';
                btn.type = 'button';
                btn.textContent = `${ item.icon } ${ item.label }`;
                if ( item.href ) {
                    btn.addEventListener( 'click', () => { window.open( item.href, '_blank', 'width=600,height=400' ); dropdown.remove(); } );
                } else if ( item.action ) {
                    btn.addEventListener( 'click', item.action );
                }
                dropdown.appendChild( btn );
            } );

            el.ref.style.position = 'relative';
            el.ref.after( dropdown );

            const closeHandler = ( e ) => {
                if ( ! dropdown.contains( e.target ) && e.target !== el.ref ) {
                    dropdown.remove();
                    document.removeEventListener( 'click', closeHandler );
                }
            };
            setTimeout( () => document.addEventListener( 'click', closeHandler ), 0 );
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

            const reason = yield jtPrompt( state.i18n?.reportPrompt || 'Why are you reporting this post?', state.i18n?.reportPlaceholder || 'Describe the issue...' );
            if ( reason === null ) return; // Cancelled

            try {
                const res = yield fetch( `${ state.apiBase }/flags`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state._nonce || state.nonce },
                    credentials: 'same-origin',
                    body: JSON.stringify( { object_type: 'post', object_id: parseInt( postId ), reason: 'other', detail: reason } ),
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

            const plainText = bodyEl.textContent.trim();
            bodyEl.style.display = 'none';

            const editor = document.createElement( 'div' );
            editor.className = 'jt-post-editor';
            editor.style.cssText = 'margin:8px 0';

            const textarea = document.createElement( 'textarea' );
            textarea.className = 'jt-input';
            textarea.value = plainText;
            textarea.rows = 6;
            textarea.style.cssText = 'width:100%;resize:vertical';

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
            editor.append( textarea, btnRow );
            bodyEl.after( editor );
            textarea.focus();

            cancelBtn.addEventListener( 'click', () => {
                editor.remove();
                bodyEl.style.display = '';
            } );

            saveBtn.addEventListener( 'click', async () => {
                const content = textarea.value.trim();
                if ( ! content ) return;

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
                        bodyEl.textContent = content;
                        editor.remove();
                        bodyEl.style.display = '';
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

        // ── Delete post (topic) ──
        *deletePost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            const spaceSlug = el.ref.dataset.spaceSlug;
            if ( ! postId ) return;

            if ( ! ( yield jtConfirm( state.i18n?.confirmDeletePost || 'Are you sure you want to delete this topic?' ) ) ) return;

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

            if ( ! ( yield jtConfirm( state.i18n?.confirmDeleteReply || 'Are you sure you want to delete this reply?' ) ) ) return;

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

        // ── Load gap replies (in-between) ──
        *loadGapReplies() {
            const ctx = getContext();
            if ( ctx.loading ) return;
            ctx.loading = true;

            try {
                const response = yield fetch(
                    `${ state.apiBase }/posts/${ ctx.postId }/replies?sort=oldest&limit=${ ctx.gapCount }&offset=${ ctx.gapStart }`,
                    { headers: { 'X-WP-Nonce': state.nonce } }
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
                    const html = buildReplyHtml( reply );
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

            try {
                const response = yield fetch(
                    `${ state.apiBase }/moderation/flags/${ flagId }`,
                    {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state.nonce,
                        },
                        body: JSON.stringify( { status: flagAction } ),
                    }
                );
                if ( response.ok ) {
                    // Remove the flag card from the DOM
                    const card = el.ref.closest( '.jt-mod-flag' );
                    if ( card ) card.remove();
                }
            } catch {
                // Silent fail
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

            try {
                const response = yield fetch(
                    `${ state.apiBase }/posts/${ postId }/replies`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': state.nonce,
                        },
                        body: JSON.stringify( {
                            content: body.innerHTML,
                            ...( parentId && { parent_id: parentId } ),
                        } ),
                    }
                );

                if ( response.ok ) {
                    // Reload to show new reply
                    window.location.reload();
                }
            } finally {
                ctx.submitting = false;
            }
        },

        // ── New post submission ──
        *submitNewPost( event ) {
            event.preventDefault();
            const ctx = getContext();
            state.isSubmitting = true;
            state.submitLabel = state.i18n?.posting || 'Posting...';

            const form = getElement().ref;
            const title = form.querySelector('[name="title"]')?.value?.trim();
            const content = form.querySelector('[contenteditable]')?.innerHTML?.trim();
            const tags = form.querySelector('[name="tags"]')?.value?.trim();

            if ( ! title || ! content ) {
                state.isSubmitting = false;
                state.submitLabel = state.i18n?.postTopic || 'Post Topic';
                return;
            }

            try {
                const response = yield fetch(
                    `${ state.apiBase }/spaces/${ ctx.spaceId }/posts`,
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state.nonce },
                        body: JSON.stringify( {
                            title,
                            content,
                            type: ctx.postType,
                            tags: tags ? tags.split( ',' ).map( t => t.trim() ) : [],
                        } ),
                    }
                );
                const data = yield response.json();
                if ( data.id || data.data?.id ) {
                    const status = data.status || data.data?.status || 'publish';
                    if ( 'pending' === status || 'spam' === status ) {
                        // Post held for moderation — stay on the page and notify the user.
                        state.submitLabel = state.i18n?.postTopic || 'Post Topic';
                        state.isSubmitting = false;
                        if ( window.bnToast ) window.bnToast( jetonomyData?.i18n?.pendingNotice || 'Your post is awaiting moderation and will appear once approved.' );
                        return;
                    }
                    const slug = data.slug || data.data?.slug || '';
                    window.location.href = `${ state.communityBase }/s/${ ctx.spaceSlug }/t/${ slug }/`;
                }
            } catch {
                // silent
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
                        body: JSON.stringify( payload ),
                    }
                );
                if ( response.ok ) {
                    window.location.href = ctx.profileUrl;
                }
            } catch {
                // silent
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
                    container.insertAdjacentHTML( 'beforeend', buildReplyHtml( reply ) );
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
    },

    callbacks: {
        // Start polling on init
        startPolling() {
            // Poll every 30 seconds
            setInterval( () => {
                actions.pollNotifications();
            }, 30000 );
        },

        // Auto-trigger gap loading when user scrolls to it (infinite scroll)
        initInfiniteScroll() {
            const gaps = document.querySelectorAll( '.jt-load-gap' );
            if ( ! gaps.length ) return;

            const observer = new IntersectionObserver( ( entries ) => {
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
                        { headers: { 'X-WP-Nonce': state.nonce } }
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
   Scans .jt-post-body and .jt-reply-body for standalone links (only child of a <p>)
   and fetches OG metadata to render a preview card below the link. */
( function() {
    const API_BASE = ( window.jetonomyData && window.jetonomyData.restBase )
        ? window.jetonomyData.restBase
        : '/wp-json/jetonomy/v1';

    document.addEventListener( 'DOMContentLoaded', function() {
        const bodies = document.querySelectorAll( '.jt-post-body, .jt-reply-body' );
        bodies.forEach( function( body ) {
            const links = body.querySelectorAll( 'p > a:only-child' );
            links.forEach( function( a ) {
                const href = a.getAttribute( 'href' );
                if ( ! href || href.startsWith( '#' ) || href.startsWith( '/' ) ) return;
                // Skip internal links (mentions, tags).
                if ( a.classList.contains( 'jt-mention' ) || a.classList.contains( 'jt-tag-link' ) ) return;
                // Only process if the link text IS the URL (bare link).
                const text = ( a.textContent || '' ).trim();
                if ( ! text.startsWith( 'http' ) ) return;

                fetch( API_BASE + '/link-preview?url=' + encodeURIComponent( href ) )
                    .then( function( r ) { return r.json(); } )
                    .then( function( data ) {
                        if ( ! data.title ) return;
                        var card = document.createElement( 'a' );
                        card.href = href;
                        card.className = 'jt-link-preview';
                        card.target = '_blank';
                        card.rel = 'noopener';
                        var cardBody = document.createElement( 'div' );
                        cardBody.className = 'jt-link-preview-body';
                        var title = document.createElement( 'strong' );
                        title.className = 'jt-link-preview-title';
                        title.textContent = data.title;
                        cardBody.appendChild( title );
                        if ( data.description ) {
                            var desc = document.createElement( 'span' );
                            desc.className = 'jt-link-preview-desc';
                            desc.textContent = data.description.substring( 0, 120 );
                            cardBody.appendChild( desc );
                        }
                        var domain = document.createElement( 'span' );
                        domain.className = 'jt-link-preview-domain';
                        domain.textContent = data.domain;
                        cardBody.appendChild( domain );
                        card.appendChild( cardBody );
                        if ( data.image ) {
                            var img = document.createElement( 'img' );
                            img.src = data.image;
                            img.className = 'jt-link-preview-img';
                            img.alt = '';
                            img.loading = 'lazy';
                            card.appendChild( img );
                        }
                        // Insert card after the parent <p>.
                        a.parentElement.insertAdjacentElement( 'afterend', card );
                    } );
            } );
        } );
    } );
} )();
