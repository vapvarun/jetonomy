// @ts-check
/**
 * M05 — Resolve flag without content action.
 *
 * Seeds a flag, admin visits the mod queue, dismisses the flag without
 * taking any action on the content (no spam, no trash), and asserts the
 * flag is marked resolved while the post remains published.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'M05 — Resolve flag without content action', () => {

	const spaceId = 1;
	let postId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=M05 No Action Post ${ suffix }`,
			'--content=Post flagged but no action needed for M05 test.',
		] );
		postId = post.data?.id || post.id;

		// Seed a flag.
		journey( [
			'flag', 'create',
			'--type=post',
			`--id=${ postId }`,
			'--user=3',
			'--reason=False report for M05 test',
		] );
	} );

	test.afterEach( () => {
		if ( postId ) {
			dbWrite( `DELETE FROM wp_jt_flags WHERE object_type = 'post' AND object_id = ${ postId }` );
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'admin resolves a flag without taking content action', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/community/mod/' );
		metrics.start();

		const flaggedItem = page.locator( '.jt-mod-item, .jt-flag-row' ).first();
		await expect( flaggedItem ).toBeVisible( { timeout: 5000 } );

		// Click the dismiss/resolve button (no content action).
		const dismissBtn = flaggedItem.locator(
			'button:has-text("Dismiss"), button:has-text("Resolve"), button:has-text("No Action"), button[data-action="dismiss"], button[data-action="resolve"]'
		).first();
		await expect( dismissBtn ).toBeVisible( { timeout: 3000 } );
		await dismissBtn.click();
		metrics.recordClick();

		// Wait for the flag to be resolved in the UI.
		await expect( flaggedItem ).not.toBeVisible( { timeout: 8000 } ).catch( async () => {
			await expect( flaggedItem.locator( '.resolved, .jt-resolved' ) ).toBeVisible( { timeout: 3000 } );
		} );

		// DB: flag should be resolved.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT status FROM wp_jt_flags WHERE object_type = 'post' AND object_id = ${ postId } AND user_id = 3`
			);
			return rows[ 0 ];
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBe( 'resolved' );

		// DB: post should still be published (not trashed/spam).
		const postStatus = dbQuery( `SELECT status FROM wp_jt_posts WHERE id = ${ postId }` );
		expect( postStatus[ 0 ] ).toBe( 'published' );

		const expectation = loadSpec( 'M05' );
		matchDelivery( expectation, {
			flag_resolved_without_content_action: true,
			post_still_published: postStatus[ 0 ] === 'published',
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
			max_time_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
