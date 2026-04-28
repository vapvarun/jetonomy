/**
 * Jetonomy Login block — tab switching + login/register submit.
 *
 * Vanilla JS, no dependencies. Enqueued only when the Login block renders.
 *
 * Login    → POST /jetonomy/v1/auth/login    (since 1.4.0 A.2 commit 2)
 * Register → POST /jetonomy/v1/auth/register (since 1.4.0 A.3 commit 2)
 *
 * Both submits flow through getCaptchaToken() which resolves to '' when no
 * provider is active, or a real reCAPTCHA v3 / Turnstile token when one is.
 * The server's `Captcha_Manager::verify_or_skip` returns null on no-adapter,
 * so an empty token still passes through.
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
	 * Resolve a CAPTCHA token if a provider is active. Returns '' when no
	 * CAPTCHA is configured (server-side `Captcha_Manager::verify_or_skip`
	 * treats empty token + null adapter as a skip; nothing breaks).
	 *
	 * Mirrors the pattern in assets/js/view.js for posts/replies so all
	 * three submit surfaces use the same CAPTCHA wiring.
	 *
	 * @return {Promise<string>}
	 */
	function getCaptchaToken() {
		var captcha = window.jetonomyCaptcha;
		if ( ! captcha || ! captcha.provider ) {
			return Promise.resolve( '' );
		}
		if ( captcha.provider === 'recaptcha_v3' && window.grecaptcha ) {
			return new Promise( function ( resolve ) {
				window.grecaptcha.ready( function () {
					window.grecaptcha
						.execute( captcha.siteKey, { action: 'register' } )
						.then( resolve, function () { resolve( '' ); } );
				} );
			} );
		}
		if ( captcha.provider === 'turnstile' ) {
			var ts = document.querySelector( '[name="cf-turnstile-response"]' );
			return Promise.resolve( ts ? ts.value : '' );
		}
		return Promise.resolve( '' );
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
	 * REST register submit (1.4.0 A.3 commit 2).
	 *
	 * Body: { username, email, password, captcha_token? }
	 * Success: { success: true, message: '…' } (200) — server auto-signs the
	 * new user in via wp_set_auth_cookie, JS just reloads.
	 * Errors: 403 jetonomy_registration_disabled / 400 missing_fields /
	 *         400 username_unavailable / 400 email_unavailable /
	 *         400 password_too_short / 400 captcha_failed / 429 rate_limited.
	 *
	 * Async because the CAPTCHA token lookup may return a Promise
	 * (reCAPTCHA v3 / Turnstile invisible widgets).
	 */
	function submitRegisterREST( block, form ) {
		var unlock = lockSubmit( form );
		setMessage( form, '', false );

		var restUrl = block.dataset.restUrl
			? block.dataset.restUrl.replace( /\/+$/, '' )
			: '/wp-json/jetonomy/v1';
		var nonce = block.dataset.restNonce || '';

		getCaptchaToken().then( function ( captchaToken ) {
			var body = {
				username: ( form.querySelector( '[name="username"]' ) || {} ).value || '',
				email:    ( form.querySelector( '[name="email"]' )    || {} ).value || '',
				password: ( form.querySelector( '[name="password"]' ) || {} ).value || '',
			};
			if ( captchaToken ) {
				body.captcha_token = captchaToken;
			}

			return fetch( restUrl + '/auth/register', {
				method: 'POST',
				credentials: 'same-origin',
				headers: nonce
					? { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }
					: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body ),
			} );
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				return { ok: res.ok, json: json };
			} );
		} ).then( function ( payload ) {
			if ( ! payload.ok || ! payload.json || payload.json.success !== true ) {
				var msg = ( payload.json && payload.json.message )
					|| 'Something went wrong. Please try again.';
				setMessage( form, msg, false );
				if ( unlock ) { unlock(); }
				return;
			}
			setMessage( form, payload.json.message || 'Account created.', true );
			window.location.reload();
		} ).catch( function () {
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
				submitRegisterREST( block, registerForm );
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
