// @ts-check
/**
 * PRO-REACTIONS-03 — Switch reaction (exclusive).
 *
 * If the system enforces one reaction per user per target, switching
 * from thumbsup to heart should replace the old reaction, not add a second.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-REACTIONS-03 — Switch reaction (exclusive)', () => {

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
			`--title=Switch React ${ suffix }`,
			'--content=Switch test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Alice has an existing thumbsup.
		proJourney( [ 'reactions', 'add', '--user=3', '--target-type=post', `--target-id=${ postId }`, '--emoji=thumbsup' ] );
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_reactions WHERE target_type = 'post' AND target_id = ${ postId }` ); } catch ( e ) { /* ignore */ }
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'switching emoji replaces previous reaction', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Open reaction picker.
		const reactBtn = page.locator( '.jt-post-foot button[data-action="react"], .jt-post-foot .jt-react-trigger' ).first();
		await reactBtn.click();
		metrics.recordClick();

		// Pick a different emoji (heart).
		const heart = page.locator( '.jt-emoji-picker .jt-emoji[data-emoji="heart"], .jt-emoji-picker button:has-text("❤")' ).first();
		await expect( heart ).toBeVisible( { timeout: 5000 } );
		await heart.click();
		metrics.recordClick();

		// DB: only 1 reaction row for alice (not 2).
		const count = dbQuery( `SELECT COUNT(*) FROM wp_jt_pro_reactions WHERE target_type = 'post' AND target_id = ${ postId } AND user_id = 3` );
		expect( parseInt( count[ 0 ], 10 ) ).toBe( 1 );

		// The emoji should be heart, not thumbsup.
		const emoji = dbQuery( `SELECT emoji FROM wp_jt_pro_reactions WHERE target_type = 'post' AND target_id = ${ postId } AND user_id = 3` );
		expect( emoji[ 0 ] ).toBe( 'heart' );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
