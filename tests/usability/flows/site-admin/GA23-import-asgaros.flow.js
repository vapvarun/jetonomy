// @ts-check
/**
 * GA23 — Import from Asgaros (P2)
 *
 * Visit the Jetonomy import page and assert the Asgaros import option renders.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA23 — Import page shows Asgaros import option', () => {

	test( 'Asgaros import option is visible on import page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-import' );
		metrics.start();

		// The import page should show an Asgaros option.
		const asgarosOption = page.locator( 'text=/asgaros/i' ).first();
		await expect( asgarosOption ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
