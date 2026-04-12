// @ts-check
/**
 * GA09 — Moderation queue admin-side.
 *
 * Visits the moderation admin page and asserts the flag queue renders.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'GA09 — Moderation queue admin-side', () => {

	test( 'moderation page renders with flag queue', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-moderation' );
		metrics.start();

		// Assert page renders.
		const wrapper = page.locator( '.wrap, .jetonomy-moderation' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Assert the flag queue table or empty-state message renders.
		const queue = page.locator(
			'table, .jetonomy-mod-queue, .widefat, .jetonomy-flag-list'
		);
		const emptyState = page.locator(
			'.no-items, p:has-text("No flagged"), p:has-text("no items"), .jetonomy-empty-state'
		);
		await expect( queue.or( emptyState ).first() ).toBeVisible( { timeout: 5000 } );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
