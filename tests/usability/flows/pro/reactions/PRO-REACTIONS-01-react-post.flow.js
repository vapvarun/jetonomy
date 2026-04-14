// @ts-check
/**
 * PRO-REACTIONS-01 — React to a post.
 *
 * Seeds a post, logs in as alice, opens the emoji picker on the post,
 * selects a reaction, and asserts it is persisted to wp_jt_pro_reactions.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-REACTIONS-01 — React to a post', () => {

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
			`--title=React Post ${ suffix }`,
			'--content=React to this!',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { dbQuery( `SELECT id FROM wp_jt_pro_reactions WHERE target_type = 'post' AND target_id = ${ postId }` ).forEach( ( id ) => {
				proJourney( [ 'reactions', 'remove', id ] );
			} ); } catch ( e ) { /* ignore */ }
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice reacts to a post with an emoji', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Open the reaction picker on the post.
		const reactBtn = page.locator( '.jt-post-foot button[data-action="react"], .jt-post-foot .jt-react-trigger' ).first();
		await expect( reactBtn ).toBeVisible( { timeout: 5000 } );
		await reactBtn.click();
		metrics.recordClick();

		// Pick an emoji (e.g., thumbs up).
		const emoji = page.locator( '.jt-emoji-picker .jt-emoji[data-emoji="thumbsup"], .jt-emoji-picker button:has-text("👍")' ).first();
		await expect( emoji ).toBeVisible( { timeout: 5000 } );
		await emoji.click();
		metrics.recordClick();

		// Reaction should appear on the post.
		const reactionBadge = page.locator( '.jt-post-reactions .jt-reaction-chip' ).first();
		await expect( reactionBadge ).toBeVisible( { timeout: 5000 } );

		// DB: reaction row exists.
		assertDbRowExists( 'wp_jt_pro_reactions', `target_type = 'post' AND target_id = ${ postId } AND user_id = 3` );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
