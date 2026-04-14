// @ts-check
/**
 * PRO-WHITELABEL-01 — Frontend custom logo renders.
 *
 * Sets a custom logo URL via CLI, visits the community page,
 * and verifies the logo image renders with the custom URL.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-WHITELABEL-01 — Frontend custom logo renders', () => {

	let originalLogo;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'white-label' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'white-label' ] );
		}

		// Save current branding for restore.
		try {
			const current = proJourney( [ 'white-label', 'get' ] );
			originalLogo = current.data?.logo_url || '';
		} catch ( e ) {
			originalLogo = '';
		}
	} );

	test.afterEach( () => {
		try {
			proJourney( [ 'white-label', 'set', `--logo_url=${ originalLogo }` ] );
		} catch ( e ) { /* */ }
	} );

	test( 'custom logo renders on community page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		const logoUrl = 'https://example.com/custom-logo.png';
		proJourney( [ 'white-label', 'set', `--logo_url=${ logoUrl }` ] );

		await autoLogin( page, 1, '/community/' );
		metrics.start();

		// Look for an image with the custom logo URL.
		const logo = page.locator( `img[src="${ logoUrl }"], .jt-logo img, .jt-brand-logo img` );
		await expect( logo.first() ).toBeVisible( { timeout: 5000 } );

		const src = await logo.first().getAttribute( 'src' );
		expect( src ).toBe( logoUrl );

		metrics.assertErrorCount( 0 );
	} );
} );
