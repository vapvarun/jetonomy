// @ts-check
/**
 * PRO-REACTIONS-04 — React to a reply.
 *
 * Seeds a post with a reply, logs in as alice, reacts to the reply,
 * and asserts the reaction targets the reply (not the post).
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-REACTIONS-04 — React to a reply', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	let postId;
	let postSlug;
	let replyId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=React Reply ${ suffix }`,
			'--content=Post with a reply.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Seed a reply from bob.
		const reply = journey( [
			'reply', 'create',
			`--post=${ postId }`,
			'--author=4',
			'--content=Great discussion!',
		] );
		replyId = reply.data?.id || reply.id;
	} );

	test.afterEach( () => {
		if ( replyId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_reactions WHERE target_type = 'reply' AND target_id = ${ replyId }` ); } catch ( e ) { /* ignore */ }
			try { journey( [ 'reply', 'delete', String( replyId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice reacts to a reply', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Open reaction picker on the reply (not the post).
		const replyBlock = page.locator( '.jt-reply' ).first();
		const reactBtn = replyBlock.locator( 'button[data-action="react"], .jt-react-trigger' );
		await expect( reactBtn ).toBeVisible( { timeout: 5000 } );
		await reactBtn.click();
		metrics.recordClick();

		// Pick thumbsup.
		const emoji = page.locator( '.jt-emoji-picker .jt-emoji[data-emoji="thumbsup"], .jt-emoji-picker button:has-text("👍")' ).first();
		await emoji.click();
		metrics.recordClick();

		// Reaction chip on the reply.
		const chip = replyBlock.locator( '.jt-reaction-chip' ).first();
		await expect( chip ).toBeVisible( { timeout: 5000 } );

		// DB: reaction targets the reply, not the post.
		assertDbRowExists( 'wp_jt_pro_reactions', `target_type = 'reply' AND target_id = ${ replyId } AND user_id = 3` );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
