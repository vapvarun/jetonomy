// @ts-check
/**
 * PRO-WHITELABEL-03 — Set community name.
 *
 * Sets a custom community name via CLI and verifies it renders
 * on the frontend header area.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-WHITELABEL-03 — Set community name', () => {

	let originalName;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'white-label' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'white-label' ] );
		}

		try {
			const current = proJourney( [ 'white-label', 'get' ] );
			originalName = current.data?.community_name || '';
		} catch ( e ) {
			originalName = '';
		}
	} );

	test.afterEach( () => {
		try {
			proJourney( [ 'white-label', 'set', `--community_name=${ originalName }` ] );
		} catch ( e ) { /* */ }
	} );

	test( 'custom community name renders on frontend', async ( { page } ) => {
		const customName = 'My Custom Community';
		proJourney( [ 'white-label', 'set', `--community_name=${ customName }` ] );

		await autoLogin( page, 1, '/community/' );

		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).toContain( customName );
	} );
} );
