// @ts-check
/**
 * PRO-ADVANCEDMODERATION-05 — Flag action creates flag row.
 *
 * Seeds a rule with action=flag, triggers it, and verifies a row
 * appears in wp_jt_flags.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );

test.describe( 'PRO-ADVANCEDMODERATION-05 — Flag action creates flag row', () => {

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
			'--name=TestRule',
			'--type=keyword',
			'--pattern=flag-me-word',
			'--action=flag',
			
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
			try { dbWrite( `DELETE FROM wp_jt_flags WHERE content_type = 'post' AND content_id = ${ postId }` ); } catch ( e ) { /* */ }
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test( 'flag action creates a flag row for matching content', () => {
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Flag test flag-me-word',
			'--content=This has flag-me-word in body',
		] );
		postId = post.data?.id;
		expect( postId ).toBeTruthy();

		// A flag row should be auto-created by the moderation rule.
		assertDbRowExists( 'wp_jt_flags', `content_type = 'post' AND content_id = ${ postId }` );
	} );
} );
