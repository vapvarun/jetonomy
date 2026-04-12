// @ts-check
/**
 * X10 — Nav menu integration (P2)
 *
 * Fixme — nav menu integration test requires a specific theme + menu
 * configuration. Mark as fixme until the nav menu adapter is built.
 */

const { test } = require( '@playwright/test' );

test.describe( 'X10 — Nav menu integration', () => {

	test.fixme( true, 'Nav menu integration requires theme + menu config — not yet testable' );

	test( 'community link appears in nav menu', async ( { page } ) => {
		// When implemented:
		// 1. Create a nav menu via WP-CLI with a "Community" link
		// 2. Assign it to the primary location
		// 3. Visit the front page
		// 4. Assert the nav contains a link to /community/
	} );
} );
