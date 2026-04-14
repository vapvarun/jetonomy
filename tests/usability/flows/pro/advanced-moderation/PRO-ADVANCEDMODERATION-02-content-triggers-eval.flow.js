// @ts-check
/**
 * PRO-ADVANCEDMODERATION-02 — Content submission triggers evaluation.
 *
 * Seeds a word-filter rule, creates a post containing the trigger word,
 * and verifies the evaluation ran (post status changed or log entry).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-ADVANCEDMODERATION-02 — Content submission triggers evaluation', () => {

	let ruleId;
	let spaceId;
	let postId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'advanced-moderation' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'advanced-moderation' ] );
		}

		// Seed a rule that holds content containing "blocked-word".
		const rule = proJourney( [
			'advanced-moderation', 'create',
			'--name=TestRule',
			'--type=keyword',
			'--pattern=blocked-word',
			'--action=hold',
			
		] );
		ruleId = rule.data?.id;

		// Get a space to post in.
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

	test( 'post with trigger word is evaluated by mod rules', () => {
		// Create a post with the blocked keyword.
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Test blocked-word post',
			'--content=This contains blocked-word to test moderation',
		] );

		expect( post.success ).toBe( true );
		postId = post.data?.id;

		// The post should have been held or flagged by the rule.
		const statuses = dbQuery(
			`SELECT status FROM wp_jt_posts WHERE id = ${ postId }`
		);
		// Status should be 'held' or 'pending_review' (not 'published').
		expect( [ 'held', 'pending_review', 'pending' ] ).toContain( statuses[ 0 ] );
	} );
} );
