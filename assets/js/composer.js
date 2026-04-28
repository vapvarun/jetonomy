/**
 * Jetonomy Reply Composer
 * Simple contenteditable enhancement with toolbar actions
 */

// Mobile hamburger navigation
document.addEventListener( 'DOMContentLoaded', function() {
    var toggle = document.querySelector( '.jt-mobile-toggle' );
    var nav    = document.querySelector( '.jt-nav' );
    if ( toggle && nav ) {
        toggle.addEventListener( 'click', function() {
            nav.classList.toggle( 'open' );
            if ( nav.classList.contains( 'open' ) && ! nav.querySelector( '.jt-mobile-close' ) ) {
                var close = document.createElement( 'button' );
                close.className = 'jt-mobile-close';
                close.innerHTML = '&times;';
                close.setAttribute( 'aria-label', 'Close menu' );
                close.addEventListener( 'click', function() { nav.classList.remove( 'open' ); } );
                nav.prepend( close );
            }
        } );
    }
} );

document.addEventListener( 'DOMContentLoaded', () => {
    const composers = document.querySelectorAll( '.jt-editor' );

    composers.forEach( ( composer ) => {
        const body = composer.querySelector( '.jt-editor-body' );
        const toolbar = composer.querySelector( '.jt-editor-bar' );

        if ( ! toolbar || ! body ) return;

        // Track the selection range inside the composer body while the user
        // is interacting with it. Saved on every selectionchange that lands
        // within `body` so we can restore it after `prompt()` / `confirm()`
        // steals focus (Basecamp 9803832443: Link button did nothing because
        // prompt blurred the composer and createLink had nothing to wrap).
        let savedRange = null;
        document.addEventListener( 'selectionchange', () => {
            const sel = window.getSelection();
            if ( ! sel || sel.rangeCount === 0 ) return;
            const range = sel.getRangeAt( 0 );
            if ( body.contains( range.commonAncestorContainer ) ) {
                savedRange = range.cloneRange();
            }
        } );

        const restoreSelection = () => {
            body.focus();
            if ( ! savedRange ) return;
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange( savedRange );
        };

        const escapeHtml = ( s ) => String( s ).replace( /[&<>"']/g, ( c ) => ( {
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[ c ] ) );

        // Toolbar button actions
        toolbar.addEventListener( 'click', ( e ) => {
            const btn = e.target.closest( 'button' );
            if ( ! btn ) return;

            const cmd = btn.dataset.cmd;
            if ( ! cmd ) return;

            // Skip 'image' and 'emoji' — handled separately below.
            if ( cmd === 'image' || cmd === 'emoji' ) return;

            e.preventDefault();
            body.focus();

            switch ( cmd ) {
                case 'bold':
                    document.execCommand( 'bold' );
                    break;
                case 'italic':
                    document.execCommand( 'italic' );
                    break;
                case 'code':
                    document.execCommand( 'insertHTML', false, '<code>' + escapeHtml( window.getSelection().toString() ) + '</code>' );
                    break;
                case 'link': {
                    // prompt() steals focus — capture the current range first
                    // so we can re-anchor the insert point after the dialog closes.
                    const sel = window.getSelection();
                    if ( sel.rangeCount && body.contains( sel.getRangeAt( 0 ).commonAncestorContainer ) ) {
                        savedRange = sel.getRangeAt( 0 ).cloneRange();
                    }
                    const selectedText = sel.toString();

                    const raw = prompt( 'Enter URL:' );
                    if ( ! raw ) break;
                    const trimmed = raw.trim();
                    if ( ! trimmed ) break;

                    // Accept bare domains (example.com) and force https:// when
                    // no scheme is present so the resulting <a href> is valid.
                    const url = /^(https?:|mailto:|\/)/i.test( trimmed ) ? trimmed : 'https://' + trimmed;

                    // Put the caret back where it was before the prompt opened.
                    restoreSelection();

                    const label = selectedText || trimmed;
                    const html  = '<a href="' + escapeHtml( url ) + '" rel="noopener noreferrer" target="_blank">' + escapeHtml( label ) + '</a>';
                    document.execCommand( 'insertHTML', false, html );
                    break;
                }
                case 'quote':
                    document.execCommand( 'formatBlock', false, 'blockquote' );
                    break;
            }
        } );

        // Clear placeholder on focus
        body.addEventListener( 'focus', () => {
            if ( body.textContent.trim() === '' ) {
                body.innerHTML = '';
            }
        } );

        // Ctrl+Enter / Cmd+Enter to submit
        body.addEventListener( 'keydown', function( e ) {
            if ( ( e.ctrlKey || e.metaKey ) && e.key === 'Enter' ) {
                e.preventDefault();
                const submitBtn = composer.querySelector( '.jt-btn-fill' );
                if ( submitBtn ) submitBtn.click();
            }
        } );
    } );

    // ── G1: Drag-Drop & Paste-to-Upload Image Handling ──

    document.querySelectorAll( '.jt-editor-body' ).forEach( function( editor ) {
        // Paste handler — paste screenshots from clipboard
        editor.addEventListener( 'paste', function( e ) {
            var items = ( e.clipboardData || e.originalEvent.clipboardData ).items;
            for ( var i = 0; i < items.length; i++ ) {
                if ( items[ i ].type.indexOf( 'image' ) !== -1 ) {
                    e.preventDefault();
                    var file = items[ i ].getAsFile();
                    uploadImage( file, editor );
                    return;
                }
            }
        } );

        // Drag-drop handler
        editor.addEventListener( 'dragover', function( e ) {
            e.preventDefault();
            e.stopPropagation();
            editor.classList.add( 'jt-editor-dragover' );
        } );

        editor.addEventListener( 'dragleave', function( e ) {
            e.preventDefault();
            editor.classList.remove( 'jt-editor-dragover' );
        } );

        editor.addEventListener( 'drop', function( e ) {
            e.preventDefault();
            e.stopPropagation();
            editor.classList.remove( 'jt-editor-dragover' );

            var files = e.dataTransfer.files;
            for ( var i = 0; i < files.length; i++ ) {
                if ( files[ i ].type.indexOf( 'image' ) !== -1 ) {
                    uploadImage( files[ i ], editor );
                }
            }
        } );
    } );

    // Image button in toolbar — opens a file picker
    document.querySelectorAll( '.jt-editor-bar' ).forEach( function( toolbar ) {
        var imgBtn = toolbar.querySelector( '[data-cmd="image"]' );
        if ( ! imgBtn ) return;

        var fileInput = document.createElement( 'input' );
        fileInput.type = 'file';
        fileInput.accept = 'image/*';
        fileInput.style.display = 'none';
        toolbar.appendChild( fileInput );

        imgBtn.addEventListener( 'click', function( e ) {
            e.preventDefault();
            fileInput.click();
        } );

        fileInput.addEventListener( 'change', function() {
            if ( this.files[ 0 ] ) {
                var editor = toolbar.closest( '.jt-editor' ).querySelector( '.jt-editor-body' );
                uploadImage( this.files[ 0 ], editor );
                this.value = '';
            }
        } );
    } );

    function uploadImage( file, editor ) {
        // Show uploading placeholder
        var placeholder = document.createElement( 'div' );
        placeholder.className = 'jt-upload-placeholder';
        placeholder.textContent = 'Uploading\u2026';
        editor.appendChild( placeholder );

        // 1.4.0 A.1: POST /jetonomy/v1/media replaces wp_ajax_jetonomy_upload_image.
        // Response is the attachment object directly: { id, url, alt, mime, width, height }
        // on 2xx, or { code, message, data: { status } } on 4xx/5xx.
        var apiBase = ( typeof jetonomyUpload !== 'undefined' && jetonomyUpload.apiBase )
            ? jetonomyUpload.apiBase
            : '/wp-json/jetonomy/v1';
        var restNonce = ( typeof jetonomyUpload !== 'undefined' && jetonomyUpload.restNonce )
            ? jetonomyUpload.restNonce
            : '';

        var formData = new FormData();
        formData.append( 'file', file );

        fetch( apiBase + '/media', {
            method: 'POST',
            headers: restNonce ? { 'X-WP-Nonce': restNonce } : {},
            body: formData,
            credentials: 'same-origin',
        } )
        .then( function( r ) {
            return r.json().then( function( body ) {
                return { ok: r.ok, status: r.status, body: body };
            } );
        } )
        .then( function( res ) {
            placeholder.remove();
            if ( res.ok && res.body && res.body.url ) {
                var img = document.createElement( 'img' );
                img.src = res.body.url;
                img.alt = res.body.alt || file.name;
                img.style.maxWidth = '100%';
                img.style.height = 'auto';
                img.style.borderRadius = '8px';
                img.style.margin = '8px 0';
                editor.appendChild( img );
                editor.appendChild( document.createElement( 'br' ) );
            } else {
                var msg = ( res.body && res.body.message ) ? res.body.message : 'Upload failed';
                if ( window.bnToast ) { window.bnToast( msg, 'error' ); }
            }
        } )
        .catch( function() {
            placeholder.remove();
            if ( window.bnToast ) { window.bnToast( 'Upload failed', 'error' ); }
        } );
    }

    // ── G3: Instant Search-as-You-Type ──

    document.querySelectorAll( '.jt-search-page-input input' ).forEach( function( input ) {
        var dropdown = document.createElement( 'div' );
        dropdown.className = 'jt-instant-results';
        dropdown.style.display = 'none';
        var formEl = input.closest( '.jt-search-page-form' );
        if ( formEl ) {
            formEl.appendChild( dropdown );
        }

        var timer;
        input.addEventListener( 'input', function() {
            clearTimeout( timer );
            var q = input.value.trim();
            if ( q.length < 2 ) { dropdown.style.display = 'none'; return; }

            timer = setTimeout( function() {
                var apiBase = ( typeof jetonomyUpload !== 'undefined' && jetonomyUpload.apiBase )
                    ? jetonomyUpload.apiBase
                    : '/wp-json/jetonomy/v1';

                fetch( apiBase + '/search?q=' + encodeURIComponent( q ) + '&type=all&limit=5' )
                .then( function( r ) { return r.json(); } )
                .then( function( res ) {
                    var posts = ( res.data && res.data.posts ) ? res.data.posts : ( res.data || [] );
                    if ( ! posts.length ) { dropdown.style.display = 'none'; return; }

                    dropdown.innerHTML = '';
                    posts.forEach( function( post ) {
                        var item = document.createElement( 'a' );
                        item.className = 'jt-instant-result-item';
                        var cBase = ( typeof jetonomyUpload !== 'undefined' && jetonomyUpload.communityBase ) ? jetonomyUpload.communityBase : '/community';
                        item.href = post.space_slug
                            ? cBase + '/s/' + post.space_slug + '/t/' + post.slug + '/'
                            : '#';
                        var strong = document.createElement( 'strong' );
                        strong.textContent = post.title || '';
                        var span = document.createElement( 'span' );
                        span.textContent = post.space_title || '';
                        item.appendChild( strong );
                        item.appendChild( span );
                        dropdown.appendChild( item );
                    } );
                    dropdown.style.display = 'block';
                } )
                .catch( function() { dropdown.style.display = 'none'; } );
            }, 250 );
        } );

        // Close on click outside
        document.addEventListener( 'click', function( e ) {
            if ( formEl && ! formEl.contains( e.target ) ) {
                dropdown.style.display = 'none';
            }
        } );

        // Close on Escape
        input.addEventListener( 'keydown', function( e ) {
            if ( e.key === 'Escape' ) dropdown.style.display = 'none';
        } );
    } );

    // ── G5: Quote Selected Text ──

    var quoteBtn = document.createElement('button');
    quoteBtn.className = 'jt-quote-btn';
    quoteBtn.textContent = 'Quote';
    quoteBtn.style.display = 'none';
    document.body.appendChild(quoteBtn);

    document.addEventListener('mouseup', function(e) {
        var selection = window.getSelection();
        var text = selection.toString().trim();

        if (text.length < 5) { quoteBtn.style.display = 'none'; return; }

        // Check if selection is inside a reply or post body
        var range = selection.getRangeAt(0);
        var container = range.commonAncestorContainer;
        var replyBody = container.closest ? container.closest('.jt-reply-body, .jt-post-body') : container.parentElement && container.parentElement.closest('.jt-reply-body, .jt-post-body');

        if (!replyBody) { quoteBtn.style.display = 'none'; return; }

        // Get author name
        var replyCard = replyBody.closest('.jt-reply, .jt-post');
        var authorEl = replyCard ? replyCard.querySelector('.jt-user-name') : null;
        var authorName = authorEl ? authorEl.textContent.trim() : '';

        // Position the button near the selection
        var rect = range.getBoundingClientRect();
        quoteBtn.style.display = 'block';
        quoteBtn.style.top = (rect.top + window.scrollY - 40) + 'px';
        quoteBtn.style.left = (rect.left + rect.width / 2 - 30) + 'px';

        quoteBtn.onclick = function() {
            var composer = document.querySelector('.jt-editor');
            if (!composer) return;

            var editor = composer.querySelector('.jt-editor-body');
            if (!editor) return;

            var quote = '<blockquote class="jt-quote"><cite>' + authorName + '</cite>' + text + '</blockquote><p></p>';
            editor.innerHTML += quote;
            editor.focus();
            composer.scrollIntoView({ behavior: 'smooth', block: 'center' });
            quoteBtn.style.display = 'none';
        };
    });

    document.addEventListener('mousedown', function(e) {
        if (!e.target.closest('.jt-quote-btn')) {
            quoteBtn.style.display = 'none';
        }
    });

    // ── G8: Keyboard Shortcuts ──

    var currentIndex = -1;

    document.addEventListener('keydown', function(e) {
        // Don't trigger when typing in inputs
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;

        // ? = show help
        if (e.key === '?') {
            toggleShortcutHelp();
            return;
        }
        // / = focus search
        if (e.key === '/') {
            e.preventDefault();
            var search = document.querySelector('.jt-search-page-input input, .jt-community-nav input');
            if (search) search.focus();
            return;
        }
        // n = new post (if on space page)
        if (e.key === 'n') {
            var newBtn = document.querySelector('a[href*="/new/"]');
            if (newBtn) { e.preventDefault(); window.location = newBtn.href; }
            return;
        }
        // j/k = navigate items
        if (e.key === 'j' || e.key === 'k') {
            navigateItems(e.key === 'j' ? 1 : -1);
            return;
        }
        // Enter on focused item = open it
        if (e.key === 'Enter' && document.querySelector('.jt-row.jt-kb-focus')) {
            var focused = document.querySelector('.jt-row.jt-kb-focus');
            if (focused.href) window.location = focused.href;
            return;
        }
    });

    function navigateItems(direction) {
        var items = document.querySelectorAll('.jt-row, .jt-leader');
        if (!items.length) return;
        items.forEach(function(i) { i.classList.remove('jt-kb-focus'); });
        currentIndex = Math.max(0, Math.min(items.length - 1, currentIndex + direction));
        items[currentIndex].classList.add('jt-kb-focus');
        items[currentIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function toggleShortcutHelp() {
        var existing = document.querySelector('.jt-shortcut-help');
        if (existing) { existing.remove(); return; }

        var modal = document.createElement('div');
        modal.className = 'jt-shortcut-help';
        modal.innerHTML = '<div class="jt-shortcut-modal"><h3>Keyboard Shortcuts</h3><table>' +
            '<tr><td><kbd>j</kbd> / <kbd>k</kbd></td><td>Navigate items up/down</td></tr>' +
            '<tr><td><kbd>Enter</kbd></td><td>Open selected item</td></tr>' +
            '<tr><td><kbd>/</kbd></td><td>Focus search</td></tr>' +
            '<tr><td><kbd>n</kbd></td><td>New post</td></tr>' +
            '<tr><td><kbd>?</kbd></td><td>Show/hide shortcuts</td></tr>' +
            '</table><button onclick="this.closest(\'.jt-shortcut-help\').remove()">Close</button></div>';
        document.body.appendChild(modal);
    }

    // ── G9: Emoji Picker (delegated — works for composers injected after DOMContentLoaded) ──
    //
    // Binding directly to each .jt-editor-bar at DOMContentLoaded misses toolbars that are
    // injected later via modal/AJAX. Delegated listeners on document cover both cases.

    var emojis = ['\uD83D\uDE00','\uD83D\uDE02','\u2764\uFE0F','\uD83D\uDC4D','\uD83D\uDC4E','\uD83C\uDF89','\uD83E\uDD14','\uD83D\uDC40','\uD83D\uDE80','\uD83D\uDD25','\u2705','\u274C','\uD83D\uDCA1','\uD83D\uDCDD','\uD83D\uDE4F','\uD83D\uDCAA','\uD83D\uDE0D','\uD83D\uDE0E','\uD83E\uDD2F','\uD83E\uDD73'];

    function positionEmojiPicker(trigger) {
        if (!trigger) {
            return;
        }

        var rect = trigger.getBoundingClientRect();
        var gutter = 8;
        var viewportWidth = window.innerWidth;
        var viewportHeight = window.innerHeight;
        var pickerWidth = sharedPicker.offsetWidth;
        var pickerHeight = sharedPicker.offsetHeight;
        var left = rect.left;
        var top = rect.bottom + gutter;

        if (left + pickerWidth > viewportWidth - gutter) {
            left = viewportWidth - pickerWidth - gutter;
        }

        if (left < gutter) {
            left = gutter;
        }

        if (top + pickerHeight > viewportHeight - gutter) {
            top = rect.top - pickerHeight - gutter;
        }

        if (top < gutter) {
            top = gutter;
        }

        sharedPicker.style.top = top + 'px';
        sharedPicker.style.left = left + 'px';
        sharedPicker.style.right = 'auto';
        sharedPicker.style.bottom = 'auto';
    }

    // Shared singleton picker — repositioned beside the active emoji button.
    var sharedPicker = document.createElement('div');
    sharedPicker.className = 'jt-emoji-picker';
    sharedPicker.style.display = 'none';
    emojis.forEach(function(emoji) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'jt-emoji-option';
        btn.textContent = emoji;
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var toolbar = sharedPicker._activeToolbar;
            if (toolbar) {
                var editor = toolbar.closest('.jt-editor');
                if (editor) {
                    var body = editor.querySelector('.jt-editor-body');
                    if (body) {
                        body.focus();
                        document.execCommand('insertText', false, emoji);
                    }
                }
            }
            sharedPicker.style.display = 'none';
        });
        sharedPicker.appendChild(btn);
    });
    document.body.appendChild(sharedPicker);

    // Delegated click handler — catches emoji buttons in any toolbar, present or future.
    document.addEventListener('click', function(e) {
        var emojiBtn = e.target.closest('[data-cmd="emoji"]');
        if (emojiBtn) {
            e.preventDefault();
            e.stopPropagation();
            var toolbar = emojiBtn.closest('.jt-editor-bar');
            if (!toolbar) return;

            var isOpen = sharedPicker.style.display !== 'none' && sharedPicker._activeToolbar === toolbar;
            // Close any open picker first.
            sharedPicker.style.display = 'none';

            if (!isOpen) {
                sharedPicker._activeToolbar = toolbar;
                if ( sharedPicker.parentElement !== document.body ) {
                    document.body.appendChild( sharedPicker );
                }
                sharedPicker.style.display = 'grid';
                positionEmojiPicker(emojiBtn);
            }
            return;
        }

        // Close picker on any outside click.
        if (!e.target.closest('.jt-emoji-picker')) {
            sharedPicker.style.display = 'none';
        }
    });
} );

// ── Space Access Gate — Join and Request-to-Join handlers ──
// These run outside DOMContentLoaded so they apply on all page loads including
// the private/hidden space gate screen.

(function() {
    var apiBase = (window.jetonomyUpload && window.jetonomyUpload.apiBase)
        ? window.jetonomyUpload.apiBase
        : '/wp-json/jetonomy/v1';

    function showGateMessage(form, msg, isError) {
        var el = form.querySelector('.jt-gate-msg');
        if (!el) {
            el = document.createElement('p');
            el.className = 'jt-gate-msg';
            form.appendChild(el);
        }
        el.textContent = msg;
        el.classList.toggle('jt-gate-msg--error', isError);
        el.classList.toggle('jt-gate-msg--success', !isError);
    }

    // Direct join button (open policy, private space).
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.jt-join-btn');
        if (!btn) return;
        e.preventDefault();

        var spaceId = btn.dataset.spaceId;
        var nonce   = btn.dataset.nonce;
        if (!spaceId) return;

        btn.disabled = true;
        btn.textContent = 'Joining\u2026';

        fetch(apiBase + '/spaces/' + spaceId + '/members', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (res.ok && res.data.status === 'joined') {
                window.location.reload();
            } else {
                btn.disabled = false;
                btn.textContent = 'Join Space';
                (window.bnToast ? window.bnToast(res.data.message || 'Could not join space.', 'error') : null);
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Join Space';
            (window.bnToast ? window.bnToast('Network error. Please try again.', 'error') : null);
        });
    });

    // Request-to-join button (public + approval header button).
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.jt-join-request-btn');
        if (!btn) return;
        e.preventDefault();

        var spaceId = btn.dataset.spaceId;
        var nonce   = btn.dataset.nonce;
        if (!spaceId) return;

        btn.disabled = true;
        btn.textContent = 'Requesting\u2026';

        fetch(apiBase + '/spaces/' + spaceId + '/members', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (res.data.status === 'pending') {
                btn.disabled = true;
                btn.textContent = 'Awaiting Approval';
                btn.classList.remove('jt-btn-fill');
                btn.classList.add('jt-btn-outline');
                (window.bnToast ? window.bnToast(res.data.message || 'Request submitted. Awaiting approval.', 'success') : null);
            } else if (res.ok && res.data.status === 'joined') {
                window.location.reload();
            } else {
                btn.disabled = false;
                btn.textContent = 'Request to Join';
                (window.bnToast ? window.bnToast(res.data.message || 'Could not submit request.', 'error') : null);
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Request to Join';
            (window.bnToast ? window.bnToast('Network error. Please try again.', 'error') : null);
        });
    });

    // Request-to-join form (approval policy — gate block form).
    document.addEventListener('submit', function(e) {
        var form = e.target.closest('.jt-join-request-form');
        if (!form) return;
        e.preventDefault();

        var spaceId = form.dataset.spaceId;
        var nonce   = form.dataset.nonce;
        var message = (form.querySelector('[name="message"]') || {}).value || '';
        if (!spaceId) return;

        var submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting\u2026'; }

        fetch(apiBase + '/spaces/' + spaceId + '/members', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            credentials: 'same-origin',
            body: JSON.stringify({ message: message }),
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (res.data.status === 'pending') {
                showGateMessage(form, res.data.message || 'Request submitted. Awaiting approval.', false);
                if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Request Sent'; }
            } else if (res.ok && res.data.status === 'joined') {
                window.location.reload();
            } else {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Request to Join'; }
                showGateMessage(form, res.data.message || 'Could not submit request.', true);
            }
        })
        .catch(function() {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Request to Join'; }
            showGateMessage(form, 'Network error. Please try again.', true);
        });
    });
}());
