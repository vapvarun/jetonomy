/**
 * Connect-app approve screen store (Wbcom App Auth standard, phase J3).
 *
 * Powers templates/views/connect-app.php. One action: approve — POST
 * /jetonomy/v1/auth/app-connect over the member's cookie session + wp_rest
 * nonce, then navigate to the returned custom-scheme deep link. The template
 * keeps a visible tap-to-continue link bound to the same deep link, because
 * some in-app browsers suppress scripted custom-scheme navigation.
 *
 * Error copy comes from the SERVER response (already translated); the
 * fallback literals below only cover a dead-network case.
 */
import { store, getContext } from '@wordpress/interactivity';

function ctx() {
	try {
		return getContext();
	} catch ( _e ) {
		return {};
	}
}

store( 'jetonomy/connect-app', {
	state: {
		get busy() {
			return !! ctx().busy;
		},
		get connected() {
			return !! ctx().connected;
		},
		get deepLink() {
			return ctx().deepLink || '';
		},
		get error() {
			return ctx().error || '';
		},
	},
	actions: {
		*approve() {
			const c = ctx();
			if ( c.busy || c.connected ) {
				return;
			}
			c.busy = true;
			c.error = '';
			try {
				const response = yield fetch( c.restUrl + 'auth/app-connect', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': c.restNonce,
					},
					body: JSON.stringify( {
						scheme: c.scheme,
						bridge_token: c.bridgeToken,
						app_name: c.appName,
						app_id: c.appId,
						state: c.state,
					} ),
				} );
				const data = yield response.json();
				if ( response.ok && data && data.deep_link ) {
					c.deepLink = data.deep_link;
					c.connected = true;
					// Hand the credential to the app; the template's visible
					// "Open the app" link stays as the fallback.
					window.location.href = data.deep_link;
				} else {
					c.error = ( data && data.message ) || 'Something went wrong. Please try again.';
				}
			} catch ( _e ) {
				c.error = 'Something went wrong. Please try again.';
			}
			c.busy = false;
		},
	},
} );
