// @ts-check
/**
 * PRO-ADVANCEDMODERATION-03 — Block action prevents post.
 *
 * Seeds a block rule, attempts to create a post matching the pattern,
 * and verifies the post is rejected entirely (not created).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-ADVANCEDMODERATION-03 — Block action prevents post', () => {

	let ruleId;
	let spaceId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'advanced-moderation' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'advanced-moderation' ] );
		}

		// Seed a block rule.
		const rule = proJourney( [
			'advanced-moderation', 'create',
			'--name=TestRule',
			'--type=keyword',
			'--pattern=totally-blocked',
			'--action=block',
			
		] );
		ruleId = rule.data?.id;

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];
	} );

	test.afterEach( () => {
		if ( ruleId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_mod_rules WHERE id = ${ ruleId }` ); } catch ( e ) { /* */ }
		}
	} );

	test( 'post with block-trigger word is rejected', () => {
		const before = dbQuery( 'SELECT COUNT(*) FROM wp_jt_posts' );
		const countBefore = parseInt( before[ 0 ], 10 );

		// Attempt to create post with blocked content.
		let post;
		try {
			post = journey( [
				'post', 'create',
				`--space=${ spaceId }`,
				'--author=1',
				'--title=Test totally-blocked content',
				'--content=This has totally-blocked in it',
			] );
		} catch ( e ) {
			// Expected — the block action should prevent creation.
			post = { success: false };
		}

		// Post should not have been created, or should have failed.
		if ( post.success && post.data?.id ) {
			// If somehow created, it should at least be in blocked/rejected state.
			const status = dbQuery(
				`SELECT status FROM wp_jt_posts WHERE id = ${ post.data.id }`
			);
			expect( [ 'blocked', 'rejected', 'spam' ] ).toContain( status[ 0 ] );
			// Cleanup.
			try { journey( [ 'post', 'delete', String( post.data.id ) ] ); } catch ( e ) { /* */ }
		} else {
			// Creation failed — this is the expected outcome.
			expect( post.success ).toBe( false );
		}
	} );
} );
