// @ts-check
/**
 * PRO-REACTIONS-05 — View aggregated counts.
 *
 * Seeds multiple reactions on a post from different users, visits
 * the post, and asserts the reaction chips show correct counts.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-REACTIONS-05 — View aggregated counts', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	let postId;
	let postSlug;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=Count React ${ suffix }`,
			'--content=Count test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Multiple users react with the same emoji.
		proJourney( [ 'reactions', 'add', '--user=1', '--target-type=post', `--target-id=${ postId }`, '--emoji=thumbsup' ] );
		proJourney( [ 'reactions', 'add', '--user=4', '--target-type=post', `--target-id=${ postId }`, '--emoji=thumbsup' ] );
		proJourney( [ 'reactions', 'add', '--user=3', '--target-type=post', `--target-id=${ postId }`, '--emoji=heart' ] );
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_reactions WHERE target_type = 'post' AND target_id = ${ postId }` ); } catch ( e ) { /* ignore */ }
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'reaction chips show correct aggregated counts', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		const reactionArea = page.locator( '.jt-post-reactions' ).first();
		await expect( reactionArea ).toBeVisible( { timeout: 5000 } );

		// Thumbsup chip should show count of 2.
		const thumbsChip = reactionArea.locator( '.jt-reaction-chip:has-text("👍"), .jt-reaction-chip[data-emoji="thumbsup"]' );
		await expect( thumbsChip ).toBeVisible( { timeout: 5000 } );
		const thumbsText = await thumbsChip.textContent();
		expect( thumbsText ).toContain( '2' );

		// Heart chip should show count of 1.
		const heartChip = reactionArea.locator( '.jt-reaction-chip:has-text("❤"), .jt-reaction-chip[data-emoji="heart"]' );
		await expect( heartChip ).toBeVisible( { timeout: 5000 } );
		const heartText = await heartChip.textContent();
		expect( heartText ).toContain( '1' );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertErrorCount( 0 );
	} );
} );
