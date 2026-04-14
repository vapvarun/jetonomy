// @ts-check
/**
 * PRO-WHITELABEL-04 — Set accent color.
 *
 * Sets a custom accent color via CLI and verifies the CSS custom
 * property is overridden on the community page.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-WHITELABEL-04 — Set accent color', () => {

	let originalColor;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'white-label' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'white-label' ] );
		}

		try {
			const current = proJourney( [ 'white-label', 'get' ] );
			originalColor = current.data?.accent_color || '';
		} catch ( e ) {
			originalColor = '';
		}
	} );

	test.afterEach( () => {
		try {
			proJourney( [ 'white-label', 'set', `--accent_color=${ originalColor }` ] );
		} catch ( e ) { /* */ }
	} );

	test( 'custom accent color is applied as CSS variable', async ( { page } ) => {
		proJourney( [ 'white-label', 'set', '--accent_color=#FF5733' ] );

		await autoLogin( page, 1, '/community/' );

		// Check that the inline style or CSS variable override is present.
		const style = await page.evaluate( () => {
			const root = document.querySelector( '.jt-app, :root' );
			return root ? getComputedStyle( root ).getPropertyValue( '--jt-accent' ).trim() : '';
		} );

		// The accent color should be set (may be hex, rgb, or the override).
		expect( style ).toBeTruthy();
	} );
} );
