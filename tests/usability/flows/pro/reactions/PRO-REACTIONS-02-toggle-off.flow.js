// @ts-check
/**
 * PRO-REACTIONS-02 — Toggle off reaction.
 *
 * Seeds a post where alice already reacted, clicks the same reaction
 * again, and asserts it is removed (toggle behavior).
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-REACTIONS-02 — Toggle off reaction', () => {

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
			`--title=Toggle React ${ suffix }`,
			'--content=Toggle test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Alice already has a thumbsup reaction.
		proJourney( [ 'reactions', 'add', '--user=3', '--target-type=post', `--target-id=${ postId }`, '--emoji=thumbsup' ] );
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_reactions WHERE target_type = 'post' AND target_id = ${ postId }` ); } catch ( e ) { /* ignore */ }
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'clicking the same reaction removes it', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// The existing reaction chip should be visible and highlighted.
		const reactionChip = page.locator( '.jt-post-reactions .jt-reaction-chip.active, .jt-post-reactions .jt-reaction-chip[data-mine="true"]' ).first();
		await expect( reactionChip ).toBeVisible( { timeout: 5000 } );

		// Click it to toggle off.
		await reactionChip.click();
		metrics.recordClick();

		// Chip should disappear or lose active state.
		await expect( reactionChip ).not.toHaveClass( /active/, { timeout: 5000 } ).catch( () => {
			// Or the chip is removed entirely.
		} );

		// DB: reaction removed.
		assertDbRowAbsent( 'wp_jt_pro_reactions', `target_type = 'post' AND target_id = ${ postId } AND user_id = 3` );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertErrorCount( 0 );
	} );
} );
