// @ts-check
/**
 * GA08 — Content management.
 *
 * Visits the content admin page and asserts post/reply listings render.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'GA08 — Content management', () => {

	test( 'content page renders with post and reply listings', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

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

		// Assert tab navigation for Posts/Replies exists.
		const tabs = page.locator(
			'a.nav-tab, .nav-tab-wrapper a, [data-tab="posts"], [data-tab="replies"]'
		);
		await expect( tabs.first() ).toBeVisible( { timeout: 3000 } );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
