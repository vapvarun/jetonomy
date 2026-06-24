/**
 * Jetonomy Interactivity API Store
 * Handles voting, sorting, load-more, and notifications polling
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Custom modal helpers — replace browser alert/confirm/prompt with styled modals.
 * Strings come from window.jetonomyData.i18n (set via wp_localize_script on the
 * `jetonomy-data` handle in class-template-loader.php) with English fallbacks
 * for defense in depth.
 */
const jtModalI18n = () => ( ( typeof window !== 'undefined' && window.jetonomyData && window.jetonomyData.i18n ) || {} );

function jetonomyConfirm( message ) {
	return new Promise( ( resolve ) => {
		const t = jtModalI18n();
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
		cancelBtn.textContent = t.modalCancel || 'Cancel';
		cancelBtn.addEventListener( 'click', () => { overlay.remove(); resolve( false ); } );
		const okBtn = document.createElement( 'button' );
		okBtn.className = 'jt-btn jt-btn-fill';
		okBtn.textContent = t.modalConfirm || 'Confirm';
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
		const t = jtModalI18n();
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
		cancelBtn.textContent = t.modalCancel || 'Cancel';
		cancelBtn.addEventListener( 'click', () => { overlay.remove(); resolve( null ); } );
		const okBtn = document.createElement( 'button' );
		okBtn.className = 'jt-btn jt-btn-fill';
		okBtn.textContent = t.modalSubmit || 'Submit';
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
		const t = jtModalI18n();
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
		loadingOpt.textContent = t.loadingSpaces || 'Loading spaces…';
		loadingOpt.disabled = true;
		loadingOpt.selected = true;
		select.appendChild( loadingOpt );
		box.appendChild( select );
		const actions = document.createElement( 'div' );
		actions.className = 'jt-modal-actions';
		const cancelBtn = document.createElement( 'button' );
		cancelBtn.className = 'jt-btn jt-btn-ghost';
		cancelBtn.textContent = t.modalCancel || 'Cancel';
		cancelBtn.addEventListener( 'click', () => { overlay.remove(); resolve( null ); } );
		const okBtn = document.createElement( 'button' );
		okBtn.className = 'jt-btn jt-btn-fill';
		okBtn.textContent = t.modalMove || 'Move';
		okBtn.disabled = true;
		okBtn.addEventListener( 'click', () => { overlay.remove(); resolve( select.value || null ); } );
		actions.appendChild( cancelBtn );
		actions.appendChild( okBtn );
		box.appendChild( actions );
		overlay.appendChild( box );
		overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) { overlay.remove(); resolve( null ); } } );
		document.body.appendChild( overlay );

		window.jetonomyRest.restFetch( '/spaces' )
			.then( ( r ) => r.data || {} )
			.then( ( data ) => {
				while ( select.firstChild ) select.removeChild( select.firstChild );
				const defaultOpt = document.createElement( 'option' );
				defaultOpt.textContent = t.selectSpacePlaceholder || 'Select a space…';
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
					noneOpt.textContent = t.noOtherSpaces || 'No other spaces available';
					noneOpt.disabled = true;
					select.appendChild( noneOpt );
				} else {
					select.addEventListener( 'change', () => { okBtn.disabled = ! select.value; } );
				}
			} )
			.catch( () => {
				while ( select.firstChild ) select.removeChild( select.firstChild );
				const failOpt = document.createElement( 'option' );
				failOpt.textContent = t.failedLoadSpaces || 'Failed to load spaces';
				failOpt.disabled = true;
				select.appendChild( failOpt );
			} );
	} );
}

/**
 * Post picker modal for merge — search and select a target topic.
 *
 * Backwards-compatible signature: existing callers passing only
 * (title, excludePostId, spaceId) still work. New optional 4th arg
 * `sourceTitle` shows a "From: …" banner so the moderator never has to
 * mentally hold the source-topic title while reading candidates.
 *
 * All inline styles are gone — every node carries a `.jt-modal-picker-*`
 * class instead, so themes and dark mode can override via the standard
 * token cascade without fighting `style=""` specificity.
 */
function jetonomyPostPicker( title, excludePostId, spaceId, sourceTitle ) {
	return new Promise( ( resolve ) => {
		const t = jtModalI18n();
		const overlay = document.createElement( 'div' );
		overlay.className = 'jt-modal-overlay';
		const box = document.createElement( 'div' );
		box.className = 'jt-modal-box jt-modal-picker';

		const header = document.createElement( 'div' );
		header.className = 'jt-modal-picker-header';
		const heading = document.createElement( 'h2' );
		heading.className = 'jt-modal-picker-title';
		heading.textContent = title;
		header.appendChild( heading );
		if ( sourceTitle && '' !== String( sourceTitle ).trim() ) {
			const sourceLine = document.createElement( 'p' );
			sourceLine.className = 'jt-modal-picker-source';
			const fromLabel = document.createElement( 'span' );
			fromLabel.className = 'jt-modal-picker-source-label';
			fromLabel.textContent = t.mergeFromLabel || 'From';
			const sourceName = document.createElement( 'span' );
			sourceName.className = 'jt-modal-picker-source-name';
			sourceName.textContent = sourceTitle;
			sourceLine.append( fromLabel, sourceName );
			header.appendChild( sourceLine );
		}
		box.appendChild( header );

		const searchWrap = document.createElement( 'div' );
		searchWrap.className = 'jt-modal-picker-search';
		const searchInput = document.createElement( 'input' );
		searchInput.type = 'search';
		searchInput.className = 'jt-modal-picker-input';
		searchInput.placeholder = t.searchTopicPlaceholder || 'Search for a topic...';
		searchInput.setAttribute( 'aria-label', t.searchTopicPlaceholder || 'Search for a topic' );
		searchWrap.appendChild( searchInput );
		box.appendChild( searchWrap );

		const resultsList = document.createElement( 'div' );
		resultsList.className = 'jt-modal-picker-results';
		resultsList.setAttribute( 'role', 'listbox' );
		box.appendChild( resultsList );

		// Hint shown until the visitor has typed enough to fire a search.
		const hint = document.createElement( 'p' );
		hint.className = 'jt-modal-picker-hint';
		hint.textContent = t.pickerHintTwoChars || 'Type at least 2 characters to search.';
		resultsList.appendChild( hint );

		let selectedId   = null;
		let selectedItem = null;
		let items        = [];

		const setSelection = ( item, id ) => {
			if ( selectedItem ) {
				selectedItem.classList.remove( 'is-selected' );
				selectedItem.setAttribute( 'aria-selected', 'false' );
			}
			selectedItem = item;
			selectedId   = id;
			if ( item ) {
				item.classList.add( 'is-selected' );
				item.setAttribute( 'aria-selected', 'true' );
				okBtn.disabled = false;
			} else {
				okBtn.disabled = true;
			}
		};

		const actionsDiv = document.createElement( 'div' );
		actionsDiv.className = 'jt-modal-actions';
		const cancelBtn = document.createElement( 'button' );
		cancelBtn.className = 'jt-btn jt-btn-ghost';
		cancelBtn.type = 'button';
		cancelBtn.textContent = t.modalCancel || 'Cancel';
		cancelBtn.addEventListener( 'click', () => { overlay.remove(); resolve( null ); } );
		const okBtn = document.createElement( 'button' );
		okBtn.className = 'jt-btn jt-btn-fill';
		okBtn.type = 'button';
		okBtn.textContent = t.modalMerge || 'Merge';
		okBtn.disabled = true;
		okBtn.addEventListener( 'click', () => { overlay.remove(); resolve( selectedId ); } );
		actionsDiv.append( cancelBtn, okBtn );
		box.appendChild( actionsDiv );
		overlay.appendChild( box );

		overlay.addEventListener( 'click', ( e ) => {
			if ( e.target === overlay ) { overlay.remove(); resolve( null ); }
		} );

		// Esc closes, arrow keys + enter drive the list (so power users
		// never need to round-trip through the mouse).
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				overlay.remove();
				document.removeEventListener( 'keydown', onKey );
				resolve( null );
				return;
			}
			if ( ! items.length ) return;
			const idx = selectedItem ? items.indexOf( selectedItem ) : -1;
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				const next = items[ Math.min( items.length - 1, idx + 1 ) ];
				if ( next ) {
					setSelection( next, next.dataset.pickerId );
					next.scrollIntoView( { block: 'nearest' } );
				}
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				const prev = items[ Math.max( 0, idx - 1 ) ];
				if ( prev ) {
					setSelection( prev, prev.dataset.pickerId );
					prev.scrollIntoView( { block: 'nearest' } );
				}
			} else if ( e.key === 'Enter' && document.activeElement !== okBtn && document.activeElement !== cancelBtn ) {
				if ( selectedId ) {
					e.preventDefault();
					overlay.remove();
					document.removeEventListener( 'keydown', onKey );
					resolve( selectedId );
				}
			}
		};
		document.addEventListener( 'keydown', onKey );

		document.body.appendChild( overlay );
		searchInput.focus();


		const renderEmpty = ( message, isError ) => {
			while ( resultsList.firstChild ) resultsList.removeChild( resultsList.firstChild );
			const empty = document.createElement( 'p' );
			empty.className = isError ? 'jt-modal-picker-hint is-error' : 'jt-modal-picker-hint';
			empty.textContent = message;
			resultsList.appendChild( empty );
			items = [];
			setSelection( null, null );
		};

		const renderItem = ( p ) => {
			const item = document.createElement( 'button' );
			item.type = 'button';
			item.className = 'jt-modal-picker-item';
			item.setAttribute( 'role', 'option' );
			item.setAttribute( 'aria-selected', 'false' );
			item.dataset.pickerId = String( p.id );

			const titleEl = document.createElement( 'span' );
			titleEl.className = 'jt-modal-picker-item-title';
			titleEl.textContent = p.title || '(untitled)';
			item.appendChild( titleEl );

			const metaEl = document.createElement( 'span' );
			metaEl.className = 'jt-modal-picker-item-meta';

			if ( p.space_title ) {
				const spaceChip = document.createElement( 'span' );
				spaceChip.className = 'jt-modal-picker-space-chip';
				spaceChip.textContent = p.space_title;
				metaEl.appendChild( spaceChip );
			}

			const replyCount = parseInt( p.reply_count, 10 );
			if ( Number.isFinite( replyCount ) && replyCount > 0 ) {
				const replyStat = document.createElement( 'span' );
				replyStat.className = 'jt-modal-picker-item-stat';
				const replyLabel = 1 === replyCount
					? ( t.pickerReplySingular || '%d reply' )
					: ( t.pickerReplyPlural || '%d replies' );
				replyStat.textContent = replyLabel.replace( '%d', String( replyCount ) );
				metaEl.appendChild( replyStat );
			}

			item.appendChild( metaEl );

			item.addEventListener( 'click', () => setSelection( item, item.dataset.pickerId ) );
			item.addEventListener( 'dblclick', () => {
				setSelection( item, item.dataset.pickerId );
				overlay.remove();
				document.removeEventListener( 'keydown', onKey );
				resolve( item.dataset.pickerId );
			} );
			return item;
		};

		let debounce = null;
		searchInput.addEventListener( 'input', () => {
			clearTimeout( debounce );
			debounce = setTimeout( () => {
				const q = searchInput.value.trim();
				if ( q.length < 2 ) {
					renderEmpty( t.pickerHintTwoChars || 'Type at least 2 characters to search.', false );
					return;
				}
				resultsList.classList.add( 'is-loading' );
				window.jetonomyRest.restFetch( `/search?q=${ encodeURIComponent( q ) }&type=post` )
					.then( r => r.data || {} )
					.then( data => {
						resultsList.classList.remove( 'is-loading' );
						const posts = ( data.data || data.results || data || [] ).filter( p => String( p.id ) !== String( excludePostId ) );
						if ( ! Array.isArray( posts ) || posts.length === 0 ) {
							renderEmpty( t.noTopicsFound || 'No topics found', false );
							return;
						}
						while ( resultsList.firstChild ) resultsList.removeChild( resultsList.firstChild );
						items = [];
						posts.forEach( p => {
							const item = renderItem( p );
							resultsList.appendChild( item );
							items.push( item );
						} );
						// Auto-select first item so Enter is a one-keystroke confirm.
						setSelection( items[ 0 ], items[ 0 ].dataset.pickerId );
					} )
					.catch( () => {
						resultsList.classList.remove( 'is-loading' );
						renderEmpty( t.searchFailed || 'Search failed', true );
					} );
			}, 300 );
		} );
	} );
}

/**
 * Resolve the trigger element for an action handler.
 *
 * When the Interactivity API runtime fires the action (the normal path)
 * `getElement().ref` is the bound click target. When the action is invoked
 * from the pagination-hydrator fallback (Load More appended reply cards
 * that the runtime never hydrated) `getElement()` returns nothing, so we
 * fall back to the `currentTarget` the hydrator sets on the synthetic event.
 *
 * Every reply-card action passes through here so the same code path serves
 * both the hydrated and the appended cards.
 */
function triggerOf( event ) {
    try {
        const el = getElement();
        if ( el && el.ref ) return el.ref;
    } catch ( e ) {
        // getElement() throws outside an IA render scope. Fall through to
        // the event-based fallback.
    }
    return ( event && event.currentTarget ) || null;
}


// ── Avatar square-crop helpers (1.5.0) ─────────────────────────────────────
// Plain module-scope state: the crop dialog is a single instance per page.
const avatarCrop = { file: null, input: null, url: '', scale: 1, x: 0, y: 0, bound: false };

function avatarCropLayout() {
    const viewport = document.getElementById( 'jt-crop-viewport' );
    const img      = document.getElementById( 'jt-crop-image' );
    if ( ! viewport || ! img || ! img.naturalWidth ) return;

    const vp   = viewport.clientWidth;
    // Cover the square viewport at scale 1, scale up from there.
    const base = ( vp / Math.min( img.naturalWidth, img.naturalHeight ) ) * avatarCrop.scale;
    const w    = img.naturalWidth * base;
    const h    = img.naturalHeight * base;

    // Clamp the pan so the image always covers the viewport.
    const maxX = Math.max( 0, ( w - vp ) / 2 );
    const maxY = Math.max( 0, ( h - vp ) / 2 );
    avatarCrop.x = Math.min( maxX, Math.max( -maxX, avatarCrop.x ) );
    avatarCrop.y = Math.min( maxY, Math.max( -maxY, avatarCrop.y ) );

    img.style.width     = w + 'px';
    img.style.height    = h + 'px';
    img.style.transform = 'translate(calc(-50% + ' + avatarCrop.x + 'px), calc(-50% + ' + avatarCrop.y + 'px))';
}

function avatarCropBindDrag() {
    if ( avatarCrop.bound ) return;
    avatarCrop.bound = true;

    // Native Esc fires 'close' without going through our cancel action -
    // release the object URL and reset the file input there too.
    document.getElementById( 'jt-avatar-crop' )?.addEventListener( 'close', () => {
        if ( avatarCrop.url ) URL.revokeObjectURL( avatarCrop.url );
        if ( avatarCrop.input ) avatarCrop.input.value = '';
        avatarCrop.url = '';
    } );

    const viewport = document.getElementById( 'jt-crop-viewport' );
    if ( ! viewport ) return;
    let dragging = false;
    let startX = 0;
    let startY = 0;
    let originX = 0;
    let originY = 0;
    viewport.addEventListener( 'pointerdown', ( e ) => {
        dragging = true;
        startX   = e.clientX;
        startY   = e.clientY;
        originX  = avatarCrop.x;
        originY  = avatarCrop.y;
        viewport.setPointerCapture( e.pointerId );
        e.preventDefault();
    } );
    viewport.addEventListener( 'pointermove', ( e ) => {
        if ( ! dragging ) return;
        avatarCrop.x = originX + ( e.clientX - startX );
        avatarCrop.y = originY + ( e.clientY - startY );
        avatarCropLayout();
    } );
    const end = () => { dragging = false; };
    viewport.addEventListener( 'pointerup', end );
    viewport.addEventListener( 'pointercancel', end );
}

function avatarCropRender() {
    return new Promise( ( resolve ) => {
        const viewport = document.getElementById( 'jt-crop-viewport' );
        const img      = document.getElementById( 'jt-crop-image' );
        if ( ! viewport || ! img || ! img.naturalWidth ) {
            resolve( null );
            return;
        }
        const vp    = viewport.clientWidth;
        const base  = ( vp / Math.min( img.naturalWidth, img.naturalHeight ) ) * avatarCrop.scale;
        const size  = 512;
        // Viewport center maps to image center + pan offset (in display px).
        const srcW  = vp / base;
        const srcX  = ( img.naturalWidth / 2 ) - ( avatarCrop.x / base ) - ( srcW / 2 );
        const srcY  = ( img.naturalHeight / 2 ) - ( avatarCrop.y / base ) - ( srcW / 2 );

        const canvas  = document.createElement( 'canvas' );
        canvas.width  = size;
        canvas.height = size;
        const ctx2d   = canvas.getContext( '2d' );
        ctx2d.imageSmoothingQuality = 'high';
        ctx2d.drawImage( img, srcX, srcY, srcW, srcW, 0, 0, size, size );

        const isPng = avatarCrop.file && 'image/png' === avatarCrop.file.type;
        canvas.toBlob( resolve, isPng ? 'image/png' : 'image/jpeg', 0.9 );
    } );
}

function avatarCropClose() {
    const dialog = document.getElementById( 'jt-avatar-crop' );
    if ( dialog && dialog.open ) dialog.close();
    if ( avatarCrop.url ) URL.revokeObjectURL( avatarCrop.url );
    if ( avatarCrop.input ) avatarCrop.input.value = '';
    avatarCrop.file  = null;
    avatarCrop.input = null;
    avatarCrop.url   = '';
}

// Upload a (possibly cropped) avatar file via the shared media pipeline and
// stage the URL in the form. Generator so store actions can yield* into it.
function* avatarUpload( file, input ) {
    if ( ! window.jetonomyRest || typeof window.jetonomyRest.restFetch !== 'function' ) {
        if ( window.bnToast ) window.bnToast( state.i18n?.failedSaveProfile || 'Failed to save profile.', 'error' );
        return;
    }
    const fd = new FormData();
    fd.append( 'file', file );

    const result = yield window.jetonomyRest.restFetch( '/media', { method: 'POST', body: fd } );
    input.value  = '';

    if ( ! result.ok || ! result.data || ! result.data.url ) {
        const msg = ( result.data && result.data.message ) || state.i18n?.failedSaveProfile || 'Failed to save profile.';
        if ( window.bnToast ) window.bnToast( msg, 'error' );
        return;
    }

    const form   = input.closest( 'form' );
    const hidden = form?.querySelector( '[name="avatar_url"]' );
    if ( hidden ) hidden.value = result.data.url;

    const preview = document.getElementById( 'jt-avatar-preview' );
    if ( preview ) preview.src = result.data.url;
    document.getElementById( 'jt-avatar-remove' )?.removeAttribute( 'hidden' );
}

/**
 * Append a freshly-posted TOP-LEVEL reply in place instead of full-reloading
 * the thread. Re-fetches the current thread (HTML, not JSON — same approach as
 * the Load More handler), lifts out the server-rendered card for the new reply,
 * appends it to #jt-replies-container, and hydrates it so its action buttons
 * (vote/reply/quote/more) work immediately. Returns true on success; on any
 * miss (paginated thread where the reply isn't on this page, markup change,
 * network error) returns false so the caller can fall back to a reload.
 *
 * @param {number|string} newId Reply id from the create response.
 * @return {Promise<boolean>}
 */
async function appendNewReply( newId ) {
    try {
        const container = document.getElementById( 'jt-replies-container' );
        if ( ! container || ! newId ) return false;
        if ( container.querySelector( '[data-reply-id="' + newId + '"]' ) ) return true; // already there
        const res = await fetch( window.location.href, { credentials: 'same-origin' } );
        if ( ! res.ok ) return false;
        const doc = new DOMParser().parseFromString( await res.text(), 'text/html' );
        const fresh = doc.getElementById( 'jt-replies-container' );
        const marker = fresh?.querySelector( '[data-reply-id="' + newId + '"]' );
        if ( ! marker ) return false;
        // Climb to the top-level wrapper (direct child of the container).
        let node = marker;
        while ( node && node.parentElement !== fresh ) node = node.parentElement;
        if ( ! node ) return false;
        const adopted = document.importNode( node, true );
        container.appendChild( adopted );
        if ( typeof window.jetonomyHydrateInteractive === 'function' ) {
            window.jetonomyHydrateInteractive( [ adopted ] );
        }
        // Bump any reply-count pills so the header stays in sync.
        document.querySelectorAll( '.jt-replies-section .jt-count-pill' ).forEach( ( pill ) => {
            const n = parseInt( pill.textContent, 10 );
            if ( ! isNaN( n ) ) pill.textContent = String( n + 1 );
        } );
        adopted.scrollIntoView( { behavior: 'smooth', block: 'center' } );
        return true;
    } catch ( e ) {
        return false;
    }
}

/** Mark a notifications-page row read in place (ported from notifications-page.js). */
function notifMarkRowRead( row ) {
    row.classList.remove( 'unread' );
    row.setAttribute( 'data-jt-notif-read', '1' );
    const dot = row.querySelector( '.jt-notif-dot' );
    if ( dot ) dot.remove();
    const markBtn = row.querySelector( '[data-jt-notif-action="mark_read"]' );
    if ( markBtn ) {
        const li = markBtn.closest( 'li' );
        if ( li ) li.remove();
    }
}

/** Refresh the notifications bulk toolbar count + visibility from current checks. */
function notifUpdateBulkbar() {
    const bulkbar = document.querySelector( '[data-jt-notif-bulkbar]' );
    const list = document.querySelector( '[data-jt-notif-list]' );
    if ( ! bulkbar || ! list ) return;
    const checked = list.querySelectorAll( '.jt-notif-cb:checked' );
    const countEl = bulkbar.querySelector( '[data-jt-notif-selected-count]' );
    if ( countEl ) countEl.textContent = String( checked.length );
    if ( checked.length > 0 ) bulkbar.removeAttribute( 'hidden' );
    else bulkbar.setAttribute( 'hidden', '' );
}

/** Set the cover-image preview within a form. Shared by the new-space +
 * edit-space cover uploaders (data-jt-cover-* markup is identical on both). */
// Approve/deny a pending join request from the space-members mod panel.
// Returns a promise so the Interactivity action can `yield` it. On success
// the row is removed; when the last row goes, the whole panel is removed.
function jtModerateJoinRequest( btn, action ) {
    const spaceId   = btn && btn.getAttribute( 'data-space-id' );
    const requestId = btn && btn.getAttribute( 'data-request-id' );
    if ( ! spaceId || ! requestId ) return Promise.resolve();
    const row = btn.closest( '[data-jt-pending-row]' );
    if ( row ) row.querySelectorAll( 'button' ).forEach( ( b ) => { b.disabled = true; } );
    return window.jetonomyRest.restFetch(
        '/spaces/' + parseInt( spaceId, 10 ) + '/join-requests/' + parseInt( requestId, 10 ) + '/' + action,
        { method: 'POST', body: {} }
    ).then( ( res ) => {
        if ( ! res || ! res.ok ) {
            if ( row ) row.querySelectorAll( 'button' ).forEach( ( b ) => { b.disabled = false; } );
            return;
        }
        if ( ! row ) return;
        const list = row.parentNode;
        row.remove();
        if ( list && ! list.querySelector( '[data-jt-pending-row]' ) ) {
            const section = list.closest( '.jt-pending-requests' );
            if ( section ) section.remove();
        }
    } );
}

function jtSetCoverPreview( form, url ) {
    const value  = form.querySelector( '[data-jt-cover-value]' );
    const prev   = form.querySelector( '[data-jt-cover-preview]' );
    const remove = form.querySelector( '[data-jt-cover-remove]' );
    if ( value ) value.value = url;
    if ( ! prev ) return;
    if ( url ) {
        prev.hidden = false;
        let img = prev.querySelector( 'img' );
        if ( ! img ) { img = document.createElement( 'img' ); img.alt = ''; prev.appendChild( img ); }
        img.src = url;
        if ( remove ) remove.hidden = false;
    } else {
        prev.hidden = true;
        if ( remove ) remove.hidden = true;
        const existing = prev.querySelector( 'img' );
        if ( existing ) existing.remove();
    }
}

const { state, actions } = store( 'jetonomy', {
    state: {
        // Post vote scores (populated from server state)
        postScores: {},
        // Reply vote scores
        replyScores: {},
        // Current sort
        // Loading states
        isLoading: false,
        composerReplyTo: null,
        // Threaded reply-to tracking
        replyToId: null,
        replyToAuthor: '',
        // Form submission state. submitLabel intentionally omitted from
        // the JS defaults so the SSR value set via wp_interactivity_state
        // (which is type-aware: "Post Question" / "Submit Idea" /
        // "Post Status" / "Post Topic" depending on the space type)
        // survives hydration.
        isSubmitting: false,
        // Publish mode dropdown open/closed
        publishMenuOpen: false,
        // Nonce for API calls
        get nonce() {
            return state._nonce || '';
        },
    },

    actions: {
        // ── Client-side navigation (Phase 2) ──
        //
        // Delegated from data-wp-on--click on #jetonomy-app: every internal link
        // click bubbles here. We swap the [data-wp-router-region="jetonomy/main"]
        // content via the iAPI router ONLY for routes served by the always-present
        // global bundle (Rail B); anything needing a per-route script (post view
        // -> prismjs, forms, moderation, messages, notifications, edit screens)
        // falls through to a normal full-page load. The real <a href> is always
        // preserved, so JS-off, router errors, and modified clicks all degrade to
        // classic navigation — a link is never left dead.
        *navigate( event ) {
            const link = event.target?.closest?.( 'a' );
            const href = link?.href;
            if ( ! href ) return;
            // Respect any handler that already claimed this click. A direct
            // listener (e.g. Load More's fetch-and-append, which preventDefaults)
            // fires before this delegated handler on #jetonomy-app, so bailing on
            // defaultPrevented stops us double-handling content links that own
            // their own behaviour.
            if ( event.defaultPrevented ) return;
            // Ignore in-page anchors and JS-hook links (href="#" / "#foo"):
            // the skip-link, search-overlay toggle, dropdown triggers and user
            // menu all use those and have their own handlers.
            const rawHref = link.getAttribute( 'href' );
            if ( ! rawHref || '#' === rawHref.charAt( 0 ) ) return;
            // Let the browser handle new-tab / modified / download / cross-origin.
            if (
                event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ||
                event.button !== 0 ||
                link.hasAttribute( 'download' ) ||
                ( link.target && link.target !== '_self' ) ||
                link.origin !== window.location.origin
            ) {
                return;
            }
            // Rail B route guard: only client-nav routes that need nothing beyond
            // the global bundle. Resolve the path relative to the community base.
            const base = ( state.communityBase || '' )
                .replace( /^https?:\/\/[^/]+/, '' )
                .replace( /\/+$/, '' );
            let rest = link.pathname;
            if ( base && rest.indexOf( base ) === 0 ) {
                rest = rest.slice( base.length );
            }
            rest = rest.replace( /^\/+|\/+$/g, '' );
            const seg = '' === rest ? [] : rest.split( '/' );
            // Every route now runs on the global iAPI store (declarative; the
            // router re-hydrates on navigation) EXCEPT the two rich-editor pages:
            //   - single topic  (/s/{slug}/t/{slug}/)  reply composer + Prism
            //   - new post       (/s/{slug}/new/)       topic composer
            // Their editor scripts (composer.js, vendor Prism) bind on load and
            // don't re-init on client nav, and full-loading an editor page is the
            // right call anyway (clean editor + highlighter init). So those two
            // full-load; default is client-side navigation — no allow-list to keep.
            const editorRoute = 's' === seg[ 0 ] && [ 't', 'new' ].includes( seg[ 2 ] );
            if ( editorRoute ) return; // full-page load
            event.preventDefault();
            try {
                const router = yield import( '@wordpress/interactivity-router' );
                yield router.actions.navigate( href );

                // The iAPI store auto-hydrates swapped content, but classic
                // content scripts (Load More, link previews) bind on page load
                // and don't observe the swap. Fire a hook they re-init from.
                document.dispatchEvent(
                    new CustomEvent( 'jetonomy:navigated', { detail: { href } } )
                );

                // A11y: move focus + scroll to the freshly-swapped region so
                // keyboard + screen-reader users land on the new content, and
                // sync the persistent nav's active state (it lives outside the
                // region, so the server-rendered .active class is now stale).
                const region = document.querySelector( '[data-wp-router-region="jetonomy/main"]' );
                if ( region ) {
                    if ( ! region.hasAttribute( 'tabindex' ) ) {
                        region.setAttribute( 'tabindex', '-1' );
                    }
                    region.focus( { preventScroll: true } );
                }
                window.scrollTo( 0, 0 );
                document.querySelectorAll( '.jt-community-nav-links a, .jt-mobile-tabs a' ).forEach( ( a ) => {
                    a.classList.toggle( 'active', a.pathname === window.location.pathname );
                } );
            } catch ( e ) {
                window.location.href = href; // never strand the user
            }
        },
        // ── Space members: role change + ban ──
        // Declarative replacement for the former classic space-members.js. Lives
        // in the global store so it loads on every route and the router
        // re-hydrates the directives on client navigation — no per-route script.
        *changeMemberRole() {
            const select  = getElement().ref;
            const spaceId = select.getAttribute( 'data-space-id' );
            const userId  = select.getAttribute( 'data-user-id' );
            const role    = select.value;
            const prev    = select.getAttribute( 'data-prev-role' ) || '';
            if ( ! spaceId || ! userId || ! role || prev === role ) return;

            const i18n   = ( window.jetonomyData && window.jetonomyData.i18n ) || {};
            const labels = i18n.roleLabels || { member: 'Member', moderator: 'Moderator', admin: 'Admin' };
            const row    = select.closest( '.jt-member-item' );
            const nameEl = row && row.querySelector( '.jt-member-name, .jt-member-display-name, [data-member-name]' );
            const name   = nameEl ? nameEl.textContent.trim() : '';

            const confirmBody = ( i18n.confirmRoleChange || 'Change %name% from %from% to %to%?' )
                .replace( '%name%', name || 'this member' )
                .replace( '%from%', labels[ prev ] || prev )
                .replace( '%to%', labels[ role ] || role );
            const confirmed = 'function' === typeof window.jetonomyConfirm
                ? yield window.jetonomyConfirm( confirmBody, {
                    title: i18n.confirmRoleChangeTitle || 'Change role',
                    confirmLabel: i18n.confirmLabel || 'Change role',
                    cancelLabel: i18n.cancelLabel || 'Cancel',
                } )
                : true;
            if ( ! confirmed ) { select.value = prev; return; }

            const oldErr = row && row.querySelector( '.jt-member-role-error' );
            if ( oldErr ) oldErr.remove();
            select.disabled = true;

            const res = yield window.jetonomyRest.restFetch( '/spaces/' + spaceId + '/members/' + userId, {
                method: 'PATCH',
                body: { role },
            } );
            select.disabled = false;
            if ( ! res.ok ) {
                select.value = prev;
                if ( row ) {
                    const p = document.createElement( 'p' );
                    p.className = 'jt-member-role-error';
                    p.setAttribute( 'role', 'alert' );
                    p.textContent = ( res.data && res.data.message ) || i18n.roleUpdateFailed || 'Could not update role. Please try again.';
                    row.appendChild( p );
                }
                return;
            }
            if ( row ) {
                let badge = row.querySelector( '.jt-member-badge' );
                if ( 'moderator' === role || 'admin' === role ) {
                    if ( ! badge ) {
                        badge = document.createElement( 'span' );
                        badge.className = 'jt-badge-accent jt-member-badge';
                        const anchor = row.querySelector( '.jt-member-role-select' );
                        if ( anchor ) anchor.parentNode.insertBefore( badge, anchor );
                        else row.appendChild( badge );
                    }
                    badge.textContent = labels[ role ] || role;
                } else if ( badge ) {
                    badge.remove();
                }
            }
            select.setAttribute( 'data-prev-role', role );
        },

        *banMember() {
            const btn     = getElement().ref;
            const spaceId = btn.getAttribute( 'data-space-id' );
            const userId  = btn.getAttribute( 'data-user-id' );
            const name    = btn.getAttribute( 'data-user-name' );
            if ( ! spaceId || ! userId || 'function' !== typeof window.jetonomyConfirm ) return;

            const i18n    = ( window.jetonomyData && window.jetonomyData.i18n ) || {};
            const banBody = ( i18n.banConfirmFormat || 'Ban %s from this space? They will lose access to its posts and replies until you lift the ban.' ).replace( '%s', name || '' );
            const ok = yield window.jetonomyConfirm( banBody, {
                title: i18n.banMemberTitle || 'Ban member',
                confirmLabel: i18n.banLabel || 'Ban',
                danger: true,
            } );
            if ( ! ok ) return;

            btn.disabled = true;
            const res = yield window.jetonomyRest.restFetch( '/moderation/ban', {
                method: 'POST',
                body: { user_id: parseInt( userId, 10 ), space_id: parseInt( spaceId, 10 ), type: 'space_ban' },
            } );
            if ( ! res.ok ) { btn.disabled = false; return; }
            const row = btn.closest( '.jt-member-item' );
            if ( row ) {
                row.style.opacity = '0.5';
                const note = document.createElement( 'span' );
                note.className = 'jt-member-banned-note';
                note.textContent = i18n.memberBanned || 'Banned';
                row.appendChild( note );
            }
            btn.remove();
        },

        // ── Join requests (space-members mod panel) ──
        *approveJoinRequest() {
            yield jtModerateJoinRequest( getElement().ref, 'approve' );
        },
        *denyJoinRequest() {
            const btn  = getElement().ref;
            const i18n = ( window.jetonomyData && window.jetonomyData.i18n ) || {};
            if ( 'function' === typeof window.jetonomyConfirm ) {
                const ok = yield window.jetonomyConfirm(
                    i18n.denyJoinBody || 'Deny this join request? The member can request again later.',
                    { title: i18n.denyJoinTitle || 'Deny request', confirmLabel: i18n.denyLabel || 'Deny', danger: true }
                );
                if ( ! ok ) return;
            }
            yield jtModerateJoinRequest( btn, 'deny' );
        },

        // ── Space cover image (shared by new-space + edit-space forms) ──
        *uploadCover() {
            const input = getElement().ref;
            const form  = input.closest( 'form' );
            const file  = input.files && input.files[ 0 ];
            if ( ! form || ! file ) return;
            const i18n   = ( window.jetonomyData && window.jetonomyData.i18n ) || {};
            const status = form.querySelector( '[data-jt-cover-status]' );
            if ( status ) status.textContent = i18n.uploading || 'Uploading...';
            const fd = new FormData();
            fd.append( 'file', file );
            input.value = '';
            const res = yield window.jetonomyRest.restFetch( '/media', { method: 'POST', body: fd } );
            if ( ! res.ok || ! res.data || ! res.data.url ) {
                if ( status ) status.textContent = 0 === res.status
                    ? ( i18n.networkError || 'Network error.' )
                    : ( ( res.data && res.data.message ) || i18n.uploadFailed || 'Upload failed.' );
                return;
            }
            jtSetCoverPreview( form, res.data.url );
            if ( status ) {
                status.textContent = i18n.uploaded || 'Uploaded.';
                setTimeout( () => { status.textContent = ''; }, 2000 );
            }
        },
        removeCover() {
            const form = getElement().ref.closest( 'form' );
            if ( form ) jtSetCoverPreview( form, '' );
        },

        // ── Create space (new-space form; declarative, was new-space.js) ──
        *createSpace( event ) {
            event.preventDefault();
            const form   = getElement().ref;
            const errBox = form.querySelector( '[data-jt-error]' );
            const btn    = form.querySelector( 'button[type="submit"]' );
            const i18n   = ( window.jetonomyData && window.jetonomyData.i18n ) || {};
            if ( errBox ) errBox.hidden = true;
            if ( btn ) btn.disabled = true;

            const payload = {};
            new FormData( form ).forEach( ( v, k ) => {
                if ( /^jt_cf\[/.test( k ) ) return; // collected separately
                if ( v ) payload[ k ] = v;
            } );
            const customFields = window.jetonomyCollectCustomFields ? window.jetonomyCollectCustomFields( form ) : {};
            if ( Object.keys( customFields ).length > 0 ) payload.custom_fields = customFields;

            const res = yield window.jetonomyRest.restFetch( '/spaces', { method: 'POST', body: payload } );
            if ( ! res.ok || ! res.data || ! res.data.slug ) {
                if ( errBox ) {
                    errBox.textContent = ( res.data && res.data.message ) || i18n.createSpaceFailed || 'Could not create the space. Please try again.';
                    errBox.hidden = false;
                }
                if ( btn ) btn.disabled = false;
                return;
            }
            window.location.href = ( form.dataset.jtCommunityBase || '' ) + '/s/' + res.data.slug + '/';
        },

        // ── Edit space (edit-space form; declarative, was space-edit.js) ──
        togglePrefixConfig() {
            const toggle = getElement().ref;
            const config = toggle.closest( 'form' )?.querySelector( '[data-jt-prefix-config]' );
            if ( config ) config.hidden = ! toggle.checked;
        },
        addPrefixRow() {
            const list = getElement().ref.closest( 'form' )?.querySelector( '[data-jt-prefix-list]' );
            if ( ! list ) return;
            const i18n = ( window.jetonomyData && window.jetonomyData.i18n ) || {};
            const row = document.createElement( 'div' );
            row.className = 'jt-prefix-row';
            const nameInput = document.createElement( 'input' );
            nameInput.type = 'text';
            nameInput.className = 'jt-input jt-prefix-name';
            nameInput.placeholder = i18n.prefixLabel || 'Label';
            nameInput.maxLength = 50;
            const colorInput = document.createElement( 'input' );
            colorInput.type = 'color';
            colorInput.className = 'jt-prefix-color';
            colorInput.value = '#3B82F6';
            const removeBtn = document.createElement( 'button' );
            removeBtn.type = 'button';
            removeBtn.className = 'jt-btn jt-btn-ghost jt-prefix-remove';
            removeBtn.setAttribute( 'aria-label', i18n.removePrefix || 'Remove prefix' );
            removeBtn.setAttribute( 'data-wp-on--click', 'actions.removePrefixRow' );
            removeBtn.textContent = '×';
            row.appendChild( nameInput );
            row.appendChild( colorInput );
            row.appendChild( removeBtn );
            list.appendChild( row );
            // Wire the new row's remove button (iAPI doesn't hydrate post-boot inserts).
            if ( 'function' === typeof window.jetonomyHydrateInteractive ) {
                window.jetonomyHydrateInteractive( [ row ] );
            }
        },
        // Uses triggerOf (event-based) not getElement(), because dynamically-added
        // rows are wired by jetonomyHydrateInteractive, where getElement() has no
        // IA render scope. triggerOf falls back to event.currentTarget there.
        removePrefixRow( event ) {
            const row = triggerOf( event )?.closest( '.jt-prefix-row' );
            if ( row ) row.remove();
        },
        *saveSpace( event ) {
            event.preventDefault();
            const form     = getElement().ref;
            const errBox   = form.querySelector( '[data-jt-error]' );
            const savedBox = form.querySelector( '[data-jt-saved]' );
            const btn      = form.querySelector( 'button[type="submit"]' );
            const i18n     = ( window.jetonomyData && window.jetonomyData.i18n ) || {};
            if ( errBox ) errBox.hidden = true;
            if ( savedBox ) savedBox.hidden = true;
            if ( btn ) btn.disabled = true;

            const payload = {};
            new FormData( form ).forEach( ( v, k ) => {
                if ( 'posts_per_page' === k || 'enable_prefixes' === k ) return;
                if ( /^jt_cf\[/.test( k ) ) return;
                payload[ k ] = v;
            } );
            const customFields = window.jetonomyCollectCustomFields ? window.jetonomyCollectCustomFields( form ) : {};
            if ( Object.keys( customFields ).length > 0 ) payload.custom_fields = customFields;

            const settings = {};
            const pppEl = form.querySelector( '[name=posts_per_page]' );
            const ppp = pppEl ? pppEl.value.trim() : '';
            settings.posts_per_page = '' === ppp ? '' : parseInt( ppp, 10 );
            const toggle = form.querySelector( '[data-jt-prefix-toggle]' );
            settings.enable_prefixes = toggle && toggle.checked ? 1 : 0;
            const prefixes = [];
            form.querySelectorAll( '.jt-prefix-row' ).forEach( ( row ) => {
                const name = row.querySelector( '.jt-prefix-name' )?.value.trim();
                const color = row.querySelector( '.jt-prefix-color' )?.value;
                if ( name ) prefixes.push( { name, color } );
            } );
            settings.prefixes = prefixes;
            const reqApproval = form.querySelector( '#jt-se-require-approval' );
            settings.require_approval = reqApproval && reqApproval.checked ? 1 : 0;
            payload.settings = settings;

            const res = yield window.jetonomyRest.restFetch( '/spaces/' + form.dataset.jtSpaceId, {
                method: 'PATCH',
                body: payload,
            } );
            if ( btn ) btn.disabled = false;
            if ( ! res.ok ) {
                if ( errBox ) {
                    errBox.textContent = 0 === res.status
                        ? ( i18n.networkErrorRetry || 'Network error. Please try again.' )
                        : ( ( res.data && res.data.message ) || i18n.saveFailed || 'Could not save changes.' );
                    errBox.hidden = false;
                }
                return;
            }
            if ( savedBox ) {
                savedBox.hidden = false;
                setTimeout( () => { savedBox.hidden = true; }, 2500 );
            }
        },

        // ── Notifications page (declarative; was notifications-page.js) ──
        *markAllNotifsRead() {
            const btn = getElement().ref;
            btn.disabled = true;
            const res = yield window.jetonomyRest.restFetch( '/notifications/mark-all-read', { method: 'POST' } );
            if ( ! res.ok ) { btn.disabled = false; return; }
            document.querySelectorAll( '[data-jt-notif-list] .jt-notif-item.unread' ).forEach( notifMarkRowRead );
            btn.remove();
        },
        toggleNotifMenu( event ) {
            event.stopPropagation();
            const trigger = getElement().ref;
            const menu = trigger.closest( '[data-jt-notif-menu]' );
            const panel = menu && menu.querySelector( '.jt-notif-item__menu-list' );
            if ( ! panel ) return;
            panel.removeAttribute( 'hidden' );
            if ( ! trigger._jtDropdown ) {
                trigger._jtDropdown = window.jetonomySmartDropdown( trigger, panel, {
                    placement: 'bottom-end',
                    group: 'jt-notif',
                    closeOnOutside: true,
                    closeOnEscape: true,
                } );
                panel.style.display = 'none';
            }
            trigger._jtDropdown.toggle();
        },
        *markNotifRead() {
            const row = getElement().ref.closest( '.jt-notif-item' );
            const id = row && parseInt( row.getAttribute( 'data-jt-notif-id' ), 10 );
            if ( ! id ) return;
            const res = yield window.jetonomyRest.restFetch( '/notifications/' + id, { method: 'PATCH' } );
            if ( res.ok ) notifMarkRowRead( row );
        },
        *deleteNotif() {
            const row = getElement().ref.closest( '.jt-notif-item' );
            const id = row && parseInt( row.getAttribute( 'data-jt-notif-id' ), 10 );
            if ( ! id ) return;
            // Optimistic remove — re-insert on failure so the user can retry.
            const next = row.nextSibling;
            const parent = row.parentNode;
            row.style.display = 'none';
            const res = yield window.jetonomyRest.restFetch( '/notifications/' + id, { method: 'DELETE' } );
            if ( res.ok ) {
                row.remove();
                if ( ! document.querySelector( '[data-jt-notif-list] .jt-notif-item' ) ) {
                    window.location.reload(); // render the server empty state
                }
            } else {
                row.style.display = '';
                if ( parent && next ) parent.insertBefore( row, next );
            }
        },
        // ── Publish a draft / scheduled post now (drafts tab) ──
        *publishDraft( event ) {
            // The draft row is whole-row clickable (opens the draft); keep the
            // button's click from bubbling into that navigation.
            if ( event ) { event.stopPropagation(); event.preventDefault(); }
            const btn = getElement().ref;
            const row = btn.closest( '.jt-row--draft' );
            const id = row && parseInt( row.getAttribute( 'data-jt-post-id' ), 10 );
            if ( ! id || btn.disabled ) return;
            btn.disabled = true;
            const res = yield window.jetonomyRest.restFetch( '/posts/' + id, { method: 'PATCH', body: { status: 'publish' } } );
            if ( res.ok ) {
                if ( window.bnToast ) window.bnToast( state.i18n?.draftPublished || 'Published.' );
                row.remove();
                // Last draft gone — reload so the server renders the empty state.
                if ( ! document.querySelector( '.jt-row--draft' ) ) window.location.reload();
            } else {
                btn.disabled = false;
                if ( window.bnToast ) window.bnToast( ( res.data && res.data.message ) || state.i18n?.genericError || 'Could not publish.' );
            }
        },
        toggleNotifSelectAll() {
            const selectAll = getElement().ref;
            document.querySelectorAll( '[data-jt-notif-list] .jt-notif-cb' ).forEach( ( cb ) => { cb.checked = selectAll.checked; } );
            notifUpdateBulkbar();
        },
        updateNotifSelection() {
            notifUpdateBulkbar();
        },
        *bulkNotifs() {
            const btn = getElement().ref;
            const action = btn.getAttribute( 'data-jt-notif-bulk' );
            const ids = [ ...document.querySelectorAll( '[data-jt-notif-list] .jt-notif-cb:checked' ) ]
                .map( ( cb ) => parseInt( cb.value, 10 ) ).filter( ( id ) => id > 0 );
            if ( ! ids.length ) return;
            btn.disabled = true;
            const res = yield window.jetonomyRest.restFetch( '/notifications/bulk', { method: 'POST', body: { action, ids } } );
            btn.disabled = false;
            if ( ! res.ok ) return;
            ids.forEach( ( id ) => {
                const row = document.querySelector( '[data-jt-notif-list] .jt-notif-item[data-jt-notif-id="' + id + '"]' );
                if ( ! row ) return;
                if ( 'delete' === action ) row.remove();
                else notifMarkRowRead( row );
            } );
            const selectAll = document.querySelector( '[data-jt-notif-selectall]' );
            if ( selectAll ) selectAll.checked = false;
            notifUpdateBulkbar();
            if ( ! document.querySelector( '[data-jt-notif-list] .jt-notif-item' ) ) {
                window.location.reload();
            }
        },

        // ── Voting ──
        *voteUp( event ) {
            event.stopPropagation();
            const el = getElement();
            const btnEl = el.ref;
            const postId = btnEl.dataset.postId;
            if ( ! postId ) return;

            const downSibling = btnEl.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteDown"], [data-wp-on--click="actions.voteDown"]' );
            const scoreEl = btnEl.querySelector( '.n' );

            yield window.jetonomyOptimistic.gen( {
                apply: () => {
                    // Optimistic delta: same-button toggle is -1, flipping
                    // from a prior downvote is +2, fresh vote is +1.
                    const current = state.postScores[ postId ] || 0;
                    const wasVoted = btnEl.classList.contains( 'voted' );
                    const downWasVoted = !! downSibling?.classList.contains( 'voted' );
                    let delta = 1;
                    if ( wasVoted ) delta = -1;
                    else if ( downWasVoted ) delta = 2;

                    state.postScores[ postId ] = current + delta;
                    if ( scoreEl ) {
                        scoreEl.style.transform = 'scale(1.3)';
                        setTimeout( () => { scoreEl.style.transform = 'scale(1)'; }, 200 );
                    }
                    return { prevScore: current, wasVoted, downWasVoted };
                },
                fetch: () => window.jetonomyRest.restFetch(
                    `/posts/${ postId }/vote`,
                    { method: 'POST', body: { value: 1 } }
                ),
                onSuccess: ( data ) => {
                    if ( data && data.score !== undefined ) {
                        state.postScores[ postId ] = data.score;
                    }
                    if ( data && data.action === 'removed' ) {
                        btnEl.classList.remove( 'voted' );
                    } else {
                        btnEl.classList.add( 'voted' );
                        if ( downSibling ) downSibling.classList.remove( 'voted' );
                    }
                    if ( window.bnToast && ! window._jetonomyVoteToasted ) {
                        window.bnToast( state.i18n?.voteRecorded || 'Vote recorded' );
                        window._jetonomyVoteToasted = true;
                        setTimeout( () => { window._jetonomyVoteToasted = false; }, 2000 );
                    }
                },
                revert: ( snap ) => {
                    state.postScores[ postId ] = snap.prevScore;
                    btnEl.classList.toggle( 'voted', snap.wasVoted );
                    if ( downSibling ) downSibling.classList.toggle( 'voted', snap.downWasVoted );
                },
                toastOnError: true,
                errorFallback: state.i18n?.voteFailed || 'Vote failed.',
            } );
        },

        *voteDown( event ) {
            event.stopPropagation();
            const el = getElement();
            const btnEl = el.ref;
            const postId = btnEl.dataset.postId;
            if ( ! postId ) return;

            const upSibling = btnEl.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteUp"], [data-wp-on--click="actions.voteUp"]' );
            const scoreEl = btnEl.querySelector( '.n' );

            yield window.jetonomyOptimistic.gen( {
                apply: () => {
                    // Mirror of voteUp; see comment there.
                    const current = state.postScores[ postId ] || 0;
                    const wasVoted = btnEl.classList.contains( 'voted' );
                    const upWasVoted = !! upSibling?.classList.contains( 'voted' );
                    let delta = -1;
                    if ( wasVoted ) delta = 1;
                    else if ( upWasVoted ) delta = -2;

                    state.postScores[ postId ] = current + delta;
                    if ( scoreEl ) {
                        scoreEl.style.transform = 'scale(1.3)';
                        setTimeout( () => { scoreEl.style.transform = 'scale(1)'; }, 200 );
                    }
                    return { prevScore: current, wasVoted, upWasVoted };
                },
                fetch: () => window.jetonomyRest.restFetch(
                    `/posts/${ postId }/vote`,
                    { method: 'POST', body: { value: -1 } }
                ),
                onSuccess: ( data ) => {
                    if ( data && data.score !== undefined ) {
                        state.postScores[ postId ] = data.score;
                    }
                    if ( data && data.action === 'removed' ) {
                        btnEl.classList.remove( 'voted' );
                    } else {
                        btnEl.classList.add( 'voted' );
                        if ( upSibling ) upSibling.classList.remove( 'voted' );
                    }
                    if ( window.bnToast && ! window._jetonomyVoteToasted ) {
                        window.bnToast( state.i18n?.voteRecorded || 'Vote recorded' );
                        window._jetonomyVoteToasted = true;
                        setTimeout( () => { window._jetonomyVoteToasted = false; }, 2000 );
                    }
                },
                revert: ( snap ) => {
                    state.postScores[ postId ] = snap.prevScore;
                    btnEl.classList.toggle( 'voted', snap.wasVoted );
                    if ( upSibling ) upSibling.classList.toggle( 'voted', snap.upWasVoted );
                },
                toastOnError: true,
                errorFallback: state.i18n?.voteFailed || 'Vote failed.',
            } );
        },

        *voteReplyUp( event ) {
            event.stopPropagation();
            const btnEl = triggerOf( event );
            if ( ! btnEl ) return;
            const replyId = btnEl.dataset.replyId;
            if ( ! replyId ) return;

            const scoreEl = btnEl.querySelector( '.n' );
            const downSibling = btnEl.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteReplyDown"], [data-wp-on--click="actions.voteReplyDown"]' );

            yield window.jetonomyOptimistic.gen( {
                apply: () => {
                    const current = parseInt( scoreEl?.textContent || '0', 10 );
                    const wasVoted = btnEl.classList.contains( 'voted' );
                    const downWasVoted = !! downSibling?.classList.contains( 'voted' );
                    let delta = 1;
                    if ( wasVoted ) delta = -1;
                    else if ( downWasVoted ) delta = 2;

                    state.replyScores[ replyId ] = current + delta;
                    if ( scoreEl ) {
                        scoreEl.textContent = String( current + delta );
                        scoreEl.style.transform = 'scale(1.3)';
                        setTimeout( () => { scoreEl.style.transform = 'scale(1)'; }, 200 );
                    }
                    return { prevScore: current, wasVoted, downWasVoted };
                },
                fetch: () => window.jetonomyRest.restFetch(
                    `/replies/${ replyId }/vote`,
                    { method: 'POST', body: { value: 1 } }
                ),
                onSuccess: ( data ) => {
                    if ( data && data.score !== undefined ) {
                        state.replyScores[ replyId ] = data.score;
                        if ( scoreEl ) scoreEl.textContent = String( data.score );
                    }
                    if ( data && data.action === 'removed' ) {
                        btnEl.classList.remove( 'voted' );
                    } else {
                        btnEl.classList.add( 'voted' );
                        if ( downSibling ) downSibling.classList.remove( 'voted' );
                    }
                },
                revert: ( snap ) => {
                    state.replyScores[ replyId ] = snap.prevScore;
                    if ( scoreEl ) scoreEl.textContent = String( snap.prevScore );
                    btnEl.classList.toggle( 'voted', snap.wasVoted );
                    if ( downSibling ) downSibling.classList.toggle( 'voted', snap.downWasVoted );
                },
                toastOnError: true,
                errorFallback: state.i18n?.voteFailed || 'Vote failed.',
            } );
        },

        *voteReplyDown( event ) {
            event.stopPropagation();
            const btnEl = triggerOf( event );
            if ( ! btnEl ) return;
            const replyId = btnEl.dataset.replyId;
            if ( ! replyId ) return;

            const upSibling = btnEl.parentElement?.querySelector( '[data-wp-on\\:click="actions.voteReplyUp"], [data-wp-on--click="actions.voteReplyUp"]' );
            // Score `.n` is rendered inside the UP button only — the DOWN
            // button is icon-only. Read/write through the sibling so the
            // optimistic DOM update is actually visible.
            const scoreEl = upSibling?.querySelector( '.n' );

            yield window.jetonomyOptimistic.gen( {
                apply: () => {
                    const current = parseInt( scoreEl?.textContent || '0', 10 );
                    const wasVoted = btnEl.classList.contains( 'voted' );
                    const upWasVoted = !! upSibling?.classList.contains( 'voted' );
                    let delta = -1;
                    if ( wasVoted ) delta = 1;
                    else if ( upWasVoted ) delta = -2;

                    state.replyScores[ replyId ] = current + delta;
                    if ( scoreEl ) {
                        scoreEl.textContent = String( current + delta );
                        scoreEl.style.transform = 'scale(1.3)';
                        setTimeout( () => { scoreEl.style.transform = 'scale(1)'; }, 200 );
                    }
                    return { prevScore: current, wasVoted, upWasVoted };
                },
                fetch: () => window.jetonomyRest.restFetch(
                    `/replies/${ replyId }/vote`,
                    { method: 'POST', body: { value: -1 } }
                ),
                onSuccess: ( data ) => {
                    if ( data && data.score !== undefined ) {
                        state.replyScores[ replyId ] = data.score;
                        if ( scoreEl ) scoreEl.textContent = String( data.score );
                    }
                    if ( data && data.action === 'removed' ) {
                        btnEl.classList.remove( 'voted' );
                    } else {
                        btnEl.classList.add( 'voted' );
                        if ( upSibling ) upSibling.classList.remove( 'voted' );
                    }
                },
                revert: ( snap ) => {
                    // Restore DOM + state from snapshot — this was the
                    // missing revert that caused #9886066727 (stale UI on
                    // failed downvote until refresh).
                    state.replyScores[ replyId ] = snap.prevScore;
                    if ( scoreEl ) scoreEl.textContent = String( snap.prevScore );
                    btnEl.classList.toggle( 'voted', snap.wasVoted );
                    if ( upSibling ) upSibling.classList.toggle( 'voted', snap.upWasVoted );
                },
                toastOnError: true,
                errorFallback: state.i18n?.voteFailed || 'Vote failed.',
            } );
        },

        // ── Inline reply edit ──
        editReply( event ) {
            const trigger = triggerOf( event );
            if ( ! trigger ) return;
            const replyId = trigger.dataset.replyId;
            if ( ! replyId ) return;

            const replyCard = trigger.closest( '.jt-reply' );
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
                    const res = await window.jetonomyRest.restFetch( `/replies/${ replyId }`, {
                        method: 'PATCH',
                        body: { content },
                    } );

                    if ( res.ok ) {
                        // Reload so the display filter (wpautop) renders paragraphs.
                        window.location.reload();
                    } else {
                        const err = res.data || {};
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
        //
        // Two-step subscription protocol: when unfollowing we GET the existing
        // subscription row to find its ID, then DELETE it. The follow path is
        // a single POST. We apply the optimistic UI flip in `apply` and let
        // the helper revert from snapshot if the mutating request fails.
        *followPost( event ) {
            const el = getElement();
            const btnEl = el.ref;
            const postId = btnEl.dataset.postId;
            const wasFollowing = btnEl.dataset.following === '1';
            if ( ! postId ) return;

            const applyFollowingUI = ( following ) => {
                if ( following ) {
                    btnEl.dataset.following = '1';
                    btnEl.textContent = state.i18n?.following || 'Following';
                    btnEl.classList.remove( 'jt-btn-ghost' );
                    btnEl.classList.add( 'jt-btn-fill', 'jt-following' );
                } else {
                    btnEl.dataset.following = '0';
                    btnEl.textContent = state.i18n?.follow || 'Follow';
                    btnEl.classList.remove( 'jt-btn-fill', 'jt-following' );
                    btnEl.classList.add( 'jt-btn-ghost' );
                }
            };

            // Unfollow path needs the subscription ID before we can DELETE.
            // We resolve that BEFORE the optimistic helper runs so the
            // helper's `fetch` is a single, atomic request whose failure can
            // be cleanly reverted.
            let subscriptionId = null;
            if ( wasFollowing ) {
                try {
                    const res = yield window.jetonomyRest.restFetch( `/subscriptions?object_type=post&object_id=${ postId }` );
                    if ( res.ok ) {
                        const data = res.data || {};
                        const subs = data.data || [];
                        if ( subs.length > 0 ) subscriptionId = subs[ 0 ].id;
                    }
                } catch { /* fall through — DELETE will fail and revert */ }
                if ( ! subscriptionId ) {
                    // Nothing to delete; UI already in correct state.
                    return;
                }
            }

            yield window.jetonomyOptimistic.gen( {
                apply: () => {
                    applyFollowingUI( ! wasFollowing );
                    return { wasFollowing };
                },
                fetch: () => wasFollowing
                    ? window.jetonomyRest.restFetch( `/subscriptions/${ subscriptionId }`, {
                        method: 'DELETE',
                    } )
                    : window.jetonomyRest.restFetch( `/subscriptions`, {
                        method: 'POST',
                        body: { object_type: 'post', object_id: parseInt( postId ), via: 'both' },
                    } ),
                revert: ( snap ) => { applyFollowingUI( snap.wasFollowing ); },
                toastOnError: true,
                errorFallback: state.i18n?.failedSave || 'Could not update follow state.',
            } );
        },

        // ── Follow space ──
        *followSpace( event ) {
            const el = getElement();
            const btnEl = el.ref;
            const spaceId = btnEl.dataset.spaceId;
            const wasFollowing = btnEl.dataset.following === '1';
            if ( ! spaceId ) return;

            const applyFollowingUI = ( following ) => {
                if ( following ) {
                    btnEl.dataset.following = '1';
                    btnEl.textContent = state.i18n?.following || 'Following';
                    btnEl.classList.remove( 'jt-btn-ghost' );
                    btnEl.classList.add( 'jt-btn-fill', 'jt-following' );
                } else {
                    btnEl.dataset.following = '0';
                    btnEl.textContent = state.i18n?.follow || 'Follow';
                    btnEl.classList.remove( 'jt-btn-fill', 'jt-following' );
                    btnEl.classList.add( 'jt-btn-ghost' );
                }
            };

            let subscriptionId = null;
            if ( wasFollowing ) {
                try {
                    const res = yield window.jetonomyRest.restFetch( `/subscriptions?object_type=space&object_id=${ spaceId }` );
                    if ( res.ok ) {
                        const data = res.data || {};
                        const subs = data.data || [];
                        if ( subs.length > 0 ) subscriptionId = subs[ 0 ].id;
                    }
                } catch { /* fall through */ }
                if ( ! subscriptionId ) return;
            }

            yield window.jetonomyOptimistic.gen( {
                apply: () => {
                    applyFollowingUI( ! wasFollowing );
                    return { wasFollowing };
                },
                fetch: () => wasFollowing
                    ? window.jetonomyRest.restFetch( `/subscriptions/${ subscriptionId }`, {
                        method: 'DELETE',
                    } )
                    : window.jetonomyRest.restFetch( `/subscriptions`, {
                        method: 'POST',
                        body: { object_type: 'space', object_id: parseInt( spaceId ), via: 'both' },
                    } ),
                onSuccess: () => {
                    if ( window.bnToast ) {
                        window.bnToast( wasFollowing
                            ? ( state.i18n?.unfollowedSpace || 'Unfollowed space' )
                            : ( state.i18n?.followingSpace || 'Following space' )
                        );
                    }
                },
                revert: ( snap ) => { applyFollowingUI( snap.wasFollowing ); },
                toastOnError: true,
                errorFallback: state.i18n?.failedSave || 'Could not update follow state.',
            } );
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
            // Scroll + resize listeners attach immediately. Wrapping them in
            // setTimeout(0) (as the click handler is) introduced a race —
            // a fast post-click scroll (Playwright `scrollBy` immediately
            // after `click()`, or a flick on a long page) could fire before
            // the next event-loop tick and miss the listener entirely.
            // Detected by 1.4.0 Pro COMBO smoke (D.share-scroll-detach).
            window.addEventListener( 'scroll', scrollHandler, { passive: true, capture: true } );
            window.addEventListener( 'resize', scrollHandler, { passive: true } );
            // Click-outside is the only listener that needs the next-tick
            // defer — the click that opened the dropdown is still bubbling
            // when we register, and we don't want it to instantly trigger
            // close-on-outside-click.
            setTimeout( () => {
                document.addEventListener( 'click', closeHandler );
            }, 0 );
        },

        // ── Toggle bookmark ──
        *toggleBookmark( event ) {
            const el = getElement();
            const btnEl = el.ref;
            const postId = btnEl.dataset.postId;
            if ( ! postId ) return;

            yield window.jetonomyOptimistic.gen( {
                apply: () => {
                    const wasBookmarked = btnEl.dataset.bookmarked === '1';
                    const willBe = ! wasBookmarked;
                    btnEl.dataset.bookmarked = willBe ? '1' : '0';
                    btnEl.classList.toggle( 'bookmarked', willBe );
                    btnEl.title = willBe
                        ? ( state.i18n?.removeBookmark || 'Remove bookmark' )
                        : ( state.i18n?.bookmark || 'Bookmark' );
                    return { wasBookmarked };
                },
                fetch: () => window.jetonomyRest.restFetch( `/bookmarks`, {
                    method: 'POST',
                    body: { post_id: parseInt( postId ) },
                } ),
                onSuccess: ( data ) => {
                    if ( ! data ) return;
                    // Reconcile with server canonical value.
                    btnEl.dataset.bookmarked = data.bookmarked ? '1' : '0';
                    btnEl.classList.toggle( 'bookmarked', !! data.bookmarked );
                    btnEl.title = data.bookmarked
                        ? ( state.i18n?.removeBookmark || 'Remove bookmark' )
                        : ( state.i18n?.bookmark || 'Bookmark' );
                    if ( window.bnToast ) {
                        window.bnToast( data.bookmarked
                            ? ( state.i18n?.bookmarked || 'Bookmarked' )
                            : ( state.i18n?.bookmarkRemoved || 'Bookmark removed' )
                        );
                    }
                    // On the My Bookmarks page (context="list"), removing a
                    // bookmark should drop the card it no longer belongs to —
                    // otherwise an unbookmarked post lingers on the list of
                    // bookmarks, which reads as broken.
                    if ( ! data.bookmarked && 'list' === btnEl.dataset.bookmarkContext ) {
                        const card = btnEl.closest( '.jt-row' );
                        if ( card ) {
                            card.style.transition = 'opacity 200ms';
                            card.style.opacity = '0';
                            setTimeout( () => card.remove(), 220 );
                        }
                    }
                },
                revert: ( snap ) => {
                    btnEl.dataset.bookmarked = snap.wasBookmarked ? '1' : '0';
                    btnEl.classList.toggle( 'bookmarked', snap.wasBookmarked );
                    btnEl.title = snap.wasBookmarked
                        ? ( state.i18n?.removeBookmark || 'Remove bookmark' )
                        : ( state.i18n?.bookmark || 'Bookmark' );
                },
                toastOnError: true,
                errorFallback: state.i18n?.failedSave || 'Could not update bookmark.',
            } );
        },

        // ── Flag / report post ──
        *flagPost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            // Short-circuit if the user already reported this post in the
            // current render. Saves them filling the reason form just to get
            // a server-side "already flagged" rejection.
            if ( '1' === el.ref.dataset.flagged ) {
                if ( window.bnToast ) window.bnToast( state.i18n?.alreadyReported || 'You already reported this.' );
                return;
            }

            const reason = yield jetonomyPrompt( state.i18n?.reportPrompt || 'Why are you reporting this post?', state.i18n?.reportPlaceholder || 'Describe the issue...' );
            if ( reason === null ) return; // Cancelled

            try {
                const res = yield window.jetonomyRest.restFetch( `/flags`, {
                    method: 'POST',
                    body: { object_type: 'post', object_id: parseInt( postId ), reason: 'other', description: reason },
                } );
                if ( res.ok ) {
                    // Reflect "already reported" state inline so the next click
                    // hits the short-circuit above without a page reload.
                    el.ref.dataset.flagged = '1';
                    el.ref.classList.add( 'is-flagged' );
                    el.ref.title = state.i18n?.alreadyReported || 'You have reported this';
                    el.ref.setAttribute( 'aria-label', el.ref.title );
                    if ( window.bnToast ) window.bnToast( state.i18n?.reportedThankYou || 'Reported. Thank you.' );
                } else {
                    const err = res.data || {};
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedReport || 'Failed to submit report.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Flag / report reply ──
        *flagReply( event ) {
            const trigger = triggerOf( event );
            if ( ! trigger ) return;
            const replyId = trigger.dataset.replyId;
            if ( ! replyId ) return;

            const reason = yield jetonomyPrompt( state.i18n?.reportReplyPrompt || 'Why are you reporting this reply?', state.i18n?.reportPlaceholder || 'Describe the issue...' );
            if ( reason === null ) return;

            try {
                const res = yield window.jetonomyRest.restFetch( `/flags`, {
                    method: 'POST',
                    body: { object_type: 'reply', object_id: parseInt( replyId ), reason: 'other', description: reason },
                } );
                if ( res.ok ) {
                    if ( window.bnToast ) window.bnToast( state.i18n?.reportedThankYou || 'Reported. Thank you.' );
                } else {
                    const err = res.data || {};
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
                const res = yield window.jetonomyRest.restFetch( `/flags`, {
                    method: 'POST',
                    body: { object_type: 'user', object_id: parseInt( userId ), reason: 'other', description: reason },
                } );
                if ( res.ok ) {
                    if ( window.bnToast ) window.bnToast( state.i18n?.reportedThankYou || 'Reported. Thank you.' );
                } else {
                    const err = res.data || {};
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedReport || 'Failed to submit report.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Toggle "more" dropdown menu ──
        //
        // [1.4.3 WS3-B] Migrated to jetonomySmartDropdown — fixes
        // Basecamp 9886004438: the last-reply More menu was clipped by
        // overflow because the panel was absolutely positioned inside the
        // reply card. The shared primitive uses `position: fixed`, flips up
        // when there is no room below, and shifts to stay inside the
        // viewport. `group: 'jt-more'` ensures opening one closes the prior.
        toggleMoreMenu( event ) {
            event.stopPropagation();
            const trigger = triggerOf( event );
            if ( ! trigger ) return;
            const menu = trigger.closest( '.jt-more-menu' );
            if ( ! menu ) return;
            const panel = menu.querySelector( '.jt-more-dropdown' );
            if ( ! panel ) return;

            // SSR markup ships `hidden` on the panel; the smart-dropdown
            // primitive flips display/position inline once it takes over,
            // so we drop the attribute on first activation.
            panel.removeAttribute( 'hidden' );

            if ( ! trigger._jtDropdown ) {
                trigger._jtDropdown = window.jetonomySmartDropdown( trigger, panel, {
                    placement: 'bottom-end',
                    group: 'jt-more',
                    closeOnOutside: true,
                    closeOnEscape: true,
                } );
                // Initial state must be closed — primitive starts collapsed.
                panel.style.display = 'none';
            }
            trigger._jtDropdown.toggle();
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

            // Pro custom-fields renders a hidden, pre-filled editable block
            // ([data-jt-post-edit-fields]) after the body, plus a read-only
            // display block (.jt-custom-fields--display). Reveal the editable
            // block inside the editor so additional fields can be changed, and
            // hide the read-only copy while editing. Remember the block's home
            // so Cancel can put it back exactly where it was.
            const fieldsBlock = article.querySelector( '[data-jt-post-edit-fields]' );
            const displayBlock = article.querySelector( '.jt-custom-fields--display' );
            const fieldsHome = fieldsBlock ? fieldsBlock.parentNode : null;
            const fieldsNext = fieldsBlock ? fieldsBlock.nextSibling : null;

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
            editor.append( editable );
            if ( fieldsBlock ) {
                fieldsBlock.hidden = false;
                editor.append( fieldsBlock );
                if ( displayBlock ) displayBlock.style.display = 'none';
            }
            editor.append( btnRow );
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
                // Put the editable fields block back where Pro rendered it and
                // re-hide it; restore the read-only display copy.
                if ( fieldsBlock && fieldsHome ) {
                    fieldsBlock.hidden = true;
                    fieldsHome.insertBefore( fieldsBlock, fieldsNext );
                }
                if ( displayBlock ) displayBlock.style.display = '';
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

                // Collect Pro custom-field inputs from the revealed edit block via
                // the shared collector (window.jetonomyCollectCustomFields). Omitted
                // entirely when the extension is off (no block, no inputs).
                const body = { content };
                if ( fieldsBlock && window.jetonomyCollectCustomFields ) {
                    const customFields = window.jetonomyCollectCustomFields( fieldsBlock );
                    if ( Object.keys( customFields ).length > 0 ) {
                        body.custom_fields = customFields;
                    }
                }

                try {
                    const res = await window.jetonomyRest.restFetch( `/posts/${ postId }`, {
                        method: 'PATCH',
                        body,
                    } );

                    if ( res.ok ) {
                        window.location.reload();
                    } else {
                        const err = res.data || {};
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
        //
        // Pin/unpin reloads the page on success (the sticky position affects
        // the surrounding listing, not just one card), so the optimistic
        // "snapshot" is just a token; the real revert path is when the server
        // returns non-OK — the helper toasts and we stay on the page.
        *pinPost( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            yield window.jetonomyOptimistic.gen( {
                apply: () => null,
                fetch: () => window.jetonomyRest.restFetch( `/posts/${ postId }/pin`, {
                    method: 'POST',
                } ),
                onSuccess: ( data ) => {
                    if ( window.bnToast ) {
                        window.bnToast( data && data.is_sticky
                            ? ( state.i18n?.postPinned || 'Post pinned' )
                            : ( state.i18n?.postUnpinned || 'Post unpinned' )
                        );
                    }
                    setTimeout( () => window.location.reload(), 600 );
                },
                revert: () => { /* No optimistic UI to undo — helper will toast. */ },
                toastOnError: true,
                errorFallback: state.i18n?.failedPin || 'Failed to toggle pin.',
            } );
        },

        // ── Close / reopen topic (moderators) ──
        // POSTs to /posts/:id/close, which toggles is_closed server-side. We
        // reload on success because closing/reopening flips the composer guard
        // and the closed banner — let the page re-render rather than mirror that
        // state across the whole post view.
        *toggleClose( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            if ( ! postId ) return;

            yield window.jetonomyOptimistic.gen( {
                apply: () => null,
                fetch: () => window.jetonomyRest.restFetch( `/posts/${ postId }/close`, {
                    method: 'POST',
                } ),
                onSuccess: ( data ) => {
                    if ( window.bnToast ) {
                        window.bnToast( data && data.is_closed
                            ? ( state.i18n?.topicClosed || 'Topic closed' )
                            : ( state.i18n?.topicReopened || 'Topic reopened' )
                        );
                    }
                    setTimeout( () => window.location.reload(), 600 );
                },
                revert: () => { /* No optimistic UI to undo — helper will toast. */ },
                toastOnError: true,
                errorFallback: state.i18n?.failedClose || 'Failed to update topic.',
            } );
        },
        // ── Toggle private visibility ──
        *togglePrivate( event ) {
            const el = getElement();
            const postId = el.ref.dataset.postId;
            const isPrivate = el.ref.dataset.private === '1';
            if ( ! postId ) return;

            try {
                const res = yield window.jetonomyRest.restFetch( `/posts/${ postId }`, {
                    method: 'PATCH',
                    body: { is_private: ! isPrivate },
                } );

                if ( res.ok ) {
                    const data = res.data || {};
                    if ( window.bnToast ) {
                        window.bnToast( data.is_private ? ( state.i18n?.madePrivate || 'Topic is now private' ) : ( state.i18n?.madePublic || 'Topic is now public' ) );
                    }
                    setTimeout( () => window.location.reload(), 600 );
                } else {
                    const err = res.data || {};
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
                const res = yield window.jetonomyRest.restFetch( `/posts/${ postId }/move`, {
                    method: 'POST',
                    body: { target_space_id: parseInt( spaceId, 10 ) },
                } );

                if ( res.ok ) {
                    const data = res.data || {};
                    if ( window.bnToast ) {
                        window.bnToast( state.i18n?.topicMoved || 'Topic moved successfully.' );
                    }
                    const base = state.communityBase || '/community';
                    setTimeout( () => {
                        window.location.href = `${ base }/s/${ data.space_slug }/t/${ data.slug }/`;
                    }, 600 );
                } else {
                    const err = res.data || {};
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
            const trigger = triggerOf( event );
            if ( ! trigger ) return;
            const postId = trigger.dataset.postId;
            const spaceId = trigger.dataset.spaceId;
            if ( ! postId ) return;

            // Resolve the source topic title once so the picker can show a
            // "From: <title>" anchor — the moderator never has to scroll up
            // mid-search to remember what they were merging.
            const article    = trigger.closest( 'article' ) || trigger.closest( '.jt-post' );
            const titleEl    = article && ( article.querySelector( '.jt-post-title' ) || article.querySelector( 'h1' ) );
            const sourceTitle = titleEl ? titleEl.textContent.trim() : '';

            // Prompt for target post ID via search
            const targetId = yield jetonomyPostPicker( state.i18n?.mergeTopicTitle || 'Merge into another topic', postId, spaceId, sourceTitle );
            if ( ! targetId ) return;

            if ( ! ( yield jetonomyConfirm( state.i18n?.confirmMerge || 'Merge this topic into the selected one? All replies will be moved and this topic will be deleted.' ) ) ) return;

            try {
                const res = yield window.jetonomyRest.restFetch( `/posts/${ postId }/merge`, {
                    method: 'POST',
                    body: { target_post_id: parseInt( targetId, 10 ) },
                } );

                if ( res.ok ) {
                    const data = res.data || {};
                    if ( window.bnToast ) {
                        window.bnToast( state.i18n?.topicMerged || 'Topics merged successfully.' );
                    }
                    const base = state.communityBase || '/community';
                    setTimeout( () => {
                        window.location.href = `${ base }/s/${ data.space_slug }/t/${ data.slug }/`;
                    }, 600 );
                } else {
                    const err = res.data || {};
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
            const trigger = triggerOf( event );
            if ( ! trigger ) return;
            const replyId = trigger.dataset.replyId;
            const spaceId = trigger.dataset.spaceId;
            if ( ! replyId ) return;

            const title = yield jetonomyPrompt( state.i18n?.splitReplyTitle || 'Enter a title for the new topic:', '' );
            if ( ! title ) return;

            try {
                const body = { title };
                if ( spaceId ) {
                    body.space_id = parseInt( spaceId, 10 );
                }

                const res = yield window.jetonomyRest.restFetch( `/replies/${ replyId }/split`, {
                    method: 'POST',
                    body,
                } );

                if ( res.ok ) {
                    const data = res.data || {};
                    if ( window.bnToast ) {
                        window.bnToast( state.i18n?.replySplit || 'Reply split into new topic.' );
                    }
                    const base = state.communityBase || '/community';
                    setTimeout( () => {
                        window.location.href = `${ base }/s/${ data.space_slug }/t/${ data.slug }/`;
                    }, 600 );
                } else {
                    const err = res.data || {};
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
                const res = yield window.jetonomyRest.restFetch( `/posts/${ postId }`, {
                    method: 'DELETE',
                } );

                if ( res.ok ) {
                    // Redirect to space listing
                    const base = state.communityBase || '/community';
                    window.location.href = spaceSlug ? `${ base }/s/${ spaceSlug }/` : `${ base }/`;
                } else {
                    const err = res.data || {};
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedDelete || 'Failed to delete.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Delete reply ──
        *deleteReply( event ) {
            const trigger = triggerOf( event );
            if ( ! trigger ) return;
            const replyId = trigger.dataset.replyId;
            if ( ! replyId ) return;

            if ( ! ( yield jetonomyConfirm( state.i18n?.confirmDeleteReply || 'Are you sure you want to delete this reply?' ) ) ) return;

            try {
                const res = yield window.jetonomyRest.restFetch( `/replies/${ replyId }`, {
                    method: 'DELETE',
                } );

                if ( res.ok ) {
                    const replyEl = trigger.closest( '.jt-reply' );
                    if ( replyEl ) {
                        replyEl.style.opacity = '0.3';
                        replyEl.style.pointerEvents = 'none';
                        setTimeout( () => replyEl.remove(), 300 );
                    }
                } else {
                    const err = res.data || {};
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedDelete || 'Failed to delete.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            }
        },

        // ── Accept reply as best answer (Q&A) ──
        //
        // Server-side mutation reloads the page on success because the
        // "accepted" badge moves to the new reply and the previously-accepted
        // reply needs to lose its mark — we let the page rerender handle that
        // rather than mirror cross-card state here.
        *acceptReply( event ) {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            yield window.jetonomyOptimistic.gen( {
                apply: () => null,
                fetch: () => window.jetonomyRest.restFetch( `/replies/${ replyId }/accept`, {
                    method: 'POST',
                } ),
                onSuccess: () => {
                    if ( window.bnToast ) window.bnToast( state.i18n?.accepted || 'Accepted' );
                    setTimeout( () => window.location.reload(), 600 );
                },
                revert: () => { /* No optimistic UI — helper toasts on error. */ },
                toastOnError: true,
                errorFallback: state.i18n?.failedSave || 'Failed to accept.',
            } );
        },

        // ── Un-accept reply (author / moderator) ──
        // DELETE /replies/:id/accept clears the accepted answer. Reload so the
        // "ACCEPTED ANSWER" callout, the green border, and the Accept buttons on
        // the other replies all return to the unresolved state.
        *unacceptReply( event ) {
            const el = getElement();
            const replyId = el.ref.dataset.replyId;
            if ( ! replyId ) return;

            yield window.jetonomyOptimistic.gen( {
                apply: () => null,
                fetch: () => window.jetonomyRest.restFetch( `/replies/${ replyId }/accept`, {
                    method: 'DELETE',
                } ),
                onSuccess: () => {
                    if ( window.bnToast ) window.bnToast( state.i18n?.unaccepted || 'Marked as unanswered' );
                    setTimeout( () => window.location.reload(), 600 );
                },
                revert: () => { /* No optimistic UI — helper toasts on error. */ },
                toastOnError: true,
                errorFallback: state.i18n?.failedSave || 'Failed to update.',
            } );
        },

        // ── Set roadmap status on an idea (moderator only, Ideas spaces) ──
        *setIdeaStatus( event ) {
            const ctx = getContext();
            const postId = ctx.postId;
            const btn = event.currentTarget || event.target;
            const newStatus = btn?.dataset?.status;
            if ( ! postId || ! newStatus ) return;
            if ( btn?.classList?.contains( 'is-active' ) ) return; // no-op

            const setter = btn.closest( '.jt-idea-status-setter' );
            const allBtns = setter ? setter.querySelectorAll( '.jt-idea-status-btn' ) : [];

            yield window.jetonomyOptimistic.gen( {
                apply: () => {
                    // Optimistic active swap so the picker reacts instantly.
                    const prevActive = setter?.querySelector( '.jt-idea-status-btn.is-active' ) || null;
                    allBtns.forEach( ( b ) => {
                        b.classList.remove( 'is-active' );
                        b.setAttribute( 'aria-pressed', 'false' );
                    } );
                    btn.classList.add( 'is-active' );
                    btn.setAttribute( 'aria-pressed', 'true' );
                    allBtns.forEach( ( b ) => { b.disabled = true; } );
                    return { prevActive };
                },
                fetch: () => window.jetonomyRest.restFetch( `/posts/${ postId }/idea-status`, {
                    method: 'POST',
                    body: { idea_status: newStatus },
                } ),
                onSuccess: () => {
                    // Mirror the change to the post-header pill so the read-
                    // only badge customers see at the top stays in sync with
                    // the picker below.
                    const pill = document.querySelector( '.jt-post-head .jt-idea-pill:not(.jt-idea-status-btn)' );
                    if ( pill ) {
                        pill.className = 'jt-idea-pill jt-idea-pill-' + newStatus;
                        pill.textContent = btn.textContent.trim();
                    }
                    if ( window.bnToast ) window.bnToast( state.i18n?.statusUpdated || 'Roadmap status updated' );
                },
                revert: ( snap ) => {
                    allBtns.forEach( ( b ) => {
                        b.classList.remove( 'is-active' );
                        b.setAttribute( 'aria-pressed', 'false' );
                    } );
                    if ( snap.prevActive ) {
                        snap.prevActive.classList.add( 'is-active' );
                        snap.prevActive.setAttribute( 'aria-pressed', 'true' );
                    }
                },
                onFinally: () => {
                    allBtns.forEach( ( b ) => { b.disabled = false; } );
                },
                toastOnError: true,
                errorFallback: state.i18n?.failedSave || 'Could not update status.',
            } );
        },

        // ── Toggle collapsible thread ──
        toggleThread() {
            const ctx = getContext();
            ctx.collapsed = ! ctx.collapsed;
        },

        // ── Composer ──
        cancelReplyComposer() {
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
        setReplyTo( event ) {
            const trigger = triggerOf( event );
            if ( ! trigger ) return;
            const replyId = trigger.dataset.replyId;
            const authorName = trigger.dataset.replyAuthor;
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
        quoteReply( event ) {
            const trigger = triggerOf( event );
            if ( ! trigger ) return;
            const replyId = trigger.dataset.replyId;
            const authorName = trigger.dataset.replyAuthor || 'Someone';

            // Find the reply body text.
            const replyEl = trigger.closest( '.jt-reply' );
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

        // ── Editor input tracking ──
        onEditorInput() {
            // Placeholder for editor input handling (e.g. auto-save, char count).
            // The contenteditable body dispatches this on every keystroke.
        },

        // ── Moderation: resolve a queued flag ──
        // Declarative replacement for the former classic moderation.js, used by
        // templates/partials/moderation/flag-card.php on the space queue. The card
        // carries an absolute data-resolve-endpoint prefix (so per-space queues
        // work); restFetch is path-based (owns restBase), so we trim restBase back
        // to a path. Status enum is the canonical server vocabulary: valid |
        // dismissed. (Replaces the dead dismissFlag action + moderation.js.)
        *resolveFlag() {
            const btn  = getElement().ref;
            const card = btn.closest( '.jt-mod-flag' );
            if ( ! card ) return;
            const flagId     = btn.getAttribute( 'data-flag-id' );
            const resolution = btn.getAttribute( 'data-resolution' );
            const endpoint   = card.getAttribute( 'data-resolve-endpoint' );
            if ( ! flagId || ! resolution || ! endpoint ) return;

            const i18n = ( window.jetonomyData && window.jetonomyData.i18n ) || {};
            const base = ( ( window.jetonomyData && window.jetonomyData.restBase ) || '' ).replace( /\/+$/, '' );
            let path = endpoint + flagId + '/resolve';
            if ( base && 0 === path.indexOf( base ) ) path = path.slice( base.length );

            const buttons = card.querySelectorAll( '.jt-mod-resolve' );
            buttons.forEach( ( b ) => { b.disabled = true; } );

            const res = yield window.jetonomyRest.restFetch( path, {
                method: 'POST',
                body: { status: resolution },
            } );
            if ( ! res.ok ) {
                buttons.forEach( ( b ) => { b.disabled = false; } );
                const existing = card.querySelector( '.jt-mod-flag-error' );
                if ( existing ) existing.remove();
                const p = document.createElement( 'p' );
                p.className = 'jt-mod-flag-error';
                p.setAttribute( 'role', 'alert' );
                p.textContent = ( res.data && res.data.message ) || i18n.resolveFailed || 'Could not resolve flag. Please try again.';
                card.appendChild( p );
                return;
            }
            // Remove the resolved card; if the queue is now empty, swap in the
            // empty state (ported from moderation.js removeCard()).
            const container = card.parentNode;
            card.remove();
            if ( container && ! container.querySelector( '.jt-mod-flag' ) && container.parentNode ) {
                const wrapper = document.createElement( 'div' );
                wrapper.className = 'jt-empty';
                const msg = document.createElement( 'div' );
                msg.className = 'jt-empty-text';
                msg.textContent = i18n.queueClean || 'Queue cleared.';
                wrapper.appendChild( msg );
                container.parentNode.replaceChild( wrapper, container );
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

            // Get CAPTCHA token if a provider is active. The Turnstile input
            // is scoped to THIS composer's widget — a thread page can hold
            // several reply composers, each with its own container.
            let captchaToken = '';
            if ( window.jetonomyCaptcha ) {
                if ( window.jetonomyCaptcha.provider === 'recaptcha_v3' && window.grecaptcha ) {
                    captchaToken = yield new Promise( ( r ) => window.grecaptcha.execute( window.jetonomyCaptcha.siteKey, { action: 'submit' } ).then( r ) );
                } else if ( window.jetonomyCaptcha.provider === 'turnstile' ) {
                    const tsScope = editorWrap || document;
                    const tsInput = tsScope.querySelector( '[name="cf-turnstile-response"]' );
                    captchaToken = tsInput ? ( tsInput.value || '' ) : '';
                }
            }

            try {
                const response = yield window.jetonomyRest.restFetch(
                    `/posts/${ postId }/replies`,
                    {
                        method: 'POST',
                        body: {
                            content: body.innerHTML,
                            ...( parentId && { parent_id: parentId } ),
                            ...( captchaToken && { captcha_token: captchaToken } ),
                        },
                    }
                );

                if ( response.ok ) {
                    const payload = response.data || {};
                    // Held for moderation -> not visible yet; tell the user, clear,
                    // don't append.
                    if ( 'pending' === payload.status || 'spam' === payload.status ) {
                        body.innerHTML = '';
                        if ( window.bnToast ) window.bnToast( state.i18n?.pendingNotice || 'Your reply is awaiting moderation and will appear once approved.' );
                    } else {
                        // Top-level replies append in place; nested replies and any
                        // append miss fall back to a full reload.
                        const appended = ! parentId && payload.id
                            ? yield appendNewReply( payload.id )
                            : false;
                        if ( appended ) {
                            body.innerHTML = '';
                            state.replyToId = null;
                            state.replyToAuthor = '';
                        } else {
                            window.location.reload();
                        }
                    }
                } else {
                    const err = response.data || {};
                    if ( window.bnToast ) window.bnToast( err.message || state.i18n?.failedSave || 'Failed to post reply.' );
                }
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.' );
            } finally {
                ctx.submitting = false;
            }
        },

        // ── Publish mode menu ──
        //
        // [1.4.3 WS3-B] Migrated to jetonomySmartDropdown. Previous
        // implementation used state.publishMenuOpen + data-wp-bind--hidden,
        // which couldn't flip placement when the trigger sat near the
        // viewport bottom. The shared primitive handles flip/outside-click/
        // Escape for free. We still flip state.publishMenuOpen for
        // backwards-compat (other code may read it) and as a paper trail.
        togglePublishMenu( event ) {
            const trigger = ( event && ( event.currentTarget || event.target?.closest( '.jt-publish-mode__toggle' ) ) ) || getElement()?.ref;
            if ( ! trigger ) return;
            const menu = trigger.closest( '.jt-publish-mode' );
            const panel = menu?.querySelector( '.jt-publish-mode__menu' );
            if ( ! panel ) return;
            panel.removeAttribute( 'hidden' );

            if ( ! trigger._jtDropdown ) {
                trigger._jtDropdown = window.jetonomySmartDropdown( trigger, panel, {
                    placement: 'bottom-end',
                    group: 'jt-publish',
                    closeOnOutside: true,
                    closeOnEscape: true,
                    onOpen: () => { state.publishMenuOpen = true; },
                    onClose: () => { state.publishMenuOpen = false; },
                } );
                panel.style.display = 'none';
            }
            trigger._jtDropdown.toggle();
        },

        // Helper — close every open publish-mode dropdown on the page. The
        // select* actions below all need this and the smartDropdown group
        // mechanism is per-trigger, so we walk the DOM.
        _closePublishMenus() {
            document.querySelectorAll( '.jt-publish-mode__toggle' ).forEach( ( t ) => {
                if ( t._jtDropdown && t._jtDropdown.isOpen ) t._jtDropdown.close();
            } );
            state.publishMenuOpen = false;
        },

        selectPublishNow() {
            const ctx = getContext();
            ctx.postStatus    = 'publish';
            ctx.showScheduler = false;
            actions._closePublishMenus();
            state.submitLabel = state.i18n?.postTopic || 'Post Topic';
        },

        selectSaveDraft() {
            const ctx = getContext();
            ctx.postStatus    = 'draft';
            ctx.showScheduler = false;
            actions._closePublishMenus();
            state.submitLabel = state.i18n?.saveDraft || 'Save Draft';
        },

        selectSchedule() {
            const ctx = getContext();
            ctx.postStatus    = 'draft';
            ctx.showScheduler = true;
            actions._closePublishMenus();
            state.submitLabel = state.i18n?.schedule || 'Schedule';
        },

        // ── Shared post-compose generator (1.4.3) ──
        //
        // Single submission path used by BOTH the full new-post page and the
        // inline embed. The previous codebase had two parallel implementations
        // (`submitNewPost` for the page, `composeTopicSubmit` for the embed)
        // that drifted on private-flag handling, error surfaces, and the
        // server-side endpoint. composePost collapses them onto one function
        // whose behaviour is controlled by per-flag callbacks so the embed can
        // opt out of tags / scheduler / CAPTCHA while still going through the
        // same validation, REST plumbing, and post-success branching.
        //
        // Required opts:
        //   requireTitle    Boolean — when false, empty title is allowed (feed spaces).
        //   collectTags     Boolean — read `[name="tags"]` and split on commas.
        //   collectPrefix   Boolean — read `[name="prefix"]`.
        //   collectPrivate  Boolean — read `[name="is_private"]` checkbox.
        //   collectSchedule Boolean — read `[name="published_*"]` and merge into ISO.
        //   collectCaptcha  Boolean — query the active CAPTCHA provider for a token.
        //   bodySource      'editor' (read contenteditable .innerHTML) | 'textarea'.
        //   errorSink       'state'   (write to state.submitError) |
        //                   'context' (write to getContext().error).
        //   extraPayload    Optional (form) => object — Pro extensions inject fields.
        //   onSuccess       Optional (data) => void  — caller overrides redirect.
        *composePost( event, opts ) {
            if ( event && typeof event.preventDefault === 'function' ) {
                event.preventDefault();
            }

            const o          = opts || {};
            const ctx        = getContext();
            const writeError = ( msg ) => {
                if ( 'context' === o.errorSink ) {
                    ctx.error = msg || '';
                } else {
                    state.submitError = msg || '';
                }
            };

            // Reset prior error before doing anything else so a retry doesn't
            // show two errors stacked.
            writeError( '' );

            // The page surface uses a top-level submit chrome (state.*); the
            // embed surface uses per-instance context.submitting. Drive both
            // so a single helper covers both. setBusy/setIdle are no-ops on
            // the path that doesn't own a particular field.
            const setBusy = () => {
                state.isSubmitting = true;
                ctx.submitting     = true;
                // Close any open publish-mode dropdown when we start a
                // submission so the menu doesn't linger over the post-success
                // toast or redirect. _closePublishMenus also flips
                // state.publishMenuOpen for any legacy bindings.
                if ( typeof actions._closePublishMenus === 'function' ) {
                    actions._closePublishMenus();
                } else {
                    state.publishMenuOpen = false;
                }
                if ( 'state' === o.errorSink ) {
                    state.submitLabel = state.i18n?.posting || 'Posting...';
                }
            };
            const setIdle = () => {
                state.isSubmitting = false;
                ctx.submitting     = false;
                if ( 'state' === o.errorSink ) {
                    state.submitLabel = state.i18n?.postTopic || 'Post Topic';
                }
            };

            setBusy();

            // Resolve the form root. For the page composer event.target is the
            // form (form submit event); for the embed it's the inner form
            // wrapping compose-fields. Either way we need a query root for
            // every field lookup so the dispatch works the same.
            const form = getElement()?.ref
                || ( event && event.target && event.target.closest ? event.target.closest( 'form' ) : null )
                || document.getElementById( 'jt-new-post-form' )
                || document.querySelector( '.jt-compose-topic-embed .jt-compose-topic-form' );

            if ( ! form ) {
                writeError( state.i18n?.failedSave || 'Failed to create post.' );
                setIdle();
                return;
            }

            // ── Title ─────────────────────────────────────────────────────────
            // Feed spaces post UNTITLED — opts.requireTitle is false. When the
            // title input was rendered but the space type flipped to feed via
            // JS (picker mode), we still read whatever the user typed but
            // don't block submit on emptiness. The server is the source of
            // truth on "must this space have a title" — sending '' for a feed
            // space is a valid request.
            const titleInput = form.querySelector( '[name="title"]' );
            const title      = titleInput ? ( titleInput.value || '' ).trim() : '';
            if ( false !== o.requireTitle && ! title ) {
                writeError( state.i18n?.titleRequired || 'Please enter a title for your topic.' );
                setIdle();
                return;
            }

            // ── Body ──────────────────────────────────────────────────────────
            // `editor` mode reads innerHTML so we preserve formatting; we
            // additionally validate against textContent because an
            // "interacted-then-cleared" contenteditable holds <br>/&nbsp; in
            // innerHTML which is truthy and was the root cause of
            // Basecamp #9808714691 (silent submit-fail when body was empty).
            // `textarea` mode reads .value directly.
            let content      = '';
            let contentPlain = '';
            if ( 'textarea' === o.bodySource ) {
                const ta = form.querySelector( 'textarea[name="content"], .jt-compose-topic-body' );
                content      = ta ? ( ta.value || '' ).trim() : '';
                contentPlain = content;
            } else {
                const bodyEl = form.querySelector( '[contenteditable]' );
                content      = bodyEl ? ( bodyEl.innerHTML || '' ).trim() : '';
                contentPlain = bodyEl ? ( bodyEl.textContent || '' ).trim() : '';
            }
            if ( ! contentPlain ) {
                writeError( state.i18n?.bodyRequired || 'Please add some details before posting.' );
                setIdle();
                return;
            }

            // ── Tags / Prefix / Private ───────────────────────────────────────
            const tagsRaw  = o.collectTags ? ( form.querySelector( '[name="tags"]' )?.value?.trim() || '' ) : '';
            const tags     = tagsRaw ? tagsRaw.split( ',' ).map( t => t.trim() ).filter( Boolean ) : [];
            const prefix   = o.collectPrefix ? ( form.querySelector( '[name="prefix"]' )?.value || '' ) : '';
            const isPrivate = o.collectPrivate
                ? !! form.querySelector( '[name="is_private"]' )?.checked
                : false;

            // ── Schedule ──────────────────────────────────────────────────────
            // Two native inputs (date + hour + minute) get merged into an
            // ISO-like local datetime so Firefox's missing time-picker
            // popup doesn't block scheduling. See Basecamp #9788118420.
            const postStatus = ctx.postStatus || 'publish';
            let publishedAt  = '';
            if ( o.collectSchedule && ctx.showScheduler ) {
                const dateVal   = form.querySelector( '[name="published_date"]' )?.value?.trim() || '';
                const hourVal   = form.querySelector( '[name="published_hour"]' )?.value?.trim() || '';
                const minuteVal = form.querySelector( '[name="published_minute"]' )?.value?.trim() || '';
                const timeVal   = hourVal && minuteVal ? `${ hourVal }:${ minuteVal }` : '';
                // Send the plain wall-clock value the user picked. The server
                // interprets it in the SITE timezone (the WordPress Settings ->
                // General timezone) and converts to UTC for storage, matching
                // the core post scheduler. We intentionally do NOT stamp the
                // browser's timezone here — the scheduled time means "this time
                // in the site's timezone", regardless of where the author is.
                if ( dateVal && timeVal ) {
                    publishedAt = dateVal + 'T' + timeVal + ':00';
                } else if ( dateVal ) {
                    publishedAt = dateVal + 'T00:00:00';
                }
            }
            if ( o.collectSchedule && 'draft' === postStatus && ctx.showScheduler && ! publishedAt ) {
                state.isSubmitting = false;
                state.submitLabel  = state.i18n?.schedule || 'Schedule';
                if ( window.bnToast ) window.bnToast( state.i18n?.scheduleDateRequired || 'Please choose a publish date and time.' );
                return;
            }

            // ── CAPTCHA ───────────────────────────────────────────────────────
            // Turnstile input is read scoped to THIS form — the login block
            // or another composer on the same page has its own widget.
            let captchaToken = '';
            if ( o.collectCaptcha && window.jetonomyCaptcha ) {
                if ( window.jetonomyCaptcha.provider === 'recaptcha_v3' && window.grecaptcha ) {
                    captchaToken = yield new Promise( ( r ) => window.grecaptcha.execute( window.jetonomyCaptcha.siteKey, { action: 'submit' } ).then( r ) );
                } else if ( window.jetonomyCaptcha.provider === 'turnstile' ) {
                    const tsInput = form.querySelector( '[name="cf-turnstile-response"]' );
                    captchaToken  = tsInput ? ( tsInput.value || '' ) : '';
                }
            }

            // ── Payload ───────────────────────────────────────────────────────
            const payload = {
                title,
                content,
                type: ctx.postType,
                tags,
                status: postStatus,
                prefix,
                is_private: isPrivate,
            };
            if ( publishedAt ) payload.published_at = publishedAt;
            if ( captchaToken ) payload.captcha_token = captchaToken;

            // Pro custom-field inputs collected via the shared collector
            // (window.jetonomyCollectCustomFields). Empty when the extension is off
            // (no inputs) so the key is omitted and the server listener no-ops.
            const customFields = window.jetonomyCollectCustomFields ? window.jetonomyCollectCustomFields( form ) : {};
            if ( Object.keys( customFields ).length > 0 ) {
                payload.custom_fields = customFields;
            }

            if ( typeof o.extraPayload === 'function' ) {
                const extras = o.extraPayload( form ) || {};
                Object.assign( payload, extras );
            }

            // ── Request ───────────────────────────────────────────────────────
            // restFetch (1.4.3) wraps fetch, refreshes nonce on 403, and
            // normalises the response into { ok, status, data }. Using it here
            // means a stale nonce no longer eats the post silently.
            if ( ! window.jetonomyRest || typeof window.jetonomyRest.restFetch !== 'function' ) {
                writeError( state.i18n?.failedSave || 'Failed to create post.' );
                setIdle();
                return;
            }

            const result = yield window.jetonomyRest.restFetch(
                `/spaces/${ ctx.spaceId }/posts`,
                { method: 'POST', body: payload }
            );

            if ( ! result.ok ) {
                const errMsg = ( result.data && result.data.message )
                    ? result.data.message
                    : ( state.i18n?.failedSave || 'Failed to create post.' );
                writeError( errMsg );
                if ( window.bnToast ) window.bnToast( errMsg );
                setIdle();
                return;
            }

            // ── Success ───────────────────────────────────────────────────────
            const data    = result.data || {};
            const dataId  = data.id || data.data?.id;
            const status  = data.status || data.data?.status || 'publish';
            const slug    = data.slug || data.data?.slug || '';
            const spaceSlug = data.space_slug || data.data?.space_slug || ctx.spaceSlug || '';

            if ( typeof o.onSuccess === 'function' ) {
                o.onSuccess( data );
                return;
            }

            if ( ! dataId ) {
                // Server returned 2xx with no id — fall back to a reload so
                // the user still lands somewhere meaningful.
                window.location.reload();
                return;
            }

            if ( 'draft' === status ) {
                state.submitLabel  = state.i18n?.saveDraft || 'Save Draft';
                setIdle();
                if ( window.bnToast ) window.bnToast( state.i18n?.draftSaved || 'Draft saved. You can find it in your profile under Drafts.' );
                return;
            }
            if ( 'pending' === status || 'spam' === status ) {
                setIdle();
                if ( window.bnToast ) window.bnToast( state.i18n?.pendingNotice || 'Your post is awaiting moderation and will appear once approved.' );
                return;
            }

            if ( slug && spaceSlug && state.communityBase ) {
                window.location.href = `${ state.communityBase }/s/${ spaceSlug }/t/${ slug }/`;
                return;
            }
            // Last-resort fallback for embed hosts where communityBase isn't
            // localized — hard reload so the user can confirm their post
            // landed.
            window.location.reload();
        },

        // Thin wrapper used by the full new-post page. The form's
        // `data-wp-on--submit` binding (filterable as
        // `jetonomy_new_post_submit_action`) targets this name; Pro extensions
        // (e.g. the Polls builder) replace it with their own action which
        // calls composePost with extraPayload. Keeping this wrapper avoids
        // breaking any third-party customisation that already overrides it.
        *submitNewPost( event ) {
            // Feed spaces are untitled by design — the server derives a title
            // from the body. Mirror the inline-embed behaviour so the full
            // new-post page doesn't block a feed status update for lacking a
            // title (the page seeds spaceType into the form's data-wp-context).
            const requireTitle = ( 'feed' !== ( getContext().spaceType || '' ) );
            yield actions.composePost( event, {
                requireTitle,
                collectTags:     true,
                collectPrefix:   true,
                collectPrivate:  true,
                collectSchedule: true,
                collectCaptcha:  true,
                bodySource:      'editor',
                errorSink:       'state',
            } );
        },

        // ── Profile save ──
        // ── Local avatar (#9966775705) ──
        // Upload goes through the existing POST /media pipeline (uploads
        // gate + auto-alt); the resulting URL is staged in the hidden
        // avatar_url input and persisted by saveProfile's PATCH /users/me.
        chooseAvatar() {
            document.getElementById( 'jt-avatar-file' )?.click();
        },

        *avatarFileSelected( event ) {
            const input = event.target;
            const file  = input.files && input.files[0];
            if ( ! file ) return;

            // Animated GIFs lose their animation when redrawn to a canvas,
            // so they skip the cropper and upload as chosen. Everything
            // else goes through the square-crop dialog first.
            if ( 'image/gif' === file.type || ! window.HTMLDialogElement ) {
                yield* avatarUpload( file, input );
                return;
            }

            const dialog = document.getElementById( 'jt-avatar-crop' );
            const img    = document.getElementById( 'jt-crop-image' );
            if ( ! dialog || ! img ) {
                yield* avatarUpload( file, input );
                return;
            }

            avatarCrop.file  = file;
            avatarCrop.input = input;
            avatarCrop.scale = 1;
            avatarCrop.x     = 0;
            avatarCrop.y     = 0;

            const url = URL.createObjectURL( file );
            yield new Promise( ( resolve, reject ) => {
                img.onload  = resolve;
                img.onerror = reject;
                img.src     = url;
            } ).catch( () => {} );
            avatarCrop.url = url;

            const zoom = document.getElementById( 'jt-crop-zoom' );
            if ( zoom ) zoom.value = '100';
            avatarCropBindDrag();
            // Layout AFTER showModal - a closed <dialog> is display:none, so
            // the viewport measures 0 wide until it is actually open.
            dialog.showModal();
            avatarCropLayout();
        },

        avatarCropZoom( event ) {
            avatarCrop.scale = Math.max( 1, parseInt( event.target.value, 10 ) / 100 );
            avatarCropLayout();
        },

        avatarCropCancel() {
            avatarCropClose();
        },

        *avatarCropApply() {
            const blob = yield avatarCropRender();
            const { file, input } = avatarCrop;
            avatarCropClose();
            if ( ! blob || ! file || ! input ) return;
            const name    = file.name.replace( /\.[a-z0-9]+$/i, '' ) + ( 'image/png' === blob.type ? '.png' : '.jpg' );
            const cropped = new File( [ blob ], name, { type: blob.type } );
            yield* avatarUpload( cropped, input );
        },

        removeAvatar( event ) {
            const btn    = event.target.closest( 'button' );
            const form   = btn?.closest( 'form' );
            const hidden = form?.querySelector( '[name="avatar_url"]' );
            if ( hidden ) hidden.value = '';

            const preview = document.getElementById( 'jt-avatar-preview' );
            if ( preview && preview.dataset.defaultSrc ) preview.src = preview.dataset.defaultSrc;
            btn?.setAttribute( 'hidden', '' );
        },

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

            // Pro custom-field inputs collected via the shared collector
            // (window.jetonomyCollectCustomFields). Empty object when Pro /
            // custom-fields disabled — no inputs render and the extra PATCH below
            // is skipped.
            const customFields = window.jetonomyCollectCustomFields ? window.jetonomyCollectCustomFields( form ) : {};

            const payload = { display_name: displayName, bio };
            // Local avatar (#9966775705): the hidden input carries the URL of
            // the uploaded attachment ('' = removed → Gravatar fallback).
            const avatarInput = form.querySelector( '[name="avatar_url"]' );
            if ( avatarInput ) {
                payload.avatar_url = avatarInput.value || '';
            }
            if ( Object.keys( notifPrefs ).length > 0 ) {
                payload.notification_preferences = notifPrefs;
            }

            // Master email opt-out checkbox. Always sent (boolean) so
            // unchecking clears the meta, not only checking sets it.
            const optOut = form.querySelector( '[name="email_opt_out"]' );
            if ( optOut ) {
                payload.email_opt_out = optOut.checked;
            }

            try {
                const response = yield window.jetonomyRest.restFetch(
                    `/users/me`,
                    {
                        method: 'PATCH',
                        body: payload,
                    }
                );
                if ( ! response.ok ) {
                    const err = response.data || {};
                    if ( window.bnToast ) window.bnToast( err.message || ( state.i18n?.failedSaveProfile || 'Failed to save profile.' ), 'error' );
                    return;
                }

                // Persist Pro custom-field values via Pro's dedicated endpoint.
                // 404 means the extension is off — silent skip so free still
                // redirects. Validation errors surface as a toast but don't
                // block the redirect: the core profile already saved.
                if ( Object.keys( customFields ).length > 0 ) {
                    const cfResponse = yield window.jetonomyRest.restFetch(
                        `/users/me/fields`,
                        {
                            method: 'PATCH',
                            body: customFields,
                        }
                    );
                    if ( ! cfResponse.ok && 404 !== cfResponse.status ) {
                        const cfErr = cfResponse.data || {};
                        if ( window.bnToast ) {
                            window.bnToast(
                                cfErr.message || ( state.i18n?.failedSaveProfile || 'Some custom fields could not be saved.' ),
                                'error'
                            );
                        }
                    }
                }

                document.dispatchEvent( new CustomEvent( 'jetonomy:profileSaved', { detail: { form, payload, customFields } } ) );
                window.location.href = ctx.profileUrl;
            } catch {
                if ( window.bnToast ) window.bnToast( state.i18n?.networkError || 'Network error. Please try again.', 'error' );
            } finally {
                state.isSubmitting = false;
            }
        },

        // ── Compose Topic embed (1.3.7, refactored 1.4.3) ──
        // Inline topic composer usable on any WordPress page — fixed-space or
        // member picker. Submission goes through the shared composePost
        // generator; this layer only handles the picker-specific UX (space
        // selection updates title-field visibility based on the picked
        // space's type, body input syncing).
        composeTopicSelectSpace( e ) {
            const ctx     = getContext();
            const sel     = e.target;
            const spaceId = parseInt( sel.value, 10 ) || 0;
            // Resolve the picked space's type from the <option data-type="…">
            // marker the partial emits. Falls back to the server-built map on
            // context.spaceTypes if the option marker is missing (defensive).
            const opt       = sel.options ? sel.options[ sel.selectedIndex ] : null;
            const optType   = opt ? ( opt.getAttribute( 'data-type' ) || '' ) : '';
            const mapType   = ctx.spaceTypes ? ( ctx.spaceTypes[ spaceId ] || '' ) : '';
            const spaceType = optType || mapType;

            ctx.spaceId          = spaceId;
            ctx.spaceType        = spaceType;
            // Feed spaces hide the title input via data-wp-bind--hidden on the
            // title group; every other space type re-shows it.
            ctx.composeShowTitle = ( 'feed' !== spaceType );
            ctx.error            = '';
        },
        composeTopicTitleInput( e ) {
            const ctx = getContext();
            ctx.title = e.target.value;
            if ( ctx.error ) ctx.error = '';
        },
        composeTopicBodyInput( e ) {
            const ctx = getContext();
            ctx.body  = e.target.value;
            if ( ctx.error ) ctx.error = '';
        },
        *composeTopicSubmit( event ) {
            const ctx = getContext();
            ctx.error = '';

            // Picker mode demands a chosen space before anything else.
            if ( ! ctx.spaceId ) {
                ctx.error = state.i18n?.chooseSpace || 'Choose a space first.';
                return;
            }

            // requireTitle follows the picked space's type — feed spaces are
            // untitled by design, so we only enforce a title on non-feed
            // spaces. composePost still reads the title field and submits
            // whatever is there.
            const requireTitle = ( 'feed' !== ( ctx.spaceType || '' ) );

            yield actions.composePost( event, {
                requireTitle,
                collectTags:     false,
                collectPrefix:   false,
                collectPrivate:  true, // regression #9886339472 — embed must respect private flag.
                collectSchedule: false,
                collectCaptcha:  true, // posts endpoint verifies for low-trust users (#9977126420).
                bodySource:      'textarea',
                errorSink:       'context',
            } );
        },
    },

    callbacks: {
        // (Notification polling lives in header.js — the old
        // startPolling/pollNotifications chain here had no data-wp-init
        // consumer and was removed in 1.5.0; audit C.)

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

        // Poll for new replies and show a sticky banner. Adaptive + visibility-
        // aware: pauses entirely while the tab is hidden, and backs the interval
        // off when nothing new arrives, so an abandoned tab stops hammering
        // /updates. Resets to the fast cadence on activity or when refocused.
        initReplyPolling() {
            const repliesSection = document.getElementById( 'jt-replies-container' );
            if ( ! repliesSection || ! state.currentPostId ) return;

            const MIN_INTERVAL = 15000;   // active cadence
            const MAX_INTERVAL = 120000;  // idle ceiling (doubles 15->30->60->120)
            let interval  = MIN_INTERVAL;
            let lastCheck = Date.now();
            let timer     = null;

            const schedule = () => {
                clearTimeout( timer );
                // Paused while hidden; the visibilitychange handler resumes it.
                if ( document.hidden ) return;
                timer = setTimeout( poll, interval );
            };

            const poll = async () => {
                try {
                    const since = new Date( lastCheck ).toISOString();
                    const response = await window.jetonomyRest.restFetch(
                        `/updates?scope=post&id=${ state.currentPostId }&since=${ encodeURIComponent( since ) }`
                    );
                    if ( response.ok ) {
                        const data = response.data || {};
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
                            interval = MIN_INTERVAL;                      // activity -> fast again
                        } else {
                            interval = Math.min( interval * 2, MAX_INTERVAL ); // idle -> back off
                        }
                        lastCheck = Date.now();
                    }
                } catch {
                    // silent — transient network blip; keep current cadence
                }
                schedule();
            };

            document.addEventListener( 'visibilitychange', () => {
                if ( document.hidden ) {
                    clearTimeout( timer );           // pause; no work in the background
                } else {
                    clearTimeout( timer );
                    interval = MIN_INTERVAL;         // resume fast + catch up now
                    poll();
                }
            } );

            schedule();
        },
    },
} );

/* ── Link Preview Cards ──
   Renders a LinkedIn / Twitter / Facebook style rich card beneath any standalone
   link inside .jt-post-body / .jt-reply-body. Response shape comes from
   GET /jetonomy/v1/link-preview (Jetonomy\Services\Links\Preview_Data) so the
   same endpoint drives the native mobile app. */
( function() {
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
        return window.jetonomyRest.restFetch( '/link-preview?url=' + encodeURIComponent( href ), {
            headers: { 'Accept': 'application/json' },
        } ).then( r => r.ok ? r.data : null );
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
                let path = '/search?q=' + encodeURIComponent( q ) + '&type=post';
                if ( ! useAllSpaces && spaceId ) {
                    path += '&space_id=' + spaceId;
                }

                window.jetonomyRest.restFetch( path )
                    .then( function( r ) { return r.data || {}; } )
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
