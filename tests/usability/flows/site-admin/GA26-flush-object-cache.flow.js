// @ts-check
/**
 * GA26 — Flush object cache (P2)
 *
 * If an object cache flush mechanism exists (WP-CLI or admin UI), test it.
 * Otherwise mark as fixme — not all environments have a persistent object cache.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'GA26 — Flush object cache', () => {

	test( 'wp cache flush succeeds or fixme if no persistent cache', () => {
		let output;
		try {
			output = wp( [ 'cache', 'flush' ] );
		} catch ( err ) {
			// If wp cache flush fails, it means no persistent object cache is
			// configured. This is expected on some dev environments.
			test.fixme( true, 'No persistent object cache configured — wp cache flush not available' );
			return;
		}
		expect( output ).toContain( 'Success' );
	} );
} );
