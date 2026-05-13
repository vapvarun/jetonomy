/**
 * Jetonomy Optimistic Action Helper
 *
 * Tiny apply/revert wrapper around fetch() for optimistic UI updates.
 * Exposes window.jetonomyOptimistic = { run, gen }.
 *
 *   run( opts )  — Promise-based variant (async/await callers, non-Interactivity)
 *   gen( opts )  — generator variant for the WordPress Interactivity API store
 *                  (matches view.js's *voteUp/*voteReplyUp pattern: yield fetch + json)
 *
 * Both share the same options object:
 *   {
 *     apply,         // () => snapshot                              (sync, required)
 *     fetch,         // () => Promise<Response>                      (required)
 *     onSuccess,     // (data, response, snapshot) => void           (optional)
 *     revert,        // (snapshot, errOrResponse) => void            (required)
 *     onError,       // (errOrResponse, snapshot) => void            (optional)
 *     onFinally,     // (snapshot) => void                           (optional)
 *     toastOnError,  // boolean, default true
 *     errorFallback, // string, optional
 *   }
 *
 * Foundation for WS3-A; callsites migrate in WS3-B.
 */
( function ( window ) {
	'use strict';

	function isJson( response ) {
		var ct = response && response.headers && response.headers.get
			? response.headers.get( 'content-type' )
			: '';
		return !! ct && ct.indexOf( 'application/json' ) !== -1;
	}

	function maybeToast( opts, data, errOrResponse ) {
		if ( opts.toastOnError === false ) return;
		if ( typeof window.bnToast !== 'function' ) return;
		var msg = ( data && data.message ) || opts.errorFallback || 'Something went wrong';
		try {
			window.bnToast( msg, 'error' );
		} catch ( _e ) {
			/* toast failure is non-fatal */
		}
	}

	function callSafe( fn ) {
		if ( typeof fn !== 'function' ) return;
		var args = Array.prototype.slice.call( arguments, 1 );
		try {
			fn.apply( null, args );
		} catch ( e ) {
			/* eslint-disable-next-line no-console */
			if ( window.console && console.error ) console.error( '[jetonomyOptimistic]', e );
		}
	}

	/**
	 * Promise-based variant.
	 *
	 * @param {Object} opts See module header.
	 * @return {Promise<void>}
	 */
	async function run( opts ) {
		opts = opts || {};
		var snapshot;
		try {
			snapshot = opts.apply ? opts.apply() : null;
		} catch ( e ) {
			if ( window.console && console.error ) console.error( '[jetonomyOptimistic] apply threw', e );
			return;
		}

		try {
			var response = await opts.fetch();
			var data = null;
			if ( isJson( response ) ) {
				try {
					data = await response.json();
				} catch ( _e ) {
					data = null;
				}
			}
			if ( response.ok ) {
				callSafe( opts.onSuccess, data, response, snapshot );
			} else {
				callSafe( opts.revert, snapshot, response );
				callSafe( opts.onError, response, snapshot );
				maybeToast( opts, data, response );
			}
		} catch ( err ) {
			callSafe( opts.revert, snapshot, err );
			callSafe( opts.onError, err, snapshot );
			maybeToast( opts, null, err );
		} finally {
			callSafe( opts.onFinally, snapshot );
		}
	}

	/**
	 * Generator variant for the Interactivity API store.
	 * Yields the fetch Promise (and the response.json() Promise) so the
	 * Interactivity runtime can resume them on resolve — same contract as
	 * the hand-written `*voteUp` / `*voteReplyUp` actions in view.js.
	 *
	 * @param {Object} opts See module header.
	 * @return {Generator}
	 */
	function* gen( opts ) {
		opts = opts || {};
		var snapshot;
		try {
			snapshot = opts.apply ? opts.apply() : null;
		} catch ( e ) {
			if ( window.console && console.error ) console.error( '[jetonomyOptimistic] apply threw', e );
			return;
		}

		var response;
		var data = null;
		try {
			response = yield opts.fetch();
			if ( isJson( response ) ) {
				try {
					data = yield response.json();
				} catch ( _e ) {
					data = null;
				}
			}
			if ( response.ok ) {
				callSafe( opts.onSuccess, data, response, snapshot );
			} else {
				callSafe( opts.revert, snapshot, response );
				callSafe( opts.onError, response, snapshot );
				maybeToast( opts, data, response );
			}
		} catch ( err ) {
			callSafe( opts.revert, snapshot, err );
			callSafe( opts.onError, err, snapshot );
			maybeToast( opts, null, err );
		} finally {
			callSafe( opts.onFinally, snapshot );
		}
	}

	window.jetonomyOptimistic = { run: run, gen: gen };
} )( window );
