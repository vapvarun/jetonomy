// @ts-check
/**
 * M09 — Merge two posts (P2).
 *
 * Merge functionality is not yet implemented in the UI. This test is
 * marked as fixme. When implemented, it should seed two posts, visit one,
 * find the merge action, select the other as the merge target, confirm,
 * and assert the replies from both end up on the surviving post.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'M09 — Merge two posts', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	let postIdA;
	let postSlugA;
	let postIdB;

	test.beforeEach( () => {
		const suffix = Date.now();
		const postA = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=M09 Merge A ${ suffix }`,
			'--content=First post for M09 merge test.',
		] );
		postIdA = postA.data?.id || postA.id;
		postSlugA = postA.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postIdA }` )[ 0 ];

		const postB = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=M09 Merge B ${ suffix }`,
			'--content=Second post for M09 merge test.',
		] );
		postIdB = postB.data?.id || postB.id;
	} );

	test.afterEach( () => {
		if ( postIdA ) {
			try { journey( [ 'post', 'delete', String( postIdA ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( postIdB ) {
			try { journey( [ 'post', 'delete', String( postIdB ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'admin merges two posts into one', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, `/community/s/${ spaceSlug }/t/${ postSlugA }/` );
		metrics.start();

		// Open the more menu.
		const moreTrigger = page.locator( '.jt-post-foot .jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
		await moreTrigger.click();
		metrics.recordClick();

		// Click "Merge".
		const mergeBtn = page.locator(
			'.jt-more-dropdown .jt-more-item', { hasText: /Merge/i }
		).first();
		await expect( mergeBtn ).toBeVisible( { timeout: 3000 } );
		await mergeBtn.click();
		metrics.recordClick();

		// Select the target post to merge into.
		const targetInput = page.locator(
			'.jt-merge-modal input, .jt-post-picker input'
		).first();
		await expect( targetInput ).toBeVisible( { timeout: 5000 } );
		await targetInput.fill( String( postIdB ) );
		metrics.recordClick();

		// Confirm the merge.
		const confirmBtn = page.locator(
			'button:has-text("Merge"), button:has-text("Confirm")'
		).first();
		await expect( confirmBtn ).toBeVisible( { timeout: 3000 } );
		await confirmBtn.click();
		metrics.recordClick();

		// Verify post B no longer exists as a standalone post (merged into A).
		await expect.poll( () => {
			const rows = dbQuery( `SELECT COUNT(*) FROM wp_jt_posts WHERE id = ${ postIdB } AND status = 'published'` );
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000 } ).toBe( 0 );

		metrics.assertClickCount( { lessThanOrEqual: 6 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
