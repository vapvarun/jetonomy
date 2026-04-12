// @ts-check
/**
 * GA22 — Import from wpForo
 *
 * Visit the Jetonomy import page and assert the wpForo import option renders.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA22 — Import page shows wpForo import option', () => {

	test( 'wpForo import option is visible on import page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-import' );
		metrics.start();

		// The import page should show a wpForo option.
		const wpForoOption = page.locator( 'text=/wpforo/i' ).first();
		await expect( wpForoOption ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
