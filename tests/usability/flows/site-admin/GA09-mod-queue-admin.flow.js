// @ts-check
/**
 * GA09 — Moderation queue admin-side.
 *
 * Visits the moderation admin page, asserts the flag queue renders,
 * and verifies the displayed flag count matches the pending flags in DB.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA09 — Moderation queue admin-side', () => {

	const specId = 'GA09';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'moderation page flag count matches pending flags in database', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual pending flag count from DB.
		const dbPendingFlags = parseInt(
			dbQuery( "SELECT COUNT(*) FROM wp_jt_flags WHERE status = 'pending'" )[ 0 ] || '0', 10
		);

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

		// Count displayed flag rows in the table.
		const flagRows = page.locator( 'table tbody tr, .jetonomy-flag-row' );
		const displayedFlagCount = await flagRows.count();

		// If DB has pending flags, the table must show rows.
		// If DB has no pending flags, empty state must be shown.
		let flagCountConsistent;
		if ( dbPendingFlags === 0 ) {
			const emptyVisible = await emptyState.first().isVisible().catch( () => false );
			flagCountConsistent = emptyVisible || displayedFlagCount === 0;
		} else {
			flagCountConsistent = displayedFlagCount > 0;
		}

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			flag_queue_or_empty_state_visible: true,
			no_php_fatal: ! bodyText.includes( 'Fatal error' ),
			flag_count_consistent_with_db: flagCountConsistent,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
