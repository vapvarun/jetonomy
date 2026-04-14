// @ts-check
/**
 * PRO-WHITELABEL-06 — Override admin menu label.
 *
 * Sets a custom admin menu label via CLI and verifies the WordPress
 * admin sidebar shows the custom label instead of "Jetonomy".
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-WHITELABEL-06 — Override admin menu label', () => {

	let originalLabel;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'white-label' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'white-label' ] );
		}

		try {
			const current = proJourney( [ 'white-label', 'get' ] );
			originalLabel = current.data?.admin_menu_label || '';
		} catch ( e ) {
			originalLabel = '';
		}
	} );

	test.afterEach( () => {
		try {
			proJourney( [ 'white-label', 'set', `--admin_menu_label=${ originalLabel }` ] );
		} catch ( e ) { /* */ }
	} );

	test.fixme( 'custom admin menu label renders in sidebar', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const customLabel = 'My Forum';
		proJourney( [ 'white-label', 'set', `--admin_menu_label=${ customLabel }` ] );

		await autoLogin( page, 1, '/wp-admin/' );
		metrics.start();

		// Look for the custom label in the admin menu.
		const menuItem = page.locator( `#adminmenu :has-text("${ customLabel }")` );
		await expect( menuItem.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
