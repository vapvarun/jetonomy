/**
 * Jetonomy Reply Composer
 * Simple contenteditable enhancement with toolbar actions
 */
document.addEventListener( 'DOMContentLoaded', () => {
    const composers = document.querySelectorAll( '.jt-editor' );

    composers.forEach( ( composer ) => {
        const body = composer.querySelector( '.jt-editor-body' );
        const toolbar = composer.querySelector( '.jt-editor-bar' );

        if ( ! toolbar || ! body ) return;

        // Toolbar button actions
        toolbar.addEventListener( 'click', ( e ) => {
            const btn = e.target.closest( 'button' );
            if ( ! btn ) return;

            const cmd = btn.dataset.cmd;
            if ( ! cmd ) return;

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
                    document.execCommand( 'insertHTML', false, '<code>' + window.getSelection().toString() + '</code>' );
                    break;
                case 'link':
                    const url = prompt( 'Enter URL:' );
                    if ( url ) document.execCommand( 'createLink', false, url );
                    break;
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
} );
