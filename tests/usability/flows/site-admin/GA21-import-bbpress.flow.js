// @ts-check
/**
 * GA21 — Import from bbPress
 *
 * Visit the Jetonomy import page and assert the bbPress import option renders.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA21 — Import page shows bbPress import option', () => {

	test( 'bbPress import option is visible on import page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-import' );
		metrics.start();

		// The import page should show a bbPress option (card, button, or heading).
		const bbPressOption = page.locator( 'text=/bbpress/i' ).first();
		await expect( bbPressOption ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
