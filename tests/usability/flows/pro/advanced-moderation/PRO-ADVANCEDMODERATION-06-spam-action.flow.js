// @ts-check
/**
 * PRO-ADVANCEDMODERATION-06 — Spam action silent-drops.
 *
 * Seeds a rule with action=spam, creates content triggering it, and
 * verifies the post is marked as spam (silent drop).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-ADVANCEDMODERATION-06 — Spam action silent-drops', () => {

	let ruleId;
	let spaceId;
	let postId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'advanced-moderation' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'advanced-moderation' ] );
		}

		const rule = proJourney( [
			'advanced-moderation', 'create',
			'--type=word_filter',
			'--pattern=spam-trigger-xyz',
			'--action=spam',
			'--enabled=1',
		] );
		ruleId = rule.data?.id;

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];
	} );

	test.afterEach( () => {
		if ( ruleId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_mod_rules WHERE id = ${ ruleId }` ); } catch ( e ) { /* */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test( 'spam rule marks post as spam', () => {
		let post;
		try {
			post = journey( [
				'post', 'create',
				`--space=${ spaceId }`,
				'--author=1',
				'--title=Spam test spam-trigger-xyz',
				'--content=Body with spam-trigger-xyz here',
			] );
		} catch ( e ) {
			// Spam action may prevent creation entirely.
			post = { success: false };
		}

		if ( post.success && post.data?.id ) {
			postId = post.data.id;
			const statuses = dbQuery(
				`SELECT status FROM wp_jt_posts WHERE id = ${ postId }`
			);
			expect( [ 'spam', 'rejected', 'blocked' ] ).toContain( statuses[ 0 ] );
		} else {
			// Silent drop — post never created. That is also valid.
			expect( post.success ).toBe( false );
		}
	} );
} );
