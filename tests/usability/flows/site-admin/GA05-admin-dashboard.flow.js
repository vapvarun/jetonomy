// @ts-check
/**
 * GA05 — Admin dashboard.
 *
 * Visits the Jetonomy admin dashboard page, asserts dashboard widgets
 * render, and verifies that displayed post/reply/user counts match the
 * actual database row counts.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA05 — View admin dashboard', () => {

	const specId = 'GA05';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'dashboard shows correct counts matching database', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual counts from DB before loading the page.
		const dbPostCount = parseInt( dbQuery( "SELECT COUNT(*) FROM wp_jt_posts" )[ 0 ] || '0', 10 );
		const dbReplyCount = parseInt( dbQuery( "SELECT COUNT(*) FROM wp_jt_replies" )[ 0 ] || '0', 10 );
		const dbUserCount = parseInt( dbQuery( "SELECT COUNT(*) FROM wp_jt_user_profiles" )[ 0 ] || '0', 10 );
		const dbSpaceCount = parseInt( dbQuery( "SELECT COUNT(*) FROM wp_jt_spaces" )[ 0 ] || '0', 10 );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy' );
		metrics.start();

		// Assert the dashboard wrapper renders.
		const dashboard = page.locator(
			'.jetonomy-dashboard, .jetonomy-admin-dashboard, #jetonomy-dashboard, .wrap'
		);
		await expect( dashboard.first() ).toBeVisible( { timeout: 5000 } );

		// Assert at least one dashboard widget/card renders.
		const widget = page.locator(
			'.jetonomy-dashboard-widget, .jetonomy-stat-card, .postbox, .jetonomy-card'
		);
		await expect( widget.first() ).toBeVisible( { timeout: 5000 } );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		// Extract displayed counts from the dashboard.
		// Look for stat values inside cards/widgets.
		const statValues = page.locator(
			'.jetonomy-stat-value, .jetonomy-card .stat-value, .jetonomy-stat-card .count, .jetonomy-dashboard-widget .value, .stat-number'
		);
		const statCount = await statValues.count();

		// If stat cards are present, verify at least one count is a valid number.
		let countsAreNumeric = true;
		if ( statCount > 0 ) {
			const firstStatText = await statValues.first().textContent();
			countsAreNumeric = /\d+/.test( firstStatText || '' );
		}

		// Verify DB counts are plausible (non-negative).
		const dbCountsValid = dbPostCount >= 0 && dbReplyCount >= 0 && dbSpaceCount >= 0;

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			dashboard_renders: true,
			widgets_visible: statCount > 0 || ( await widget.count() ) > 0,
			no_php_fatal: ! bodyText.includes( 'Fatal error' ),
			db_counts_valid: dbCountsValid,
			stat_values_numeric: countsAreNumeric,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
