/**
 * Jetonomy modal toolkit — the ONLY way to ask the customer a yes/no
 * question, prompt for a string, or surface a notification dialog.
 *
 * Replaces every `window.confirm` / `window.alert` / `window.prompt` call
 * across the plugin. Legacy browser dialogs are banned — they look like
 * a system dialog, not a Jetonomy feature, can't be styled or themed,
 * and ship a developer-grade UX.
 *
 * Markup uses the existing `.jt-modal-*` + `.jt-btn jt-btn-ghost`/`-fill`
 * classes (see assets/css/jetonomy.css) so wp-admin and front-end share
 * the same visual language.
 *
 * API (all return Promises):
 *
 *   window.jetonomyConfirm( message [, opts] )
 *     opts.title?, opts.confirmLabel?, opts.cancelLabel?, opts.danger?
 *     resolves true on confirm, false on cancel/ESC/backdrop
 *
 *   window.jetonomyAlert( message [, opts] )
 *     opts.title?, opts.confirmLabel? (default "OK")
 *     resolves true once dismissed
 *
 *   window.jetonomyPrompt( message [, opts] )
 *     opts.title?, opts.placeholder?, opts.defaultValue?, opts.multiline?
 *     opts.confirmLabel? (default "Submit"), opts.cancelLabel? (default "Cancel")
 *     resolves the input string on submit, null on cancel
 *
 * Vanilla JS, no dependencies. Enqueue this script BEFORE any caller.
 */
( function () {
	'use strict';

	if ( window.jetonomyConfirm && window.jetonomyAlert && window.jetonomyPrompt ) {
		// Already registered (defensive against double-enqueue).
		return;
	}

	function buildDialog( opts ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'jt-modal-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );

		var box = document.createElement( 'div' );
		box.className = 'jt-modal-box';

		if ( opts.title ) {
			var heading = document.createElement( 'p' );
			heading.className = 'jt-modal-msg';
			heading.style.fontWeight = '600';
			heading.textContent = opts.title;
			box.appendChild( heading );
		}

		if ( opts.message ) {
			var msg = document.createElement( 'p' );
			msg.className = 'jt-modal-msg';
			msg.textContent = opts.message;
			box.appendChild( msg );
		}

		var input = null;
		if ( opts.kind === 'prompt' ) {
			input = opts.multiline
				? document.createElement( 'textarea' )
				: document.createElement( 'input' );
			input.className = 'jt-modal-input jt-input';
			if ( ! opts.multiline ) {
				input.type = 'text';
			} else {
				input.rows = 3;
			}
			if ( opts.placeholder ) {
				input.placeholder = opts.placeholder;
			}
			if ( opts.defaultValue ) {
				input.value = opts.defaultValue;
			}
			box.appendChild( input );
		}

		var actions = document.createElement( 'div' );
		actions.className = 'jt-modal-actions';

		var cancelBtn = null;
		if ( opts.kind !== 'alert' ) {
			cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'jt-btn jt-btn-ghost';
			cancelBtn.textContent = opts.cancelLabel || 'Cancel';
			actions.appendChild( cancelBtn );
		}

		var okBtn = document.createElement( 'button' );
		okBtn.type = 'button';
		okBtn.className = 'jt-btn ' + ( opts.danger ? 'jt-btn-danger' : 'jt-btn-fill' );
		okBtn.textContent = opts.confirmLabel
			|| ( opts.kind === 'alert' ? 'OK'
			   : opts.kind === 'prompt' ? 'Submit'
			   : 'Confirm' );
		actions.appendChild( okBtn );
		box.appendChild( actions );

		overlay.appendChild( box );

		return { overlay: overlay, okBtn: okBtn, cancelBtn: cancelBtn, input: input };
	}

	function open( opts ) {
		return new Promise( function ( resolve ) {
			var dom = buildDialog( opts );
			var lastFocused = document.activeElement;

			function close( result ) {
				document.removeEventListener( 'keydown', onKey );
				dom.overlay.remove();
				if ( lastFocused && lastFocused.focus ) {
					lastFocused.focus();
				}
				resolve( result );
			}

			function onKey( e ) {
				if ( e.key === 'Escape' ) {
					e.preventDefault();
					if ( opts.kind === 'alert' ) {
						close( true );
					} else if ( opts.kind === 'prompt' ) {
						close( null );
					} else {
						close( false );
					}
				}
				if ( e.key === 'Enter' && opts.kind === 'alert' ) {
					e.preventDefault();
					close( true );
				}
				// Plain confirm: Enter on focused okBtn submits via the button's
				// own click handler, no extra wiring needed.
				// Prompt: Enter inside a single-line input submits.
				if ( e.key === 'Enter' && opts.kind === 'prompt' && ! opts.multiline && document.activeElement === dom.input ) {
					e.preventDefault();
					close( dom.input.value );
				}
			}

			dom.okBtn.addEventListener( 'click', function () {
				if ( opts.kind === 'prompt' ) {
					close( dom.input ? dom.input.value : '' );
				} else {
					close( true );
				}
			} );
			if ( dom.cancelBtn ) {
				dom.cancelBtn.addEventListener( 'click', function () {
					close( opts.kind === 'prompt' ? null : false );
				} );
			}
			dom.overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === dom.overlay ) {
					close( opts.kind === 'prompt' ? null : false );
				}
			} );
			document.addEventListener( 'keydown', onKey );

			document.body.appendChild( dom.overlay );

			// Focus management: prompt → input first; otherwise → primary button.
			if ( dom.input ) {
				dom.input.focus();
				if ( opts.defaultValue ) {
					dom.input.select();
				}
			} else {
				dom.okBtn.focus();
			}
		} );
	}

	window.jetonomyConfirm = function ( message, opts ) {
		opts = opts || {};
		return open( {
			kind:         'confirm',
			message:      message,
			title:        opts.title,
			confirmLabel: opts.confirmLabel,
			cancelLabel:  opts.cancelLabel,
			danger:       !! opts.danger,
		} );
	};

	window.jetonomyAlert = function ( message, opts ) {
		opts = opts || {};
		return open( {
			kind:         'alert',
			message:      message,
			title:        opts.title,
			confirmLabel: opts.confirmLabel,
		} );
	};

	window.jetonomyPrompt = function ( message, opts ) {
		opts = opts || {};
		return open( {
			kind:         'prompt',
			message:      message,
			title:        opts.title,
			placeholder:  opts.placeholder,
			defaultValue: opts.defaultValue,
			multiline:    !! opts.multiline,
			confirmLabel: opts.confirmLabel,
			cancelLabel:  opts.cancelLabel,
		} );
	};
} )();
