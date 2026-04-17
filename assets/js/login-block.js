/**
 * Jetonomy Login block — tab switching + AJAX submit.
 *
 * Intentionally vanilla JS, no dependencies. Enqueued only when the
 * login block is present on the page (see Blocks::enqueue_login_block).
 */
( function () {
	'use strict';

	function activateTab( block, name ) {
		block.querySelectorAll( '.jt-login-tab' ).forEach( function ( tab ) {
			var active = tab.dataset.jtTab === name;
			tab.classList.toggle( 'is-active', active );
			tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
		block.querySelectorAll( '.jt-login-form' ).forEach( function ( form ) {
			form.classList.toggle( 'is-active', form.dataset.jtPanel === name );
		} );
		var messageEls = block.querySelectorAll( '.jt-login-message' );
		messageEls.forEach( function ( el ) {
			el.textContent = '';
			el.classList.remove( 'is-success' );
		} );
	}

	function setMessage( form, text, isSuccess ) {
		var el = form.querySelector( '.jt-login-message' );
		if ( ! el ) return;
		el.textContent = text || '';
		el.classList.toggle( 'is-success', !! isSuccess );
	}

	function submitForm( block, form, action, nonce ) {
		var submitBtn = form.querySelector( '.jt-login-submit' );
		var originalLabel = submitBtn ? submitBtn.textContent : '';
		if ( submitBtn ) {
			submitBtn.disabled = true;
			submitBtn.textContent = '…';
		}
		setMessage( form, '', false );

		var body = new FormData( form );
		body.append( 'action', action );
		body.append( 'nonce', nonce );

		fetch( block.dataset.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( res ) {
				return res.json().then( function ( json ) {
					return { ok: res.ok, json: json };
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload.ok || ! payload.json || ! payload.json.success ) {
					var msg = ( payload.json && payload.json.data && payload.json.data.message )
						|| 'Something went wrong. Please try again.';
					setMessage( form, msg, false );
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.textContent = originalLabel;
					}
					return;
				}
				setMessage( form, payload.json.data && payload.json.data.message || 'Success', true );
				window.location.reload();
			} )
			.catch( function () {
				setMessage( form, 'Network error. Please try again.', false );
				if ( submitBtn ) {
					submitBtn.disabled = false;
					submitBtn.textContent = originalLabel;
				}
			} );
	}

	function init( block ) {
		block.querySelectorAll( '.jt-login-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activateTab( block, tab.dataset.jtTab );
			} );
		} );

		var loginForm = block.querySelector( '.jt-login-form[data-jt-panel="login"]' );
		if ( loginForm ) {
			loginForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				submitForm( block, loginForm, 'jetonomy_quick_login', block.dataset.loginNonce );
			} );
		}

		var registerForm = block.querySelector( '.jt-login-form[data-jt-panel="register"]' );
		if ( registerForm ) {
			registerForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				submitForm( block, registerForm, 'jetonomy_quick_register', block.dataset.registerNonce );
			} );
		}
	}

	function boot() {
		document.querySelectorAll( '.jt-login-block' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
