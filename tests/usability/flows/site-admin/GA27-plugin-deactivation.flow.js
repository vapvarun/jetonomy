// @ts-check
/**
 * GA27 — Plugin deactivation
 *
 * Deactivate jetonomy via WP-CLI, assert no fatal errors, then reactivate.
 * Pure CLI test.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'GA27 — Plugin deactivation cycle', () => {

	test.afterEach( () => {
		// Always ensure the plugin is reactivated after the test.
		try {
			wp( [ 'plugin', 'activate', 'jetonomy' ] );
		} catch ( e ) { /* already active */ }
	} );

	test( 'deactivate jetonomy without fatal, then reactivate', () => {
		// Deactivate.
		const deactivateOutput = wp( [ 'plugin', 'deactivate', 'jetonomy' ] );
		expect( deactivateOutput ).toContain( 'Success' );

		// Verify it is inactive.
		const status = wp( [ 'plugin', 'status', 'jetonomy' ] );
		expect( status ).toMatch( /inactive/i );

		// Reactivate.
		const activateOutput = wp( [ 'plugin', 'activate', 'jetonomy' ] );
		expect( activateOutput ).toContain( 'Success' );

		// Verify it is active again.
		const statusAfter = wp( [ 'plugin', 'status', 'jetonomy' ] );
		expect( statusAfter ).toMatch( /active/i );
	} );
} );
