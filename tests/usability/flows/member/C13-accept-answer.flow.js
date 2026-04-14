// @ts-check
/**
 * C13 — Accept an answer (Q&A).
 *
 * Creates a Q&A space (if needed), seeds a post as alice with a reply
 * from bob, logs in as alice (the post author), clicks the Accept button
 * on bob's reply, and asserts the reply is marked as accepted.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, getUserId, getSpaceId } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbColumn } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C13 — Accept an answer (Q&A)', () => {

	let qaSpaceId;
	let qaSpaceSlug;
	let createdSpaceId;
	let postId;
	let postSlug;
	let replyId;
	const aliceId = users.id( 'alice' );
	const bobId = users.id( 'bob' );

	test.beforeEach( () => {
		// Find or create a Q&A space.
		const rows = dbQuery( "SELECT id FROM wp_jt_spaces WHERE type = 'qa' AND status = 'active' LIMIT 1" );
		if ( rows.length > 0 ) {
			qaSpaceId = parseInt( rows[ 0 ], 10 );
			qaSpaceSlug = dbQuery( `SELECT slug FROM wp_jt_spaces WHERE id = ${ qaSpaceId }` )[ 0 ];
		} else {
			const suffix = Date.now();
			const catRows = dbQuery( 'SELECT id FROM wp_jt_categories LIMIT 1' );
			const catId = catRows.length > 0 ? parseInt( catRows[ 0 ], 10 ) : 1;
			const space = journey( [
				'space', 'create',
				`--title=QA Accept Test ${ suffix }`,
				`--slug=qa-accept-${ suffix }`,
				`--category=${ catId }`,
				'--type=qa',
				'--visibility=public',
				'--join-policy=open',
			] );
			qaSpaceId = space.data?.id || space.id;
			qaSpaceSlug = space.data?.slug || `qa-accept-${ suffix }`;
			createdSpaceId = qaSpaceId;
		}

		// Seed a post by alice.
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ qaSpaceId }`,
			`--author=${ aliceId }`,
			`--title=C13 QA Post ${ suffix }`,
			'--content=Question that needs an accepted answer.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Seed a reply by bob.
		const reply = journey( [
			'reply', 'create',
			`--post=${ postId }`,
			`--author=${ bobId }`,
			'--content=This is the answer to accept.',
		] );
		replyId = reply.data?.id || reply.id;
	} );

	test.afterEach( () => {
		if ( replyId ) {
			try { journey( [ 'reply', 'delete', String( replyId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( createdSpaceId ) {
			try { journey( [ 'space', 'delete', String( createdSpaceId ) ] ); } catch ( e ) { /* ignore */ }
			createdSpaceId = null;
		}
	} );

	test( 'post author (alice) accepts a reply as the answer', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ qaSpaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Find the Accept button on bob's reply.
		const acceptBtn = page.locator( 'button[data-wp-on--click="actions.acceptReply"]' ).first();
		await expect( acceptBtn ).toBeVisible( { timeout: 5000 } );
		await acceptBtn.click();
		metrics.recordClick();

		// The reply should now show the "Accepted" tag.
		const acceptedTag = page.locator( '.jt-accepted-tag' );
		await expect( acceptedTag ).toBeVisible( { timeout: 10000 } );

		// The reply wrapper should have the .accepted class.
		const replyCard = page.locator( '.jt-reply.accepted' );
		await expect( replyCard ).toBeVisible( { timeout: 5000 } );

		// DB: reply is_accepted should be 1.
		await expect.poll( () => {
			const val = dbQuery( `SELECT is_accepted FROM wp_jt_replies WHERE id = ${ replyId }` );
			return val[ 0 ] === '1';
		}, { timeout: 5000 } ).toBe( true );

		assertDbColumn( 'wp_jt_replies', replyId, 'is_accepted', 1 );

		const expectation = loadSpec( 'C13' );
		matchDelivery( expectation, {
			accepted_badge_visible: true,
			reply_has_accepted_class: true,
			db_is_accepted_equals_1: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_accept: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
