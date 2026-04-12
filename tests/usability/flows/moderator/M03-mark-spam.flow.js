// @ts-check
/**
 * M03 — Mark content as spam.
 *
 * Seeds a flag, admin visits the mod queue, clicks the spam button on the
 * flagged item, and asserts the post status changes to spam.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'M03 — Mark content as spam', () => {

	const spaceId = 1;
	let postId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=M03 Spam Post ${ suffix }`,
			'--content=Post to be marked as spam for M03 test.',
		] );
		postId = post.data?.id || post.id;

		// Seed a flag.
		journey( [
			'flag', 'create',
			'--type=post',
			`--id=${ postId }`,
			'--user=3',
			'--reason=Spam content for M03 test',
		] );
	} );

	test.afterEach( () => {
		if ( postId ) {
			dbWrite( `DELETE FROM wp_jt_flags WHERE object_type = 'post' AND object_id = ${ postId }` );
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'admin marks a flagged post as spam from the mod queue', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/community/mod/' );
		metrics.start();

		const flaggedItem = page.locator( '.jt-mod-item, .jt-flag-row' ).first();
		await expect( flaggedItem ).toBeVisible( { timeout: 5000 } );

		// Click the spam button.
		const spamBtn = flaggedItem.locator(
			'button:has-text("Spam"), button[data-action="spam"]'
		).first();
		await expect( spamBtn ).toBeVisible( { timeout: 3000 } );
		await spamBtn.click();
		metrics.recordClick();

		// Wait for the action to complete.
		await expect( flaggedItem ).not.toBeVisible( { timeout: 8000 } ).catch( async () => {
			await expect( flaggedItem.locator( '.resolved, .jt-resolved, .spam' ) ).toBeVisible( { timeout: 3000 } );
		} );

		// DB: post status should be 'spam'.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT status FROM wp_jt_posts WHERE id = ${ postId }`
			);
			return rows[ 0 ];
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBe( 'spam' );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
