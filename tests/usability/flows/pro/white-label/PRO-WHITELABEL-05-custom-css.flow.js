// @ts-check
/**
 * PRO-WHITELABEL-05 — Inject custom CSS.
 *
 * Sets custom CSS via CLI and verifies it appears in a <style> block
 * on the community page.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-WHITELABEL-05 — Inject custom CSS', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'white-label' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'white-label' ] );
		}
	} );

	test.afterEach( () => {
		try {
			proJourney( [ 'white-label', 'set', '--custom_css=' ] );
		} catch ( e ) { /* */ }
	} );

	test( 'custom CSS block appears on community page', async ( { page } ) => {
		const customCss = '.jt-whitelabel-test-marker { display: block; }';
		proJourney( [ 'white-label', 'set', `--custom_css=${ customCss }` ] );

		await autoLogin( page, 1, '/community/' );

		// Check for the custom CSS in any <style> tag.
		const pageContent = await page.content();
		expect( pageContent ).toContain( 'jt-whitelabel-test-marker' );
	} );
} );
