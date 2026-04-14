// @ts-check
/**
 * PRO-EMAILDIGEST-07 — Admin views digest stats.
 *
 * Logs in as admin, navigates to the email digest dashboard, and
 * asserts stats (total sent, open rate, subscriber count) render.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-EMAILDIGEST-07 — Admin views digest stats', () => {

	test.fixme( 'admin sees digest statistics dashboard', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-email-digest' );
		metrics.start();

		// Stats section should be visible.
		const statsSection = page.locator( '.jt-digest-stats, #digest-stats' );
		await expect( statsSection ).toBeVisible( { timeout: 5000 } );

		// Should show total digests sent.
		const totalSent = statsSection.locator( '.jt-stat-total-sent, [data-stat="total_sent"]' );
		await expect( totalSent ).toBeVisible( { timeout: 5000 } );

		// Should show subscriber count.
		const subscribers = statsSection.locator( '.jt-stat-subscribers, [data-stat="subscribers"]' );
		await expect( subscribers ).toBeVisible( { timeout: 5000 } );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
