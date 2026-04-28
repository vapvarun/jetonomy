/**
 * Jetonomy Login block — tab switching + login/register submit.
 *
 * Vanilla JS, no dependencies. Enqueued only when the Login block renders.
 *
 * Login submits to POST /jetonomy/v1/auth/login (1.4.0 A.2 commit 2).
 * Register still hits wp_ajax_nopriv_jetonomy_quick_register and migrates to
 * REST in A.3 commit 2.
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
		block.querySelectorAll( '.jt-login-message' ).forEach( function ( el ) {
			el.textContent = '';
			el.classList.remove( 'is-success' );
		} );
	}

	function setMessage( form, text, isSuccess ) {
		var el = form.querySelector( '.jt-login-message' );
		if ( ! el ) { return; }
		el.textContent = text || '';
		el.classList.toggle( 'is-success', !! isSuccess );
	}

	function lockSubmit( form ) {
		var btn = form.querySelector( '.jt-login-submit' );
		if ( ! btn ) { return null; }
		var original = btn.textContent;
		btn.disabled = true;
		btn.textContent = '…';
		return function unlock() {
			btn.disabled = false;
			btn.textContent = original;
		};
	}

	/**
	 * REST login submit (1.4.0 A.2 commit 2).
	 *
	 * Body shape: { user_login, user_password, remember? }
	 * Success response: { success: true, message: '…' } (200)
	 * Error response:   { code, message, data: { status } } (400 / 401 / 429)
	 */
	function submitLoginREST( block, form ) {
		var unlock = lockSubmit( form );
		setMessage( form, '', false );

		var restUrl = block.dataset.restUrl
			? block.dataset.restUrl.replace( /\/+$/, '' )
			: '/wp-json/jetonomy/v1';
		var nonce = block.dataset.restNonce || '';

		var body = {
			user_login:    ( form.querySelector( '[name="login"]' )    || {} ).value || '',
			user_password: ( form.querySelector( '[name="password"]' ) || {} ).value || '',
			remember:      ( form.querySelector( '[name="remember"]' ) || {} ).checked === true,
		};

		fetch( restUrl + '/auth/login', {
			method: 'POST',
			credentials: 'same-origin',
			headers: nonce
				? { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }
				: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( json ) {
					return { ok: res.ok, json: json };
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload.ok || ! payload.json || payload.json.success !== true ) {
					var msg = ( payload.json && payload.json.message )
						|| 'Something went wrong. Please try again.';
					setMessage( form, msg, false );
					if ( unlock ) { unlock(); }
					return;
				}
				setMessage( form, payload.json.message || 'Signed in.', true );
				window.location.reload();
			} )
			.catch( function () {
				setMessage( form, 'Network error. Please try again.', false );
				if ( unlock ) { unlock(); }
			} );
	}

	/**
	 * Legacy admin-ajax register submit. Stays here until v1.4.0 A.3 commit 2
	 * migrates `wp_ajax_nopriv_jetonomy_quick_register` to
	 * POST /jetonomy/v1/auth/register.
	 */
	function submitRegisterLegacy( block, form ) {
		var unlock = lockSubmit( form );
		setMessage( form, '', false );

		var fd = new FormData( form );
		fd.append( 'action', 'jetonomy_quick_register' );
		fd.append( 'nonce', block.dataset.registerNonce || '' );

		fetch( block.dataset.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
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
					if ( unlock ) { unlock(); }
					return;
				}
				setMessage( form, ( payload.json.data && payload.json.data.message ) || 'Success', true );
				window.location.reload();
			} )
			.catch( function () {
				setMessage( form, 'Network error. Please try again.', false );
				if ( unlock ) { unlock(); }
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
				submitLoginREST( block, loginForm );
			} );
		}

		var registerForm = block.querySelector( '.jt-login-form[data-jt-panel="register"]' );
		if ( registerForm ) {
			registerForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				submitRegisterLegacy( block, registerForm );
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
