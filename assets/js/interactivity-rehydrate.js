/**
 * Jetonomy — re-hydrate WP Interactivity API directives on appended nodes.
 *
 * Why this exists: WordPress's IA runtime (wp-includes/.../interactivity/index.js)
 * scans `[data-wp-interactive]` regions exactly once, on DOMContentLoaded.
 * Regions inserted into the DOM later carry the directive attributes but get
 * no event listeners attached, so every `data-wp-on--click` button is inert.
 * Core's `@wordpress/interactivity-router` solves the same problem by reaching
 * into the IA via its private-API consent lock; we follow the identical path.
 *
 * Generic intra-plugin helper — NOT pagination-specific. Every Jetonomy
 * surface that injects `[data-wp-interactive]` markup after DOMContentLoaded
 * should call `window.jetonomyHydrateInteractive( regions )` once the markup
 * is in the DOM. Today the caller is pagination-frontend.js (Load More);
 * future callers will include modal-loaded reply cards, tab switches that
 * fetch partials, infinite-scroll surfaces, the notifications-panel refresh,
 * and any other "fetch HTML and slot it in" path. Reusing this primitive
 * means each new caller avoids re-implementing the consent-lock dance.
 *
 * The consent string is the documented opt-in: if WordPress reshapes the
 * Interactivity API in a future release this helper needs updating in
 * lockstep. We catch and warn rather than crash so a shape change leaves
 * buttons inert (the original bug surface) instead of breaking the page.
 */
import { privateApis } from '@wordpress/interactivity';

const consent =
	'I acknowledge that using private APIs means my theme or plugin will inevitably break in the next version of WordPress.';

let apis = null;
try {
	apis = privateApis( consent );
} catch ( err ) {
	if ( window.console && console.warn ) {
		console.warn( '[jetonomy] private interactivity API unavailable', err );
	}
}

function toNodeList( input ) {
	if ( ! input ) {
		return [];
	}
	if ( input.nodeType === 1 ) {
		return [ input ];
	}
	if ( typeof input.length === 'number' ) {
		return Array.from( input );
	}
	return [];
}

function hydrateInteractive( regions ) {
	if ( ! apis ) {
		return;
	}
	toNodeList( regions ).forEach( ( node ) => {
		if (
			! node ||
			node.nodeType !== 1 ||
			! node.hasAttribute( 'data-wp-interactive' )
		) {
			return;
		}
		try {
			const fragment = apis.getRegionRootFragment( node );
			const vdom = apis.toVdom( node );
			apis.initialVdom.set( node, vdom );
			apis.render( vdom, fragment );
		} catch ( err ) {
			if ( window.console && console.warn ) {
				console.warn( '[jetonomy] hydrateInteractive failed', err );
			}
		}
	} );
}

window.jetonomyHydrateInteractive = hydrateInteractive;
