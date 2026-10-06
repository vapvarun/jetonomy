/**
 * Jetonomy Reply Composer
 * Simple contenteditable enhancement with toolbar actions.
 * UI strings are translated with wp.i18n.
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
                close.setAttribute( 'aria-label', wp.i18n.__( 'Close menu', 'jetonomy' ) );
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
                syncBlockButtons();
            }
        } );

        // Code block and Quote are toggles over one block element each. The
        // bare formatBlock call had no way back out: clicking again nested a
        // second block, and Enter only ever added lines inside it, so text
        // typed after a code block stayed in the <pre> (QA 10320778207).
        const BLOCK_TAGS = { codeblock: 'pre', quote: 'blockquote' };
        const MEDIA = 'img, video, iframe, audio, hr, table';

        // Innermost `selector` block around the caret, inside this composer.
        const caretBlock = ( selector ) => {
            const sel = window.getSelection();
            if ( ! sel || ! sel.rangeCount ) return null;
            const node = sel.getRangeAt( 0 ).startContainer;
            const el = node.nodeType === 1 ? node : node.parentElement;
            const block = el && el.closest( selector );
            return block && block !== body && body.contains( block ) ? block : null;
        };

        const syncBlockButtons = () => {
            Object.keys( BLOCK_TAGS ).forEach( ( cmd ) => {
                const btn = toolbar.querySelector( '[data-cmd="' + cmd + '"]' );
                if ( btn ) btn.setAttribute( 'aria-pressed', caretBlock( BLOCK_TAGS[ cmd ] ) ? 'true' : 'false' );
            } );
        };
        syncBlockButtons();

        // DOM edits below bypass the browser's own input event; mentions and
        // the unsaved-changes guard listen for it.
        const changed = () => {
            body.dispatchEvent( new Event( 'input', { bubbles: true } ) );
            syncBlockButtons();
        };

        // Turn a block back into ordinary text: a <p> holding its content, or
        // its own paragraphs when it already has them.
        const unwrapBlock = ( block ) => {
            const sel = window.getSelection();
            const r = sel.rangeCount ? sel.getRangeAt( 0 ) : null;
            const caret = r && r.startContainer !== block ? [ r.startContainer, r.startOffset ] : null;

            // A <pre> can hold typed newlines as text; a <p> would fold them into spaces.
            if ( block.tagName === 'PRE' ) {
                const walker = document.createTreeWalker( block, NodeFilter.SHOW_TEXT );
                const texts = [];
                while ( walker.nextNode() ) texts.push( walker.currentNode );
                texts.forEach( ( t ) => {
                    if ( t.data.indexOf( '\n' ) === -1 ) return;
                    const parts = t.data.split( '\n' );
                    const frag = document.createDocumentFragment();
                    parts.forEach( ( part, i ) => {
                        if ( i ) frag.appendChild( document.createElement( 'br' ) );
                        if ( part ) frag.appendChild( document.createTextNode( part ) );
                    } );
                    t.replaceWith( frag );
                } );
            }

            const hasParagraphs = Array.from( block.children ).some( ( c ) => /^(P|DIV|PRE|BLOCKQUOTE|UL|OL|H[1-6])$/.test( c.tagName ) );
            let target = block.parentNode;
            if ( hasParagraphs ) {
                while ( block.firstChild ) block.parentNode.insertBefore( block.firstChild, block );
            } else {
                target = document.createElement( 'p' );
                while ( block.firstChild ) target.appendChild( block.firstChild );
                if ( ! target.firstChild ) target.appendChild( document.createElement( 'br' ) );
                block.parentNode.insertBefore( target, block );
            }
            block.remove();

            if ( caret && caret[ 0 ].isConnected && body.contains( caret[ 0 ] ) ) {
                sel.collapse( caret[ 0 ], Math.min( caret[ 1 ], caret[ 0 ].nodeType === 3 ? caret[ 0 ].length : caret[ 0 ].childNodes.length ) );
            } else if ( ! hasParagraphs ) {
                sel.collapse( target, target.childNodes.length );
            }
        };

        const toggleBlock = ( tag ) => {
            const block = caretBlock( tag );
            if ( block ) {
                unwrapBlock( block );
                changed();
            } else {
                // With a bare caret, formatBlock wraps only the caret's
                // <br>-line and nests it inside the <p> (<p>a<br><pre>b</pre></p>).
                // Select the whole enclosing paragraph first so it converts as one.
                const sel = window.getSelection();
                if ( sel.rangeCount && sel.isCollapsed ) {
                    let node = sel.anchorNode;
                    while ( node && node !== body && ! ( node.nodeType === 1 && /^(P|DIV)$/.test( node.nodeName ) ) ) {
                        node = node.parentNode;
                    }
                    if ( node && node !== body ) {
                        const range = document.createRange();
                        range.selectNodeContents( node );
                        sel.removeAllRanges();
                        sel.addRange( range );
                    }
                }
                // A real <pre> block: the server keeps its whitespace
                // (jetonomy_sanitize_editor_content), unlike typed ``` fences.
                document.execCommand( 'formatBlock', false, tag );
                syncBlockButtons();
            }
        };

        // Content of a range as plain lines: <br> and paragraph starts become "\n".
        const rangeLines = ( range ) => {
            const d = document.createElement( 'div' );
            d.appendChild( range.cloneContents() );
            d.querySelectorAll( 'br' ).forEach( ( br ) => br.replaceWith( '\n' ) );
            d.querySelectorAll( 'p, div' ).forEach( ( p ) => p.prepend( '\n' ) );
            return d;
        };

        // Drop the empty last line of `el`: a trailing <br>, "\n" or empty paragraph.
        const trimLastLine = ( el ) => {
            for ( let n = el.lastChild; n; n = el.lastChild ) {
                if ( n.nodeType === 3 ) {
                    if ( n.data === '' ) { n.remove(); continue; }
                    if ( n.data.endsWith( '\n' ) ) n.data = n.data.slice( 0, -1 );
                    return;
                }
                if ( n.nodeType !== 1 ) { n.remove(); continue; }
                if ( n.nodeName === 'BR' ) { n.remove(); return; }
                if ( n.textContent === '' && ! n.querySelector( MEDIA ) ) {
                    n.remove();
                    if ( /^(P|DIV)$/.test( n.nodeName ) ) return;
                    continue;
                }
                el = n;
            }
        };

        // Enter on an empty last line of a code block or quote leaves it
        // (the usual "double Enter" exit): drop that line and continue in a
        // new paragraph after the block. Any other Enter keeps its native
        // behaviour, so code still gets its newlines.
        const exitBlockOnEmptyLine = ( e ) => {
            const sel = window.getSelection();
            if ( ! sel || ! sel.rangeCount || ! sel.isCollapsed ) return;
            const block = caretBlock( 'pre, blockquote' );
            if ( ! block ) return;
            const range = sel.getRangeAt( 0 );

            const tail = document.createRange();
            tail.selectNodeContents( block );
            tail.setStart( range.startContainer, range.startOffset );
            const after = rangeLines( tail );
            if ( after.textContent.trim() !== '' || after.querySelector( MEDIA ) ) return;

            const head = document.createRange();
            head.selectNodeContents( block );
            head.setEnd( range.startContainer, range.startOffset );
            const before = rangeLines( head );
            const emptyBlock = before.textContent.trim() === '' && ! before.querySelector( MEDIA );
            if ( ! emptyBlock && ! before.textContent.endsWith( '\n' ) ) return;

            e.preventDefault();
            const p = document.createElement( 'p' );
            p.appendChild( document.createElement( 'br' ) );
            if ( emptyBlock ) {
                block.replaceWith( p );
            } else {
                tail.deleteContents();
                trimLastLine( block );
                block.after( p );
            }
            sel.collapse( p, 0 );
            changed();
        };

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
                    // The shared modal toolkit (jetonomy-modals.js) steals focus
                    // for the dialog — capture the current range first so we
                    // can re-anchor the insert point after the dialog closes.
                    const sel = window.getSelection();
                    if ( sel.rangeCount && body.contains( sel.getRangeAt( 0 ).commonAncestorContainer ) ) {
                        savedRange = sel.getRangeAt( 0 ).cloneRange();
                    }
                    const selectedText = sel.toString();
                    // Per the no-browser-alerts rule, never fall back to
                    // window.prompt. If the modal toolkit isn't loaded
                    // (it's a hard JS dependency on the composer enqueue)
                    // the link insert silently aborts — better UX than a
                    // native dialog flashing on screen.
                    if ( typeof window.jetonomyPrompt !== 'function' ) {
                        return;
                    }

                    window.jetonomyPrompt(
                        wp.i18n.__( 'Enter URL:', 'jetonomy' ),
                        { placeholder: wp.i18n.__( 'https://example.com', 'jetonomy' ) }
                    ).then( ( raw ) => {
                        if ( ! raw ) return;
                        const trimmed = raw.trim();
                        if ( ! trimmed ) return;

                        // Accept bare domains (example.com) and force https:// when
                        // no scheme is present so the resulting <a href> is valid.
                        const url = /^(https?:|mailto:|\/)/i.test( trimmed ) ? trimmed : 'https://' + trimmed;

                        // Put the caret back where it was before the prompt opened.
                        restoreSelection();

                        const label = selectedText || trimmed;
                        const html  = '<a href="' + escapeHtml( url ) + '" rel="noopener noreferrer" target="_blank">' + escapeHtml( label ) + '</a>';
                        document.execCommand( 'insertHTML', false, html );
                    } );
                    break;
                }
                case 'quote':
                case 'codeblock':
                    toggleBlock( BLOCK_TAGS[ cmd ] );
                    break;
            }
        } );

        // Clear placeholder on focus.
        //
        // "No text" is not "empty". An image-only body has no textContent, so
        // this deleted the image the moment the member clicked in to start
        // typing - which is exactly the normal order for "here is a screenshot,
        // here is my question". The upload was also left orphaned in the media
        // library. An emoji never reproduced it because an emoji IS text, which
        // is the clue to the cause.
        body.addEventListener( 'focus', () => {
            if ( body.textContent.trim() === '' && ! body.querySelector( 'img, video, iframe, audio, hr, table, blockquote, pre' ) ) {
                body.innerHTML = '';
            }
        } );

        // Ctrl+Enter / Cmd+Enter to submit
        body.addEventListener( 'keydown', function( e ) {
            if ( ( e.ctrlKey || e.metaKey ) && e.key === 'Enter' ) {
                e.preventDefault();
                const submitBtn = composer.querySelector( '.jt-btn-fill' );
                if ( submitBtn ) submitBtn.click();
                return;
            }
            if ( e.key === 'Enter' && ! e.shiftKey && ! e.altKey && ! e.ctrlKey && ! e.metaKey && ! e.isComposing ) {
                exitBlockOnEmptyLine( e );
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
        placeholder.textContent = wp.i18n.__( 'Uploading…', 'jetonomy' );
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

        // Tag the upload with its originating space (if the composer is inside
        // one) so the Community Media admin view can filter by space.
        var spaceEl = ( editor && editor.closest ) ? editor.closest( '[data-space-id]' ) : null;
        var spaceId = spaceEl ? spaceEl.getAttribute( 'data-space-id' ) : '';

        var formData = new FormData();
        formData.append( 'file', file );
        if ( spaceId ) {
            formData.append( 'space_id', spaceId );
        }

        // restFetch handles nonce injection + the 403/invalid-nonce refresh
        // path centrally so this upload no longer has to remember the
        // X-WP-Nonce header (or fail silently when restNonce isn't around).
        var doUpload = function () {
            if ( ! window.jetonomyRest || typeof window.jetonomyRest.restFetch !== 'function' ) {
                placeholder.remove();
                if ( window.bnToast ) { window.bnToast( wp.i18n.__( 'Upload failed.', 'jetonomy' ), 'error' ); }
                return;
            }
            window.jetonomyRest.restFetch( '/media', {
                method: 'POST',
                body: formData,
            } )
            .then( function ( res ) {
                placeholder.remove();
                if ( res.ok && res.data && res.data.url ) {
                    var img = document.createElement( 'img' );
                    img.src = res.data.url;
                    img.alt = res.data.alt || file.name;
                    img.style.maxWidth = '100%';
                    img.style.height = 'auto';
                    img.style.borderRadius = '8px';
                    img.style.margin = '8px 0';
                    editor.appendChild( img );
                    editor.appendChild( document.createElement( 'br' ) );
                } else {
                    var msg = ( res.data && res.data.message ) ? res.data.message : wp.i18n.__( 'Upload failed.', 'jetonomy' );
                    if ( window.bnToast ) { window.bnToast( msg, 'error' ); }
                }
            } );
        };
        doUpload();
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
                window.jetonomyRest.restFetch( '/search?q=' + encodeURIComponent( q ) + '&type=all&limit=5' )
                .then( function( result ) {
                    var res = result.data || {};
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
    quoteBtn.textContent = wp.i18n.__( 'Quote', 'jetonomy' );
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
        // .jt-user-name is the top-level post's author element (single-post.php);
        // reply-card.php names the same role .jt-reply-author. Quoting a reply
        // matched nothing and inserted an empty <cite> until both were checked.
        var authorEl = replyCard ? replyCard.querySelector('.jt-user-name, .jt-reply-author') : null;
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

            // Build nodes, never an HTML string: the selection is plain text that
            // can read like markup (a post showing "<img onerror=...>" as text),
            // and concatenating it into innerHTML ran it in the quoter's session.
            var quote = document.createElement('blockquote');
            quote.className = 'jt-quote';
            if (authorName) {
                var cite = document.createElement('cite');
                cite.textContent = authorName;
                quote.appendChild(cite);
            }
            quote.appendChild(document.createTextNode(text));
            editor.appendChild(quote);
            editor.appendChild(document.createElement('p'));
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

    // ── Keyboard shortcuts live in header.js ──
    //
    // This file used to carry a second, competing implementation of the whole
    // set (?, /, n, j/k, Enter) plus its own `.jt-shortcut-help` modal. Two
    // handlers owned `?`, so the help surface toggled itself and QA could not
    // get a stable panel (10150869012). The duplicate was also the worse copy:
    // hardcoded English, no `l`/`r` rows, an inline onclick, and the broken
    // `focused.href` Enter handler that started the "Enter does nothing" report
    // in the first place - a .jt-row DIV has no href.
    //
    // header.js is the single owner: localized strings, the full shortcut list,
    // and Enter resolving the row's real title link.

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
    // Popup semantics (QA 10149499573): the picker is a named menu, the
    // trigger carries aria-haspopup/expanded/controls, focus moves into the
    // menu on open, arrows/Tab move between options, Escape closes and
    // returns focus to the trigger.
    var sharedPicker = document.createElement('div');
    sharedPicker.className = 'jt-emoji-picker';
    sharedPicker.id = 'jt-emoji-picker';
    sharedPicker.setAttribute('role', 'menu');
    sharedPicker.style.display = 'none';
    emojis.forEach(function(emoji) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'jt-emoji-option';
        btn.setAttribute('role', 'menuitem');
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
            closePicker();
        });
        sharedPicker.appendChild(btn);
    });
    document.body.appendChild(sharedPicker);

    var pickerTrigger = null;

    function closePicker() {
        if (sharedPicker.style.display === 'none') { return; }
        sharedPicker.style.display = 'none';
        if (pickerTrigger) {
            pickerTrigger.setAttribute('aria-expanded', 'false');
            pickerTrigger.focus();
            pickerTrigger = null;
        }
    }

    sharedPicker.addEventListener('keydown', function (e) {
        var options = Array.prototype.filter.call(sharedPicker.children, function (b) { return b.offsetParent; });
        var i = options.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); closePicker(); return; }
        var next = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { next = options[(i + 1) % options.length]; }
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { next = options[(i - 1 + options.length) % options.length]; }
        if (e.key === 'Tab') { e.preventDefault(); next = e.shiftKey ? options[(i - 1 + options.length) % options.length] : options[(i + 1) % options.length]; }
        if (next) { e.preventDefault(); next.focus(); }
    });

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
            closePicker();

            if (!isOpen) {
                sharedPicker._activeToolbar = toolbar;
                pickerTrigger = emojiBtn;
                // aria-haspopup / aria-controls are stamped in the template so
                // the contract is complete on first paint rather than only
                // after the first click (QA 10149499573, second pass). Kept
                // here as a belt-and-braces for any theme override that
                // renders its own toolbar without them.
                emojiBtn.setAttribute('aria-haspopup', 'menu');
                emojiBtn.setAttribute('aria-controls', 'jt-emoji-picker');
                emojiBtn.setAttribute('aria-expanded', 'true');
                // Localized name comes from the trigger's own title.
                sharedPicker.setAttribute('aria-label', emojiBtn.getAttribute('title') || emojiBtn.getAttribute('aria-label') || wp.i18n.__( 'Insert emoji', 'jetonomy' ));
                if ( sharedPicker.parentElement !== document.body ) {
                    document.body.appendChild( sharedPicker );
                }
                sharedPicker.style.display = 'grid';
                positionEmojiPicker(emojiBtn);
                var firstOption = sharedPicker.querySelector('.jt-emoji-option');
                if (firstOption) { firstOption.focus(); }
            }
            return;
        }

        // Close picker on any outside click.
        if (!e.target.closest('.jt-emoji-picker')) {
            if (sharedPicker.style.display !== 'none') {
                sharedPicker.style.display = 'none';
                if (pickerTrigger) { pickerTrigger.setAttribute('aria-expanded', 'false'); pickerTrigger = null; }
            }
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

        // Restore the server-rendered label on failure: it carries the owner's
        // configured space noun, which this script cannot know.
        var label = btn.textContent;
        btn.disabled = true;
        btn.textContent = wp.i18n.__( 'Joining…', 'jetonomy' );

        window.jetonomyRest.restFetch( '/spaces/' + spaceId + '/members', {
            method: 'POST',
            body: {},
        })
        .then(function(res) {
            if (res.ok && res.data && res.data.status === 'joined') {
                window.location.reload();
            } else {
                btn.disabled = false;
                btn.textContent = label;
                (window.bnToast ? window.bnToast((res.data && res.data.message) || ( window.jetonomyData && window.jetonomyData.i18n && window.jetonomyData.i18n.joinSpaceFailed ) || wp.i18n.__( 'Something went wrong. Please try again.', 'jetonomy' ), 'error') : null);
            }
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
        btn.textContent = wp.i18n.__( 'Requesting…', 'jetonomy' );

        window.jetonomyRest.restFetch( '/spaces/' + spaceId + '/members', {
            method: 'POST',
            body: {},
        })
        .then(function(res) {
            var data = res.data || {};
            if (data.status === 'pending') {
                btn.disabled = true;
                btn.textContent = wp.i18n.__( 'Awaiting Approval', 'jetonomy' );
                btn.classList.remove('jt-btn-fill');
                btn.classList.add('jt-btn-outline');
                (window.bnToast ? window.bnToast(data.message || wp.i18n.__( 'Request submitted. Awaiting approval.', 'jetonomy' ), 'success') : null);
            } else if (res.ok && data.status === 'joined') {
                window.location.reload();
            } else {
                btn.disabled = false;
                btn.textContent = wp.i18n.__( 'Request to Join', 'jetonomy' );
                (window.bnToast ? window.bnToast(data.message || wp.i18n.__( 'Could not submit request.', 'jetonomy' ), 'error') : null);
            }
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
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = wp.i18n.__( 'Submitting…', 'jetonomy' ); }

        window.jetonomyRest.restFetch( '/spaces/' + spaceId + '/members', {
            method: 'POST',
            body: { message: message },
        })
        .then(function(res) {
            var data = res.data || {};
            if (data.status === 'pending') {
                showGateMessage(form, data.message || wp.i18n.__( 'Request submitted. Awaiting approval.', 'jetonomy' ), false);
                if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = wp.i18n.__( 'Request Sent', 'jetonomy' ); }
            } else if (res.ok && data.status === 'joined') {
                window.location.reload();
            } else {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = wp.i18n.__( 'Request to Join', 'jetonomy' ); }
                showGateMessage(form, data.message || wp.i18n.__( 'Could not submit request.', 'jetonomy' ), true);
            }
        });
    });
}());

/**
 * 1.4.0 C.7 — @mention autocomplete in any contenteditable composer.
 *
 * Watches every `.jt-editor-body` for an `@` followed by a partial login
 * or display-name fragment. Debounces 250ms, fetches from
 * /jetonomy/v1/users/suggest, and renders a dropdown beside the caret.
 * Arrow keys + Enter / Tab insert `@login `; Escape dismisses.
 *
 * Permission scoping: when a parent element carries data-jt-space-id,
 * suggestions are restricted to that space's members so a member can
 * only @mention people who can see the post.
 *
 * Builds DOM nodes directly (no innerHTML) to keep XSS off the table —
 * any user-supplied display_name flows through textContent only.
 */
( function () {
    'use strict';

    var apiBase = ( window.jetonomyData && window.jetonomyData.restBase ) || '';
    if ( ! apiBase ) {
        return;
    }

    var dropdown = null;
    var activeBody = null;
    var activeRange = null;
    var matches = [];
    var selectedIndex = 0;
    var debounceTimer = null;
    var lastQuery = '';

    function closeDropdown() {
        if ( dropdown && dropdown.parentNode ) {
            dropdown.parentNode.removeChild( dropdown );
        }
        dropdown = null;
        activeBody = null;
        activeRange = null;
        matches = [];
        selectedIndex = 0;
    }

    function buildDropdown( body ) {
        closeDropdown();
        dropdown = document.createElement( 'div' );
        dropdown.className = 'jt-mention-dropdown';
        dropdown.setAttribute( 'role', 'listbox' );
        document.body.appendChild( dropdown );
        activeBody = body;
    }

    function positionDropdown( range ) {
        if ( ! dropdown ) {
            return;
        }
        var rect = range.getBoundingClientRect();
        if ( ! rect || ( rect.width === 0 && rect.height === 0 ) ) {
            rect = activeBody.getBoundingClientRect();
        }
        dropdown.style.position = 'absolute';
        dropdown.style.top      = ( window.scrollY + rect.bottom + 4 ) + 'px';
        dropdown.style.left     = ( window.scrollX + rect.left ) + 'px';
        dropdown.style.zIndex   = '9999';
    }

    function renderMatches() {
        if ( ! dropdown ) {
            return;
        }
        // Clear children safely without innerHTML.
        while ( dropdown.firstChild ) {
            dropdown.removeChild( dropdown.firstChild );
        }
        if ( ! matches.length ) {
            var empty = document.createElement( 'div' );
            empty.className = 'jt-mention-empty';
            empty.textContent = wp.i18n.__( 'No matches', 'jetonomy' );
            dropdown.appendChild( empty );
            return;
        }
        matches.forEach( function ( m, i ) {
            var row = document.createElement( 'div' );
            row.className = 'jt-mention-row' + ( i === selectedIndex ? ' is-selected' : '' );
            row.setAttribute( 'role', 'option' );
            row.dataset.index = String( i );

            var img = document.createElement( 'img' );
            img.className = 'jt-mention-avatar';
            img.alt = '';
            img.src = m.avatar_url;
            row.appendChild( img );

            var name = document.createElement( 'span' );
            name.className = 'jt-mention-name';
            name.textContent = m.display_name || '';
            row.appendChild( name );

            var login = document.createElement( 'span' );
            login.className = 'jt-mention-login';
            login.textContent = '@' + ( m.handle || m.login || '' );
            row.appendChild( login );

            row.addEventListener( 'mousedown', function ( e ) {
                e.preventDefault();
                selectedIndex = i;
                accept();
            } );
            dropdown.appendChild( row );
        } );
    }

    function findMentionTrigger( body ) {
        var sel = window.getSelection();
        if ( ! sel || sel.rangeCount === 0 ) { return null; }
        var range = sel.getRangeAt( 0 );
        if ( ! body.contains( range.startContainer ) ) { return null; }
        if ( range.startContainer.nodeType !== Node.TEXT_NODE ) { return null; }
        var text = range.startContainer.textContent.slice( 0, range.startOffset );
        var match = text.match( /(?:^|\s)@([a-zA-Z0-9_\-\.]{0,30})$/ );
        if ( ! match ) { return null; }
        return {
            range: range,
            query: match[ 1 ],
            startOffset: range.startOffset - match[ 1 ].length,
        };
    }

    function fetchMatches( query, spaceId ) {
        if ( query === lastQuery ) {
            return;
        }
        lastQuery = query;
        var path = '/users/suggest?q=' + encodeURIComponent( query );
        if ( spaceId ) {
            path += '&space_id=' + encodeURIComponent( spaceId );
        }
        window.jetonomyRest.restFetch( path, {
            headers: { 'Accept': 'application/json' }
        } ).then( function ( result ) {
            var data = result.ok ? result.data : [];
            matches = Array.isArray( data ) ? data : [];
            selectedIndex = 0;
            renderMatches();
        } );
    }

    function accept() {
        if ( ! dropdown || ! matches.length || ! activeBody || ! activeRange ) { return; }
        var pick = matches[ selectedIndex ];
        if ( ! pick ) { return; }
        var sel = window.getSelection();
        if ( ! sel || sel.rangeCount === 0 ) { return; }
        var range = sel.getRangeAt( 0 );
        if ( range.startContainer.nodeType !== Node.TEXT_NODE ) { return; }
        var node = range.startContainer;
        var caret = range.startOffset;
        var before = node.textContent.slice( 0, caret );
        var after  = node.textContent.slice( caret );
        var stripped = before.replace( /@[a-zA-Z0-9_\-\.]{0,30}$/, '' );
        // Insert the HANDLE (user_nicename), which is what the server
            // resolves. Falls back to login only for an older API response.
            var pickHandle = pick.handle || pick.login;
            node.textContent = stripped + '@' + pickHandle + ' ' + after;
        var newOffset = stripped.length + ( '@' + pickHandle + ' ' ).length;
        var newRange = document.createRange();
        newRange.setStart( node, newOffset );
        newRange.setEnd( node, newOffset );
        sel.removeAllRanges();
        sel.addRange( newRange );
        closeDropdown();
    }

    function getSpaceId( body ) {
        var ancestor = body.closest( '[data-jt-space-id]' );
        return ancestor ? parseInt( ancestor.getAttribute( 'data-jt-space-id' ), 10 ) || 0 : 0;
    }

    document.addEventListener( 'input', function ( e ) {
        var body = e.target && e.target.classList && e.target.classList.contains( 'jt-editor-body' ) ? e.target : null;
        if ( ! body ) { return; }
        var trigger = findMentionTrigger( body );
        if ( ! trigger ) { closeDropdown(); return; }
        if ( trigger.query.length < 2 ) { closeDropdown(); return; }
        if ( ! dropdown ) { buildDropdown( body ); }
        activeRange = trigger.range;
        positionDropdown( trigger.range );
        clearTimeout( debounceTimer );
        debounceTimer = setTimeout( function () {
            fetchMatches( trigger.query, getSpaceId( body ) );
        }, 250 );
    } );

    document.addEventListener( 'keydown', function ( e ) {
        if ( ! dropdown || ! matches.length ) { return; }
        if ( e.key === 'ArrowDown' ) {
            e.preventDefault();
            selectedIndex = ( selectedIndex + 1 ) % matches.length;
            renderMatches();
        } else if ( e.key === 'ArrowUp' ) {
            e.preventDefault();
            selectedIndex = ( selectedIndex - 1 + matches.length ) % matches.length;
            renderMatches();
        } else if ( e.key === 'Enter' || e.key === 'Tab' ) {
            e.preventDefault();
            accept();
        } else if ( e.key === 'Escape' ) {
            closeDropdown();
        }
    }, true );

    document.addEventListener( 'click', function ( e ) {
        if ( dropdown && ! dropdown.contains( e.target ) && ( ! activeBody || ! activeBody.contains( e.target ) ) ) {
            closeDropdown();
        }
    } );

    window.addEventListener( 'scroll', closeDropdown, true );
    window.addEventListener( 'resize', closeDropdown );
}() );

/* ── Unsaved-changes guard for the new-post composer ──
 * Warns before a full-page unload (tab close, refresh, external link) when the
 * new-post form has edits that were never submitted. Disarms on submit so the
 * post-submit redirect doesn't trip it. Re-attaches on iAPI client navigation
 * (DOMContentLoaded fires only once) per the frontend-interactivity standard.
 */
( function () {
    function attachUnsavedGuard() {
        var form = document.getElementById( 'jt-new-post-form' );
        if ( ! form || form.dataset.jtUnsavedGuard ) { return; }
        form.dataset.jtUnsavedGuard = '1';
        var dirty = false;
        form.addEventListener( 'input', function () { dirty = true; } );
        form.addEventListener( 'change', function () { dirty = true; } );
        // Submitting is intentional — don't warn on the success redirect.
        form.addEventListener( 'submit', function () { dirty = false; }, true );
        window.addEventListener( 'beforeunload', function ( e ) {
            if ( ! dirty ) { return; }
            e.preventDefault();
            e.returnValue = ''; // Triggers the browser's native "Leave site?" prompt.
        } );
    }
    document.addEventListener( 'DOMContentLoaded', attachUnsavedGuard );
    document.addEventListener( 'jetonomy:navigated', attachUnsavedGuard );
    if ( document.readyState !== 'loading' ) { attachUnsavedGuard(); }
}() );
