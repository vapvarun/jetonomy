/**
 * Jetonomy — admin confirmation delegate.
 *
 * Replaces inline `onclick="return confirm(...)"` attributes on links and
 * buttons with a CSP-friendly event listener that uses the shared modal
 * toolkit (assets/js/jetonomy-modals.js).
 *
 * Markup contract:
 *
 *   <a href="..."             data-jt-confirm="Delete this poll?">Delete</a>
 *   <button type="submit"     data-jt-confirm="Delete this webhook?"
 *           data-jt-confirm-tone="danger">Delete</button>
 *
 *   <a href="..."             data-jt-confirm="Re-Import?"
 *           data-jt-confirm-handler="dispatch-click">Re-Import</a>
 *
 * Tone defaults to "danger" so destructive actions get the red styling.
 * Pass data-jt-confirm-tone="info" for non-destructive prompts.
 *
 * For anchors and form-submit buttons the listener follows / submits when
 * the user confirms; for buttons that need to keep their existing click
 * handlers running (the import re-import case), set
 * data-jt-confirm-handler="dispatch-click" and the listener re-fires the
 * click event after the confirm resolves so delegated handlers run.
 *
 * If window.jetonomyConfirm is somehow absent (modal toolkit failed to
 * enqueue) the listener resolves to "cancel" so destructive actions
 * never run silently.
 */
( function () {
	'use strict';

	function tone( el ) {
		var attr = el.getAttribute( 'data-jt-confirm-tone' );
		if ( 'info' === attr || 'warning' === attr ) {
			return attr;
		}
		return 'danger';
	}

	function asConfirm( message, opts ) {
		if ( typeof window.jetonomyConfirm === 'function' ) {
			return window.jetonomyConfirm( message, opts || {} );
		}
		return Promise.resolve( false );
	}

	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '[data-jt-confirm]' );
		if ( ! trigger ) {
			return;
		}

		// Skip our own re-fired click so the listener does not loop.
		if ( trigger.dataset.jtConfirmInFlight === '1' ) {
			delete trigger.dataset.jtConfirmInFlight;
			return;
		}

		e.preventDefault();
		e.stopPropagation();

		var message = trigger.getAttribute( 'data-jt-confirm' );
		var handler = trigger.getAttribute( 'data-jt-confirm-handler' );
		var isAnchor = 'A' === trigger.tagName;
		var form     = trigger.closest( 'form' );

		asConfirm( message, { danger: 'danger' === tone( trigger ) } ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}

			if ( 'dispatch-click' === handler ) {
				// Re-fire the click so delegated listeners on the same element run.
				trigger.dataset.jtConfirmInFlight = '1';
				trigger.click();
				return;
			}

			if ( isAnchor ) {
				window.location.href = trigger.href;
				return;
			}

			if ( form && ( 'submit' === trigger.type || 'BUTTON' === trigger.tagName ) ) {
				form.submit();
				return;
			}
		} );
	}, true ); // Capture phase so we run before delegated handlers fire.
} )();
