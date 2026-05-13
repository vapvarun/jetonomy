/**
 * Jetonomy — unified REST fetch client.
 *
 * Exposes window.jetonomyRest.restFetch(path, opts) for every front-end
 * mutation/read. Centralising fetch removes the four divergent variants
 * scattered across composer.js / moderation.js / view.js in 1.3.x.
 *
 * Behaviour:
 *   - Resolves base URL from window.jetonomyData.restBase, else default.
 *   - Resolves nonce from window.jetonomyData.restNonce or
 *     window.jetonomyProData.restNonce (Pro plugin can localize either).
 *   - Always sends `credentials: 'same-origin'` so the auth cookie travels.
 *   - Always sends `X-WP-Nonce` header (REST_Auth::auth_mutation requires it
 *     for cookie-auth requests).
 *   - JSON-encodes plain-object bodies and sets `Content-Type: application/json`.
 *   - Parses JSON response when content-type matches; otherwise data is null.
 *   - On `403 rest_cookie_invalid_nonce`, fetches /auth/nonce once to refresh
 *     and retries. If /auth/nonce returns 404 (endpoint not yet shipped),
 *     abandons the retry and returns the original 403 verbatim.
 *   - Never throws on HTTP errors — always resolves to `{ ok, status, data }`.
 *
 * @since 1.4.3
 */
(function () {
	'use strict';

	function resolveBase() {
		if (window.jetonomyData && window.jetonomyData.restBase) {
			return String(window.jetonomyData.restBase).replace(/\/+$/, '');
		}
		return '/wp-json/jetonomy/v1';
	}

	function resolveNonce() {
		if (window.jetonomyData && window.jetonomyData.restNonce) {
			return window.jetonomyData.restNonce;
		}
		if (window.jetonomyProData && window.jetonomyProData.restNonce) {
			return window.jetonomyProData.restNonce;
		}
		return '';
	}

	// In-memory nonce; updated when /auth/nonce refresh succeeds. Falls back
	// to the freshly-localized value on every call so a hard page reload
	// always wins over a stale in-memory copy.
	var currentNonce = null;

	function activeNonce() {
		return currentNonce || resolveNonce();
	}

	function buildUrl(path) {
		var base = resolveBase();
		if (!path) {
			return base;
		}
		// Allow callers to pass either '/posts' or 'posts'.
		if (path.charAt(0) !== '/') {
			path = '/' + path;
		}
		return base + path;
	}

	function isPlainObject(val) {
		return val && typeof val === 'object'
			&& Object.prototype.toString.call(val) === '[object Object]';
	}

	function parseBody(response) {
		var ct = response.headers && response.headers.get
			? (response.headers.get('content-type') || '')
			: '';
		if (ct.indexOf('application/json') !== -1) {
			return response.json().catch(function () { return null; });
		}
		return Promise.resolve(null);
	}

	function doFetch(url, init) {
		// Some older browsers (and jsdom in tests) reject `signal: undefined`,
		// so drop it when absent rather than passing it through.
		if (typeof init.signal === 'undefined') {
			delete init.signal;
		}
		return fetch(url, init);
	}

	function refreshNonce() {
		// Best-effort GET to /auth/nonce. Endpoint may not exist yet (planned
		// in a later WS2 wave); a 404 here is expected and non-fatal.
		var url = resolveBase() + '/auth/nonce';
		return doFetch(url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' }
		}).then(function (response) {
			if (response.status === 404) {
				return { refreshed: false, missing: true };
			}
			if (!response.ok) {
				return { refreshed: false, missing: false };
			}
			return parseBody(response).then(function (data) {
				if (data && typeof data.nonce === 'string' && data.nonce) {
					currentNonce = data.nonce;
					// Mirror onto window so other consumers see the refresh.
					if (window.jetonomyData) {
						window.jetonomyData.restNonce = data.nonce;
					}
					return { refreshed: true, missing: false };
				}
				return { refreshed: false, missing: false };
			});
		}).catch(function () {
			return { refreshed: false, missing: false };
		});
	}

	function performRequest(path, opts, isRetry) {
		opts = opts || {};
		var method = (opts.method || 'GET').toUpperCase();
		var headers = {};
		var k;
		if (opts.headers) {
			for (k in opts.headers) {
				if (Object.prototype.hasOwnProperty.call(opts.headers, k)) {
					headers[k] = opts.headers[k];
				}
			}
		}

		var nonce = activeNonce();
		if (nonce && !headers['X-WP-Nonce']) {
			headers['X-WP-Nonce'] = nonce;
		}

		var body = opts.body;
		if (isPlainObject(body)) {
			if (!headers['Content-Type']) {
				headers['Content-Type'] = 'application/json';
			}
			body = JSON.stringify(body);
		}

		var init = {
			method: method,
			credentials: 'same-origin',
			headers: headers,
			signal: opts.signal
		};
		if (typeof body !== 'undefined' && method !== 'GET' && method !== 'HEAD') {
			init.body = body;
		}

		var url = buildUrl(path);

		return doFetch(url, init).then(function (response) {
			return parseBody(response).then(function (data) {
				var result = {
					ok: response.ok,
					status: response.status,
					data: data
				};

				// 403 + rest_cookie_invalid_nonce → refresh once and retry.
				if (
					!isRetry
					&& response.status === 403
					&& data
					&& data.code === 'rest_cookie_invalid_nonce'
				) {
					return refreshNonce().then(function (state) {
						if (state.refreshed) {
							return performRequest(path, opts, true);
						}
						// Endpoint missing OR refresh failed — return original.
						return result;
					});
				}

				return result;
			});
		}).catch(function (err) {
			// Network failure / abort. Surface as a uniform shape so callers
			// don't need separate try/catch blocks.
			return {
				ok: false,
				status: 0,
				data: null,
				error: err && err.message ? err.message : 'network_error'
			};
		});
	}

	function restFetch(path, opts) {
		return performRequest(path, opts || {}, false);
	}

	window.jetonomyRest = {
		restFetch: restFetch
	};
})();
