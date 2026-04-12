// @ts-check
/**
 * M08 — Move post to another space.
 *
 * Admin visits a post, finds the move button in the more-menu, selects a
 * target space, confirms, and asserts the post's space_id has changed.
 * If the move UI is not yet implemented, the test is marked as fixme.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'M08 — Move post to another space', () => {

	const sourceSpaceId = 1;
	const sourceSpaceSlug = 'welcome';
	let targetSpaceId;
	let postId;
	let postSlug;

	test.beforeEach( () => {
		// Find a second space to move the post into.
		const spaceRows = dbQuery( `SELECT id FROM wp_jt_spaces WHERE id != ${ sourceSpaceId } LIMIT 1` );
		targetSpaceId = spaceRows.length > 0 ? parseInt( spaceRows[ 0 ], 10 ) : null;

		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ sourceSpaceId }`,
			'--author=4',
			`--title=M08 Move Test ${ suffix }`,
			'--content=Post to be moved for M08 test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			// Move back to original space for cleanup.
			dbWrite( `UPDATE wp_jt_posts SET space_id = ${ sourceSpaceId } WHERE id = ${ postId }` );
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'admin moves a post to another space', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Skip if no second space exists.
		expect( targetSpaceId ).toBeTruthy();

		await autoLogin( page, 1, `/community/s/${ sourceSpaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Open the more menu.
		const moreTrigger = page.locator( '.jt-post-foot .jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
		await moreTrigger.click();
		metrics.recordClick();

		// Click "Move".
		const moveBtn = page.locator(
			'.jt-more-dropdown .jt-more-item', { hasText: /Move/i }
		).first();
		await expect( moveBtn ).toBeVisible( { timeout: 3000 } );
		await moveBtn.click();
		metrics.recordClick();

		// A modal/dropdown should appear to select target space.
		const spaceSelector = page.locator(
			'.jt-move-modal select, .jt-space-picker, .jt-move-dropdown'
		).first();
		await expect( spaceSelector ).toBeVisible( { timeout: 5000 } );

		// Select the target space.
		await spaceSelector.selectOption( { value: String( targetSpaceId ) } );
		metrics.recordClick();

		// Confirm the move.
		const confirmBtn = page.locator(
			'button:has-text("Move"), button:has-text("Confirm")'
		).first();
		await expect( confirmBtn ).toBeVisible( { timeout: 3000 } );
		await confirmBtn.click();
		metrics.recordClick();

		// DB: post space_id should be the target space.
		await expect.poll( () => {
			const rows = dbQuery( `SELECT space_id FROM wp_jt_posts WHERE id = ${ postId }` );
			return rows[ 0 ];
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBe( String( targetSpaceId ) );

		metrics.assertClickCount( { lessThanOrEqual: 6 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
