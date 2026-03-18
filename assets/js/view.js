/**
 * Jetonomy Interactivity API Store
 * Handles voting, sorting, load-more, and notifications polling
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

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
        // Form submission state
        isSubmitting: false,
        submitLabel: 'Post Topic',
        // Community base URL (populated from server state)
        communityBase: '',
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
                if ( ! response.ok ) {
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
                if ( ! response.ok ) {
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

            try {
                yield fetch(
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
            } catch {
                state.replyScores[ replyId ] = current;
            }
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

        // ── Reply submission ──
        *submitReply() {
            const composer = document.getElementById( 'jt-composer' );
            const body = composer?.querySelector( '[contenteditable]' );
            if ( ! body || ! body.innerHTML.trim() ) return;

            state.isLoading = true;

            try {
                const postId = state.currentPostId;
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
                            parent_id: state.composerReplyTo || null,
                        } ),
                    }
                );

                if ( response.ok ) {
                    // Reload to show new reply
                    window.location.reload();
                }
            } finally {
                state.isLoading = false;
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
    },
} );
