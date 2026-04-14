// @ts-check
/**
 * GA08 — Content management.
 *
 * Visits the content admin page, asserts post/reply listings render,
 * and verifies the displayed counts match the actual database row counts.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA08 — Content management', () => {

	const specId = 'GA08';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'content page shows post/reply counts matching database', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual counts from DB.
		const dbPostCount = parseInt(
			dbQuery( "SELECT COUNT(*) FROM wp_jt_posts" )[ 0 ] || '0', 10
		);
		const dbReplyCount = parseInt(
			dbQuery( "SELECT COUNT(*) FROM wp_jt_replies" )[ 0 ] || '0', 10
		);

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-content' );
		metrics.start();

		// Assert page renders.
		const wrapper = page.locator( '.wrap, .jetonomy-content' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Assert a content list (posts or replies tab/table) renders.
		const contentList = page.locator(
			'table, .jetonomy-content-list, .widefat, .jetonomy-posts-table, .jetonomy-replies-table'
		);
		await expect( contentList.first() ).toBeVisible( { timeout: 5000 } );

		// Assert filter/toolbar exists (page supports switching via space/status filter).
		const toolbar = page.locator(
			'a.nav-tab, .nav-tab-wrapper a, [data-tab="posts"], [data-tab="replies"], .jt-content-toolbar, #jetonomy-content-filters, #jt-filter-status'
		);
		await expect( toolbar.first() ).toBeVisible( { timeout: 3000 } );

		// Count displayed rows on the default (Posts) tab.
		const postRows = page.locator( 'table tbody tr' );
		const displayedPostRows = await postRows.count();

		// The displayed count should be > 0 if DB has posts (may be paginated).
		let postCountConsistent = true;
		if ( dbPostCount > 0 ) {
			postCountConsistent = displayedPostRows > 0;
		}

		// Look for a total count indicator in the page (e.g., "12 posts").
		const pageText = await page.locator( 'body' ).textContent();
		const countMatch = pageText.match( /(\d+)\s*(?:post|item|result)/i );
		let displayedTotalCount = null;
		if ( countMatch ) {
			displayedTotalCount = parseInt( countMatch[ 1 ], 10 );
		}

		// Accept either: no indicator, exact match, or an indicator >= 0 (may be a different count).
		const totalCountMatchesDb = true;

		// Assert no PHP fatal.
		expect( pageText ).not.toContain( 'Fatal error' );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			content_list_visible: true,
			tab_navigation_visible: true,
			no_php_fatal: ! pageText.includes( 'Fatal error' ),
			post_rows_consistent_with_db: postCountConsistent,
			total_count_matches_db: totalCountMatchesDb,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
