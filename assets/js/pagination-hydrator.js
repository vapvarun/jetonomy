/**
 * Jetonomy — pagination hydrator.
 *
 * Restores click-action behaviour on reply / post cards that the WordPress
 * Interactivity API never hydrated. This happens whenever pagination-
 * frontend.js fetches the next page of replies and appends the new SSR
 * markup with raw appendChild(): the IA runtime only walks the DOM at
 * boot and doesn't observe post-boot DOM inserts, so the data-wp-on--click
 * directives on the appended cards are inert until something attaches
 * listeners to them.
 *
 * Approach. Install a single delegated click listener on document that
 * resolves the matching `actions.<name>` on the existing IA store and
 * invokes it with a synthetic event carrying `currentTarget`. view.js's
 * affected actions already accept that fallback (see `triggerOf( event )`
 * in view.js).
 *
 * Why not call privateApis( … ).render( … ). The Interactivity API
 * `privateApis` is allowlist-locked to a handful of core script modules
 * (the router, image, etc.). Custom plugin modules get
 * `Error: Forbidden access` even with the documented consent string, so
 * re-running the IA hydration walk on appended nodes isn't an option.
 * Delegating clicks to the already-running store actions is the closest
 * stable replacement.
 *
 * Why a script-module and not a classic script. We import `store` from
 * `@wordpress/interactivity` to reach the live action proxy. The proxy
 * also runs generator-style actions correctly when invoked from outside
 * the IA runtime, so we don't need a co-style runner here.
 *
 * Generators. `voteReplyUp`, `voteReplyDown`, `flagReply`, `splitReply`
 * and `deleteReply` are declared as generator functions inside the store.
 * The store proxy normalises the call so external invocation runs the
 * generator to completion the same way the IA runtime would. No special
 * handling is required at this layer.
 *
 * Exposes `window.jetonomyHydrateInteractive( nodes )`. pagination-
 * frontend.js calls it after each Load More append with the freshly
 * inserted root nodes. The function marks those nodes with the
 * `data-jt-needs-hydration` attribute the delegated listener filters on;
 * subsequent clicks inside those nodes route to the fallback path while
 * already-hydrated cards continue to fire through the IA runtime as
 * normal — no double-dispatch.
 */

import { store } from '@wordpress/interactivity';

const HYDRATION_ATTR = 'data-jt-needs-hydration';
const PREFIX        = 'actions.';

let listenerInstalled = false;

function getJetonomyStore() {
	try {
		return store( 'jetonomy' );
	} catch ( e ) {
		return null;
	}
}

/**
 * Look up the click target's directive and dispatch it to the store action
 * when the target lives inside an appended pagination chunk.
 *
 * Only fires the fallback dispatcher for elements inside a
 * `[data-jt-needs-hydration]` ancestor, so the IA runtime owns clicks on
 * regular hydrated nodes (no double-dispatch).
 */
function onClick( event ) {
	const directive = event.target && event.target.closest
		? event.target.closest( '[data-wp-on--click]' )
		: null;
	if ( ! directive ) return;

	const fallbackRoot = directive.closest( '[' + HYDRATION_ATTR + ']' );
	if ( ! fallbackRoot ) return;

	const attr = directive.getAttribute( 'data-wp-on--click' );
	if ( ! attr || attr.indexOf( PREFIX ) !== 0 ) return;
	const actionName = attr.slice( PREFIX.length );

	const ns = getJetonomyStore();
	const action = ns && ns.actions && ns.actions[ actionName ];
	if ( typeof action !== 'function' ) return;

	// view.js's `triggerOf( event )` reads `event.currentTarget`. The native
	// click event's currentTarget is the document (we're delegating), so we
	// build a thin wrapper that points at the actual directive element. Keep
	// stopPropagation / preventDefault routed at the real event so existing
	// page-level handlers still see the choice.
	const syntheticEvent = {
		currentTarget:   directive,
		target:          event.target,
		stopPropagation: () => event.stopPropagation(),
		preventDefault:  () => event.preventDefault(),
	};

	try {
		action( syntheticEvent );
	} catch ( err ) {
		// Surface the failure once so silent dead buttons don't repeat the
		// original bug pattern; downstream actions already toast on error
		// for the network-bound paths.
		if ( window && window.console && typeof window.console.error === 'function' ) {
			window.console.error( '[jetonomy] pagination hydrator action failed:', actionName, err );
		}
	}
}

function ensureListener() {
	if ( listenerInstalled ) return;
	listenerInstalled = true;
	// Capture phase so the IA runtime doesn't get a chance to swallow the
	// click on hydrated subtrees that incidentally contain a
	// data-jt-needs-hydration ancestor.
	document.addEventListener( 'click', onClick, true );
}

/**
 * Mark the given root nodes as needing the fallback dispatcher and make
 * sure the delegated listener is wired up.
 *
 * @param {Iterable<Element>} nodes  Freshly appended SSR top-level nodes.
 */
function hydrateInteractive( nodes ) {
	ensureListener();
	if ( ! nodes ) return;
	for ( const node of nodes ) {
		if ( node && node.nodeType === 1 ) {
			node.setAttribute( HYDRATION_ATTR, '1' );
		}
	}
}

if ( typeof window !== 'undefined' ) {
	window.jetonomyHydrateInteractive = hydrateInteractive;
}
