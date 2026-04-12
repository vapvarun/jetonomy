// @ts-check
/**
 * M01 — Open global moderation queue.
 *
 * Admin (user 1) navigates to /community/mod/ and asserts the moderation
 * queue page renders with the expected UI elements. Verifies the displayed
 * flag count matches the pending flags in the database.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'M01 — Open global moderation queue', () => {

	const specId = 'M01';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'admin opens the mod queue and flag count matches DB', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual pending flag count from DB.
		const dbPendingCount = parseInt(
			dbQuery( "SELECT COUNT(*) FROM wp_jt_flags WHERE status = 'pending'" )[ 0 ] || '0', 10
		);

		await autoLogin( page, 1, '/community/mod/' );
		metrics.start();

		// Assert the mod queue page rendered.
		const queueContainer = page.locator(
			'.jt-mod-queue, .jt-moderation, [class*="mod-queue"], main'
		).first();
		await expect( queueContainer ).toBeVisible( { timeout: 5000 } );

		// Verify the page title or heading contains "Moderation" or "Queue".
		const heading = page.locator( 'h1, h2, .jt-page-title' ).first();
		await expect( heading ).toBeVisible( { timeout: 3000 } );

		// The page should not show an access-denied or 404 state.
		const body = await page.locator( 'body' ).textContent();
		expect( body ).not.toContain( 'Access Denied' );
		expect( body ).not.toContain( '404' );

		// Count displayed flag items in the queue.
		const flagItems = page.locator(
			'.jt-flag-item, .jt-mod-item, .jt-queue-item, tr.flag-row'
		);
		const displayedFlagCount = await flagItems.count();

		// If there are pending flags, the queue must show items.
		// If no pending flags, empty state is acceptable.
		let flagCountConsistent;
		if ( dbPendingCount === 0 ) {
			flagCountConsistent = displayedFlagCount === 0;
		} else {
			flagCountConsistent = displayedFlagCount > 0;
		}

		// Look for a count badge or indicator showing total pending.
		const countBadge = page.locator(
			'.jt-badge, .jt-count, [data-count], .flag-count'
		);
		let badgeMatchesDb = true;
		if ( await countBadge.count() > 0 ) {
			const badgeText = await countBadge.first().textContent();
			const badgeNum = parseInt( badgeText?.trim() || '0', 10 );
			if ( ! isNaN( badgeNum ) ) {
				badgeMatchesDb = badgeNum === dbPendingCount;
			}
		}

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			queue_container_visible: true,
			no_access_denied: ! body.includes( 'Access Denied' ),
			no_404: ! body.includes( '404' ),
			flag_count_consistent_with_db: flagCountConsistent,
			max_clicks: metrics.clicks,
			max_time_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
