/**
 * Jetonomy Interactivity API Store
 * Handles voting, sorting, load-more, and notifications polling
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

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

            const current = state.replyScores[ replyId ] || 0;
            state.replyScores[ replyId ] = current + 1;

            // Visual feedback — vote pop
            const scoreEl = el.ref.parentElement?.querySelector( '.n' );
            if ( scoreEl ) {
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
                    }
                } else {
                    state.replyScores[ replyId ] = current;
                }
            } catch {
                state.replyScores[ replyId ] = current;
            }
        },

        *voteReplyDown( event ) {
            event.stopPropagation();
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            const current = state.replyScores[ replyId ] || 0;
            state.replyScores[ replyId ] = current - 1;

            // Visual feedback — vote pop
            const scoreEl = el.ref.parentElement?.querySelector( '.n' );
            if ( scoreEl ) {
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
                    }
                } else {
                    state.replyScores[ replyId ] = current;
                }
            } catch {
                state.replyScores[ replyId ] = current;
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

                    const textNode = document.createTextNode( 'Replying to ' );
                    const strong = document.createElement( 'strong' );
                    strong.textContent = authorName || 'reply';
                    const cancelBtn = document.createElement( 'button' );
                    cancelBtn.className = 'jt-replying-to-cancel';
                    cancelBtn.setAttribute( 'aria-label', 'Cancel reply' );
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
                            ...( ( state.replyToId || replyTo ) && { parent_id: state.replyToId || replyTo } ),
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
            state.submitLabel = 'Posting...';

            const form = getElement().ref;
            const title = form.querySelector('[name="title"]')?.value?.trim();
            const content = form.querySelector('[contenteditable]')?.innerHTML?.trim();
            const tags = form.querySelector('[name="tags"]')?.value?.trim();

            if ( ! title || ! content ) {
                state.isSubmitting = false;
                state.submitLabel = 'Post Topic';
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
                        state.submitLabel = 'Post Topic';
                        state.isSubmitting = false;
                        alert( jetonomyData?.i18n?.pendingNotice || 'Your post is awaiting moderation and will appear once approved.' );
                        return;
                    }
                    const slug = data.slug || data.data?.slug || '';
                    window.location.href = `${ state.communityBase }/s/${ ctx.spaceSlug }/t/${ slug }/`;
                }
            } catch {
                // silent
            } finally {
                state.isSubmitting = false;
                state.submitLabel = 'Post Topic';
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

            try {
                const response = yield fetch(
                    `${ state.apiBase }/users/me`,
                    {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state.nonce },
                        body: JSON.stringify( { display_name: displayName, bio } ),
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
